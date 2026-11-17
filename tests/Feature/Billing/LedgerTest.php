<?php

namespace Tests\Feature\Billing;

use App\Billing\Ledger;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_hold_then_settle_charges_only_the_actual_price(): void
    {
        $ledger = new Ledger;
        $user = User::factory()->create();
        $ledger->purchase($user, 1_000_000_000);
        $generation = Generation::factory()->for($user)->create(['hold_amount' => 300_000_000]);

        $ledger->hold($generation);
        $this->assertSame(700_000_000, $ledger->balance($user));

        $ledger->settle($generation, 120_000_000);
        $this->assertSame(880_000_000, $ledger->balance($user));
    }

    public function test_settling_twice_records_the_charge_once(): void
    {
        $ledger = new Ledger;
        $user = User::factory()->create();
        $ledger->purchase($user, 1_000_000_000);
        $generation = Generation::factory()->for($user)->create(['hold_amount' => 300_000_000]);

        $ledger->hold($generation);
        $ledger->settle($generation, 120_000_000);
        $ledger->settle($generation, 120_000_000);
        $ledger->hold($generation);

        $this->assertSame(880_000_000, $ledger->balance($user));
        $this->assertSame(3, $generation->creditTransactions()->count());
    }

    public function test_release_makes_a_failed_generation_free(): void
    {
        $ledger = new Ledger;
        $user = User::factory()->create();
        $ledger->purchase($user, 1_000_000_000);
        $generation = Generation::factory()->for($user)->create(['hold_amount' => 300_000_000]);

        $ledger->hold($generation);
        $ledger->release($generation);
        $ledger->release($generation);

        $this->assertSame(1_000_000_000, $ledger->balance($user));
    }
}
