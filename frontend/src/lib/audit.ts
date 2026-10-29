import type { AuditLog } from '@/types/api'

const fields: Record<string, string> = {
  name: 'Nome', email: 'E-mail', active: 'Ativo', status: 'Situação', type: 'Tipo',
  document: 'Documento', phone: 'Telefone', trade_name: 'Nome fantasia',
  roles: 'Perfis de acesso', permissions: 'Permissões', password: 'Senha',
  user_id: 'Usuário', client_id: 'Cliente', tenant_id: 'Empresa',
  technical_responsible_id: 'Responsável técnico', consumer_unit_id: 'Unidade consumidora',
  solar_project_id: 'Projeto', homologation_process_id: 'Processo',
  created_at: 'Criado em', updated_at: 'Atualizado em', deleted_at: 'Excluído em',
  last_login_at: 'Último acesso', reason: 'Motivo', code: 'Código', stage: 'Fase',
  is_super_admin: 'Superadministrador', registration: 'Registro profissional',
  review_status: 'Revisão', document_type: 'Tipo de documento', original_name: 'Nome do arquivo',
  ip_address: 'Endereço IP', user_agent: 'Navegador', correlation_id: 'Referência',
}

export function auditFieldLabel(key: string) {
  return fields[key] ?? key.replace(/[_\.]/g, ' ').replace(/^./, letter => letter.toLocaleUpperCase('pt-BR'))
}

export function auditValue(value: unknown): string {
  if (value === undefined) return '—'
  if (value === null || value === '') return 'Não informado'
  if (value === true) return 'Sim'
  if (value === false) return 'Não'
  if (value === '[REDACTED]') return 'Dado protegido'
  if (typeof value === 'object') return JSON.stringify(value, null, 2)
  return String(value)
}

export function auditChanges(log: AuditLog) {
  const before = log.old_values ?? {}
  const after = log.new_values ?? {}
  return [...new Set([...Object.keys(before), ...Object.keys(after)])]
    .filter(key => JSON.stringify(before[key]) !== JSON.stringify(after[key]))
    .map(key => ({ key, label: auditFieldLabel(key), before: auditValue(before[key]), after: auditValue(after[key]) }))
}

const subjects: Record<string, string> = {
  User: 'Usuário', Client: 'Cliente', Tenant: 'Empresa', Role: 'Perfil de acesso',
  ConsumerUnit: 'Unidade consumidora', TechnicalResponsible: 'Responsável técnico',
  SolarProject: 'Projeto', HomologationProcess: 'Processo', ClientRequest: 'Solicitação de cliente',
  EquipmentItem: 'Equipamento', Distributor: 'Distribuidora', ProcessDocument: 'Documento', Document: 'Documento',
}
export function auditSubject(value: string | null) {
  return value ? subjects[value] ?? value : 'Não informado'
}

export function auditTone(event: string) {
  if (event?.includes('failed') || event?.endsWith('.deleted')) return 'danger' as const
  if (event?.includes('permissions_changed') || event?.includes('super_admin_changed')) return 'warning' as const
  if (event?.endsWith('.created')) return 'success' as const
  return 'info' as const
}

export const auditEventOptions = [
  { value: 'auth.', label: 'Autenticação · todos' },
  { value: 'auth.login', label: 'Acesso ao sistema' },
  { value: 'auth.login_failed', label: 'Falha de acesso' },
  { value: 'auth.logout', label: 'Saída do sistema' },
  { value: 'auth.password_reset', label: 'Senha redefinida' },
  { value: 'user.', label: 'Usuários · todos' },
  { value: 'user.created', label: 'Usuário criado' },
  { value: 'user.updated', label: 'Usuário alterado' },
  { value: 'user.roles_changed', label: 'Perfis do usuário alterados' },
  { value: 'role.', label: 'Perfis de acesso · todos' },
  { value: 'tenant.', label: 'Empresas · todos' },
  { value: 'client.', label: 'Clientes · todos' },
  { value: 'consumer_unit.', label: 'Unidades consumidoras · todos' },
  { value: 'solar_project.', label: 'Projetos · todos' },
  { value: 'homologation_process.', label: 'Processos · todos' },
  { value: 'client_request.', label: 'Solicitações · todos' },
  { value: 'process_document.', label: 'Documentos · todos' },
]
