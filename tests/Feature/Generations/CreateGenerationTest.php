<?php

namespace Tests\Feature\Generations;

use App\Actions\CreateGeneration;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class CreateGenerationTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);
        Queue::fake();
    }

    public function test_it_holds_the_maximum_price_and_queues_submission(): void
    {
        $user = User::factory()->create();
        app(Ledger::class)->purchase($user, 5_000_000_000);

        $generation = app(CreateGeneration::class)->handle($user, [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ]);

        // Docs' worked example: max cost $0.373875, x1.25 = $0.46734375.
        $this->assertSame(467_343_750, $generation->hold_amount);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(5_000_000_000 - 467_343_750, app(Ledger::class)->balance($user));
        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generation->is($generation));
    }

    public function test_it_refuses_when_the_balance_cannot_cover_the_maximum_price(): void
    {
        $user = User::factory()->create();
        app(Ledger::class)->purchase($user, 100_000_000); // $0.10

        try {
            app(CreateGeneration::class)->handle($user, ['prompt' => 'x', 'size' => '1536x1024', 'quality' => 'high']);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('balance', $e->errors());
        }

        $this->assertSame(0, $user->generations()->count());
        $this->assertSame(100_000_000, app(Ledger::class)->balance($user));
        Queue::assertNothingPushed();
    }
}
