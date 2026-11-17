<?php

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\Money;
use App\Pixelforge\PriceEstimator;
use App\Pixelforge\PricingTable;
use App\Pixelforge\Rates;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function pricing(): PricingTable
    {
        return PricingTable::fromArray(json_decode(file_get_contents(__DIR__.'/../../Fixtures/pixelforge/pricing.json'), true));
    }

    public function test_round_trips_nine_decimal_amounts_exactly(): void
    {
        $this->assertSame(46_125_000, Money::fromDecimal('0.046125000'));
        $this->assertSame('0.046125000', Money::toDecimal(46_125_000));
        $this->assertSame(169_080_000, Money::fromDecimal('0.169080000'));
        $this->assertSame(12_000_000_000, Money::fromDecimal('12'));
    }

    public function test_refuses_amounts_finer_than_a_nanodollar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('0.0000000001');
    }

    public function test_applies_the_markup_and_never_rounds_down(): void
    {
        $this->assertSame(125, Money::withMarkup(100));
        $this->assertSame(2, Money::withMarkup(1)); // 1.25 -> 2
        $this->assertSame(0, Money::withMarkup(0));
    }

    public function test_formats_without_understating_the_price(): void
    {
        $this->assertSame('$0.046125', Money::format(46_125_000));
        $this->assertSame('$5.00', Money::format(5_000_000_000));
        $this->assertSame('$0.000001', Money::format(1));
    }

    public function test_computes_cost_from_usage_and_frozen_rates(): void
    {
        // The "succeeded" example from the docs.
        $rates = Rates::fromArray(['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']);

        $cost = $rates->cost(['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184]);

        // 12*5 + 765*10 + 6450*40 + 3184*12.5 = 305510 per 1M tokens.
        $this->assertSame('0.305510000', Money::toDecimal($cost));
    }

    public function test_matches_the_maximum_cost_worked_example_in_the_docs(): void
    {
        $estimate = (new PriceEstimator)->estimate(self::pricing(), str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(115, $estimate->tokens['input_text_tokens']['max']);
        $this->assertSame('0.373875000', Money::toDecimal($estimate->maxCost));
        $this->assertSame(467_343_750, $estimate->maxCharge());
    }

    public function test_estimates_a_range_for_unknown_token_counts(): void
    {
        $estimate = (new PriceEstimator)->estimate(self::pricing(), str_repeat('é', 40), '1024x1024', 'low');

        // 40 Unicode chars -> 10 tokens, +-15% -> 8..12.
        $this->assertSame(['min' => 8, 'expected' => 10, 'max' => 12], $estimate->tokens['input_text_tokens']);
        $this->assertSame(['min' => 0, 'expected' => 250, 'max' => 500], $estimate->tokens['reasoning_tokens']);
        $this->assertLessThan($estimate->expectedCost, $estimate->minCost);
        $this->assertLessThan($estimate->maxCost, $estimate->expectedCost);
    }
}
