<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\User;
use App\Services\GamificationService;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InsightsController extends Controller
{
    public function __construct(private StatsService $stats, private GamificationService $gamification) {}

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $user->today();

        $data = $this->stats->overview($user, $date, $this->gamification);
        $data['tasks'] = TaskResource::collection($data['tasks'])->resolve($request);

        return response()->json(['data' => $data]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $user = $request->user();
        $month = Carbon::parse($user->today());
        [$from, $to] = $this->range($request, $month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString(), 62);

        $data = $this->stats->calendar($user, $from, $to);
        $data['tasks'] = TaskResource::collection($data['tasks'])->resolve($request);

        return response()->json(['data' => $data]);
    }

    /** Free accounts see the last 7 days; longer ranges are Premium. */
    public function analytics(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->range($request, ...$this->defaultRange($user, 7), max: $this->maxDays($user));

        return response()->json(['data' => $this->stats->analytics($user, $from, $to)]);
    }

    public function gamification(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->gamification->summary($request->user())]);
    }

    public function advancedAnalytics(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->range($request, ...$this->defaultRange($user, 30), max: 366);

        return response()->json(['data' => $this->stats->advanced($user, $from, $to)]);
    }

    public function report(Request $request): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        [$from, $to] = $this->range($request, ...$this->defaultRange($user, 30), max: 366);
        $summary = $this->stats->analytics($user, $from, $to);

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($summary) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['date', 'tasks_completed', 'focus_minutes'], escape: '');
                foreach ($summary['days'] as $day) {
                    fputcsv($out, [$day['date'], $day['tasks_completed'], $day['focus_minutes']], escape: '');
                }
                fclose($out);
            }, "sira-report-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv']);
        }

        $advanced = $this->stats->advanced($user, $from, $to);

        return response()->json(['data' => [
            'from' => $from,
            'to' => $to,
            'summary' => $summary,
            'advanced' => $advanced,
            'highlights' => $this->stats->highlights($summary, $advanced),
        ]]);
    }

    /** @return array{0: string, 1: string} */
    private function defaultRange(User $user, int $days): array
    {
        $to = Carbon::parse($user->today());

        return [$to->copy()->subDays($days - 1)->toDateString(), $to->toDateString()];
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request, string $defaultFrom, string $defaultTo, int $max): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $to = $data['to'] ?? $defaultTo;
        $from = $data['from'] ?? ($request->filled('to') ? Carbon::parse($to)->subDays(Carbon::parse($defaultFrom)->diffInDays($defaultTo))->toDateString() : $defaultFrom);

        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }
        $days = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        if ($days > $max) {
            if ($max === config('sira.free.analytics_max_days') && ! $request->user()->isPremium()) {
                throw ApiException::premiumRequired("The free plan shows the last {$max} days. Upgrade to Premium for longer ranges.");
            }
            throw ValidationException::withMessages(['from' => "Choose a range of at most {$max} days."]);
        }

        return [$from, $to];
    }

    private function maxDays(User $user): int
    {
        return $user->isPremium() ? config('sira.premium.analytics_max_days') : config('sira.free.analytics_max_days');
    }
}
