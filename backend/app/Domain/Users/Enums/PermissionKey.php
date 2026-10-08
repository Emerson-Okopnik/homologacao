<?php

namespace App\Domain\Users\Enums;

/**
 * Catálogo de permissões granulares. O catálogo é global (código), a associação
 * a roles é por tenant (banco). As permissões das Waves futuras já existem para que
 * os perfis padrão fiquem estáveis desde a Wave 1.
 */
enum PermissionKey: string
{
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesView = 'roles.view';
    case AuditView = 'audit.view';

    case ClientsView = 'clients.view';
    case ClientsManage = 'clients.manage';
    case TechnicalResponsiblesManage = 'technical_responsibles.manage';
    case ProjectsView = 'projects.view';
    case ProjectsManage = 'projects.manage';
    case DocumentsView = 'documents.view';
    case DocumentsManage = 'documents.manage';
    case HomologationsView = 'homologations.view';
    case HomologationsManage = 'homologations.manage';
    case ProcessCancel = 'process.cancel';
    case ProtocolOverride = 'protocol.override';
    case WorkflowConfigure = 'workflow.configure';
    case RequirementsConfigure = 'requirements.configure';
    case IntegrationsConfigure = 'integrations.configure';
    case DashboardView = 'dashboard.view';

    public function label(): string
    {
        return match ($this) {
            self::UsersView => 'Visualizar usuários',
            self::UsersManage => 'Gerenciar usuários',
            self::RolesView => 'Visualizar perfis',
            self::AuditView => 'Visualizar auditoria',
            self::ClientsView => 'Visualizar clientes',
            self::ClientsManage => 'Gerenciar clientes e UCs',
            self::TechnicalResponsiblesManage => 'Gerenciar responsáveis técnicos',
            self::ProjectsView => 'Visualizar projetos',
            self::ProjectsManage => 'Gerenciar projetos',
            self::DocumentsView => 'Visualizar documentos',
            self::DocumentsManage => 'Gerenciar documentos',
            self::HomologationsView => 'Visualizar homologações',
            self::HomologationsManage => 'Operar homologações',
            self::ProcessCancel => 'Cancelar processo',
            self::ProtocolOverride => 'Corrigir protocolo manualmente',
            self::WorkflowConfigure => 'Configurar workflow',
            self::RequirementsConfigure => 'Configurar requisitos',
            self::IntegrationsConfigure => 'Configurar integrações',
            self::DashboardView => 'Visualizar dashboard',
        };
    }

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }
}
