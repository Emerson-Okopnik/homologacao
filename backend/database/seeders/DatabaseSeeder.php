<?php

namespace Database\Seeders;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Actions\ProvisionTenantRoles;
use App\Domain\Users\Enums\SystemRole;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dados DEMONSTRATIVOS para desenvolvimento local. Não representam usuários reais
 * nem regras oficiais da CELESC. Senha padrão: "Homologa@2026" (apenas em ambiente local).
 */
class DatabaseSeeder extends Seeder
{
    public function run(ProvisionTenantRoles $provisionRoles, TenantContext $context): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo-solar'],
            ['name' => 'Demo Solar Engenharia', 'active' => true],
        );

        $provisionRoles->handle($tenant);

        $context->run($tenant, function (): void {
            $demoUsers = [
                ['Ana Administradora', 'admin@homologa.local', SystemRole::Administrator],
                ['Henrique Homologador', 'homologador@homologa.local', SystemRole::Homologator],
                ['Rafaela Responsável Técnica', 'rt@homologa.local', SystemRole::TechnicalResponsible],
                ['Gustavo Gestor', 'gestor@homologa.local', SystemRole::Manager],
                ['Carla Consulta', 'consulta@homologa.local', SystemRole::ReadOnly],
            ];

            foreach ($demoUsers as [$name, $email, $role]) {
                $user = User::query()->firstOrCreate(
                    ['email' => $email],
                    ['name' => $name, 'password' => 'Homologa@2026', 'active' => true],
                );

                $user->roles()->syncWithoutDetaching(
                    Role::query()->where('slug', $role->value)->pluck('id'),
                );
            }
        });
    }
}
