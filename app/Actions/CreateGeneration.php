<?php

namespace App\Actions;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Money;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PriceEstimator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves a generation, reserves its maximum possible price from the user's
 * balance and queues it for submission. The Pixelforge call happens in a job
 * so rate limits and overloads can be waited out without blocking the user.
 */
class CreateGeneration
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly PriceEstimator $estimator,
        private readonly Ledger $ledger,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>}  $input
     */
    public function handle(User $user, array $input, ?Generation $retryOf = null): Generation
    {
        $referenceImages = array_values($input['reference_images'] ?? []);

        $estimate = $this->estimator->estimate(
            $this->pixelforge->pricing(),
            $input['prompt'],
            $input['size'],
            $input['quality'],
            count($referenceImages),
        );

        $generation = DB::transaction(function () use ($user, $input, $referenceImages, $estimate, $retryOf) {
            // Locks the user row, so two clicks on "Try again" are handled one after the other.
            $balance = $this->ledger->lockedBalance($user);

            if ($retryOf !== null && ! $retryOf->fresh()->canBeRetried()) {
                throw ValidationException::withMessages(['retry' => 'This image has already been retried.']);
            }

            if ($balance < $estimate->maxCharge()) {
                throw ValidationException::withMessages([
                    'balance' => sprintf(
                        'This image can cost up to %s and your balance is %s. Buy credits to continue.',
                        Money::format($estimate->maxCharge()),
                        Money::format($balance),
                    ),
                ]);
            }

            $generation = $user->generations()->create([
                'retry_of_id' => $retryOf?->id,
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $referenceImages,
                'status' => GenerationStatus::Pending,
                'estimated_min_charge' => $estimate->minCharge(),
                'estimated_charge' => $estimate->expectedCharge(),
                'estimated_max_charge' => $estimate->maxCharge(),
                'hold_amount' => $estimate->maxCharge(),
            ]);

            $this->ledger->hold($generation);

            return $generation;
        });

        SubmitGeneration::dispatch($generation);

        return $generation;
    }
}
