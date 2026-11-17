<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any request not signed by Pixelforge.
 *
 * Header: Pixelforge-Signature: t=<unix>,v1=<hex HMAC-SHA256("<t>.<raw body>", whsec)>
 * The signature is checked against the raw body before any JSON decoding,
 * compared in constant time, and stale timestamps are refused (replays).
 */
class VerifyPixelforgeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '') {
            abort(500, 'Pixelforge webhook secret is not configured.');
        }

        [$timestamp, $signatures] = $this->parse((string) $request->header('Pixelforge-Signature'));

        if ($timestamp === null || $signatures === []) {
            abort(400, 'Missing or malformed signature.');
        }

        if (abs(time() - $timestamp) > config('services.pixelforge.webhook_tolerance')) {
            abort(400, 'Signature timestamp outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        abort(400, 'Invalid signature.');
    }

    /** @return array{0: int|null, 1: list<string>} */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
