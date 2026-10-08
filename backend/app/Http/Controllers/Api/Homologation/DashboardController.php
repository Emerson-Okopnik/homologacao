<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Documents\Models\Document;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Enums\WorkflowStage;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\ProcessDeadline;
use App\Domain\Homologations\Models\ProcessPendency;
use App\Domain\Projects\Models\SolarProject;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('dashboard.view');

        $byStage = HomologationProcess::query()
            ->where('status', ProcessStatus::Active->value)
            ->select('stage', DB::raw('count(*) as total'))
            ->groupBy('stage')
            ->pluck('total', 'stage');

        $avgDays = HomologationProcess::query()
            ->whereNotNull('submitted_at')->whereNotNull('approved_at')
            ->get(['submitted_at', 'approved_at'])
            ->avg(fn ($p) => $p->submitted_at->diffInDays($p->approved_at));

        $overdue = ProcessDeadline::query()
            ->where('status', 'OPEN')
            ->whereDate('due_at', '<', today())
            ->whereHas('process', fn ($q) => $q->where('status', ProcessStatus::Active->value))
            ->count();

        $completedPower = SolarProject::query()
            ->whereHas('process', fn ($q) => $q->where('status', ProcessStatus::Completed->value))
            ->sum('considered_power_kw');

        $recent = HomologationProcess::query()
            ->with(['project.client'])
            ->latest('stage_changed_at')
            ->limit(6)
            ->get()
            ->map(fn (HomologationProcess $p) => [
                'id' => $p->uuid,
                'code' => $p->code,
                'client' => $p->project?->client?->name,
                'stage' => $p->stage->value,
                'stage_label' => $p->stage->label(),
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'stage_changed_at' => $p->stage_changed_at?->toIso8601String(),
            ]);

        $waiting = [WorkflowStage::ExternalAnalysis->value, WorkflowStage::Inspection->value];

        return response()->json([
            'data' => [
                'totals' => [
                    'active' => (int) $byStage->sum(),
                    'waiting_distributor' => (int) $byStage->only($waiting)->sum(),
                    'in_correction' => (int) ($byStage[WorkflowStage::Correction->value] ?? 0),
                    'completed' => HomologationProcess::query()->where('status', ProcessStatus::Completed->value)->count(),
                    'overdue' => $overdue,
                    'open_pendencies' => ProcessPendency::query()->where('status', 'aberta')->count(),
                    'documents_to_review' => Document::query()->where('review_status', 'pendente')
                        ->whereHas('links', fn ($q) => $q->where('is_current', true))->count(),
                    'completed_power_kw' => round((float) $completedPower, 2),
                    'avg_approval_days' => $avgDays !== null ? round((float) $avgDays, 1) : null,
                ],
                'by_stage' => array_map(fn (WorkflowStage $s) => [
                    'stage' => $s->value,
                    'label' => $s->label(),
                    'total' => (int) ($byStage[$s->value] ?? 0),
                ], WorkflowStage::cases()),
                'recent' => $recent,
            ],
        ]);
    }
}
