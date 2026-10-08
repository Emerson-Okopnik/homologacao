<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Protocolo inicial (solicitação de serviço aberta na distribuidora antes do projeto).
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

        Schema::create('solar_projects', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('consumer_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('source_type', 20)->default('SOLAR');
            $table->decimal('installed_power_kwp', 10, 3)->default(0); // potência dos módulos (CC)
            $table->decimal('inverter_power_kw', 10, 3)->default(0);   // potência dos inversores (CA)
            $table->decimal('considered_power_kw', 10, 3)->default(0); // menor valor entre os dois
            $table->boolean('has_battery')->default(false);
            $table->decimal('storage_energy_kwh', 10, 2)->nullable();
            $table->boolean('has_dispatch_controller')->default(false);
            $table->boolean('declared_dispatchable')->default(false); // apenas declaração; o domínio valida
            $table->boolean('has_coupling_transformer')->default(false);
            $table->decimal('estimated_generation_kwh_month', 12, 2)->nullable();
            $table->string('compensation_mode', 30)->default('LOCAL_SELF_CONSUMPTION');
            $table->string('compensation_method', 20)->nullable(); // PERCENTAGE | PRIORITY
            $table->string('classification', 30)->nullable();
            $table->unsignedBigInteger('classification_decision_id')->nullable();
            $table->boolean('fast_track_eligible')->default(false);
            $table->unsignedBigInteger('fast_track_decision_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'client_id']);
        });

        Schema::create('project_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('solar_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained('equipment_catalog')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['solar_project_id', 'equipment_item_id']);
        });

        // UCs que recebem créditos (sistema de compensação). A UC geradora também pode constar.
        Schema::create('compensation_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('solar_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consumer_unit_id')->constrained()->restrictOnDelete();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->unsignedSmallInteger('priority')->nullable();
            $table->timestamps();

            $table->unique(['solar_project_id', 'consumer_unit_id']);
        });

        // ART de projeto e ART de execução são registros distintos.
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
            $table->string('signer_document', 14);
            $table->string('statement_version', 40);
            $table->text('statement_text');
            $table->unsignedBigInteger('evidence_document_id')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['solar_project_id', 'party']);
        });

        // Exceções autorizadas a requisitos dispensáveis (ex.: protocolo inicial com procuração).
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

        Schema::create('project_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('solar_project_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('reason', 30); // SUBMISSION | RESUBMISSION
            $table->jsonb('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['solar_project_id', 'version']);
        });

        Schema::create('homologation_processes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('solar_project_id')->constrained()->restrictOnDelete();
            $table->foreignId('distributor_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | COMPLETED | CANCELLED
            $table->string('stage', 30)->default('PREPARATION');
            $table->string('network_work_status', 30)->default('UNDER_ANALYSIS');
            $table->string('protocol_number', 80)->nullable(); // protocolo da solicitação de acesso na distribuidora
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'stage']);
            $table->unique(['tenant_id', 'distributor_id', 'protocol_number'], 'homologation_protocol_unique');
        });

        Schema::create('process_pendencies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 20)->default('interna'); // interna | distribuidora | vistoria
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('aberta'); // aberta | resolvida
            $table->date('due_date')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['homologation_process_id', 'status']);
        });

        Schema::create('process_interactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->text('description');
            $table->string('channel', 30)->default('portal');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
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

        // Cada vistoria é um registro próprio: uma reprovação nunca é sobrescrita.
        Schema::create('inspections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
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

        // Linha do tempo unificada. Somente inserção.
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

        // Arquivos: um documento é imutável e pode ser vinculado a projeto, equipamento,
        // execução, vistoria, evento de conexão ou processo por meio de document_links.
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 60);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('review_status', 20)->default('pendente'); // pendente | aprovado | reprovado
            $table->text('review_notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_type']);
        });

        Schema::create('document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type', 30); // alias do morph map: project | equipment | execution | inspection | connection_event | process
            $table->unsignedBigInteger('linkable_id');
            $table->string('document_type', 60);
            $table->boolean('is_current')->default(true);
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'linkable_type', 'linkable_id']);
            $table->index(['linkable_type', 'linkable_id', 'document_type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Uma única versão corrente por (vínculo, tipo de documento).
            DB::statement('CREATE UNIQUE INDEX document_links_current_unique ON document_links (linkable_type, linkable_id, document_type) WHERE is_current');
            DB::statement("ALTER TABLE document_links ADD CONSTRAINT document_links_type_check CHECK (linkable_type IN ('project','equipment','execution','inspection','connection_event','process'))");
            DB::statement("ALTER TABLE technical_responsibilities ADD CONSTRAINT tr_purpose_check CHECK (purpose IN ('PROJECT','EXECUTION'))");
            DB::statement('ALTER TABLE compensation_units ADD CONSTRAINT cu_percentage_check CHECK (percentage IS NULL OR (percentage > 0 AND percentage <= 100))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_links');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('timeline_events');
        Schema::dropIfExists('process_deadlines');
        Schema::dropIfExists('connection_events');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('project_executions');
        Schema::dropIfExists('process_interactions');
        Schema::dropIfExists('process_pendencies');
        Schema::dropIfExists('homologation_processes');
        Schema::dropIfExists('project_versions');
        Schema::dropIfExists('requirement_waivers');
        Schema::dropIfExists('fast_track_acceptances');
        Schema::dropIfExists('technical_responsibilities');
        Schema::dropIfExists('compensation_units');
        Schema::dropIfExists('project_equipment');
        Schema::dropIfExists('solar_projects');
        Schema::dropIfExists('service_requests');
    }
};
