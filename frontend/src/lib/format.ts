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
