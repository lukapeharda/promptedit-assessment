<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Pixelforge\Rates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Generation extends Model
{
    /** @use HasFactory<\Database\Factories\GenerationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'submit_attempts' => 'integer',
            'estimated_min_charge' => 'integer',
            'estimated_charge' => 'integer',
            'estimated_max_charge' => 'integer',
            'hold_amount' => 'integer',
            'provider_cost' => 'integer',
            'user_charge' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'image_url_expires_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function frozenRates(): ?Rates
    {
        return $this->rates ? Rates::fromArray($this->rates) : null;
    }

    /** Parameters sent to POST /v1/generations. */
    public function toPixelforgeRequest(): array
    {
        return array_filter([
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images ?: null,
            // Lets a webhook that beats the POST response find this row.
            'metadata' => ['generation_id' => (string) $this->id],
        ], fn ($value) => $value !== null);
    }
}
