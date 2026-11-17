<?php

namespace Tests\Feature\Generations;

use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_image_is_copied_to_the_image_disk(): void
    {
        Http::fake(['*' => Http::response('png-bytes')]);
        $generation = $this->succeeded();

        DownloadGenerationImage::dispatchSync($generation);

        $path = $generation->fresh()->image_path;
        Storage::disk('public')->assertExists($path);
        $this->assertSame('png-bytes', Storage::disk('public')->get($path));
    }

    public function test_a_failed_upload_throws_so_the_job_retries_and_no_path_is_saved(): void
    {
        Http::fake(['*' => Http::response('png-bytes')]);
        $generation = $this->succeeded();
        Storage::shouldReceive('disk->put')->andReturnFalse();

        try {
            DownloadGenerationImage::dispatchSync($generation);
            $this->fail('Expected the job to throw.');
        } catch (\RuntimeException) {
        }

        $this->assertNull($generation->fresh()->image_path);
    }

    public function test_missing_images_are_copied_again_while_the_link_is_valid(): void
    {
        Queue::fake();
        $present = $this->succeeded(['image_path' => 'generations/1/present.png']);
        Storage::disk('public')->put('generations/1/present.png', 'x');
        $missing = $this->succeeded(['image_path' => 'generations/1/missing.png']);
        $expired = $this->succeeded(['image_path' => 'generations/1/gone.png', 'image_url_expires_at' => now()->subMinute()]);

        $this->artisan('pixelforge:copy-missing-images')->assertSuccessful();

        Queue::assertPushedTimes(DownloadGenerationImage::class, 1);
        Queue::assertPushed(DownloadGenerationImage::class, fn ($job) => $job->generation->id === $missing->id);
        $this->assertSame('generations/1/present.png', $present->fresh()->image_path);
        $this->assertNull($missing->fresh()->image_path);
        $this->assertNull($expired->fresh()->image_path);
    }

    private function succeeded(array $attributes = []): Generation
    {
        return Generation::factory()->accepted('succeeded')->create([
            'image_url' => 'https://pixelforge.test/'.uniqid().'.png',
            'image_url_expires_at' => now()->addDay(),
            ...$attributes,
        ]);
    }
}
