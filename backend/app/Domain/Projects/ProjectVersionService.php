<?php

namespace App\Domain\Projects;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\ProjectVersion;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Users\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProjectVersionService
{
    /** @return Collection<int, ProcessDocument> */
    public function documents(SolarProject $project, ?HomologationProcess $process = null): Collection
    {
        return ProcessDocument::query()->where('solar_project_id', $project->id)->where('is_current', true)
            ->where(fn ($q) => $q->whereNull('homologation_process_id')->when($process, fn ($q) => $q->orWhere('homologation_process_id', $process->id)))
            ->orderBy('id')->get()->sortBy(fn ($d) => $d->homologation_process_id ? 1 : 0)->keyBy('document_type')->values();
    }

    public function freeze(SolarProject $project, User $actor, string $reason, ?HomologationProcess $process = null): ProjectVersion
    {
        return DB::transaction(function () use ($project, $actor, $reason, $process): ProjectVersion {
            $project = SolarProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $project->load(['client.contacts', 'consumerUnit.address', 'technicalResponsible', 'equipment', ...ProjectTechnicalData::RELATIONS]);
            $documents = $this->documents($project, $process);
            $snapshot = [
                'project' => ['id' => $project->uuid, ...$project->only(['code', 'name', 'installation_type', 'modality', 'generation_type', 'installed_power_kwp', 'inverter_power_kw', 'has_battery', 'estimated_generation_kwh_month', 'notes'])],
                'client' => ['id' => $project->client->uuid, ...$project->client->only(['type', 'name', 'document', 'email', 'phone']), 'contacts' => $project->client->contacts->map(fn ($c) => $c->only(['name', 'role', 'email', 'phone', 'is_legal_representative']))->all()],
                'consumer_unit' => ['id' => $project->consumerUnit->uuid, ...$project->consumerUnit->only(['number', 'supply_type', 'voltage', 'neutral_voltage', 'installed_load_kw', 'contracted_demand_kw', 'breaker_a']),
                    'address' => $project->consumerUnit->address?->only(['street', 'number', 'district', 'complement', 'city', 'state', 'zip_code', 'utm_zone', 'utm_x', 'utm_y'])],
                'technical_responsible' => $project->technicalResponsible ? ['id' => $project->technicalResponsible->uuid, ...$project->technicalResponsible->only(['name', 'cpf', 'council', 'registration', 'state', 'email', 'phone'])] : null,
                'technical_data' => app(ProjectTechnicalData::class)->data($project),
                'equipment' => $project->equipment->map(fn ($e) => ['id' => $e->uuid, ...$e->only(['type', 'manufacturer', 'model', 'power_w', 'energy_kwh', 'efficiency', 'certification']), 'quantity' => $e->pivot->getAttribute('quantity')])->values()->all(),
                'documents' => $documents->map(fn ($d) => ['id' => $d->uuid, ...$d->only(['document_type', 'version', 'original_name', 'sha256', 'review_status']), 'issued_at' => $d->issued_at?->toDateString(), 'expires_at' => $d->expires_at?->toDateString()])->values()->all(),
            ];
            $version = ProjectVersion::create(['solar_project_id' => $project->id, 'version' => ((int) $project->versions()->max('version')) + 1,
                'status' => 'frozen', 'change_reason' => $reason, 'snapshot_json' => $snapshot, 'snapshot_sha256' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'created_by' => $actor->id, 'frozen_at' => now()]);
            $version->documents()->sync($documents->pluck('id'));

            return $version;
        });
    }
}
