<?php

namespace Tests\Support;

use App\Models\Generation;

trait PixelforgeFixtures
{
    protected function pricingPayload(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/pixelforge/pricing.json')), true);
    }

    /** A Pixelforge generation object for the given local generation. */
    protected function generationObject(Generation $generation, string $status, array $overrides = []): array
    {
        $object = [
            'id' => $generation->pixelforge_id ?? 'gen_TEST'.$generation->id,
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images ?? [],
            'metadata' => ['generation_id' => (string) $generation->id],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => in_array($status, ['processing', 'succeeded', 'failed']) ? '2026-11-04T14:03:13Z' : null,
            'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-04T14:03:29Z' : null,
        ];

        if ($status === 'succeeded') {
            $object['image_url'] = 'https://pixelforge.test/images/'.$object['id'].'.png?signature=x';
            $object['image_url_expires_at'] = '2026-11-05T14:03:29Z';
            // 12*5 + 0 + 1100*40 + 1000*12.5 = 56,560 per 1M = $0.05656 cost, $0.0707 charge.
            $object['usage'] = ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2112];
        }

        if ($status === 'failed') {
            $object['error'] = ['code' => 'internal_error', 'message' => 'Something went wrong. You were not charged; please retry.'];
        }

        return array_replace($object, $overrides);
    }
}
