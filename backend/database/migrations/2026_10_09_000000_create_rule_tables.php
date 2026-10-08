<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regras de negócio versionadas e globais (definidas pela plataforma, não por tenant).
 * Escopo por distribuidora via distributor_code (null = vale para todas).
 */
return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table): void {
            $table->id();
            $table->string('rule_code', 60);
            $table->unsignedInteger('version');
            $table->string('distributor_code', 30)->nullable();
            $table->integer('priority')->default(0);
            $table->string('description');
            $table->jsonb('conditions');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('source_reference');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['rule_code', 'version']);
            $table->index(['rule_code', 'effective_from', 'effective_to']);
        };

        Schema::create('generation_classification_rules', function (Blueprint $table) use ($common): void {
            $common($table);
            $table->string('classification', 30);
        });

        Schema::create('fast_track_rules', function (Blueprint $table) use ($common): void {
            $common($table);
        });

        Schema::create('requirement_rules', function (Blueprint $table) use ($common): void {
            $common($table);
            $table->string('phase', 30); // SUBMISSION | INSPECTION_REQUEST | COMPLETION
            $table->string('kind', 20); // DOCUMENT | FACT
            $table->string('document_type', 60)->nullable();
            $table->jsonb('satisfied_when')->nullable();
            $table->string('outcome', 20)->default('REQUIRED'); // REQUIRED | OPTIONAL
            $table->string('label');
            $table->text('reason');
            $table->boolean('waivable')->default(false);
        });

        Schema::create('deadline_rules', function (Blueprint $table) use ($common): void {
            $common($table);
            $table->string('deadline_type', 30); // ACCESS_OPINION | INSPECTION
            $table->unsignedSmallInteger('days');
            $table->string('day_count', 20); // CALENDAR | BUSINESS
        });

        // Snapshot imutável da regra e dos fatos usados em cada decisão do domínio.
        Schema::create('rule_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('decision_type', 30); // CLASSIFICATION | FAST_TRACK | REQUIREMENTS | DEADLINE
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('rule_table', 60)->nullable();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('rule_code', 60)->nullable();
            $table->unsignedInteger('rule_version')->nullable();
            $table->jsonb('rule_snapshot')->nullable();
            $table->jsonb('facts');
            $table->jsonb('result');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'decision_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_decisions');
        Schema::dropIfExists('deadline_rules');
        Schema::dropIfExists('requirement_rules');
        Schema::dropIfExists('fast_track_rules');
        Schema::dropIfExists('generation_classification_rules');
    }
};
