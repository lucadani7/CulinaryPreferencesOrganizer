<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Services\ExportService;
use App\Services\StatisticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatisticsController extends Controller
{
    public function __construct(private readonly StatisticsService $stats) {}

    /** GET /api/stats — list of available reports */
    public function index(): JsonResponse
    {
        return response()->json(collect(StatisticsService::REPORTS)
            ->map(fn ($title, $key) => ['key' => $key, 'title' => $title])->values());
    }

    /** GET /api/stats/{report}?scope=me|group|global&group_id= */
    public function show(Request $request, string $report): JsonResponse
    {
        $this->assertReportExists($report);
        $svc = $this->scoped($request);

        return response()->json($svc->report($report) + ['scope' => $svc->scopeLabel()]);
    }

    /** GET /api/stats/{report}/export?format=csv|pdf&scope=... */
    public function export(Request $request, string $report, ExportService $export): StreamedResponse|Response
    {
        $this->assertReportExists($report);
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'pdf'])]])['format'];
        $svc = $this->scoped($request);
        $data = $svc->report($report);

        return $format === 'csv'
            ? $export->csv("statistics_{$report}", $data['columns'], $data['rows'])
            : $export->pdf("statistics_{$report}", $data, $svc->scopeLabel());
    }

    /** GET /api/stats/export/all — all reports in a single PDF */
    public function exportAll(Request $request): Response
    {
        $svc = $this->scoped($request);
        $reports = collect(array_keys(StatisticsService::REPORTS))->map(fn ($k) => $svc->report($k))->all();

        return Pdf::loadView('pdf.all-reports', [
            'reports' => $reports,
            'scopeLabel' => $svc->scopeLabel(),
            'generatedAt' => now(),
        ])->download('statistics_full_' . now()->format('Ymd_His') . '.pdf');
    }

    private function assertReportExists(string $report): void
    {
        abort_unless(array_key_exists($report, StatisticsService::REPORTS), 404, "Unknown report: {$report}");
    }

    private function scoped(Request $request): StatisticsService
    {
        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['me', 'group', 'global'])],
            'group_id' => ['required_if:scope,group', 'nullable', 'integer', 'exists:groups,id'],
        ]);
        $user = $request->user();
        $scope = $data['scope'] ?? 'me';

        // say "no" explicitly instead of quietly showing the user's own numbers under a "global" title
        abort_if($scope === 'global' && ! $user->isAdmin(), 403, 'Global statistics are available to administrators only.');

        $group = null;
        if ($scope === 'group') {
            $group = Group::findOrFail($data['group_id']);
            abort_unless($user->isAdmin() || $group->hasMember($user), 403, 'You are not a member of this group.');
        }
        return $this->stats->forScope($user, $scope, $group);
    }
}