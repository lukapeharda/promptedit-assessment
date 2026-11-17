<?php

namespace Tests\Feature\Webhooks;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private const SECRET = 'whsec_test';

    private Generation $generation;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.webhook_secret' => self::SECRET]);
        Queue::fake();

        $this->user = User::factory()->create();
        app(Ledger::class)->purchase($this->user, 1_000_000_000);
        $this->generation = Generation::factory()->for($this->user)->accepted()->create(['hold_amount' => 300_000_000]);
        app(Ledger::class)->hold($this->generation);
    }

    private function deliver(string $eventId, string $status, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => "generation.{$status}",
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => $this->generationObject($this->generation, $status)],
        ]);

        $t = $timestamp ?? time();
        $signature = hash_hmac('sha256', "{$t}.{$body}", $secret ?? self::SECRET);

        return $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1={$signature}",
            'HTTP_PIXELFORGE_EVENT_ID' => $eventId,
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
        ], content: $body);
    }

    public function test_a_signed_event_is_applied(): void
    {
        $this->deliver('evt_1', 'succeeded')->assertOk()->assertJson(['status' => 'processed']);

        $this->assertSame(GenerationStatus::Succeeded, $this->generation->fresh()->status);
        $this->assertSame(1_000_000_000 - 70_700_000, app(Ledger::class)->balance($this->user));
        $this->assertNotNull(WebhookEvent::firstWhere('event_id', 'evt_1')->processed_at);
    }

    public function test_the_same_event_delivered_twice_is_recorded_once(): void
    {
        $this->deliver('evt_1', 'succeeded')->assertOk();
        $this->deliver('evt_1', 'succeeded')->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1_000_000_000 - 70_700_000, app(Ledger::class)->balance($this->user));
    }

    public function test_out_of_order_events_end_in_the_right_state(): void
    {
        $this->deliver('evt_3', 'succeeded')->assertOk();
        $this->deliver('evt_2', 'processing')->assertOk();

        $this->assertSame(GenerationStatus::Succeeded, $this->generation->fresh()->status);
        $this->assertSame(1_000_000_000 - 70_700_000, app(Ledger::class)->balance($this->user));
    }

    public function test_a_failed_event_releases_the_hold(): void
    {
        $this->deliver('evt_1', 'failed')->assertOk();

        $this->assertSame(GenerationStatus::Failed, $this->generation->fresh()->status);
        $this->assertSame(1_000_000_000, app(Ledger::class)->balance($this->user));
    }

    public function test_a_wrong_signature_is_rejected(): void
    {
        $this->deliver('evt_1', 'succeeded', secret: 'whsec_wrong')->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(GenerationStatus::Queued, $this->generation->fresh()->status);
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $this->deliver('evt_1', 'succeeded', timestamp: time() - 301)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $this->postJson('/webhooks/pixelforge', ['id' => 'evt_1', 'type' => 'ping'])->assertStatus(400);
    }

    public function test_ping_is_acknowledged(): void
    {
        $body = json_encode(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []]);
        $t = time();

        $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", self::SECRET),
        ], content: $body)->assertOk();
    }
}
