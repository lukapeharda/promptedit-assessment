<?php

namespace App\Pixelforge;

use InvalidArgumentException;

final class PriceEstimator
{
    public function estimate(
        PricingTable $pricing,
        string $prompt,
        string $size,
        string $quality,
        int $referenceImageCount = 0,
    ): Estimate {
        $output = $pricing->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("Unknown quality/size [{$quality}/{$size}].");
        $reasoning = $pricing->reasoningTokens[$quality];

        // Pixelforge counts Unicode characters, not bytes.
        $baseText = (int) ceil(mb_strlen($prompt) / $pricing->charactersPerTextToken);
        $images = $referenceImageCount * $pricing->tokensPerReferenceImage;

        $tokens = [
            'input_text_tokens' => [
                'min' => $this->scaleFloor($baseText, bcsub('1', $pricing->textTokenVariance, 6)),
                'expected' => $baseText,
                'max' => $this->scaleCeil($baseText, bcadd('1', $pricing->textTokenVariance, 6)),
            ],
            'input_image_tokens' => ['min' => $images, 'expected' => $images, 'max' => $images],
            'output_tokens' => ['min' => $output, 'expected' => $output, 'max' => $output],
            'reasoning_tokens' => [
                'min' => $reasoning['min'],
                'expected' => intdiv($reasoning['min'] + $reasoning['max'], 2),
                'max' => $reasoning['max'],
            ],
        ];

        return new Estimate(
            tokens: $tokens,
            minCost: $pricing->rates->cost(array_map(fn ($t) => $t['min'], $tokens)),
            expectedCost: $pricing->rates->cost(array_map(fn ($t) => $t['expected'], $tokens)),
            maxCost: $pricing->rates->cost(array_map(fn ($t) => $t['max'], $tokens)),
        );
    }

    private function scaleCeil(int $tokens, string $factor): int
    {
        $scaled = bcmul((string) $tokens, $factor, 6);
        $whole = bcadd($scaled, '0', 0);

        return (int) (bccomp($scaled, $whole, 6) === 1 ? bcadd($whole, '1', 0) : $whole);
    }

    private function scaleFloor(int $tokens, string $factor): int
    {
        return (int) bcadd(bcmul((string) $tokens, $factor, 6), '0', 0);
    }
}
