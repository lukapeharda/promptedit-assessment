<?php

namespace Tests\Feature\Generations;

use App\Actions\CreateGeneration;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/**
 * Runs jobs through the real (sync) queue driver instead of Queue::fake(),
 * which skips payload creation and so hides problems such as a job method
 * clashing with the queue's own conventions (e.g. backoff()).
 */
class QueueDispatchTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    public function test_a_new_generation_is_submitted_through_the_queue(): void
    {
        $user = User::factory()->create();
        app(Ledger::class)->purchase($user, 5_000_000_000);
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/generations' => fn ($request) => Http::response([
                'id' => 'gen_SYNC',
                'status' => 'queued',
                'metadata' => $request['metadata'],
                'rates' => $this->pricingPayload()['rates'],
                'created_at' => '2026-11-04T14:03:11Z',
            ], 202),
        ]);

        $generation = app(CreateGeneration::class)->handle($user, ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low']);

        $this->assertSame('gen_SYNC', $generation->fresh()->pixelforge_id);
        $this->assertSame(GenerationStatus::Queued, $generation->fresh()->status);
    }
}
