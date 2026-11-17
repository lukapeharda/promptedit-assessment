<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * Parsed response of GET /v1/pricing: rates plus the token tables needed to
 * estimate a generation's cost before it is submitted.
 */
final readonly class PricingTable
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $tokens = $payload['tokens'] ?? throw new InvalidArgumentException('Pricing payload has no tokens table.');

        $reasoning = [];
        foreach ($tokens['reasoning'] as $quality => $range) {
            $reasoning[$quality] = ['min' => (int) $range['min'], 'max' => (int) $range['max']];
        }

        return new self(
            rates: Rates::fromArray($payload['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            // JSON decodes 0.15 as a float; keep a short decimal string for bcmath.
            textTokenVariance: rtrim(rtrim(sprintf('%.6F', $tokens['text_token_variance']), '0'), '.'),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: array_map(fn (array $sizes) => array_map('intval', $sizes), $tokens['output']),
            reasoningTokens: $reasoning,
        );
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys(reset($this->outputTokens) ?: []);
    }
}
