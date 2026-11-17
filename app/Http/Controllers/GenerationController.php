<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Money;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PriceEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(PixelforgeClient $pixelforge): Response
    {
        $pricing = $pixelforge->pricing();

        return Inertia::render('Generations/Create', [
            'sizes' => $pricing->sizes(),
            'qualities' => $pricing->qualities(),
            'maxPromptLength' => GenerationRequest::MAX_PROMPT_LENGTH,
            'maxReferenceImages' => GenerationRequest::MAX_REFERENCE_IMAGES,
        ]);
    }

    /** Live price range for the form; the same calculation used when submitting. */
    public function estimate(GenerationRequest $request, PixelforgeClient $pixelforge, PriceEstimator $estimator, Ledger $ledger): JsonResponse
    {
        $estimate = $estimator->estimate(
            $pixelforge->pricing(),
            (string) $request->input('prompt', ''),
            $request->input('size'),
            $request->input('quality'),
            count($request->input('reference_images', [])),
        );

        $balance = $ledger->balance($request->user());

        return response()->json([
            ...$estimate->toArray(),
            'balance' => Money::format($balance),
            'can_afford' => $balance >= $estimate->maxCharge(),
        ]);
    }

    public function store(GenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        $createGeneration->handle($request->user(), $request->validated());

        return to_route('generations.index')->with('success', 'Your image is queued. You will only be charged if it succeeds.');
    }

    /**
     * "Try again" on a failed generation. The failed one is final (free, hold
     * released), so this starts a new generation with the same settings and
     * a new idempotency key, priced and balance-checked like any other.
     */
    public function retry(Request $request, Generation $generation, CreateGeneration $createGeneration): RedirectResponse
    {
        // 404 rather than 403, so other users' ids are not revealed.
        abort_unless($generation->user_id === $request->user()->id, 404);

        if ($generation->status !== GenerationStatus::Failed) {
            return back()->withErrors(['retry' => 'Only failed images can be retried.']);
        }

        $createGeneration->handle($request->user(), [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images ?? [],
        ], retryOf: $generation);

        return to_route('generations.index')->with('success', 'Trying again with the same settings. You will only be charged if it succeeds.');
    }

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->with('retry:id,retry_of_id,status')->latest('id')->paginate(12);

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($generations),
        ]);
    }
}
