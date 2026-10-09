<?php

use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Users\Enums\SystemRole;

it('configura situações nas mesmas seis fases do Dashboard e do Kanban', function (): void {
    $tenant = tenantWithRoles();
    $this->actingAs(userWithRole($tenant, SystemRole::Administrator), 'web');
    $configuration = $this->getJson('/api/workflow-configuration')->assertOk()
        ->assertJsonCount(6, 'data.phases')->assertJsonCount(11, 'data.status_types')->assertJsonCount(11, 'data.stages')->json('data');
    $catalog = $this->getJson('/api/process-stages')->assertOk()->json('data.stages');
    $dashboard = $this->getJson('/api/dashboard')->assertOk()->json('data.by_stage');
    expect($configuration['phases'])->toBe($catalog);
    expect(array_column($configuration['phases'], 'value'))->toBe(array_column($dashboard, 'stage'));
    $phases = collect($configuration['stages'])->pluck('phase', 'code');
    expect($phases['rascunho'])->toBe('PREPARATION')
        ->and($phases['pronto_para_envio'])->toBe('PREPARATION')
        ->and($phases['enviado'])->toBe('EXTERNAL_ANALYSIS')
        ->and($phases['pendencia_distribuidora'])->toBe('CORRECTION')
        ->and($phases['aprovado'])->toBe('EXECUTION')
        ->and($phases['vistoria_solicitada'])->toBe('INSPECTION')
        ->and($phases['conectado'])->toBe('CONNECTION')
        ->and($phases['cancelado'])->toBeNull();
    $custom = ['code' => 'conferencia_tecnica', 'name' => 'Conferência técnica', 'order' => 2, 'stage_type' => 'em_preparacao', 'next' => ['pronto_para_envio'], 'active' => true];
    $created = $this->postJson('/api/workflow-stages', $custom)->assertOk()->json('data.stages');
    $stage = collect($created)->firstWhere('code', 'conferencia_tecnica');
    expect($stage['phase'])->toBe('PREPARATION');
    $updated = $this->putJson('/api/workflow-stages/'.$stage['id'], [...$custom, 'stage_type' => 'pendencia_distribuidora'])->assertOk()->json('data.stages');
    expect(collect($updated)->firstWhere('code', 'conferencia_tecnica')['phase'])->toBe('CORRECTION');
});

it('preserva a etapa inicial e os encerramentos ao configurar as fases', function (): void {
    $tenant = tenantWithRoles();
    $this->actingAs(userWithRole($tenant, SystemRole::Administrator), 'web');
    $stages = $this->getJson('/api/workflow-configuration')->assertOk()->json('data.stages');
    $initial = collect($stages)->firstWhere('code', 'rascunho');
    $this->putJson('/api/workflow-stages/'.$initial['id'], [...$initial, 'active' => false])->assertUnprocessable();
    $this->putJson('/api/workflow-stages/'.$initial['id'], [...$initial, 'code' => 'outro_codigo'])->assertUnprocessable();
    $terminal = collect($stages)->firstWhere('code', 'conectado');
    $this->putJson('/api/workflow-stages/'.$terminal['id'], [...$terminal, 'next' => ['em_preparacao']])->assertUnprocessable();
    expect(ProcessStatus::Reprovado->workflowStage()->value)->toBe('PREPARATION');
    expect(ProcessStatus::Reprovado->isTerminal())->toBeFalse();
});
