<?php

namespace App\Pixelforge;

/**
 * Up-front price range for a generation. Input text tokens (+-15%) and
 * reasoning tokens are only known after completion, so the price is a range.
 * All amounts are nanodollars; "charge" amounts include the 25% markup.
 */
final readonly class Estimate
{
    /**
     * @param  array<string, array{min: int, expected: int, max: int}>  $tokens
     */
    public function __construct(
        public array $tokens,
        public int $minCost,
        public int $expectedCost,
        public int $maxCost,
    ) {}

    public function minCharge(): int
    {
        return Money::withMarkup($this->minCost);
    }

    public function expectedCharge(): int
    {
        return Money::withMarkup($this->expectedCost);
    }

    /** The most the user can be charged; this is what we hold from the balance. */
    public function maxCharge(): int
    {
        return Money::withMarkup($this->maxCost);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tokens' => $this->tokens,
            'charge' => [
                'min' => $this->minCharge(),
                'expected' => $this->expectedCharge(),
                'max' => $this->maxCharge(),
                'min_formatted' => Money::format($this->minCharge()),
                'expected_formatted' => Money::format($this->expectedCharge()),
                'max_formatted' => Money::format($this->maxCharge()),
            ],
        ];
    }
}
