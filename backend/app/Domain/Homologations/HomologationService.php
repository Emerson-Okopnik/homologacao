<?php

namespace App\Domain\Homologations;

use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\SequentialCode;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

final class HomologationService
{
    public function open(SolarProject $project, User $actor): HomologationProcess
    {
        return DB::transaction(function () use ($project, $actor) {
            $stage = app(WorkflowDefinition::class)->stageFor('rascunho');
            $process = HomologationProcess::create(['solar_project_id' => $project->id, 'distributor_id' => $project->consumerUnit->distributor_id,
                'assigned_user_id' => $actor->id, 'code' => SequentialCode::next(HomologationProcess::class, 'HOM'), 'status' => 'rascunho', 'process_type' => $project->generation_type, 'current_stage_id' => $stage->id, 'opened_at' => now()]);
            $process->history()->create(['from_status' => null, 'to_status' => 'rascunho', 'user_id' => $actor->id, 'reason' => 'Processo aberto para o projeto.']);
            $process->stageHistory()->create(['workflow_stage_id' => $stage->id, 'entered_at' => now(), 'changed_by' => $actor->id]);
            $process->assignments()->create(['user_id' => $actor->id, 'role' => 'responsavel', 'assigned_at' => now(), 'active' => true]);

            return $process->refresh();
        });
    }
}
