<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Billing\Ledger;
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

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest('id')->paginate(12);

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($generations),
        ]);
    }
}
