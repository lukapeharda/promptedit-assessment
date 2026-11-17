<?php

namespace App\Billing;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The credit ledger. Every balance change is a row in credit_transactions and
 * the balance is their sum, so it can always be audited and recomputed.
 *
 * A generation's lifecycle in the ledger:
 *   hold  (-max price)  at submission, so concurrent requests cannot overspend
 *   release (+hold)     when it finishes, succeeded or failed
 *   charge (-actual)    only when it succeeded
 *
 * Generation entries are written with ON CONFLICT DO NOTHING against the
 * unique (generation_id, type) index, so replays are harmless.
 */
class Ledger
{
    public function balance(User $user): int
    {
        return (int) $user->creditTransactions()->sum('amount');
    }

    /**
     * Locks the user's row for the rest of the current transaction so balance
     * checks and the hold that follows them cannot interleave.
     */
    public function lockedBalance(User $user): int
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();

        return $this->balance($user);
    }

    public function purchase(User $user, int $amount, string $description = 'Credit purchase'): CreditTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Purchase amount must be positive.');
        }

        return $user->creditTransactions()->create([
            'type' => CreditTransactionType::Purchase,
            'amount' => $amount,
            'description' => $description,
        ]);
    }

    public function hold(Generation $generation): void
    {
        $this->record($generation, CreditTransactionType::Hold, -$generation->hold_amount, 'Reserved until the image is ready');
    }

    public function release(Generation $generation): void
    {
        $this->record($generation, CreditTransactionType::Release, $generation->hold_amount, 'Reservation released');
    }

    /** Release the hold and charge the actual price, atomically. */
    public function settle(Generation $generation, int $charge): void
    {
        DB::transaction(function () use ($generation, $charge) {
            $this->release($generation);
            $this->record($generation, CreditTransactionType::Charge, -$charge, 'Image generation');
        });
    }

    private function record(Generation $generation, CreditTransactionType $type, int $amount, string $description): void
    {
        $now = now();

        CreditTransaction::query()->insertOrIgnore([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => $type->value,
            'amount' => $amount,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
