<?php

namespace Tests\Feature\Http;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/usage*' => Http::response(['object' => 'usage', 'data' => [
                ['date' => '2026-11-04', 'generations' => 1, 'input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'cost_usd' => '0.056560000'],
            ], 'total' => []]),
        ]);

        $this->user = User::factory()->create();
        app(Ledger::class)->purchase($this->user, 1_000_000_000);
    }

    public function test_the_generate_page_offers_pixelforge_sizes_and_qualities(): void
    {
        $this->actingAs($this->user)->get('/generate')->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Create')
            ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('qualities', ['low', 'medium', 'high'])
            ->where('balance', '$1.00'));
    }

    public function test_the_estimate_returns_a_price_range_and_affordability(): void
    {
        $this->actingAs($this->user)
            ->postJson('/generate/estimate', ['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png']])
            ->assertOk()
            ->assertJsonPath('charge.max', 467_343_750)
            ->assertJsonPath('charge.max_formatted', '$0.467344')
            ->assertJsonPath('can_afford', true);
    }

    public function test_submitting_holds_credits_and_queues_the_generation(): void
    {
        $this->actingAs($this->user)
            ->post('/generations', ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => ['', '  ']])
            ->assertRedirect('/generations');

        $generation = $this->user->generations()->sole();
        $this->assertSame([], $generation->reference_images);
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_submitting_validates_like_pixelforge(): void
    {
        $this->actingAs($this->user)
            ->post('/generations', ['prompt' => '', 'size' => '999x999', 'quality' => 'ultra', 'reference_images' => ['ftp://x', 'a', 'b', 'c', 'd']])
            ->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images', 'reference_images.0']);
    }

    public function test_submitting_without_enough_credits_is_refused(): void
    {
        $poor = User::factory()->create();

        $this->actingAs($poor)
            ->post('/generations', ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'high'])
            ->assertSessionHasErrors('balance');

        Queue::assertNothingPushed();
    }

    public function test_history_shows_status_estimate_and_actual_charge(): void
    {
        Generation::factory()->for($this->user)->accepted('succeeded')->create([
            'user_charge' => 70_700_000, 'provider_cost' => 56_560_000, 'image_url' => 'https://pixelforge.test/x.png', 'image_url_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($this->user)->get('/generations')->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Index')
            ->where('generations.data.0.status', 'succeeded')
            ->where('generations.data.0.charge', '$0.0707')
            ->where('generations.data.0.estimate.max', '$0.03')
            ->where('generations.data.0.image', 'https://pixelforge.test/x.png'));
    }

    public function test_retrying_a_failed_generation_starts_a_new_one_with_the_same_settings(): void
    {
        $failed = Generation::factory()->for($this->user)->accepted('failed')->create([
            'prompt' => '[fail] A robot', 'size' => '1536x1024', 'quality' => 'low',
            'reference_images' => ['https://example.com/a.png'], 'error_code' => 'internal_error', 'user_charge' => 0,
        ]);

        $this->actingAs($this->user)->post("/generations/{$failed->id}/retry")
            ->assertRedirect('/generations')
            ->assertSessionHas('success');

        $retry = $this->user->generations()->whereKeyNot($failed->id)->sole();
        $this->assertSame(['[fail] A robot', '1536x1024', 'low', ['https://example.com/a.png']], [$retry->prompt, $retry->size, $retry->quality, $retry->reference_images]);
        $this->assertSame(GenerationStatus::Pending, $retry->status);
        $this->assertNotSame($failed->idempotency_key, $retry->idempotency_key);
        $this->assertSame(GenerationStatus::Failed, $failed->fresh()->status);
        $this->assertSame(1_000_000_000 - $retry->hold_amount, app(Ledger::class)->balance($this->user));
        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generation->id === $retry->id);
    }

    public function test_a_failed_generation_can_only_be_retried_once(): void
    {
        $failed = Generation::factory()->for($this->user)->accepted('failed')->create(['error_code' => 'internal_error']);

        $this->actingAs($this->user)->post("/generations/{$failed->id}/retry")->assertRedirect('/generations');
        $this->actingAs($this->user)->post("/generations/{$failed->id}/retry")->assertSessionHasErrors('retry');

        $this->assertSame(2, $this->user->generations()->count());
        Queue::assertPushedTimes(SubmitGeneration::class, 1);

        $this->actingAs($this->user)->get('/generations')->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.is_retry', true)
            ->where('generations.data.1.can_retry', false)
            ->where('generations.data.1.retried_status', 'pending'));
    }

    public function test_a_failed_retry_can_itself_be_retried(): void
    {
        $failed = Generation::factory()->for($this->user)->accepted('failed')->create(['error_code' => 'internal_error']);
        $failedRetry = Generation::factory()->for($this->user)->accepted('failed')->create(['retry_of_id' => $failed->id, 'error_code' => 'internal_error']);

        $this->actingAs($this->user)->post("/generations/{$failed->id}/retry")->assertSessionHasErrors('retry');
        $this->actingAs($this->user)->post("/generations/{$failedRetry->id}/retry")->assertRedirect('/generations');

        $this->assertSame($failedRetry->id, $this->user->generations()->latest('id')->first()->retry_of_id);
    }

    public function test_only_failed_generations_can_be_retried(): void
    {
        $succeeded = Generation::factory()->for($this->user)->accepted('succeeded')->create();

        $this->actingAs($this->user)->post("/generations/{$succeeded->id}/retry")->assertSessionHasErrors('retry');

        $this->assertSame(1, $this->user->generations()->count());
    }

    public function test_users_cannot_retry_someone_elses_generation(): void
    {
        $other = Generation::factory()->accepted('failed')->create(['error_code' => 'internal_error']);

        $this->actingAs($this->user)->post("/generations/{$other->id}/retry")->assertNotFound();
    }

    public function test_a_retry_is_refused_without_enough_credits(): void
    {
        $poor = User::factory()->create();
        $failed = Generation::factory()->for($poor)->accepted('failed')->create(['error_code' => 'overloaded']);

        $this->actingAs($poor)->post("/generations/{$failed->id}/retry")->assertSessionHasErrors('balance');

        $this->assertSame(1, $poor->generations()->count());
        Queue::assertNothingPushed();
    }

    public function test_failures_caused_by_the_request_cannot_be_retried_as_they_are(): void
    {
        foreach (['content_policy_violation', 'generation_failed', 'validation_failed'] as $code) {
            $failed = Generation::factory()->for($this->user)->accepted('failed')->create(['error_code' => $code]);

            $this->actingAs($this->user)->post("/generations/{$failed->id}/retry")->assertSessionHasErrors('retry');
        }

        $this->assertSame(3, $this->user->generations()->count());
        $this->actingAs($this->user)->get('/generations')->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.can_retry', false)
            ->where('generations.data.1.can_retry', false)
            ->where('generations.data.2.can_retry', false));
    }

    public function test_a_daily_limit_failure_can_be_retried_once_the_utc_day_has_reset(): void
    {
        $this->travelTo('2026-11-20 23:00:00');
        $failed = Generation::factory()->for($this->user)->accepted('failed')->create(['error_code' => 'daily_limit_exceeded', 'completed_at' => now()]);

        $this->assertFalse($failed->fresh()->canBeRetried());

        $this->travelTo('2026-11-21 00:00:01');
        $this->assertTrue($failed->fresh()->canBeRetried());
    }

    public function test_the_generate_form_can_start_from_a_previous_generation(): void
    {
        $previous = Generation::factory()->for($this->user)->accepted('failed')->create([
            'prompt' => '[blocked] Something', 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/a.png'],
        ]);

        $this->actingAs($this->user)->get("/generate?from={$previous->id}")->assertInertia(fn (Assert $page) => $page
            ->where('prefill.prompt', '[blocked] Something')
            ->where('prefill.size', '1536x1024')
            ->where('prefill.quality', 'high')
            ->where('prefill.reference_images', ['https://example.com/a.png']));
    }

    public function test_the_generate_form_cannot_start_from_someone_elses_generation(): void
    {
        $other = Generation::factory()->create(['prompt' => 'Private prompt']);

        $this->actingAs($this->user)->get("/generate?from={$other->id}")->assertInertia(fn (Assert $page) => $page->where('prefill', null));
    }

    public function test_users_only_see_their_own_generations(): void
    {
        Generation::factory()->create();

        $this->actingAs($this->user)->get('/generations')->assertInertia(fn (Assert $page) => $page->has('generations.data', 0));
    }

    public function test_buying_credits_adds_to_the_balance(): void
    {
        $this->actingAs($this->user)->post('/credits', ['amount' => 10])->assertRedirect();
        $this->actingAs($this->user)->post('/credits', ['amount' => 1_000_000])->assertSessionHasErrors('amount');

        $this->assertSame(11_000_000_000, app(Ledger::class)->balance($this->user));
    }

    public function test_the_report_is_for_admins_only(): void
    {
        $this->actingAs($this->user)->get('/admin/report')->assertForbidden();
    }

    public function test_the_report_reconciles_provider_cost_with_pixelforge_usage(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Generation::factory()->for($this->user)->accepted('succeeded')->create([
            'provider_cost' => 56_560_000, 'user_charge' => 70_700_000, 'completed_at' => '2026-11-04 23:59:59',
        ]);
        Generation::factory()->for($this->user)->accepted('failed')->create([
            'provider_cost' => 0, 'user_charge' => 0, 'completed_at' => '2026-11-04 12:00:00',
        ]);

        $this->actingAs($admin)->get('/admin/report?start=2026-11-03&end=2026-11-04')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Report')
            ->where('report.days.0.date', '2026-11-04')
            ->where('report.days.0.generations', 1)
            ->where('report.days.0.charged', '0.070700000')
            ->where('report.days.0.cost', '0.056560000')
            ->where('report.days.0.profit', '0.014140000')
            ->where('report.days.0.matches_pixelforge', true)
            ->where('report.days.0.users.0.email', $this->user->email)
            ->where('report.days.1.date', '2026-11-03')
            ->where('report.totals.matches_pixelforge', true));

        $this->assertSame(GenerationStatus::Succeeded, Generation::first()->status);
    }
}
