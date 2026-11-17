<?php

namespace App\Http\Controllers;

use App\Billing\Ledger;
use App\Pixelforge\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /** Fake purchase packs, in whole dollars. */
    public const PACKS = [5, 10, 25];

    public function index(Request $request): Response
    {
        $transactions = $request->user()->creditTransactions()
            ->with('generation:id,prompt')
            ->latest('id')
            ->paginate(20)
            ->through(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'amount' => Money::format($t->amount),
                'is_credit' => $t->amount > 0,
                'description' => $t->description,
                'prompt' => $t->generation?->prompt,
                'created_at' => $t->created_at->toIso8601String(),
            ]);

        return Inertia::render('Credits/Index', [
            'packs' => self::PACKS,
            'transactions' => $transactions,
        ]);
    }

    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        $validated = $request->validate(['amount' => ['required', 'integer', Rule::in(self::PACKS)]]);

        $ledger->purchase($request->user(), $validated['amount'] * Money::NANOS_PER_DOLLAR, "Bought \${$validated['amount']} of credits");

        return back()->with('success', "Added \${$validated['amount']}.00 to your balance.");
    }
}
