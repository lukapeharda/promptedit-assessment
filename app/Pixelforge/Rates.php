<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * Pixelforge's per-token rates, in USD per 1M tokens, kept as the exact
 * decimal strings the API returns. A generation's rates are frozen when it is
 * created, so cost is always computed from the rates on that generation.
 */
final readonly class Rates
{
    public const KEYS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /** @param array<string, string> $perMillion */
    private function __construct(public array $perMillion) {}

    /** @param array<string, string|int|float> $rates */
    public static function fromArray(array $rates): self
    {
        $normalized = [];

        foreach (self::KEYS as $key) {
            if (! isset($rates[$key]) || ! is_string($rates[$key])) {
                throw new InvalidArgumentException("Missing or non-string rate [{$key}].");
            }

            $normalized[$key] = $rates[$key];
        }

        return new self($normalized);
    }

    /**
     * Exact cost in nanodollars: sum of tokens x rate / 1,000,000.
     *
     * @param  array<string, int>  $tokens  keyed like Rates::KEYS; missing keys count as 0
     */
    public function cost(array $tokens): int
    {
        $usd = '0';

        foreach (self::KEYS as $key) {
            $count = (int) ($tokens[$key] ?? 0);
            $usd = bcadd($usd, bcdiv(bcmul((string) $count, $this->perMillion[$key], 12), '1000000', 12), 12);
        }

        return Money::fromDecimal($usd);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->perMillion;
    }
}
