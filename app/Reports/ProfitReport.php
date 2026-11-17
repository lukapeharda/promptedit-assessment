<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Money;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily and per-user credit spend, provider cost and profit.
 *
 * Days are UTC days of *completion*, because that is how Pixelforge bills
 * (GET /v1/usage). Each day's provider cost is compared with Pixelforge's own
 * figure for the API key this app uses.
 */
class ProfitReport
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded)
            ->where('generations.completed_at', '>=', $start->startOfDay())
            ->where('generations.completed_at', '<', $end->addDay()->startOfDay())
            ->groupBy(DB::raw('DATE(generations.completed_at)'), 'users.id', 'users.name', 'users.email')
            ->orderBy(DB::raw('DATE(generations.completed_at)'))
            ->orderBy('users.name')
            ->get([
                DB::raw('DATE(generations.completed_at) as day'),
                'users.id as user_id',
                'users.name',
                'users.email',
                DB::raw('COUNT(*) as generations'),
                DB::raw('SUM(generations.user_charge) as charged'),
                DB::raw('SUM(generations.provider_cost) as cost'),
            ]);

        [$usage, $usageError] = $this->usage($start, $end);

        $days = collect(CarbonPeriod::create($start, $end))->map(function ($date) use ($rows, $usage) {
            $day = $date->toDateString();
            $users = $rows->where('day', $day)->values();
            $charged = (int) $users->sum('charged');
            $cost = (int) $users->sum('cost');
            $pixelforgeCost = $usage[$day] ?? null;

            return [
                'date' => $day,
                'generations' => (int) $users->sum('generations'),
                ...$this->money($charged, $cost),
                'pixelforge_cost' => $pixelforgeCost === null ? null : Money::toDecimal($pixelforgeCost),
                'matches_pixelforge' => $pixelforgeCost === null ? null : $pixelforgeCost === $cost,
                'users' => $users->map(fn ($row) => [
                    'user_id' => $row->user_id,
                    'name' => $row->name,
                    'email' => $row->email,
                    'generations' => (int) $row->generations,
                    ...$this->money((int) $row->charged, (int) $row->cost),
                ])->all(),
            ];
        })->reverse()->values();

        $totalCharged = (int) $rows->sum('charged');
        $totalCost = (int) $rows->sum('cost');

        return [
            'days' => $days,
            'totals' => [
                'generations' => (int) $rows->sum('generations'),
                ...$this->money($totalCharged, $totalCost),
                'pixelforge_cost' => $usage === null ? null : Money::toDecimal(array_sum($usage)),
                'matches_pixelforge' => $usage === null ? null : array_sum($usage) === $totalCost,
            ],
            'per_user' => $this->perUser($rows),
            'usage_error' => $usageError,
        ];
    }

    /** @return array{0: array<string, int>|null, 1: string|null} day => nanodollars */
    private function usage(CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            $response = $this->pixelforge->usage($start->toDateString(), $end->toDateString());
        } catch (PixelforgeException $e) {
            return [null, "Could not load Pixelforge usage: {$e->getMessage()}"];
        }

        return [collect($response['data'])->mapWithKeys(fn ($day) => [$day['date'] => Money::fromDecimal($day['cost_usd'])])->all(), null];
    }

    private function perUser(Collection $rows): array
    {
        return $rows->groupBy('user_id')->map(fn (Collection $userRows) => [
            'user_id' => $userRows->first()->user_id,
            'name' => $userRows->first()->name,
            'email' => $userRows->first()->email,
            'generations' => (int) $userRows->sum('generations'),
            ...$this->money((int) $userRows->sum('charged'), (int) $userRows->sum('cost')),
        ])->sortByDesc('charged_nanos')->values()->all();
    }

    /** @return array<string, int|string> */
    private function money(int $charged, int $cost): array
    {
        return [
            'charged_nanos' => $charged,
            'charged' => Money::toDecimal($charged),
            'cost' => Money::toDecimal($cost),
            'profit' => Money::toDecimal($charged - $cost),
        ];
    }
}
