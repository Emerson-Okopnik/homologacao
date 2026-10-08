import { afterEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, buildUrl, onUnauthorized } from './http'

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } })
}

afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('buildUrl', () => {
  it('omite parâmetros vazios e serializa booleanos', () => {
    expect(buildUrl('/users', { search: '', page: 2, active: true, x: null })).toBe('/api/users?page=2&active=1')
  })
})

describe('api', () => {
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
