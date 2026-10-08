<?php

use App\Domain\Homologations\Enums\ProcessStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $identity = static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        };
        $process = static function (Blueprint $table) use ($identity): void {
            $identity($table);
            $table->foreignId('homologation_process_id')->constrained()->restrictOnDelete();
        };
        Schema::create('workflow_stages', function (Blueprint $table) use ($identity): void {
            $identity($table);
            $table->string('code', 60);
            $table->string('name');
            $table->unsignedInteger('order');
            $table->string('stage_type', 40);
            $table->jsonb('next_stage_rule_json');
            $table->boolean('active')->default(true);
            $table->unique(['tenant_id', 'code']);
        });
        Schema::table('homologation_processes', function (Blueprint $table): void {
            $table->foreignId('current_stage_id')->nullable()->constrained('workflow_stages')->restrictOnDelete();
            $table->string('process_type', 20)->nullable();
            $table->string('priority', 20)->default('normal');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
        Schema::create('process_stage_history', function (Blueprint $table) use ($process): void {
            $process($table);
            $table->foreignId('workflow_stage_id')->constrained('workflow_stages')->restrictOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
        });
        Schema::create('process_assignments', function (Blueprint $table) use ($process): void {
            $process($table);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 60);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('requirements', function (Blueprint $table) use ($identity): void {
            $identity($table);
            $table->foreignId('distributor_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->jsonb('applies_when_json')->nullable();
            $table->string('required_document_type', 60)->nullable();
            $table->boolean('active')->default(true);
            $table->unique(['tenant_id', 'code']);
        });
        Schema::create('process_checklist', function (Blueprint $table) use ($process): void {
            $process($table);
            $table->foreignId('requirement_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pendente');
            $table->boolean('applicable')->default(true);
            $table->foreignId('document_id')->nullable()->constrained('process_documents')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('notes')->nullable();
            $table->unique(['homologation_process_id', 'requirement_id']);
        });
        Schema::create('external_processes', function (Blueprint $table) use ($process): void {
            $process($table);
            $table->unique('homologation_process_id');
            $table->string('external_protocol', 80)->nullable();
            $table->string('external_status', 120)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->jsonb('raw_metadata_json')->nullable();
        });
        Schema::create('external_credentials', function (Blueprint $table) use ($identity): void {
            $identity($table);
            $table->foreignId('distributor_id')->constrained()->restrictOnDelete();
            $table->string('credential_ref', 120);
            $table->string('auth_type', 30);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->unique(['tenant_id', 'distributor_id', 'credential_ref']);
        });
        Schema::create('submissions', function (Blueprint $table) use ($process): void {
            $process($table);
            $table->foreignId('external_process_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_version_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('idempotency_key', 120);
            $table->char('request_hash', 64);
            $table->jsonb('payload_json');
            $table->string('status', 20)->default('prepared');
            $table->timestamp('submitted_at')->nullable();
            $table->string('external_receipt', 255)->nullable();
            $table->foreignId('receipt_document_id')->nullable()->constrained('process_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['homologation_process_id', 'idempotency_key']);
        });
        Schema::create('integration_events', function (Blueprint $table) use ($process): void {
            $process($table);
            $table->foreignId('submission_id')->nullable()->constrained('submissions')->restrictOnDelete();
            $table->string('direction', 3);
            $table->string('event_type', 60);
            $table->char('request_hash', 64)->nullable();
            $table->unsignedInteger('response_code')->nullable();
            $table->jsonb('response_payload_json')->nullable();
            $table->boolean('success');
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('external_pending_items', function (Blueprint $table) use ($identity): void {
            $identity($table);
            $table->foreignId('external_process_id')->constrained()->restrictOnDelete();
            $table->string('code', 80)->nullable();
            $table->text('description');
            $table->string('status', 20)->default('aberta');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('response_document_id')->nullable()->constrained('process_documents')->restrictOnDelete();
            $table->foreignId('response_submission_id')->nullable()->constrained('submissions')->restrictOnDelete();
        });
        Schema::table('process_pendencies', fn (Blueprint $table) => $table->foreignId('external_pending_item_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('submissions', fn (Blueprint $table) => $table->foreignId('response_to_pending_item_id')->nullable()->constrained('external_pending_items')->restrictOnDelete());
        Schema::create('connection_budgets', function (Blueprint $table) use ($identity): void {
            $identity($table);
            $table->foreignId('external_process_id')->constrained()->restrictOnDelete();
            $table->date('issued_at');
            $table->date('expires_at')->nullable();
            $table->decimal('amount', 14, 2);
            $table->boolean('works_required')->default(false);
            $table->foreignId('document_id')->constrained('process_documents')->restrictOnDelete();
        });
        Schema::create('inspections', function (Blueprint $table) use ($identity): void {
            $identity($table);
            $table->foreignId('external_process_id')->constrained()->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->string('status', 20)->default('solicitada');
            $table->foreignId('report_document_id')->nullable()->constrained('process_documents')->restrictOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('submissions')->restrictOnDelete();
            $table->timestamp('connection_approved_at')->nullable();
        });

        // As etapas históricas existentes são importadas com suas datas reais.
        foreach (DB::table('tenants')->get() as $tenant) {
            foreach (ProcessStatus::cases() as $order => $status) {
                DB::table('workflow_stages')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
                    'code' => $status->value, 'name' => $status->label(), 'order' => $order, 'stage_type' => $status->value,
                    'next_stage_rule_json' => json_encode(['next' => array_map(fn ($s) => $s->value, $status->allowedTransitions())]), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('homologation_processes')->orderBy('id')->each(function ($row): void {
            $stages = DB::table('workflow_stages')->where('tenant_id', $row->tenant_id)->pluck('id', 'stage_type');
            DB::table('homologation_processes')->where('id', $row->id)->update(['current_stage_id' => $stages[$row->status], 'process_type' => DB::table('solar_projects')->where('id', $row->solar_project_id)->value('generation_type'), 'opened_at' => $row->created_at, 'completed_at' => $row->connected_at]);
            $history = DB::table('process_status_histories')->where('homologation_process_id', $row->id)->orderBy('id')->get();
            foreach ($history as $index => $entry) {
                DB::table('process_stage_history')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id,
                    'homologation_process_id' => $row->id, 'workflow_stage_id' => $stages[$entry->to_status], 'entered_at' => $entry->created_at,
                    'left_at' => $history[$index + 1]->created_at ?? null, 'changed_by' => $entry->user_id, 'notes' => $entry->reason,
                    'created_at' => $entry->created_at, 'updated_at' => $entry->created_at]);
            }
            if ($row->assigned_user_id) {
                DB::table('process_assignments')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'homologation_process_id' => $row->id, 'user_id' => $row->assigned_user_id, 'role' => 'homologador', 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($row->protocol_number) {
                DB::table('external_processes')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'homologation_process_id' => $row->id,
                    'external_protocol' => $row->protocol_number, 'raw_metadata_json' => json_encode(['source' => 'legacy', 'note' => 'Protocolo importado; a versão enviada originalmente não pode ser reconstruída.']), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION protect_submission_snapshot() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP = 'DELETE' OR NEW.project_version_id IS DISTINCT FROM OLD.project_version_id OR NEW.payload_json IS DISTINCT FROM OLD.payload_json OR NEW.request_hash IS DISTINCT FROM OLD.request_hash OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.homologation_process_id IS DISTINCT FROM OLD.homologation_process_id OR NEW.external_process_id IS DISTINCT FROM OLD.external_process_id OR NEW.response_to_pending_item_id IS DISTINCT FROM OLD.response_to_pending_item_id THEN RAISE EXCEPTION 'Snapshot de submissao e imutavel'; END IF; RETURN NEW; END \$\$;");
            DB::unprepared('CREATE TRIGGER immutable_submission_snapshot BEFORE UPDATE OR DELETE ON submissions FOR EACH ROW EXECUTE FUNCTION protect_submission_snapshot();');
            DB::unprepared("CREATE OR REPLACE FUNCTION protect_integration_event() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Evento de integracao e imutavel'; END \$\$;");
            DB::unprepared('CREATE TRIGGER immutable_integration_event BEFORE UPDATE OR DELETE ON integration_events FOR EACH ROW EXECUTE FUNCTION protect_integration_event();');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_submission_snapshot() CASCADE;');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_integration_event() CASCADE;');
        }
        Schema::table('submissions', fn (Blueprint $table) => $table->dropConstrainedForeignId('response_to_pending_item_id'));
        Schema::table('process_pendencies', fn (Blueprint $table) => $table->dropConstrainedForeignId('external_pending_item_id'));
        Schema::table('homologation_processes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_stage_id');
            $table->dropColumn(['priority', 'opened_at', 'completed_at', 'process_type']);
        });
        foreach (['inspections', 'connection_budgets', 'external_pending_items', 'integration_events', 'submissions', 'external_credentials', 'external_processes', 'process_checklist', 'requirements', 'process_assignments', 'process_stage_history', 'workflow_stages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
