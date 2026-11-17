<?php

namespace App\Jobs;

use App\Actions\ApplyGenerationState;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a pending generation to Pixelforge.
 *
 * Every attempt reuses the generation's Idempotency-Key, so a retry after a
 * timeout or lost response returns the original generation instead of
 * creating (and billing) a second one.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** How long we keep waiting out rate limits and overloads before giving up. */
    public const GIVE_UP_AFTER_MINUTES = 10;

    /** Unexpected exceptions (bugs, DB errors) before the job stops retrying. */
    public int $maxExceptions = 3;

    public DateTimeInterface $giveUpAt;

    public function __construct(public Generation $generation)
    {
        $this->giveUpAt = now()->addMinutes(self::GIVE_UP_AFTER_MINUTES);
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->giveUpAt;
    }

    public function handle(PixelforgeClient $pixelforge, ApplyGenerationState $state): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return; // Already accepted (possibly reported by a webhook first) or already failed.
        }

        $generation->increment('submit_attempts');

        try {
            $object = $pixelforge->createGeneration($generation->toPixelforgeRequest(), $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            $this->handleRejection($e, $generation, $state);

            return;
        }

        $state->apply($object, $generation);
    }

    private function handleRejection(PixelforgeException $e, Generation $generation, ApplyGenerationState $state): void
    {
        if (! $e->isRetryable()) {
            $state->rejectSubmission($generation, $e);

            return;
        }

        $delay = $this->retryDelay($e, $generation->submit_attempts);

        if (now()->addSeconds($delay)->greaterThan($this->retryUntil())) {
            // A connection error leaves the outcome unknown: Pixelforge may
            // have accepted it. Leave it pending; the reconciler will replay
            // the same Idempotency-Key and learn the truth. For 429/503
            // Pixelforge guarantees nothing was created, so it is safe to fail.
            if ($e->errorCode !== 'connection_error' && $e->errorCode !== 'server_error') {
                $state->rejectSubmission($generation, $e);
            }

            return;
        }

        $this->release($delay);
    }

    /** Honour Retry-After, back off exponentially, add jitter, cap at a minute. */
    private function retryDelay(PixelforgeException $e, int $attempt): int
    {
        $exponential = min(60, 2 ** min($attempt, 6));

        return max($e->retryAfter ?? 0, $exponential) + random_int(0, 3);
    }
}
