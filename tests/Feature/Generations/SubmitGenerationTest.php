<?php

namespace Tests\Feature\Generations;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $user = User::factory()->create();
        app(Ledger::class)->purchase($user, 1_000_000_000);
        $this->generation = Generation::factory()->for($user)->create();
        app(Ledger::class)->hold($this->generation);
    }

    private function run_job(): SubmitGeneration
    {
        $job = (new SubmitGeneration($this->generation))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    public function test_an_accepted_generation_is_queued_with_its_pixelforge_id_and_rates(): void
    {
        Http::fake(['*/generations' => Http::response($this->generationObject($this->generation, 'queued'), 202)]);

        $this->run_job()->assertNotReleased();

        $generation = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_TEST'.$generation->id, $generation->pixelforge_id);
        $this->assertSame('40.00', $generation->rates['output_tokens']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && $r['metadata'] === ['generation_id' => (string) $generation->id]);
    }

    public function test_overloaded_is_retried_after_retry_after_with_the_same_idempotency_key(): void
    {
        Http::fake(['*' => Http::response(['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Busy']], 503, ['Retry-After' => '2'])]);

        $job = $this->run_job();

        $job->assertReleased();
        $this->assertSame(GenerationStatus::Pending, $this->generation->fresh()->status);
        $this->assertSame(1, $this->generation->fresh()->submit_attempts);
    }

    public function test_content_policy_rejection_fails_it_for_free(): void
    {
        Http::fake(['*' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected']], 400)]);

        $this->run_job()->assertNotReleased();

        $generation = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(0, $generation->user_charge);
        $this->assertSame(1_000_000_000, app(Ledger::class)->balance($generation->user));
    }

    public function test_a_webhook_that_already_advanced_the_generation_is_not_overwritten(): void
    {
        // The succeeded webhook beat the POST response; the job then sees a pending-free row and stops.
        $this->generation->update(['status' => GenerationStatus::Succeeded, 'pixelforge_id' => 'gen_X']);
        Http::fake();

        $this->run_job();

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::Succeeded, $this->generation->fresh()->status);
    }
}
