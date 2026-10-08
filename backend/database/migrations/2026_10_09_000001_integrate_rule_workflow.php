<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('consumer_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('distributor_id')->constrained()->restrictOnDelete();
            $table->string('protocol_number', 80);
            $table->date('opened_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'distributor_id', 'protocol_number']);
        });

        Schema::create('technical_responsibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('solar_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technical_responsible_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 20); // PROJECT | EXECUTION
            $table->string('art_number', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['solar_project_id', 'purpose']);
        });

        Schema::create('fast_track_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('solar_project_id')->constrained()->cascadeOnDelete();
            $table->string('party', 30); // REQUESTER | TECHNICAL_RESPONSIBLE
            $table->string('signer_name');
            $table->string('signer_document', 30)->nullable();
            $table->string('statement_version', 40);
            $table->text('statement_text');
            $table->unsignedBigInteger('evidence_document_id')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['solar_project_id', 'party']);
        });

        Schema::create('requirement_waivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('solar_project_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_code', 60);
            $table->text('reason');
            $table->unsignedBigInteger('evidence_document_id')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['solar_project_id', 'requirement_code']);
        });

        Schema::create('project_executions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->date('started_at')->nullable();
            $table->date('completed_at');
            $table->text('notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('homologation_process_id');
        });

        Schema::create('process_inspections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('submission_id')->nullable()->constrained('submissions')->restrictOnDelete();
            $table->string('status', 20)->default('REQUESTED');
            $table->timestamp('requested_at');
            $table->date('scheduled_for')->nullable();
            $table->timestamp('result_at')->nullable();
            $table->text('result_notes')->nullable();
            $table->foreignId('pendency_id')->nullable()->constrained('process_pendencies')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['homologation_process_id', 'sequence']);
        });

        Schema::create('connection_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // METER_INSTALLED | SYSTEM_CONNECTED | OTHER
            $table->timestamp('occurred_at');
            $table->string('meter_number', 60)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['homologation_process_id', 'type']);
        });

        Schema::create('process_deadlines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->string('deadline_type', 30); // ACCESS_OPINION | INSPECTION
            $table->string('status', 20)->default('OPEN'); // OPEN | MET | BREACHED | SUPERSEDED
            $table->timestamp('starts_at');
            $table->date('due_at');
            $table->unsignedSmallInteger('days');
            $table->string('day_count', 20); // CALENDAR | BUSINESS
            $table->unsignedBigInteger('rule_decision_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['homologation_process_id', 'deadline_type', 'status']);
        });

        Schema::create('timeline_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->jsonb('payload')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['homologation_process_id', 'occurred_at']);
        });

        Schema::create('document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained('process_documents')->cascadeOnDelete();
            $table->string('linkable_type', 30); // alias do morph map: project | equipment | execution | inspection | connection_event | process
            $table->unsignedBigInteger('linkable_id');
            $table->string('document_type', 60);
            $table->boolean('is_current')->default(true);
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'linkable_type', 'linkable_id']);
            $table->index(['linkable_type', 'linkable_id', 'document_type']);
        });

        Schema::table('solar_projects', function (Blueprint $table): void {
            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 20)->default('SOLAR');
            $table->decimal('considered_power_kw', 10, 3)->default(0); // menor valor entre os dois
            $table->decimal('storage_energy_kwh', 10, 2)->nullable();
            $table->boolean('has_dispatch_controller')->default(false);
            $table->boolean('declared_dispatchable')->default(false); // apenas declaração; o domínio valida
            $table->boolean('has_coupling_transformer')->default(false);
            $table->string('compensation_mode', 30)->default('LOCAL_SELF_CONSUMPTION');
            $table->string('compensation_method', 20)->nullable(); // PERCENTAGE | PRIORITY
            $table->string('classification', 30)->nullable();
            $table->unsignedBigInteger('classification_decision_id')->nullable();
            $table->boolean('fast_track_eligible')->default(false);
            $table->unsignedBigInteger('fast_track_decision_id')->nullable();
        });
        Schema::table('homologation_processes', function (Blueprint $table): void {
            $table->foreignId('project_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 30)->default('PREPARATION');
            $table->string('network_work_status', 30)->default('UNDER_ANALYSIS');
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::table('equipment_catalog', function (Blueprint $table): void {
            $table->decimal('nominal_ac_power_kw', 10, 3)->nullable();
            $table->boolean('has_inmetro_registration')->default(false);
            $table->string('inmetro_registration_number', 80)->nullable();
        });
        Schema::table('process_pendencies', fn (Blueprint $table) => $table->string('origin', 20)->default('interna')->change());
        Schema::table('process_documents', fn (Blueprint $table) => $table->foreignId('supersedes_document_id')->nullable()->constrained('process_documents')->restrictOnDelete());

        $modes = ['autoconsumo_local' => 'LOCAL_SELF_CONSUMPTION', 'autoconsumo_remoto' => 'REMOTE_SELF_CONSUMPTION', 'geracao_compartilhada' => 'SHARED_GENERATION', 'multiplas_uc' => 'MULTIPLE_UNITS'];
        foreach (DB::table('solar_projects')->get() as $project) {
            DB::table('solar_projects')->where('id', $project->id)->update(['compensation_mode' => $modes[$project->modality], 'considered_power_kw' => min($project->installed_power_kwp, $project->inverter_power_kw)]);
            if ($project->technical_responsible_id) {
                $term = DB::table('responsibility_terms')->where('solar_project_id', $project->id)->where('technical_responsible_id', $project->technical_responsible_id)->latest('id')->first();
                DB::table('technical_responsibilities')->insert(['tenant_id' => $project->tenant_id, 'solar_project_id' => $project->id, 'technical_responsible_id' => $project->technical_responsible_id, 'purpose' => 'PROJECT', 'art_number' => $term?->number, 'created_at' => $project->created_at, 'updated_at' => $project->updated_at]);
            }
        }
        foreach (DB::table('homologation_processes')->get() as $process) {
            $stage = match ($process->status) {
                'enviado', 'em_analise' => 'EXTERNAL_ANALYSIS', 'pendencia_distribuidora' => 'CORRECTION', 'aprovado' => 'EXECUTION', 'vistoria_solicitada' => 'INSPECTION', 'conectado' => 'CONNECTION', default => 'PREPARATION',
            };
            DB::table('homologation_processes')->where('id', $process->id)->update(['stage' => $stage, 'stage_changed_at' => $process->status_changed_at]);
        }
        foreach (DB::table('process_documents')->get() as $document) {
            $ownerType = $document->homologation_process_id ? 'process' : 'project';
            $ownerId = $document->homologation_process_id ?? $document->solar_project_id;
            if ($ownerId) {
                DB::table('document_links')->insert(['tenant_id' => $document->tenant_id, 'document_id' => $document->id, 'linkable_type' => $ownerType, 'linkable_id' => $ownerId, 'document_type' => $document->document_type, 'is_current' => $document->is_current, 'linked_by' => $document->uploaded_by, 'created_at' => $document->created_at, 'updated_at' => $document->updated_at]);
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX document_links_current_unique ON document_links (tenant_id, linkable_type, linkable_id, document_type) WHERE is_current');
        }
    }

    public function down(): void
    {
        Schema::table('process_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('supersedes_document_id'));
        Schema::table('homologation_processes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('project_version_id');
            $table->dropColumn(['stage', 'network_work_status', 'stage_changed_at', 'cancelled_at']);
        });
        Schema::table('solar_projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_request_id');
            $table->dropColumn(['source_type', 'considered_power_kw', 'storage_energy_kwh', 'has_dispatch_controller', 'declared_dispatchable', 'has_coupling_transformer', 'compensation_mode', 'compensation_method', 'classification', 'classification_decision_id', 'fast_track_eligible', 'fast_track_decision_id']);
        });
        Schema::table('equipment_catalog', fn (Blueprint $table) => $table->dropColumn(['nominal_ac_power_kw', 'has_inmetro_registration', 'inmetro_registration_number']));
        foreach (['document_links', 'timeline_events', 'process_deadlines', 'connection_events', 'process_inspections', 'project_executions', 'requirement_waivers', 'fast_track_acceptances', 'technical_responsibilities', 'service_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
