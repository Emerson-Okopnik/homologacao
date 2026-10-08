<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\HomologationService;
use App\Domain\Homologations\ValidationService;
use App\Domain\Projects\Models\ProjectVersion;
use App\Domain\Projects\Models\ResponsibilityTerm;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Projects\ProjectTechnicalData;
use App\Domain\Projects\ProjectVersionService;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Http\Controllers\Controller;
use App\Http\Resources\Homologation\DocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ProjectTechnicalController extends Controller
{
    public function show(Request $request, SolarProject $project): JsonResponse
    {
        $this->authorize('projects.view');

        return response()->json(['data' => ['technical_data' => app(ProjectTechnicalData::class)->data($project),
            'documents' => DocumentResource::collection($project->documents()->with(['uploader', 'reviewer'])->orderByDesc('id')->get())->toArray($request),
            'editable' => ! $project->processes()->whereNotIn('status', ['rascunho', 'em_preparacao', 'pendencia_distribuidora', 'reprovado', 'cancelado', 'conectado'])->exists(),
            'versions' => $project->versions->map(fn ($v) => $this->versionData($v)),
            'processes' => $project->processes()->with('currentStage')->get()->map(fn ($p) => ['id' => $p->uuid, 'code' => $p->code, 'status' => $p->status->value, 'stage' => $p->currentStage?->name]),
            'issues' => app(ProjectTechnicalData::class)->issues($project)]]);
    }

    public function update(Request $request, SolarProject $project): JsonResponse
    {
        $this->authorize('projects.manage');
        DB::transaction(function () use ($request, $project) {
            $locked = SolarProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            app(ProjectTechnicalData::class)->sync($locked, $request->all());
        });

        return $this->show($request, $project->refresh());
    }

    public function term(Request $request, SolarProject $project): JsonResponse
    {
        $this->authorize('projects.manage');
        $data = $request->validate(['type' => ['required', Rule::in(['ART', 'TRT'])], 'number' => ['required', 'string', 'max:80'],
            'issued_at' => ['required', 'date', 'before_or_equal:today'], 'valid_until' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'technical_responsible_id' => ['required', 'uuid'], 'file_id' => ['required', 'uuid']]);
        DB::transaction(function () use ($project, $data) {
            $project = SolarProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $project->assertEditable();
            $rt = TechnicalResponsible::query()->where('uuid', $data['technical_responsible_id'])->firstOrFail();
            if ($rt->id !== $project->technical_responsible_id || ($data['type'] === 'ART' ? 'CREA' : 'CFT') !== $rt->council) {
                throw new DomainException('O termo deve corresponder ao responsável técnico e ao conselho do projeto.', 'term_responsible_mismatch');
            }
            $file = ProcessDocument::query()->where('uuid', $data['file_id'])->where('solar_project_id', $project->id)->where('document_type', 'art_trt')->firstOrFail();
            $existing = ResponsibilityTerm::where('type', $data['type'])->where('number', $data['number'])->first();
            if ($existing && $existing->solar_project_id !== $project->id) {
                throw new DomainException('Este termo já está vinculado a outro projeto.', 'term_duplicated', 409);
            }
            ResponsibilityTerm::updateOrCreate(['solar_project_id' => $project->id, 'type' => $data['type'], 'number' => $data['number']], [...$data, 'technical_responsible_id' => $rt->id, 'file_id' => $file->id]);
            $project->touch();
        });

        return $this->show($request, $project->refresh());
    }

    public function freeze(Request $request, SolarProject $project): JsonResponse
    {
        $this->authorize('projects.manage');
        $data = $request->validate(['change_reason' => ['required', 'string', 'min:3', 'max:2000'], 'process_id' => ['required', 'uuid']]);
        $version = DB::transaction(function () use ($project, $data, $request) {
            $project = SolarProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $process = $project->processes()->where('uuid', $data['process_id'])->firstOrFail();
            app(ValidationService::class)->ensureReady($process);

            return app(ProjectVersionService::class)->freeze($project, $request->user(), $data['change_reason'], $process);
        });

        return response()->json(['data' => $this->versionData($version)], 201);
    }

    public function version(Request $request, SolarProject $project, ProjectVersion $version): JsonResponse
    {
        $this->authorize('projects.view');
        abort_unless($version->solar_project_id === $project->id, 404);
        $data = $this->versionData($version) + ['snapshot' => $version->snapshot_json];
        if ($request->filled('compare_to')) {
            $data['changes'] = $version->compareTo($project->versions()->where('uuid', $request->string('compare_to')->toString())->firstOrFail());
        }

        return response()->json(['data' => $data]);
    }

    public function open(Request $request, SolarProject $project): JsonResponse
    {
        $this->authorize('homologations.manage');
        $process = app(HomologationService::class)->open($project, $request->user());

        return response()->json(['data' => ['id' => $process->uuid, 'code' => $process->code]], 201);
    }

    /** @return array<string, mixed> */
    public function versionData(ProjectVersion $version): array
    {
        return ['id' => $version->uuid, 'version' => $version->version, 'status' => $version->status, 'change_reason' => $version->change_reason,
            'sha256' => $version->snapshot_sha256, 'frozen_at' => $version->frozen_at->toIso8601String()];
    }
}
