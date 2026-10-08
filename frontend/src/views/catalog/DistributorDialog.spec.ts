import { createPinia } from 'pinia'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { useAuthStore } from '@/stores/auth'
import type { Distributor, PermissionKey } from '@/types/api'
import CatalogView from './CatalogView.vue'
import DistributorDialog from './DistributorDialog.vue'

enableAutoUnmount(afterEach)

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token'
})

afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

const dialogStub = {
  props: ['title', 'submitting'],
  emits: ['submit', 'close'],
  template: '<form @submit.prevent="$emit(\'submit\')"><h2>{{ title }}</h2><slot /><button type="submit" :disabled="submitting">Salvar</button></form>',
}

const distributor: Distributor = {
  id: 'distributor-1', code: 'TESTE', name: 'Distribuidora teste', state: null,
  integration_mode: 'assisted', integration_mode_label: 'Fluxo assistido (manual)',
  portal_url: null, has_credential: false, active: true,
}

function response(data: unknown, status = 200) {
  return new Response(JSON.stringify({ data }), { status })
}

function authenticatedPinia(permissions: PermissionKey[]) {
  const pinia = createPinia()
  useAuthStore(pinia).setSession({
    user: { id: 'admin', name: 'Administrador', email: 'admin@example.com', active: true, created_at: null, last_login_at: null },
    tenant: { id: 'tenant-1', name: 'Empresa teste', slug: 'teste' }, permissions,
  })
  return pinia
}

describe('distribuidoras', () => {
  it('abre o cadastro pelo catálogo, salva e atualiza a listagem', async () => {
    let created = false
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
      if (url === '/api/technical-responsibles') return response([])
      if (url === '/api/distributors' && init?.method === 'POST') {
        created = true
        return response(distributor, 201)
      }
      if (url === '/api/distributors') return response(created ? [distributor] : [])
      throw new Error(`Requisição inesperada: ${url}`)
    })
    const wrapper = mount(CatalogView, {
      global: { plugins: [authenticatedPinia(['projects.view', 'integrations.configure'])], stubs: { BaseDialog: dialogStub } },
    })
    await flushPromises()
    await wrapper.findAll('[role="tab"]').find((tab) => tab.text() === 'Distribuidoras')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Nenhuma distribuidora cadastrada.')
    await wrapper.findAll('button').find((button) => button.text() === 'Nova distribuidora')!.trigger('click')
    for (const [label, value] of [['Nome da distribuidora', distributor.name], ['Código', distributor.code]]) {
      const component = wrapper.findAllComponents(FormField).find((field) => field.props('label') === label)!
      await component.get('input').setValue(value)
    }

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const request = fetchMock.mock.calls.find(([url, init]) => url === '/api/distributors' && init?.method === 'POST')
    expect(request).toBeDefined()
    expect(JSON.parse(String(request![1]?.body))).toMatchObject({
      name: distributor.name, code: distributor.code, state: null, integration_mode: 'assisted', active: true,
    })
    expect(wrapper.findComponent(DistributorDialog).exists()).toBe(false)
    expect(wrapper.get('tbody').text()).toContain(distributor.name)
  })

  it('mantém a configuração existente e não sobrescreve credencial em branco', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(distributor))
    const wrapper = mount(DistributorDialog, {
      props: { item: { ...distributor, has_credential: true } },
      global: { stubs: { BaseDialog: dialogStub } },
    })
    await wrapper.getComponent(SelectField).get('select').setValue('api')
    const portal = wrapper.findAllComponents(FormField).find((field) => field.props('label') === 'URL do portal')!
    await portal.get('input').setValue('https://example.com/portal')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledOnce()
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe('/api/distributors/distributor-1')
    expect(init?.method).toBe('PUT')
    expect(JSON.parse(String(init?.body))).toEqual({ integration_mode: 'api', portal_url: 'https://example.com/portal', active: true })
    expect(wrapper.emitted('saved')).toEqual([[]])
  })

  it('oculta o cadastro para usuários sem permissão', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => response([]))
    const wrapper = mount(CatalogView, { global: { plugins: [authenticatedPinia(['projects.view'])] } })
    await flushPromises()
    await wrapper.findAll('[role="tab"]').find((tab) => tab.text() === 'Distribuidoras')!.trigger('click')
    await flushPromises()

    expect(wrapper.findAll('button').some((button) => button.text() === 'Nova distribuidora')).toBe(false)
  })
})
