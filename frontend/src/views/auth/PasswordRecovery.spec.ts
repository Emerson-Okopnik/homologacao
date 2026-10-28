import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import ForgotPasswordView from './ForgotPasswordView.vue'
import ResetPasswordView from './ResetPasswordView.vue'

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.stubEnv('VITE_API_URL', '')
  document.cookie = 'XSRF-TOKEN=test-token'
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllEnvs()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

const response = (payload: unknown, status = 200) => new Response(JSON.stringify(payload), { status })

async function recoveryAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: { template: '<h1>Entrar</h1>' } },
      { path: '/esqueci-senha', name: 'forgot-password', component: ForgotPasswordView },
      { path: '/redefinir-senha/:token', name: 'reset-password', component: ResetPasswordView },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(RouterView, {
    global: {
      plugins: [router],
      stubs: { AuthShell: { props: ['title'], template: '<main><h1>{{ title }}</h1><slot /></main>' } },
    },
  })
  return { wrapper, router }
}

describe('recuperação de senha', () => {
  it('envia uma única solicitação enquanto aguarda a API e confirma somente o recebimento', async () => {
    let finish!: (value: Response) => void
    const pending = new Promise<Response>(resolve => { finish = resolve })
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockReturnValue(pending)
    const { wrapper } = await recoveryAt('/esqueci-senha')
    await wrapper.get('input[type="email"]').setValue(' ana@example.com ')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    await wrapper.get('form').trigger('submit')

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledWith('/api/auth/forgot-password', expect.objectContaining({
      method: 'POST', body: JSON.stringify({ email: 'ana@example.com' }),
    }))
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[role="status"]').exists()).toBe(false)

    finish(response({ message: 'Solicitação recebida.' }, 202))
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toContain('Solicitação recebida. Se houver uma conta ativa')
    expect(wrapper.text()).toContain('O envio pode levar alguns minutos.')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('mostra a validação do e-mail sem confirmar o pedido', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response({
      message: 'Os dados informados são inválidos.',
      errors: { email: ['O campo e-mail deve conter um endereço de e-mail válido.'] },
    }, 422))
    const { wrapper } = await recoveryAt('/esqueci-senha')
    await wrapper.get('input[type="email"]').setValue('invalido')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('O campo e-mail deve conter um endereço de e-mail válido.')
    expect(wrapper.get('input[type="email"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('mantém o formulário e explica o limite de tentativas', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response({ message: 'Muitas tentativas. Aguarde e tente novamente.' }, 429))
    const { wrapper } = await recoveryAt('/esqueci-senha')
    await wrapper.get('input[type="email"]').setValue('ana@example.com')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Muitas tentativas. Aguarde e tente novamente.')
    expect(wrapper.find('form').exists()).toBe(true)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('impede o envio quando a confirmação de senha não confere', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
    const { wrapper } = await recoveryAt('/redefinir-senha/token-seguro?email=ana%40example.com')
    const passwords = wrapper.findAll('input[type="password"]')
    await passwords[0]!.setValue('Senha12345')
    await passwords[1]!.setValue('Outra12345')
    await wrapper.get('form').trigger('submit')

    expect(fetchMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('A confirmação de senha não confere.')
    expect(passwords[1]!.attributes('aria-invalid')).toBe('true')
  })

  it('envia o token e o e-mail do link e retorna à tela de acesso após redefinir', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response({ message: 'Senha redefinida.' }))
    const { wrapper, router } = await recoveryAt('/redefinir-senha/token-seguro?email=ana%2Bsolar%40example.com')
    const passwords = wrapper.findAll('input[type="password"]')
    await passwords[0]!.setValue('Senha12345')
    await passwords[1]!.setValue('Senha12345')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledWith('/api/auth/reset-password', expect.objectContaining({
      method: 'POST', body: JSON.stringify({ email: 'ana+solar@example.com', password: 'Senha12345', password_confirmation: 'Senha12345', token: 'token-seguro' }),
    }))
    expect(wrapper.get('[role="status"]').text()).toBe('Senha redefinida. Entre com a nova senha.')
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    await wrapper.get('a[href="/login"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('permite solicitar outro link expirado sem redigitar o e-mail', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async url => {
      if (url === '/api/auth/reset-password') return response({
        message: 'Os dados informados são inválidos.', errors: { token: ['Link de redefinição inválido ou expirado.'] },
      }, 422)
      if (url === '/api/auth/forgot-password') return response({ message: 'Solicitação recebida.' }, 202)
      throw new Error(`Requisição inesperada: ${url}`)
    })
    const { wrapper, router } = await recoveryAt('/redefinir-senha/expirado?email=ana%40example.com')
    const passwords = wrapper.findAll('input[type="password"]')
    await passwords[0]!.setValue('Senha12345')
    await passwords[1]!.setValue('Senha12345')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Link de redefinição inválido ou expirado.')
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    await wrapper.get('a').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('forgot-password')
    expect((wrapper.get('input[type="email"]').element as HTMLInputElement).value).toBe('ana@example.com')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(fetchMock.mock.calls.at(-1)![0]).toBe('/api/auth/forgot-password')
    expect(wrapper.find('[role="status"]').exists()).toBe(true)
  })
})
