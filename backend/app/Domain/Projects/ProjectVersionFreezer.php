<?php

namespace App\Domain\Projects;

use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\ProjectVersion;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Rules\Models\RuleDecision;
use App\Domain\Rules\RequirementEngine;

/**
 * Congela o projeto no envio: dados técnicos, equipamentos, RTs, compensação,
 * classificação + regra, Fast Track + aceites, documentos (versão e hash) e checklist.
 */
final class ProjectVersionFreezer
{
    public function __construct(private readonly RequirementEngine $requirements) {}

    /**
     * @param  array<string, mixed>  $checklist  resultado de RequirementEngine::evaluateAndRecord
     */
    public function freeze(SolarProject $project, HomologationProcess $process, string $reason, array $checklist, ?int $userId): ProjectVersion
    {
        $project->load([
            'client', 'consumerUnit.distributor', 'serviceRequest', 'equipment',
            'responsibilities.responsible', 'compensationUnits.consumerUnit', 'fastTrackAcceptances', 'waivers',
        ]);

        $classification = $project->classification_decision_id ? RuleDecision::find($project->classification_decision_id) : null;
        $fastTrack = $project->fast_track_decision_id ? RuleDecision::find($project->fast_track_decision_id) : null;

        $snapshot = [
            'project' => [
                'code' => $project->code,
                'source_type' => $project->source_type,
                'modules_power_kwp' => $project->installed_power_kwp,
                'inverters_power_kw' => $project->inverter_power_kw,
                'considered_power_kw' => $project->considered_power_kw,
                'has_storage' => $project->has_battery,
                'storage_energy_kwh' => $project->storage_energy_kwh,
                'has_dispatch_controller' => $project->has_dispatch_controller,
                'declared_dispatchable' => $project->declared_dispatchable,
                'has_coupling_transformer' => $project->has_coupling_transformer,
                'estimated_generation_kwh_month' => $project->estimated_generation_kwh_month,
            ],
            'client' => ['uuid' => $project->client->uuid, 'name' => $project->client->name, 'document' => $project->client->document],
            'consumer_unit' => [
                'uuid' => $project->consumerUnit->uuid,
                'number' => $project->consumerUnit->number,
                'voltage_class' => $project->consumerUnit->voltage_class,
                'distributor' => $project->consumerUnit->distributor?->code,
            ],
            'initial_protocol' => $project->serviceRequest?->protocol_number,
            'distributor_protocol' => $process->protocol_number,
            'equipment' => $project->equipment->map(fn ($e) => [
                'uuid' => $e->uuid, 'type' => $e->type, 'manufacturer' => $e->manufacturer, 'model' => $e->model,
                'power_w' => $e->power_w, 'nominal_ac_power_kw' => $e->nominal_ac_power_kw,
                'has_inmetro_registration' => $e->has_inmetro_registration,
                'inmetro_registration_number' => $e->inmetro_registration_number,
                'quantity' => (int) $e->pivot->quantity,
            ])->values()->all(),
            'technical_responsibilities' => $project->responsibilities->map(fn ($r) => [
                'purpose' => $r->purpose->value,
                'name' => $r->responsible->name,
                'council' => $r->responsible->council,
                'registration' => $r->responsible->registration,
                'registration_status' => $r->responsible->registration_status,
                'art_number' => $r->art_number,
            ])->values()->all(),
            'compensation' => [
                'mode' => $project->compensation_mode->value,
                'method' => $project->compensation_method,
                'units' => $project->compensationUnits->map(fn ($u) => [
                    'consumer_unit' => $u->consumerUnit->number, 'percentage' => $u->percentage, 'priority' => $u->priority,
                ])->values()->all(),
            ],
            'classification' => [
                'value' => $project->classification?->value,
                'rule_code' => $classification?->rule_code,
                'rule_version' => $classification?->rule_version,
                'decision_id' => $classification?->id,
            ],
            'fast_track' => [
                'eligible' => $project->fast_track_eligible,
                'rule_code' => $fastTrack?->rule_code,
                'rule_version' => $fastTrack?->rule_version,
                'reasons' => $fastTrack?->result['reasons'] ?? [],
                'acceptances' => $project->fastTrackAcceptances->whereNull('revoked_at')->map(fn ($a) => [
                    'party' => $a->party->value, 'signer_name' => $a->signer_name,
                    'statement_version' => $a->statement_version, 'accepted_at' => $a->accepted_at->toIso8601String(),
                ])->values()->all(),
            ],
            'documents' => $this->requirements->currentDocuments($project, $process)->map(fn ($d) => [
                'uuid' => $d->uuid, 'type' => $d->document_type, 'version' => $d->version,
                'sha256' => $d->sha256, 'review_status' => $d->review_status, 'name' => $d->original_name,
            ])->values()->all(),
            'waivers' => $project->waivers->whereNull('revoked_at')->map(fn ($w) => [
                'requirement_code' => $w->requirement_code, 'reason' => $w->reason,
            ])->values()->all(),
            'checklist' => [
                'phase' => $checklist['phase'],
                'decision_id' => $checklist['decision_id'] ?? null,
                'items' => collect($checklist['items'])->map(fn ($i) => [
                    'code' => $i['code'], 'version' => $i['version'], 'status' => $i['status'],
                ])->all(),
            ],
        ];

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $project->versions()->create([
            'version' => ((int) $project->versions()->max('version')) + 1,
            'reason' => $reason,
            'snapshot' => $snapshot,
            'snapshot_sha256' => hash('sha256', (string) $json),
            'created_by' => $userId,
        ]);
    }
}
