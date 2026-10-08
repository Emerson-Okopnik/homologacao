<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2 — Cadastros: distribuidoras, clientes, contatos, UCs, responsáveis técnicos e catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('state', 2)->nullable();
            // assisted | api | automation. Sem canal oficial definido, o padrão é assisted (RN-20).
            $table->string('integration_mode', 20)->default('assisted');
            // Apenas a REFERÊNCIA do segredo no cofre; nunca o valor (RN-28 / RF-34).
            $table->string('secret_ref', 120)->nullable();
            $table->string('portal_url')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('type', 2); // PF | PJ
            $table->string('document', 14); // somente dígitos
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // RN-02: documento único por tenant.
            $table->unique(['tenant_id', 'document']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('client_contacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('role', 80)->nullable();
            $table->boolean('is_legal_representative')->default(false);
            $table->timestamps();
        });

        Schema::create('consumer_units', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('distributor_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->string('street');
            $table->string('address_number', 20)->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city');
            $table->string('state', 2);
            $table->string('zip', 8)->nullable();
            $table->string('voltage_class', 2)->default('BT'); // BT | MT
            $table->string('supply_type', 20)->default('monofasico');
            $table->decimal('installed_load_kw', 10, 2)->nullable();
            $table->decimal('contracted_demand_kw', 10, 2)->nullable();
            $table->unsignedInteger('breaker_a')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'client_id']);
        });

        // RN-03: distribuidora + número identifica uma UC ATIVA (índice parcial no PostgreSQL).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX consumer_units_active_unique ON consumer_units (tenant_id, distributor_id, number) WHERE active = true'
            );
        }

        Schema::create('technical_responsibles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('council', 10); // CREA | CFT | CAU
            $table->string('registration', 40);
            $table->string('cpf', 11)->nullable();
            $table->string('state', 2);
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            // Situação INFORMADA pelo usuário (não há consulta automática ao conselho).
            $table->string('registration_status', 20)->default('nao_verificado');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'council', 'state', 'registration']);
        });

        Schema::create('equipment_catalog', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // module | inverter | battery
            $table->string('manufacturer');
            $table->string('model');
            $table->decimal('power_w', 12, 2)->nullable();
            $table->decimal('energy_kwh', 10, 2)->nullable();
            $table->decimal('efficiency', 5, 2)->nullable();
            $table->string('certification')->nullable();
            $table->decimal('nominal_ac_power_kw', 10, 3)->nullable();
            $table->boolean('has_inmetro_registration')->default(false);
            $table->string('inmetro_registration_number', 60)->nullable();
            $table->jsonb('specs')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'type', 'manufacturer', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_catalog');
        Schema::dropIfExists('technical_responsibles');
        Schema::dropIfExists('consumer_units');
        Schema::dropIfExists('client_contacts');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('distributors');
    }
};
