<?php

namespace App\Domain\Users\Enums;

use App\Domain\Users\Enums\PermissionKey as P;

/**
 * Perfis padrão criados para cada tenant. "Consulta" não aparece entre os atores do PDF;
 * foi incluído como perfil somente leitura (decisão registrada na análise).
 */
enum SystemRole: string
{
    case Administrator = 'administrador';
    case Homologator = 'homologador';
    case TechnicalResponsible = 'responsavel_tecnico';
    case Manager = 'gestor';
    case ReadOnly = 'consulta';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador',
            self::Homologator => 'Homologador',
            self::TechnicalResponsible => 'Responsável Técnico',
            self::Manager => 'Gestor',
            self::ReadOnly => 'Consulta',
        };
    }

    /**
     * @return list<PermissionKey>
     */
    public function defaultPermissions(): array
    {
        $read = [P::DashboardView, P::ClientsView, P::ProjectsView, P::DocumentsView, P::HomologationsView];

        return match ($this) {
            self::Administrator => P::cases(),
            self::Homologator => [...$read, P::ClientsManage, P::TechnicalResponsiblesManage, P::ProjectsManage,
                P::DocumentsManage, P::HomologationsManage],
            self::TechnicalResponsible => [...$read, P::ProjectsManage, P::DocumentsManage],
            self::Manager => [...$read, P::UsersView, P::RolesView, P::AuditView, P::ProcessCancel],
            self::ReadOnly => $read,
        };
    }
}
