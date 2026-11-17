<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /** Pixelforge's /v1/usage accepts at most 92 days. */
    private const MAX_DAYS = 92;

    public function __invoke(Request $request, ProfitReport $report): Response
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = CarbonImmutable::parse($validated['end'] ?? 'today', 'UTC')->startOfDay();
        $start = CarbonImmutable::parse($validated['start'] ?? $end->subDays(13)->toDateString(), 'UTC')->startOfDay();

        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return Inertia::render('Admin/Report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'report' => $report->build($start, $end),
        ]);
    }
}
