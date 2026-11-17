<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => GenerationStatus::Pending,
            'estimated_min_charge' => 10_000_000,
            'estimated_charge' => 20_000_000,
            'estimated_max_charge' => 30_000_000,
            'hold_amount' => 30_000_000,
        ];
    }

    public function accepted(string $status = 'queued'): static
    {
        return $this->state(fn () => [
            'pixelforge_id' => 'gen_'.Str::upper(Str::random(26)),
            'status' => GenerationStatus::from($status),
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'submitted_at' => now(),
        ]);
    }
}
