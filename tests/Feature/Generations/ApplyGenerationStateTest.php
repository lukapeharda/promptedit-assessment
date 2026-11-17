<?php

namespace Tests\Feature\Generations;

use App\Actions\ApplyGenerationState;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class ApplyGenerationStateTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private Generation $generation;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        app(Ledger::class)->purchase($this->user, 1_000_000_000);
        $this->generation = Generation::factory()->for($this->user)->accepted()->create(['hold_amount' => 300_000_000]);
        app(Ledger::class)->hold($this->generation);
    }

    private function apply(string $status): ?Generation
    {
        return app(ApplyGenerationState::class)->apply($this->generationObject($this->generation, $status));
    }

    public function test_success_records_the_exact_cost_and_charges_cost_plus_markup(): void
    {
        $this->apply('succeeded');

        $generation = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(56_560_000, $generation->provider_cost);  // $0.05656
        $this->assertSame(70_700_000, $generation->user_charge);    // $0.0707
        $this->assertSame('2026-11-04 14:03:29', $generation->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame(1_000_000_000 - 70_700_000, app(Ledger::class)->balance($this->user));
        Queue::assertPushed(DownloadGenerationImage::class);
    }

    public function test_the_same_success_applied_twice_is_charged_once(): void
    {
        $this->apply('succeeded');
        $this->apply('succeeded');

        $this->assertSame(1_000_000_000 - 70_700_000, app(Ledger::class)->balance($this->user));
        $this->assertSame(1, $this->generation->creditTransactions()->where('type', 'charge')->count());
    }

    public function test_a_late_processing_event_does_not_move_a_finished_generation_back(): void
    {
        $this->apply('succeeded');
        $this->apply('processing');
        $this->apply('failed');

        $generation = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(70_700_000, $generation->user_charge);
        $this->assertSame(1_000_000_000 - 70_700_000, app(Ledger::class)->balance($this->user));
    }

    public function test_a_failed_generation_is_free(): void
    {
        $this->apply('processing');
        $this->apply('failed');

        $generation = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('internal_error', $generation->error_code);
        $this->assertSame(0, $generation->user_charge);
        $this->assertSame(1_000_000_000, app(Ledger::class)->balance($this->user));
    }

    public function test_an_event_that_arrives_before_the_post_response_is_matched_by_metadata(): void
    {
        $pending = Generation::factory()->for($this->user)->create(['hold_amount' => 300_000_000]);
        app(Ledger::class)->hold($pending);

        app(ApplyGenerationState::class)->apply($this->generationObject($pending, 'succeeded', ['id' => 'gen_EARLY']));

        $pending->refresh();
        $this->assertSame('gen_EARLY', $pending->pixelforge_id);
        $this->assertSame(GenerationStatus::Succeeded, $pending->status);
        $this->assertSame(70_700_000, $pending->user_charge);
    }

    public function test_an_unknown_generation_is_ignored(): void
    {
        $object = $this->generationObject($this->generation, 'succeeded', ['id' => 'gen_OTHER', 'metadata' => []]);

        $this->assertNull(app(ApplyGenerationState::class)->apply($object));
    }
}
