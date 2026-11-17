<?php

namespace App\Console\Commands;

use App\Actions\ApplyGenerationState;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for lost webhooks and interrupted submissions. Webhooks are
 * "not guaranteed", so anything that has been quiet for a few minutes is
 * checked against Pixelforge directly.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile {--limit=20 : Max API calls per run (rate limit is 60/min per account)}';

    protected $description = 'Poll Pixelforge for generations whose webhooks have not arrived, and resubmit stalled ones';

    /** Quiet period before we stop trusting webhooks/jobs to make progress. */
    private const STALE_AFTER_MINUTES = 3;

    /** After this long without being accepted, a submission is given up (no charge). */
    private const ABANDON_AFTER_MINUTES = 60;

    public function handle(PixelforgeClient $pixelforge, ApplyGenerationState $state): int
    {
        $limit = (int) $this->option('limit');
        $staleBefore = now()->subMinutes(self::STALE_AFTER_MINUTES);

        $accepted = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where('updated_at', '<', $staleBefore)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($accepted as $generation) {
            try {
                $state->apply($pixelforge->getGeneration($generation->pixelforge_id), $generation);
                $generation->touch(); // Space out polls of still-running generations.
            } catch (PixelforgeException $e) {
                Log::warning('Reconcile poll failed', ['generation' => $generation->id, 'code' => $e->errorCode]);

                if ($e->errorCode === 'rate_limit_exceeded') {
                    break;
                }
            }
        }

        // Pending rows whose submit job has stopped (gave up, or crashed).
        // Re-submitting reuses the Idempotency-Key, so if Pixelforge did
        // accept an earlier attempt we simply get that generation back.
        $stalled = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', $staleBefore)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($stalled as $generation) {
            if ($generation->created_at->lt(now()->subMinutes(self::ABANDON_AFTER_MINUTES))) {
                $state->rejectSubmission($generation, new PixelforgeException('submission_abandoned', 'Could not reach Pixelforge.'));

                continue;
            }

            $generation->touch();
            SubmitGeneration::dispatch($generation);
        }

        $this->info("Polled {$accepted->count()} accepted and resubmitted/closed {$stalled->count()} pending generations.");

        return self::SUCCESS;
    }
}
