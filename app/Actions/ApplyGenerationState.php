<?php

namespace App\Actions;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Pixelforge\Money;
use App\Pixelforge\PixelforgeException;
use App\Pixelforge\Rates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place a generation's status and money change. Webhooks, the
 * submit job and the reconciler all hand it a Pixelforge generation object.
 *
 * Safe to call any number of times, in any order:
 *  - the row is locked, so concurrent deliveries are serialised;
 *  - status only moves forward and succeeded/failed are final;
 *  - ledger entries are unique per generation, so money is settled once.
 */
class ApplyGenerationState
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @param  array<string, mixed>  $object  a Pixelforge generation object
     * @param  Generation|null  $known  the local row, when the caller already knows it
     * @return Generation|null null when the object does not belong to this app
     */
    public function apply(array $object, ?Generation $known = null): ?Generation
    {
        return DB::transaction(function () use ($object, $known) {
            $generation = $this->findAndLock($object, $known);

            if ($generation === null) {
                return null;
            }

            $this->recordAcceptance($generation, $object);

            $next = GenerationStatus::from($object['status']);

            if ($generation->status->canTransitionTo($next)) {
                $generation->status = $next;
                $generation->started_at ??= $this->time($object['started_at'] ?? null);

                match ($next) {
                    GenerationStatus::Succeeded => $this->settleSuccess($generation, $object),
                    GenerationStatus::Failed => $this->settleFailure(
                        $generation,
                        $object['error']['code'] ?? 'generation_failed',
                        $object['error']['message'] ?? 'The generation failed.',
                        $this->time($object['completed_at'] ?? null),
                    ),
                    default => null,
                };
            }

            $generation->save();

            return $generation;
        });
    }

    /**
     * Fails a generation Pixelforge never accepted (e.g. content policy, or
     * still overloaded after every retry). Nothing was billed, so it is free.
     */
    public function rejectSubmission(Generation $generation, PixelforgeException $error): void
    {
        DB::transaction(function () use ($generation, $error) {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->status !== GenerationStatus::Pending) {
                return;
            }

            $generation->status = GenerationStatus::Failed;
            $this->settleFailure($generation, $error->errorCode, $error->userMessage(), now());
            $generation->save();
        });
    }

    private function findAndLock(array $object, ?Generation $known): ?Generation
    {
        $query = fn () => Generation::query()->lockForUpdate();

        $generation = $query()->where('pixelforge_id', $object['id'])->first();

        // A webhook can arrive before the POST response that tells us the id.
        $localId = $object['metadata']['generation_id'] ?? $known?->id;
        if ($generation === null && $localId !== null) {
            $generation = $query()->whereKey($localId)->whereNull('pixelforge_id')->first();
        }

        return $generation;
    }

    private function recordAcceptance(Generation $generation, array $object): void
    {
        $generation->pixelforge_id ??= $object['id'];
        $generation->rates ??= $object['rates'] ?? null;
        $generation->submitted_at ??= $this->time($object['created_at'] ?? null) ?? now();

        if ($generation->status === GenerationStatus::Pending) {
            $generation->status = GenerationStatus::Queued;
        }
    }

    private function settleSuccess(Generation $generation, array $object): void
    {
        $usage = $object['usage'];
        $rates = Rates::fromArray($object['rates'] ?? $generation->rates);

        $cost = $rates->cost($usage);
        $charge = Money::withMarkup($cost);

        if ($charge > $generation->hold_amount) {
            Log::warning('Generation cost more than its hold', ['generation' => $generation->id, 'charge' => $charge, 'hold' => $generation->hold_amount]);
        }

        $generation->fill([
            'input_text_tokens' => $usage['input_text_tokens'],
            'input_image_tokens' => $usage['input_image_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'reasoning_tokens' => $usage['reasoning_tokens'],
            'provider_cost' => $cost,
            'user_charge' => $charge,
            'image_url' => $object['image_url'] ?? null,
            'image_url_expires_at' => $this->time($object['image_url_expires_at'] ?? null),
            'completed_at' => $this->time($object['completed_at'] ?? null) ?? now(),
            'settled_at' => now(),
        ]);

        $this->ledger->settle($generation, $charge);

        DownloadGenerationImage::dispatch($generation)->afterCommit();
    }

    private function settleFailure(Generation $generation, string $code, string $message, ?Carbon $completedAt): void
    {
        $generation->fill([
            'error_code' => $code,
            'error_message' => $message,
            'provider_cost' => 0,
            'user_charge' => 0,
            'completed_at' => $completedAt ?? now(),
            'settled_at' => now(),
        ]);

        $this->ledger->release($generation);
    }

    private function time(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }
}
