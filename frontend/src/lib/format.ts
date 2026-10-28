const dateTime = new Intl.DateTimeFormat('pt-BR', {
  dateStyle: 'short',
  timeStyle: 'short',
  timeZone: 'America/Sao_Paulo',
})

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : dateTime.format(date)
}

const eventLabels: Record<string, string> = {
  'auth.login': 'Acesso ao sistema',
  'auth.login_failed': 'Falha de acesso',
  'auth.logout': 'Saída do sistema',
  'auth.password_reset': 'Senha redefinida',
  'user.created': 'Usuário criado',
  'user.updated': 'Usuário alterado',
  'user.roles_changed': 'Perfis do usuário alterados',
  'user.super_admin_changed': 'Acesso de superadministrador alterado',
  'role.permissions_changed': 'Permissões do perfil alteradas',
}

const auditEntities: Record<string, [string, boolean?]> = {
  user: ['Usuário'], client: ['Cliente'], tenant: ['Empresa', true], role: ['Perfil de acesso'],
  consumer_unit: ['Unidade consumidora', true], technical_responsible: ['Responsável técnico'],
  solar_project: ['Projeto'], homologation_process: ['Processo'], client_request: ['Solicitação de cliente', true],
  process_document: ['Documento'], document: ['Documento'], equipment_catalog: ['Equipamento'],
  distributor: ['Distribuidora', true], inspection: ['Vistoria', true], project_execution: ['Execução', true],
  process_pendency: ['Pendência', true], connection_event: ['Evento de conexão'],
}

export function formatEvent(event: string): string {
  if (!event) return 'Evento'
  if (eventLabels[event]) return eventLabels[event]
  const [entity, action] = event.split('.')
  const subject = auditEntities[entity ?? '']
  const actions: Record<string, [string, string]> = { created: ['criado', 'criada'], updated: ['alterado', 'alterada'], deleted: ['excluído', 'excluída'] }
  const suffix = actions[action ?? '']
  return subject && suffix ? `${subject[0]} ${suffix[subject[1] ? 1 : 0]}` : event
}

const dateOnly = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'UTC' })

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value.length === 10 ? `${value}T00:00:00Z` : value)
  return Number.isNaN(date.getTime()) ? '—' : dateOnly.format(date)
}

const decimal = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 })

export function formatNumber(value: number | null | undefined, unit?: string): string {
  if (value === null || value === undefined) return '—'
  return unit ? `${decimal.format(value)} ${unit}` : decimal.format(value)
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export function formatDocument(value: string): string {
  const d = value.replace(/\D/g, '')
  if (d.length === 11) return d.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')
  if (d.length === 14) return d.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')
  return value
}

type Tone = 'success' | 'warning' | 'danger' | 'neutral' | 'info'

const statusTones: Record<string, Tone> = {
  rascunho: 'neutral',
  em_preparacao: 'neutral',
  pronto_para_envio: 'info',
  enviado: 'info',
  em_analise: 'info',
  pendencia_distribuidora: 'warning',
  aprovado: 'success',
  vistoria_solicitada: 'warning',
  conectado: 'success',
  reprovado: 'danger',
  cancelado: 'danger',
  pendente: 'warning',
  aberta: 'warning',
  resolvida: 'success',
  active: 'success',
  inactive: 'neutral',
}

export function statusTone(status: string): Tone {
  return statusTones[status] ?? 'neutral'
}

const stageTones: Record<string, Tone> = {
  PREPARATION: 'neutral',
  EXTERNAL_ANALYSIS: 'info',
  CORRECTION: 'warning',
  EXECUTION: 'info',
  INSPECTION: 'warning',
  CONNECTION: 'success',
}

export function stageTone(stage: string, status: string = 'ACTIVE'): Tone {
  if (status === 'COMPLETED' || status === 'conectado') return 'success'
  if (status === 'CANCELLED' || status === 'cancelado') return 'danger'
  return stageTones[stage] ?? 'neutral'
}
