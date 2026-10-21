import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, buildUrl, onUnauthorized, upload } from './http'

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } })
}

beforeEach(() => {
  vi.stubEnv('VITE_API_URL', '')
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllEnvs()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('buildUrl', () => {
  it('omite parâmetros vazios e serializa booleanos', () => {
    expect(buildUrl('/users', { search: '', page: 2, active: true, x: null })).toBe('/api/users?page=2&active=1')
  })

  it.each([
    ['', '/users', '/api/users'],
    ['   ', 'users', '/api/users'],
    ['/api/', '/api/users', '/api/users'],
    ['https://api.empresa.com.br', '/users', 'https://api.empresa.com.br/api/users'],
    ['https://api.empresa.com.br/', 'users', 'https://api.empresa.com.br/api/users'],
    [' https://api.empresa.com.br/api/// ', '/api/users', 'https://api.empresa.com.br/api/users'],
  ])('normaliza a base %s e o caminho %s', (base, path, expected) => {
    vi.stubEnv('VITE_API_URL', base)
    expect(buildUrl(path)).toBe(expected)
  })

  it('preserva o prefixo de endpoints que começam com api', () => {
    expect(buildUrl('/api-tokens')).toBe('/api/api-tokens')
  })
})

describe('api', () => {
  it.each([
    ['', '/sanctum/csrf-cookie', '/api/auth/login'],
    ['https://api.empresa.com.br/api/', 'https://api.empresa.com.br/sanctum/csrf-cookie', 'https://api.empresa.com.br/api/auth/login'],
  ])('obtém CSRF na raiz correta antes do login com base %s', async (base, csrfUrl, loginUrl) => {
    vi.stubEnv('VITE_API_URL', base)
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(async () => {
        document.cookie = 'XSRF-TOKEN=session%3D'
        return new Response(null, { status: 204 })
      })
      .mockResolvedValueOnce(jsonResponse(200, { data: { id: 'user-1' } }))

    await expect(api('/auth/login', { method: 'POST', body: { email: 'admin@empresa.com.br' } }))
      .resolves.toEqual({ data: { id: 'user-1' } })

    expect(fetchMock).toHaveBeenNthCalledWith(1, csrfUrl, expect.objectContaining({ credentials: 'include' }))
    expect(fetchMock).toHaveBeenNthCalledWith(2, loginUrl, expect.objectContaining({
      method: 'POST',
      credentials: 'include',
      body: JSON.stringify({ email: 'admin@empresa.com.br' }),
      headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'session=', 'Content-Type': 'application/json' }),
    }))
  })

  it('envia consultas autenticadas para o domínio externo da API', async () => {
    vi.stubEnv('VITE_API_URL', 'https://api.empresa.com.br/api')
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(200, { data: [] }))

    await api('/users', { query: { page: 2, active: false } })

    expect(fetchMock).toHaveBeenCalledExactlyOnceWith('https://api.empresa.com.br/api/users?page=2&active=0', expect.objectContaining({
      method: 'GET', credentials: 'include',
    }))
  })

  it('interrompe a escrita se a obtenção do cookie CSRF falhar', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(503, {}))

    await expect(api('/auth/login', { method: 'POST' })).rejects.toMatchObject({ status: 503 })
    expect(fetchMock).toHaveBeenCalledOnce()
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/sanctum/csrf-cookie')
  })

  it('envia o token XSRF em requisições de escrita', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D'
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(200, { ok: true }))

    await api('/auth/logout', { method: 'POST' })

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit
    expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=')
    expect(init.credentials).toBe('include')
  })

  it('converte erros de validação em ApiError com campos', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      jsonResponse(422, { message: 'Dados inválidos', errors: { email: ['E-mail inválido'] } }, { 'X-Correlation-Id': 'c-1' }),
    )

    const error = await api('/users').catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).firstError('email')).toBe('E-mail inválido')
    expect((error as ApiError).correlationId).toBe('c-1')
  })

  it('aciona o handler de sessão expirada em 401', async () => {
    const handler = vi.fn()
    onUnauthorized(handler)
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))

    await expect(api('/auth/me')).rejects.toThrow(ApiError)
    expect(handler).toHaveBeenCalledOnce()
  })
})

describe('upload', () => {
  it('envia o arquivo para a API externa com o cookie CSRF e o boundary do navegador', async () => {
    vi.stubEnv('VITE_API_URL', 'https://api.empresa.com.br/api')
    const form = new FormData()
    form.append('file', new File(['documento'], 'projeto.pdf', { type: 'application/pdf' }))
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(async () => {
        document.cookie = 'XSRF-TOKEN=upload%3D'
        return new Response(null, { status: 204 })
      })
      .mockResolvedValueOnce(jsonResponse(201, { data: { id: 'document-1' } }))

    await expect(upload('/processes/process-1/documents', form)).resolves.toEqual({ data: { id: 'document-1' } })

    expect(fetchMock).toHaveBeenNthCalledWith(1, 'https://api.empresa.com.br/sanctum/csrf-cookie', expect.anything())
    const [url, init] = fetchMock.mock.calls[1]!
    expect(url).toBe('https://api.empresa.com.br/api/processes/process-1/documents')
    expect(init).toMatchObject({ method: 'POST', credentials: 'include', body: form })
    expect(init?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'upload=' })
    expect(init?.headers).not.toHaveProperty('Content-Type')
  })
})
