<?php

namespace Database\Seeders;

use App\Billing\Ledger;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the operator account. Uses no factories: Faker is a dev
     * dependency and is not installed in production (composer --no-dev).
     * Safe to run again: an existing admin is left as it is.
     */
    public function run(): void
    {
        $admin = User::firstOrNew(['email' => env('ADMIN_EMAIL', 'admin@example.com')]);

        if ($admin->exists) {
            return;
        }

        $admin->forceFill([
            'name' => 'Operator',
            'password' => env('ADMIN_PASSWORD', 'password'),
            'email_verified_at' => now(),
            'is_admin' => true,
        ])->save();

        app(Ledger::class)->purchase($admin, 10 * Money::NANOS_PER_DOLLAR, 'Starting credits');
    }
}
