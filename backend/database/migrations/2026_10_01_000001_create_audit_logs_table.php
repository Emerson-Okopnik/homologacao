<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 120);
            $table->nullableMorphs('auditable');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('justification')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'event']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Append-only garantido também no banco: nem um UPDATE/DELETE manual passa.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_logs_prevent_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs é append-only (% bloqueado)', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_logs_no_update_delete
                    BEFORE UPDATE OR DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION audit_logs_prevent_mutation();

                CREATE TRIGGER audit_logs_no_truncate
                    BEFORE TRUNCATE ON audit_logs
                    FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_prevent_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
                DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs;
                DROP FUNCTION IF EXISTS audit_logs_prevent_mutation();
            SQL);
        }

        Schema::dropIfExists('audit_logs');
    }
};
