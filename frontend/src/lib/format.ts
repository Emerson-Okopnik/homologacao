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
  'auth.login': 'Login',
  'auth.login_failed': 'Falha de login',
  'auth.logout': 'Logout',
  'auth.password_reset': 'Senha redefinida',
  'user.created': 'Usuário criado',
  'user.updated': 'Usuário alterado',
}

export function formatEvent(event: string): string {
  return eventLabels[event] ?? event
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
  if (status === 'COMPLETED') return 'success'
  if (status === 'CANCELLED') return 'danger'
  return stageTones[stage] ?? 'neutral'
}
