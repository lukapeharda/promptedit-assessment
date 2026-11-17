<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Pixelforge\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'status' => $this->status->value,
            'is_final' => $this->status->isTerminal(),
            'error_message' => $this->error_message,
            'estimate' => [
                'min' => Money::format($this->estimated_min_charge),
                'expected' => Money::format($this->estimated_charge),
                'max' => Money::format($this->estimated_max_charge),
            ],
            'charge' => $this->user_charge === null ? null : Money::format($this->user_charge),
            'tokens' => $this->output_tokens === null ? null : [
                'input_text' => $this->input_text_tokens,
                'input_image' => $this->input_image_tokens,
                'output' => $this->output_tokens,
                'reasoning' => $this->reasoning_tokens,
            ],
            'image' => $this->imageUrl(),
            'image_expired' => $this->image_path === null && $this->image_url !== null && $this->image_url_expires_at?->isPast(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    private function imageUrl(): ?string
    {
        if ($this->image_path !== null) {
            return Storage::disk(config('services.pixelforge.image_disk'))->url($this->image_path);
        }

        if ($this->image_url !== null && ! $this->image_url_expires_at?->isPast()) {
            return $this->image_url;
        }

        return null;
    }
}
