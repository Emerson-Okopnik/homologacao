<?php

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
        $project = static function (Blueprint $table) use ($identity): void {
            $identity($table);
            $table->foreignId('solar_project_id')->constrained()->restrictOnDelete();
        };

        Schema::create('addresses', function (Blueprint $table) use ($identity): void {
            $identity($table);
            $table->string('street');
            $table->string('number', 20)->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city');
            $table->string('state', 2);
            $table->string('zip_code', 8)->nullable();
            $table->string('utm_zone', 10)->nullable();
            $table->decimal('utm_x', 14, 3)->nullable();
            $table->decimal('utm_y', 14, 3)->nullable();
        });
        Schema::table('consumer_units', function (Blueprint $table): void {
            $table->foreignId('address_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('voltage', 10, 2)->nullable();
            $table->decimal('neutral_voltage', 10, 2)->nullable();
        });
        Schema::table('technical_responsibles', function (Blueprint $table): void {
            $table->string('cpf', 11)->nullable();
            $table->unique(['tenant_id', 'cpf']);
        });
        Schema::table('solar_projects', function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('status', 30)->default('rascunho');
            $table->string('installation_type', 20)->nullable();
        });
        Schema::create('project_connection_data', function (Blueprint $table) use ($project): void {
            $project($table);
            $table->unique('solar_project_id');
            $table->string('connection_point')->nullable();
            $table->decimal('supply_voltage', 10, 2)->nullable();
            $table->string('phase_configuration', 20)->nullable();
            $table->unsignedInteger('main_breaker_a')->nullable();
            $table->decimal('installed_load_kw', 12, 3)->nullable();
            $table->decimal('contracted_demand_kw', 12, 3)->nullable();
            $table->decimal('existing_generation_kw', 12, 3)->nullable();
            $table->boolean('emergency_generator')->default(false);
        });
        Schema::create('project_arrays', function (Blueprint $table) use ($project): void {
            $project($table);
            $table->foreignId('module_model_id')->constrained('equipment_catalog')->restrictOnDelete();
            $table->unsignedInteger('module_quantity');
            $table->unsignedInteger('strings_quantity')->nullable();
            $table->unsignedInteger('modules_per_string')->nullable();
            $table->decimal('azimuth', 6, 2)->nullable();
            $table->decimal('tilt', 5, 2)->nullable();
        });
        Schema::create('project_inverters', function (Blueprint $table) use ($project): void {
            $project($table);
            $table->foreignId('inverter_model_id')->constrained('equipment_catalog')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('nominal_ac_kw', 12, 3);
            $table->decimal('connection_voltage', 10, 2)->nullable();
            $table->jsonb('protection_config_json')->nullable();
        });
        Schema::create('project_storage', function (Blueprint $table) use ($project): void {
            $project($table);
            $table->foreignId('battery_model_id')->constrained('equipment_catalog')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('energy_kwh', 12, 3)->nullable();
            $table->decimal('power_kw', 12, 3)->nullable();
            $table->boolean('dispatchable')->default(false);
            $table->text('operating_strategy')->nullable();
        });
        Schema::create('compensation_config', function (Blueprint $table) use ($project): void {
            $project($table);
            $table->unique('solar_project_id');
            $table->string('mode', 40);
            $table->string('allocation_rule', 20)->default('percentage');
        });
        Schema::create('compensation_units', function (Blueprint $table) use ($project): void {
            $project($table);
            $table->foreignId('compensation_config_id')->constrained('compensation_config')->restrictOnDelete();
            $table->foreignId('consumer_unit_id')->constrained()->restrictOnDelete();
            $table->decimal('percentage', 6, 3)->nullable();
            $table->unsignedInteger('priority')->nullable();
            $table->unique(['compensation_config_id', 'consumer_unit_id']);
        });
        Schema::table('process_documents', function (Blueprint $table): void {
            $table->foreignId('solar_project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('homologation_process_id')->nullable()->change();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
        });
        Schema::create('responsibility_terms', function (Blueprint $table) use ($project): void {
            $project($table);
            $table->foreignId('technical_responsible_id')->constrained()->restrictOnDelete();
            $table->string('type', 3);
            $table->string('number', 80);
            $table->date('issued_at');
            $table->date('valid_until')->nullable();
            $table->foreignId('file_id')->constrained('process_documents')->restrictOnDelete();
            $table->unique(['tenant_id', 'type', 'number']);
        });
        Schema::create('project_versions', function (Blueprint $table) use ($project): void {
            $project($table);
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('frozen');
            $table->text('change_reason');
            $table->jsonb('snapshot_json');
            $table->char('snapshot_sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('frozen_at');
            $table->unique(['solar_project_id', 'version']);
        });
        Schema::create('project_version_documents', function (Blueprint $table): void {
            $table->foreignId('project_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('process_document_id')->constrained()->restrictOnDelete();
            $table->primary(['project_version_id', 'process_document_id']);
        });

        // Copia somente fatos existentes. Dados técnicos desconhecidos permanecem nulos.
        DB::table('consumer_units')->orderBy('id')->each(function ($unit): void {
            $addressId = DB::table('addresses')->insertGetId([
                'uuid' => (string) Str::uuid(), 'tenant_id' => $unit->tenant_id,
                'street' => $unit->street, 'number' => $unit->address_number, 'complement' => $unit->complement,
                'district' => $unit->district, 'city' => $unit->city, 'state' => $unit->state, 'zip_code' => $unit->zip,
                'created_at' => $unit->created_at, 'updated_at' => $unit->updated_at,
            ]);
            DB::table('consumer_units')->where('id', $unit->id)->update(['address_id' => $addressId]);
        });
        DB::table('solar_projects')->orderBy('id')->each(function ($row): void {
            DB::table('solar_projects')->where('id', $row->id)->update(['name' => $row->code]);
            $unit = DB::table('consumer_units')->where('id', $row->consumer_unit_id)->first();
            $common = ['uuid' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'solar_project_id' => $row->id,
                'created_at' => $row->created_at, 'updated_at' => $row->updated_at];
            DB::table('project_connection_data')->insert([...$common, 'phase_configuration' => $unit->supply_type,
                'main_breaker_a' => $unit->breaker_a, 'installed_load_kw' => $unit->installed_load_kw, 'contracted_demand_kw' => $unit->contracted_demand_kw]);
            $configId = DB::table('compensation_config')->insertGetId([...$common, 'uuid' => (string) Str::uuid(), 'mode' => $row->modality]);
            if ($row->modality === 'autoconsumo_local') {
                DB::table('compensation_units')->insert([...$common, 'uuid' => (string) Str::uuid(),
                    'compensation_config_id' => $configId, 'consumer_unit_id' => $row->consumer_unit_id, 'percentage' => 100]);
            }
            foreach (DB::table('project_equipment')->where('solar_project_id', $row->id)->get() as $pivot) {
                $equipment = DB::table('equipment_catalog')->find($pivot->equipment_item_id);
                $data = [...$common, 'uuid' => (string) Str::uuid()];
                match ($equipment->type) {
                    'module' => DB::table('project_arrays')->insert([...$data, 'module_model_id' => $equipment->id, 'module_quantity' => $pivot->quantity]),
                    'inverter' => DB::table('project_inverters')->insert([...$data, 'inverter_model_id' => $equipment->id, 'quantity' => $pivot->quantity, 'nominal_ac_kw' => ((float) $equipment->power_w) / 1000]),
                    'battery' => DB::table('project_storage')->insert([...$data, 'battery_model_id' => $equipment->id, 'quantity' => $pivot->quantity, 'energy_kwh' => $equipment->energy_kwh]),
                    default => null,
                };
            }
        });
        DB::table('process_documents')->orderBy('id')->each(function ($document): void {
            $projectId = DB::table('homologation_processes')->where('id', $document->homologation_process_id)->value('solar_project_id');
            DB::table('process_documents')->where('id', $document->id)->update(['solar_project_id' => $projectId]);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION prevent_frozen_version_change() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF OLD.status = 'frozen' THEN RAISE EXCEPTION 'Versao congelada e imutavel'; END IF; IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END \$\$;");
            DB::unprepared('CREATE TRIGGER frozen_project_version BEFORE UPDATE OR DELETE ON project_versions FOR EACH ROW EXECUTE FUNCTION prevent_frozen_version_change();');
            DB::unprepared("CREATE OR REPLACE FUNCTION protect_document_content() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP = 'DELETE' OR (to_jsonb(NEW) - ARRAY['is_current','review_status','review_notes','reviewed_by','reviewed_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['is_current','review_status','review_notes','reviewed_by','reviewed_at','updated_at']) THEN RAISE EXCEPTION 'Conteudo documental e imutavel'; END IF; RETURN NEW; END \$\$;");
            DB::unprepared('CREATE TRIGGER immutable_document_content BEFORE UPDATE OR DELETE ON process_documents FOR EACH ROW EXECUTE FUNCTION protect_document_content();');
            DB::unprepared("CREATE OR REPLACE FUNCTION protect_version_manifest() RETURNS trigger LANGUAGE plpgsql AS \$\$ DECLARE snapshot jsonb; document_uuid text; BEGIN IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Manifesto de versao e imutavel'; END IF; SELECT snapshot_json INTO snapshot FROM project_versions WHERE id = NEW.project_version_id; SELECT uuid::text INTO document_uuid FROM process_documents WHERE id = NEW.process_document_id; IF NOT EXISTS (SELECT 1 FROM jsonb_array_elements(snapshot->'documents') AS d WHERE d->>'id' = document_uuid) THEN RAISE EXCEPTION 'Documento nao pertence ao manifesto congelado'; END IF; RETURN NEW; END \$\$;");
            DB::unprepared('CREATE TRIGGER immutable_version_manifest BEFORE INSERT OR UPDATE OR DELETE ON project_version_documents FOR EACH ROW EXECUTE FUNCTION protect_version_manifest();');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_frozen_version_change(), protect_document_content(), protect_version_manifest() CASCADE;');
        }
        foreach (['project_version_documents', 'project_versions', 'responsibility_terms', 'compensation_units', 'compensation_config', 'project_storage', 'project_inverters', 'project_arrays', 'project_connection_data'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('process_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('solar_project_id');
            $table->dropColumn(['issued_at', 'expires_at']);
            // O rollback mantém documentos independentes; não torna a FK obrigatória.
        });
        Schema::table('solar_projects', fn (Blueprint $table) => $table->dropColumn(['name', 'status', 'installation_type']));
        Schema::table('technical_responsibles', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'cpf']);
            $table->dropColumn('cpf');
        });
        Schema::table('consumer_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('address_id');
            $table->dropColumn(['voltage', 'neutral_voltage']);
        });
        Schema::dropIfExists('addresses');
    }
};
