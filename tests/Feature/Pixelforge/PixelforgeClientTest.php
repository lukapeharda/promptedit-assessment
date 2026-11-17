<?php

namespace Tests\Feature\Pixelforge;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    private function client(): PixelforgeClient
    {
        return new PixelforgeClient('https://pixelforge.test/v1', 'pk_test');
    }

    public function test_create_sends_the_idempotency_key_and_bearer_token(): void
    {
        Http::fake(['pixelforge.test/v1/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

        $generation = $this->client()->createGeneration(['prompt' => 'hi', 'size' => '1024x1024', 'quality' => 'low'], 'key-123');

        $this->assertSame('gen_1', $generation['id']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', 'key-123')
            && $request->hasHeader('Authorization', 'Bearer pk_test')
            && $request['prompt'] === 'hi');
    }

    public function test_maps_api_errors_and_retry_after(): void
    {
        Http::fake(['*' => Http::response(
            ['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many.']],
            429,
            ['Retry-After' => '5'],
        )]);

        try {
            $this->client()->createGeneration(['prompt' => 'hi'], 'k');
            $this->fail('Expected an exception.');
        } catch (PixelforgeException $e) {
            $this->assertSame('concurrency_limit_exceeded', $e->errorCode);
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame(5, $e->retryAfter);
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_content_policy_errors_are_not_retryable(): void
    {
        Http::fake(['*' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'No.']], 400)]);

        $this->expectExceptionObject(new PixelforgeException('content_policy_violation', 'No.'));

        try {
            $this->client()->createGeneration(['prompt' => '[blocked]'], 'k');
        } catch (PixelforgeException $e) {
            $this->assertFalse($e->isRetryable());
            throw $e;
        }
    }

    public function test_pricing_falls_back_to_the_last_good_table(): void
    {
        $pricing = json_decode(file_get_contents(base_path('tests/Fixtures/pixelforge/pricing.json')), true);

        Http::fake(['*' => Http::sequence()->push($pricing)->push([], 503)]);

        $this->assertSame('40.00', $this->client()->pricing()->rates->perMillion['output_tokens']);

        Cache::forget('pixelforge.pricing.fresh');

        $this->assertSame('40.00', $this->client()->pricing()->rates->perMillion['output_tokens']);
        Http::assertSentCount(2);
    }
}
