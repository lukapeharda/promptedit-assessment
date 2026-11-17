<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-copies images that are missing from the image disk, e.g. after moving
 * to a bucket, or when they were written to a server's local disk that the
 * web servers can't see. Only works while Pixelforge's URL is still valid
 * (24 hours after completion).
 */
class CopyMissingImages extends Command
{
    protected $signature = 'pixelforge:copy-missing-images';

    protected $description = 'Queue a fresh copy of every succeeded image that is missing from the image disk';

    public function handle(): int
    {
        $disk = Storage::disk(config('services.pixelforge.image_disk'));
        $queued = $lost = 0;

        Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereNotNull('image_url')
            ->lazyById()
            ->each(function (Generation $generation) use ($disk, &$queued, &$lost) {
                if ($generation->image_path !== null && $disk->exists($generation->image_path)) {
                    return;
                }

                if ($generation->image_url_expires_at?->isPast()) {
                    // History then shows "Image link expired" instead of a broken image.
                    $generation->update(['image_path' => null]);
                    $lost++;

                    return;
                }

                $generation->update(['image_path' => null]);
                DownloadGenerationImage::dispatch($generation);
                $queued++;
            });

        $this->info("Queued {$queued} image(s) to copy.".($lost ? " {$lost} can't be recovered: their Pixelforge link has expired." : ''));

        return self::SUCCESS;
    }
}
