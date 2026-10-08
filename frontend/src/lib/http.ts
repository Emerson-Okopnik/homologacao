export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: Record<string, string[]> = {},
    public readonly correlationId: string | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
  }

  firstError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

type Query = Record<string, string | number | boolean | null | undefined>

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  query?: Query
}

let unauthorizedHandler: (() => void) | null = null

export function onUnauthorized(handler: () => void): void {
  unauthorizedHandler = handler
}

export function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

export function buildUrl(path: string, query?: Query): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value === null || value === undefined || value === '') continue
    params.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
  }
  const qs = params.toString()
  return `/api${path}${qs ? `?${qs}` : ''}`
}

async function ensureCsrfCookie(): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }

  if (method !== 'GET') {
    await ensureCsrfCookie()
    const token = readCookie('XSRF-TOKEN')
    if (token) headers['X-XSRF-TOKEN'] = token
  }

  if (options.body !== undefined) headers['Content-Type'] = 'application/json'

  const response = await fetch(buildUrl(path, options.query), {
    method,
    headers,
    credentials: 'include',
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  })

  if (response.status === 204) return undefined as T

  const payload = (await response.json().catch(() => ({}))) as {
    message?: string
    errors?: Record<string, string[]>
  }

  if (!response.ok) {
    if (response.status === 401 && unauthorizedHandler) unauthorizedHandler()

    throw new ApiError(
      response.status,
      payload.message ?? messageForStatus(response.status),
      payload.errors ?? {},
      response.headers.get('X-Correlation-Id'),
    )
  }

  return payload as T
}

export async function upload<T>(path: string, form: FormData): Promise<T> {
  await ensureCsrfCookie()
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  const token = readCookie('XSRF-TOKEN')
  if (token) headers['X-XSRF-TOKEN'] = token

  const response = await fetch(buildUrl(path), { method: 'POST', headers, credentials: 'include', body: form })
  const payload = (await response.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> }

  if (!response.ok) {
    if (response.status === 401 && unauthorizedHandler) unauthorizedHandler()
    throw new ApiError(
      response.status,
      payload.message ?? messageForStatus(response.status),
      payload.errors ?? {},
      response.headers.get('X-Correlation-Id'),
    )
  }
  return payload as T
}

export function toApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, 'Falha de conexão com o servidor.')
}

function messageForStatus(status: number): string {
  if (status === 403) return 'Você não tem permissão para esta ação.'
  if (status === 404) return 'Registro não encontrado.'
  if (status === 419) return 'Sua sessão expirou. Recarregue a página.'
  if (status === 429) return 'Muitas requisições. Aguarde um instante.'
  return 'Não foi possível concluir a operação. Tente novamente.'
}
