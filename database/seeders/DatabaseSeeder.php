<?php

namespace Database\Seeders;

use App\Billing\Ledger;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Operator',
            'email' => env('ADMIN_EMAIL', 'admin@example.com'),
            'password' => env('ADMIN_PASSWORD', 'password'),
            'is_admin' => true,
        ]);

        app(Ledger::class)->purchase($admin, 10 * Money::NANOS_PER_DOLLAR, 'Starting credits');
    }
}
