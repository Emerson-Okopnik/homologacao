<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Usuário do portal: pertence a um cliente e só enxerga os dados dele.
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        // Solicitação aberta pelo próprio cliente (dono do sistema). A equipe faz a
        // triagem, atribui um RT e converte em projeto.
        Schema::create('client_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('consumer_unit_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('status', 20)->default('SUBMITTED');
            // Dados declarados pelo cliente (equipamentos, compensação, instalação).
            $table->jsonb('system');
            // Conversa entre equipe e cliente: [{at, from, author, text}]
            $table->jsonb('messages')->default('[]');
            $table->foreignId('technical_responsible_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('solar_project_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_requests');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
