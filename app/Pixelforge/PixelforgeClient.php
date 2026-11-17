<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper around the Pixelforge API. It does not retry: retries are
 * owned by the queued jobs, which know the request is safe to repeat because
 * it carries an Idempotency-Key.
 */
class PixelforgeClient
{
    private const PRICING_FRESH_KEY = 'pixelforge.pricing.fresh';

    private const PRICING_LAST_GOOD_KEY = 'pixelforge.pricing.last_good';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    /**
     * POST /generations. Returns the generation object (202, or a replay of an
     * earlier request with the same Idempotency-Key).
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata?: array<string, string>}  $params
     * @return array<string, mixed>
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $params));
    }

    /** @return array<string, mixed> */
    public function getGeneration(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('generations/'.rawurlencode($id)));
    }

    /**
     * GET /usage: what Pixelforge billed this API key per UTC day of completion.
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * GET /pricing, cached for 10 minutes. If Pixelforge is unreachable the last
     * good table is used, so the estimate never blocks on a pricing outage.
     */
    public function pricing(): PricingTable
    {
        $payload = Cache::get(self::PRICING_FRESH_KEY);

        if ($payload === null) {
            try {
                $payload = $this->send(fn (PendingRequest $http) => $http->get('pricing'), authenticated: false);
                Cache::put(self::PRICING_FRESH_KEY, $payload, now()->addMinutes(10));
                Cache::forever(self::PRICING_LAST_GOOD_KEY, $payload);
            } catch (PixelforgeException $e) {
                $payload = Cache::get(self::PRICING_LAST_GOOD_KEY) ?? throw $e;
            }
        }

        return PricingTable::fromArray($payload);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function send(callable $request, bool $authenticated = true): array
    {
        $http = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(5);

        if ($authenticated) {
            $http = $http->withToken((string) $this->apiKey);
        }

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw new PixelforgeException('connection_error', $e->getMessage());
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }
}
