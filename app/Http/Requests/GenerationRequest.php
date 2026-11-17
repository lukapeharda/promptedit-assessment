<?php

namespace App\Http\Requests;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own validation so users get errors before anything is
 * held or sent. Used for both the live estimate and the actual submission.
 */
class GenerationRequest extends FormRequest
{
    public const MAX_PROMPT_LENGTH = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    public function rules(): array
    {
        $pricing = app(PixelforgeClient::class)->pricing();
        $isEstimate = $this->routeIs('generations.estimate');

        return [
            'prompt' => [$isEstimate ? 'nullable' : 'required', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['nullable', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.max' => 'You can add at most '.self::MAX_REFERENCE_IMAGES.' reference images.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Ignore blank URL inputs from the form.
        $images = array_values(array_filter(
            array_map(fn ($url) => is_string($url) ? trim($url) : $url, (array) $this->input('reference_images', [])),
            fn ($url) => $url !== '' && $url !== null,
        ));

        $this->merge(['reference_images' => $images]);
    }
}
