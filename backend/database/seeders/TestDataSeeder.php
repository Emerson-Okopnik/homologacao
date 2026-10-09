<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\Address;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\HomologationService;
use App\Domain\Homologations\ProcessWorkflow;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Projects\ProjectEvaluator;
use App\Domain\Projects\ProjectFormAdapter;
use App\Domain\Projects\ProjectTechnicalData;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Dados fictícios identificados por TST; repetir a carga preserva registros já criados. */
final class TestDataSeeder extends Seeder
{
    public function run(TenantContext $context): void
    {
        $tenant = Tenant::where('slug', config('test-data.tenant', 'demo-solar'))->firstOrFail();
        $this->call(RulesSeeder::class);
        $context->run($tenant, function (): void {
            DB::transaction(function (): void {
                $actor = User::where('active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'administrador'))->first()
                    ?? User::where('active', true)->firstOrFail();
                $distributor = Distributor::where('active', true)->first()
                    ?? Distributor::create(['code' => 'TST', 'name' => 'Distribuidora de Teste', 'state' => 'SC', 'integration_mode' => 'assisted', 'active' => true]);
                $clients = [];
                $units = [];
                $cities = ['Florianópolis', 'Joinville', 'Blumenau', 'Chapecó', 'Itajaí', 'Criciúma', 'São José', 'Palhoça'];
                for ($i = 1; $i <= 8; $i++) {
                    $company = $i > 6;
                    $client = Client::firstOrCreate(['document' => $company ? $this->companyDocument($i) : $this->personalDocument(731002000 + $i)],
                        ['type' => $company ? 'PJ' : 'PF', 'name' => sprintf($company ? 'Empresa Teste %02d Ltda' : 'Cliente Teste %02d', $i),
                            'trade_name' => $company ? sprintf('Solar Teste %02d', $i) : null, 'email' => sprintf('cliente%02d@example.test', $i),
                            'status' => 'active', 'notes' => 'Dado fictício para testes. Identificador: TST-CLIENT-'.sprintf('%02d', $i)]);
                    $clients[] = $client;
                    if ($client->wasRecentlyCreated) {
                        $client->contacts()->create(['name' => sprintf('Contato Teste %02d', $i), 'role' => 'Titular', 'email' => $client->email, 'is_legal_representative' => $company]);
                    }
                    for ($n = 1; $n <= 2; $n++) {
                        $number = sprintf('TST-UC-%02d-%02d', $i, $n);
                        $unit = ConsumerUnit::firstOrCreate(['number' => $number, 'distributor_id' => $distributor->id],
                            ['client_id' => $client->id, 'street' => 'Rua dos Testes', 'address_number' => (string) (100 + $i * 10 + $n),
                                'district' => 'Bairro de Teste', 'city' => $cities[$i - 1], 'state' => 'SC', 'zip' => '88000000', 'voltage_class' => 'BT',
                                'supply_type' => 'trifasico', 'voltage' => 380, 'neutral_voltage' => 220, 'installed_load_kw' => 15 + $i * 5, 'breaker_a' => 63, 'active' => true]);
                        if ($unit->wasRecentlyCreated) {
                            $address = Address::create(['street' => $unit->street, 'number' => $unit->address_number, 'district' => $unit->district,
                                'city' => $unit->city, 'state' => $unit->state, 'zip_code' => '88000000']);
                            $unit->update(['address_id' => $address->id]);
                        }
                        $units[$i - 1][$n - 1] = $unit;
                    }
                }
                $responsibles = [];
                for ($i = 1; $i <= 6; $i++) {
                    $responsibles[] = TechnicalResponsible::firstOrCreate(['cpf' => $this->personalDocument(831003000 + $i)],
                        ['name' => sprintf('Responsável Técnico Teste %02d', $i), 'council' => $i === 6 ? 'CFT' : 'CREA', 'registration' => sprintf('TST-RT-%04d', $i),
                            'state' => 'SC', 'email' => sprintf('rt%02d@example.test', $i), 'registration_status' => $i === 6 ? 'nao_verificado' : 'regular', 'active' => true]);
                }
                $modules = [];
                foreach ([450, 500, 550, 585, 600, 650] as $watts) {
                    $modules[$watts] = EquipmentItem::firstOrCreate(['type' => 'module', 'manufacturer' => 'Fabricante Teste', 'model' => 'TST-M'.$watts],
                        ['power_w' => $watts, 'efficiency' => 21.5, 'certification' => 'Ficha técnica fictícia para testes', 'active' => true]);
                }
                $inverters = [];
                foreach ([3, 5, 8, 10, 30, 60] as $kw) {
                    $inverters[$kw] = EquipmentItem::firstOrCreate(['type' => 'inverter', 'manufacturer' => 'Fabricante Teste', 'model' => 'TST-I'.$kw],
                        ['power_w' => $kw * 1000, 'nominal_ac_power_kw' => $kw, 'efficiency' => 97.5, 'has_inmetro_registration' => false, 'active' => true]);
                }
                $batteries = [];
                foreach ([5.12, 10.24] as $kwh) {
                    $batteries[] = EquipmentItem::firstOrCreate(['type' => 'battery', 'manufacturer' => 'Fabricante Teste', 'model' => 'TST-B'.$kwh],
                        ['energy_kwh' => $kwh, 'efficiency' => 95, 'active' => true]);
                }
                $sizes = [[550, 10, 5, 1], [500, 16, 8, 1], [650, 20, 10, 1], [585, 40, 30, 1], [600, 80, 30, 2], [550, 220, 60, 2],
                    [650, 300, 60, 4], [500, 30, 8, 2], [450, 12, 5, 1], [600, 50, 30, 1], [550, 18, 10, 1], [585, 24, 8, 2]];
                $modes = ['autoconsumo_local', 'autoconsumo_remoto', 'geracao_compartilhada', 'multiplas_uc'];
                foreach ($sizes as $index => [$watts,$moduleQty,$inverterKw,$inverterQty]) {
                    $owner = $index % 8;
                    $mode = $modes[$index % 4];
                    $storage = in_array($index, [7, 10], true);
                    $rt = $responsibles[$index % 6];
                    $project = SolarProject::firstOrCreate(['code' => sprintf('TST-PROJ-%03d', $index + 1)],
                        ['name' => sprintf('Projeto de Teste %02d — %s', $index + 1, $cities[$owner]), 'client_id' => $clients[$owner]->id,
                            'consumer_unit_id' => $units[$owner][0]->id, 'technical_responsible_id' => $rt->id,
                            'modality' => $mode, 'compensation_mode' => array_search($mode, ProjectFormAdapter::MODES, true),
                            'installed_power_kwp' => $watts * $moduleQty / 1000, 'inverter_power_kw' => $inverterKw * $inverterQty,
                            'generation_type' => SolarProject::classify(min($watts * $moduleQty / 1000, $inverterKw * $inverterQty)),
                            'estimated_generation_kwh_month' => round($watts * $moduleQty / 1000 * 120, 2), 'has_battery' => $storage,
                            'installation_type' => $index >= 5 ? 'ground' : 'rooftop', 'created_by' => $actor->id, 'notes' => 'Projeto fictício TST. Completar e revisar os documentos antes de preparar o envio.']);
                    if (! $project->wasRecentlyCreated) {
                        continue;
                    }
                    $pivot = [$modules[$watts]->id => ['quantity' => $moduleQty, 'tenant_id' => $project->tenant_id],
                        $inverters[$inverterKw]->id => ['quantity' => $inverterQty, 'tenant_id' => $project->tenant_id]];
                    if ($storage) {
                        $pivot[$batteries[1]->id] = ['quantity' => 2, 'tenant_id' => $project->tenant_id];
                    }
                    $project->equipment()->sync($pivot);
                    $project->load('equipment', 'consumerUnit');
                    app(ProjectTechnicalData::class)->seedFromEquipment($project);
                    $project->connectionData()->update(['connection_point' => 'Quadro principal de teste', 'supply_voltage' => 380, 'main_breaker_a' => 63, 'installed_load_kw' => 50]);
                    $project->arrays()->update(['strings_quantity' => 2, 'modules_per_string' => $moduleQty / 2, 'azimuth' => 0, 'tilt' => 20]);
                    $project->inverters()->update(['connection_voltage' => 380, 'protection_config_json' => ['description' => 'Proteções fictícias para simulação de cadastro']]);
                    $config = $project->compensation;
                    $config->update(['allocation_rule' => 'percentage']);
                    if ($mode !== 'autoconsumo_local') {
                        $config->units()->create(['solar_project_id' => $project->id, 'consumer_unit_id' => $units[$owner][0]->id, 'percentage' => 60]);
                        $config->units()->create(['solar_project_id' => $project->id, 'consumer_unit_id' => $units[$owner][1]->id, 'percentage' => 40]);
                    }
                    $project->responsibilities()->create(['purpose' => 'PROJECT', 'technical_responsible_id' => $rt->id, 'created_by' => $actor->id]);
                    $project->responsibilities()->create(['purpose' => 'EXECUTION', 'technical_responsible_id' => $responsibles[($index + 1) % 5]->id, 'created_by' => $actor->id]);
                    $project->update(['storage_energy_kwh' => $storage ? 20.48 : null, 'compensation_method' => 'PERCENTAGE']);
                    app(ProjectEvaluator::class)->evaluate($project);
                    $process = app(HomologationService::class)->open($project, $actor);
                    if ($index % 2 === 0) {
                        app(ProcessWorkflow::class)->transition($process, ProcessStatus::EmPreparacao, $actor);
                    }
                }
            });
        });
        $this->command->info('Dados TST carregados para '.$tenant->slug.': 8 clientes, 16 UCs, 6 RTs, 14 equipamentos e 12 projetos (sem duplicar existentes).');
    }

    private function personalDocument(int $number): string
    {
        $digits = str_pad((string) $number, 9, '0', STR_PAD_LEFT);
        foreach ([10, 11] as $weight) {
            $sum = 0;
            foreach (str_split($digits) as $digit) {
                $sum += (int) $digit * $weight--;
            }
            $remainder = $sum % 11;
            $digits .= $remainder < 2 ? '0' : (string) (11 - $remainder);
        }

        return $digits;
    }

    private function companyDocument(int $number): string
    {
        $digits = '72'.str_pad((string) $number, 6, '0', STR_PAD_LEFT).'0001';
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;
            foreach (str_split($digits) as $i => $digit) {
                $sum += (int) $digit * $weights[$i];
            }
            $remainder = $sum % 11;
            $digits .= $remainder < 2 ? '0' : (string) (11 - $remainder);
        }

        return $digits;
    }
}
