<?php

namespace Tests\Feature\Generations;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class ReconcileGenerationsTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        app(Ledger::class)->purchase($this->user, 1_000_000_000);
    }

    private function generation(array $attributes = [], bool $accepted = true): Generation
    {
        $factory = Generation::factory()->for($this->user);
        $generation = ($accepted ? $factory->accepted('processing') : $factory)->create(['hold_amount' => 300_000_000] + $attributes);
        app(Ledger::class)->hold($generation);

        return $generation;
    }

    public function test_a_generation_whose_webhook_never_arrived_is_settled_by_polling(): void
    {
        $generation = $this->generation();
        Generation::whereKey($generation->id)->update(['updated_at' => now()->subMinutes(5)]);
        Http::fake(["*/generations/{$generation->pixelforge_id}" => Http::response($this->generationObject($generation, 'succeeded'))]);

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
        $this->assertSame(1_000_000_000 - 70_700_000, app(Ledger::class)->balance($this->user));
    }

    public function test_recently_updated_generations_are_left_to_their_webhooks(): void
    {
        $this->generation();
        Http::fake();

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_stalled_submission_is_resubmitted(): void
    {
        $generation = $this->generation(accepted: false);
        Generation::whereKey($generation->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generation->id === $generation->id);
    }

    public function test_a_submission_that_never_got_through_is_closed_for_free(): void
    {
        $generation = $this->generation(accepted: false);
        Generation::whereKey($generation->id)->update(['created_at' => now()->subHours(2), 'updated_at' => now()->subMinutes(5)]);

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        $this->assertSame(GenerationStatus::Failed, $generation->fresh()->status);
        $this->assertSame(1_000_000_000, app(Ledger::class)->balance($this->user));
        Queue::assertNothingPushed();
    }
}
