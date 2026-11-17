<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Copies a finished image to our own storage, because Pixelforge's signed
 * URL stops working 24 hours after completion.
 */
class DownloadGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public Generation $generation) {}

    public function handle(): void
    {
        $generation = $this->generation->fresh();

        if ($generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        if ($generation->image_url_expires_at?->isPast()) {
            return;
        }

        $response = Http::timeout(30)->get($generation->image_url)->throw();

        $path = "generations/{$generation->user_id}/{$generation->pixelforge_id}.png";
        // The disks are configured not to throw, so check the result: a failed
        // upload must retry, not leave a path that points at nothing.
        if (! Storage::disk(config('services.pixelforge.image_disk'))->put($path, $response->body())) {
            throw new \RuntimeException("Could not store the image for generation {$generation->id}.");
        }

        $generation->update(['image_path' => $path]);
    }
}
