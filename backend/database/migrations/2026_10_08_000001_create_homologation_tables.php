<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solar_projects', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('consumer_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('technical_responsible_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('generation_type', 10); // micro | mini
            $table->string('modality', 40); // autoconsumo_local | autoconsumo_remoto | geracao_compartilhada | multiplas_uc
            $table->decimal('installed_power_kwp', 10, 3)->default(0);
            $table->decimal('inverter_power_kw', 10, 3)->default(0);
            $table->boolean('has_battery')->default(false);
            $table->decimal('estimated_generation_kwh_month', 12, 2)->nullable();
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

        Schema::create('homologation_processes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('solar_project_id')->constrained()->restrictOnDelete();
            $table->foreignId('distributor_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 30);
            $table->string('status', 40)->default('rascunho');
            $table->string('protocol_number', 80)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        // Protocolo é único por distribuidora (RN de rastreabilidade externa).
        Schema::table('homologation_processes', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'distributor_id', 'protocol_number'], 'homologation_protocol_unique');
        });

        Schema::create('process_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('process_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 60);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
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

            $table->index(['homologation_process_id', 'document_type', 'is_current']);
        });

        Schema::create('process_pendencies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 20)->default('interna'); // interna | distribuidora
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
            $table->string('type', 30); // nota | envio | resposta_distribuidora | contato
            $table->text('description');
            $table->string('channel', 30)->default('portal'); // portal | email | telefone | presencial | api
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_interactions');
        Schema::dropIfExists('process_pendencies');
        Schema::dropIfExists('process_documents');
        Schema::dropIfExists('process_status_histories');
        Schema::dropIfExists('homologation_processes');
        Schema::dropIfExists('project_equipment');
        Schema::dropIfExists('solar_projects');
    }
};
