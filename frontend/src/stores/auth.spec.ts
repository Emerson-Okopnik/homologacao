import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from './auth'
import { safeRedirect } from '@/router/redirect'

const me = {
  user: { id: 'u1', name: 'Ana Souza', email: 'ana@x.com', active: true, last_login_at: null, created_at: null },
  tenant: { id: 't1', name: 'Solar Sul', slug: 'solar-sul' },
  permissions: ['users.view'],
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.restoreAllMocks()
})

describe('auth store', () => {
  it('carrega a sessão e expõe permissões', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: me }), { status: 200 }))
    const auth = useAuthStore()

    await auth.ensureLoaded()

    expect(auth.status).toBe('authenticated')
    expect(auth.can('users.view')).toBe(true)
    expect(auth.can('users.manage')).toBe(false)
  })

  it('marca como visitante quando a API responde 401', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}', { status: 401 }))
    const auth = useAuthStore()

    await auth.ensureLoaded()

    expect(auth.status).toBe('guest')
  })

  it('não repete a requisição quando já carregado', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(new Response(JSON.stringify({ data: me }), { status: 200 }))
    const auth = useAuthStore()

    await Promise.all([auth.ensureLoaded(), auth.ensureLoaded()])
    await auth.ensureLoaded()

    expect(fetchMock).toHaveBeenCalledOnce()
  })
})

describe('safeRedirect', () => {
  it('aceita apenas caminhos internos', () => {
    expect(safeRedirect('/usuarios?page=2')).toBe('/usuarios?page=2')
    expect(safeRedirect('//evil.com')).toBe('/')
    expect(safeRedirect('https://evil.com')).toBe('/')
    expect(safeRedirect(undefined)).toBe('/')
  })
})
