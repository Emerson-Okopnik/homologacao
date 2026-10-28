import { createPinia } from 'pinia'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/auth'
import type { Me } from '@/types/api'
import UsersView from './users/UsersView.vue'
import ClientsView from './clients/ClientsView.vue'
import ProjectsView from './projects/ProjectsView.vue'
import DocumentsView from './documents/DocumentsView.vue'
import AuditView from './audit/AuditView.vue'
import ClientRequestsView from './requests/ClientRequestsView.vue'
import CatalogView from './catalog/CatalogView.vue'

enableAutoUnmount(afterEach)
afterEach(() => vi.restoreAllMocks())

function setup() {
  const pinia = createPinia()
  useAuthStore(pinia).setSession({ user: { id: 'user-1' }, tenant: null, permissions: ['projects.view'] } as Me)
  return { plugins: [pinia], stubs: { RouterLink: { template: '<a><slot /></a>' } } }
}

function mockList(record: Record<string, unknown>) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => {
    const request = new URL(String(url), 'http://localhost')
    const page = Number(request.searchParams.get('page') ?? 1)
    const size = Number(request.searchParams.get('per_page') ?? 20)
    return new Response(JSON.stringify({
      data: [record],
      meta: { current_page: page, last_page: Math.ceil(123 / size), per_page: size, total: 123, from: (page - 1) * size + 1, to: Math.min(page * size, 123) },
    }))
  })
}

const record = {
  id: 'item-1', name: 'Registro teste', type: 'PF', document: '52998224725',
  code: 'SOL-001', created_at: null, last_login_at: null, active: true,
  status: { value: 'SUBMITTED', label: 'Enviada' }, declared_powers: { modules_kwp: 5 },
  client_progress: { sent: 0, required: 1 }, review_status: 'pendente',
}

describe('paginação das listagens', () => {
  it.each([
    { name: 'clientes', component: ClientsView, endpoint: '/api/clients' },
    { name: 'usuários', component: UsersView, endpoint: '/api/users' },
    { name: 'projetos', component: ProjectsView, endpoint: '/api/projects' },
    { name: 'documentos', component: DocumentsView, endpoint: '/api/documents' },
    { name: 'auditoria', component: AuditView, endpoint: '/api/audit-logs' },
    { name: 'solicitações', component: ClientRequestsView, endpoint: '/api/client-requests' },
  ])('navega em $name e volta à primeira página ao alterar a quantidade', async ({ component, endpoint }) => {
    const fetchMock = mockList(record)
    const wrapper = mount(component, { global: setup() })
    await flushPromises()
    const lastRequest = () => new URL(String(fetchMock.mock.calls.at(-1)![0]), 'http://localhost')
    await wrapper.get('[aria-label="Página 3"]').trigger('click')
    await flushPromises()
    expect(lastRequest().pathname).toBe(endpoint)
    expect(lastRequest().searchParams.get('page')).toBe('3')
    await wrapper.get('[aria-label="Registros por página"]').trigger('click')
    await wrapper.findAll('[role="option"]').find(option => option.text() === '50')!.trigger('click')
    await flushPromises()
    expect(lastRequest().searchParams.get('page')).toBe('1')
    expect(lastRequest().searchParams.get('per_page')).toBe('50')
    expect(wrapper.get('[aria-current="page"]').text()).toBe('1')
  })

  it('reinicia a busca de usuários na primeira página depois do debounce', async () => {
    const fetchMock = mockList(record)
    const wrapper = mount(UsersView, { global: setup() })
    await flushPromises()
    await wrapper.get('[aria-label="Página 3"]').trigger('click')
    await flushPromises()
    await wrapper.get('input[type="search"]').setValue('Maria')
    await new Promise(resolve => setTimeout(resolve, 350))
    await flushPromises()
    const request = new URL(String(fetchMock.mock.calls.at(-1)![0]), 'http://localhost')
    expect(request.searchParams.get('search')).toBe('Maria')
    expect(request.searchParams.get('page')).toBe('1')
  })

  it('pagina responsáveis e equipamentos e reinicia a página ao trocar de aba', async () => {
    const fetchMock = mockList({ ...record, type: 'module', manufacturer: 'Fabricante', model: 'M550' })
    const wrapper = mount(CatalogView, { global: setup() })
    await flushPromises()
    await wrapper.get('[aria-label="Página 3"]').trigger('click')
    await flushPromises()
    expect(String(fetchMock.mock.calls.at(-1)![0])).toContain('/technical-responsibles?page=3')
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === 'Equipamentos')!.trigger('click')
    await flushPromises()
    const request = new URL(String(fetchMock.mock.calls.at(-1)![0]), 'http://localhost')
    expect(request.pathname).toBe('/api/equipment')
    expect(request.searchParams.get('page')).toBe('1')
    await wrapper.get('[aria-label="Página 2"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[aria-current="page"]').text()).toBe('2')
  })
})
