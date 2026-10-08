<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Models\HomologationProcess;
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

        $byStatus = HomologationProcess::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $closed = [ProcessStatus::Conectado->value, ProcessStatus::Cancelado->value, ProcessStatus::Reprovado->value];
        $waitingDistributor = [ProcessStatus::Enviado->value, ProcessStatus::EmAnalise->value, ProcessStatus::VistoriaSolicitada->value];

        $avgDays = HomologationProcess::query()
            ->whereNotNull('submitted_at')->whereNotNull('approved_at')
            ->get(['submitted_at', 'approved_at'])
            ->avg(fn ($p) => $p->submitted_at->diffInDays($p->approved_at));

        $overdue = HomologationProcess::query()
            ->whereNotIn('status', $closed)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->count();

        $connectedPower = SolarProject::query()
            ->whereHas('process', fn ($q) => $q->where('status', ProcessStatus::Conectado->value))
            ->sum('installed_power_kwp');

        $recent = HomologationProcess::query()
            ->with(['project.client'])
            ->latest('status_changed_at')
            ->limit(6)
            ->get()
            ->map(fn (HomologationProcess $p) => [
                'id' => $p->uuid,
                'code' => $p->code,
                'client' => $p->project?->client?->name,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'status_changed_at' => $p->status_changed_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'totals' => [
                    'active' => $byStatus->except($closed)->sum(),
                    'waiting_distributor' => $byStatus->only($waitingDistributor)->sum(),
                    'with_pendencies' => (int) ($byStatus[ProcessStatus::PendenciaDistribuidora->value] ?? 0),
                    'connected' => (int) ($byStatus[ProcessStatus::Conectado->value] ?? 0),
                    'overdue' => $overdue,
                    'open_pendencies' => ProcessPendency::query()->where('status', 'aberta')->count(),
                    'documents_to_review' => ProcessDocument::query()->where('is_current', true)->where('review_status', 'pendente')->count(),
                    'connected_power_kwp' => round((float) $connectedPower, 2),
                    'avg_approval_days' => $avgDays !== null ? round((float) $avgDays, 1) : null,
                ],
                'by_status' => array_map(fn (ProcessStatus $s) => [
                    'status' => $s->value,
                    'label' => $s->label(),
                    'total' => (int) ($byStatus[$s->value] ?? 0),
                ], ProcessStatus::cases()),
                'recent' => $recent,
            ],
        ]);
    }
}
