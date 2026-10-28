import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Role, TechnicalResponsible, User } from '@/types/api'
import ResponsibleDialog from '@/views/catalog/ResponsibleDialog.vue'
import UserFormDialog from './UserFormDialog.vue'
import TenantUserDialog from '@/views/admin/TenantUserDialog.vue'

enableAutoUnmount(afterEach)

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token'
  Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value() { this.open = true } })
})
afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

const staff = { id: 'user-rt', name: 'Leonardo', email: 'leo@example.test' }
const client = { id: 'client-1', name: 'Titular', document: '52998224725' }
const role: Role = { slug: 'cliente', name: 'Cliente', description: null, is_system: true, permissions: ['portal.access'] }
const rt: TechnicalResponsible = { id: 'rt-1', name: 'Leonardo', council: 'CREA', registration: '123', state: 'SC', email: staff.email, phone: null, registration_status: 'regular', active: true, user: null }
const portalUser: User = { id: 'portal-1', name: 'Titular', email: 'holder@example.test', active: true, created_at: null, last_login_at: null, roles: [role], client }
const response = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), { status })
const dialogStub = { emits: ['submit'], template: '<form @submit.prevent="$emit(\'submit\')"><slot /><button type="submit">Salvar</button></form>' }

describe('vínculos de contas', () => {
  it('seleciona e envia a conta do RT e restaura o vínculo ao reabrir o formulário', async () => {
    const saved = { ...rt, user: staff }
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
      if (url === '/api/technical-responsibles/users') return response([staff])
      if (url === '/api/technical-responsibles/rt-1' && init?.method === 'PUT') return response(saved)
      throw new Error(`Requisição inesperada: ${url}`)
    })
    const wrapper = mount(ResponsibleDialog, { props: { item: rt }, global: { stubs: { BaseDialog: dialogStub } } })
    await flushPromises()
    const select = wrapper.get('select').element as HTMLSelectElement
    expect(select.value).toBe('')
    await wrapper.get('select').setValue(staff.id)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const request = fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT')!
    expect(JSON.parse(String(request[1]?.body))).toMatchObject({ user_id: staff.id })
    expect(wrapper.emitted('saved')).toEqual([[saved]])
    wrapper.unmount()
    const reopened = mount(ResponsibleDialog, { props: { item: saved }, global: { stubs: { BaseDialog: dialogStub } } })
    await flushPromises()
    expect((reopened.get('select').element as HTMLSelectElement).value).toBe(staff.id)
  })

  it('envia o titular selecionado ao criar um usuário Cliente', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
      if (url === '/api/users/client-options') return response([client])
      if (url === '/api/users' && init?.method === 'POST') return response(portalUser, 201)
      throw new Error(`Requisição inesperada: ${url}`)
    })
    const wrapper = mount(UserFormDialog, { props: { user: null, roles: [role] } })
    await wrapper.get('input[value="cliente"]').setValue(true)
    await flushPromises()
    await wrapper.get('select').setValue(client.id)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const request = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')!
    expect(JSON.parse(String(request[1]?.body))).toMatchObject({ client_id: client.id, roles: ['cliente'] })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('mantém o titular na edição do usuário e permite corrigir uma conta sem vínculo', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
      if (url === '/api/users/client-options') return response([client])
      if (url === '/api/users/portal-1' && init?.method === 'PATCH') return response(portalUser)
      throw new Error(`Requisição inesperada: ${url}`)
    })
    const wrapper = mount(UserFormDialog, { props: { user: portalUser, roles: [role] } })
    await flushPromises()
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe(client.id)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const request = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')!
    expect(JSON.parse(String(request[1]?.body))).toMatchObject({ client_id: client.id })
    wrapper.unmount()
    const legacy = mount(UserFormDialog, { props: { user: { ...portalUser, client: null }, roles: [role] } })
    await flushPromises()
    expect((legacy.get('select').element as HTMLSelectElement).value).toBe('')
    await legacy.get('select').setValue(client.id)
    await legacy.get('form').trigger('submit')
    await flushPromises()
    expect(legacy.emitted('saved')).toHaveLength(1)
  })

  it('carrega os titulares do tenant selecionado na edição pelo super admin', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
      if (url === '/api/admin/tenants/tenant-2/clients') return response([client])
      if (url === '/api/admin/tenants/tenant-2/users/portal-1' && init?.method === 'PATCH') return response(portalUser)
      throw new Error(`Requisição inesperada: ${url}`)
    })
    const wrapper = mount(TenantUserDialog, { props: { tenantId: 'tenant-2', user: portalUser, roles: [role], selfId: 'admin' }, global: { stubs: { BaseDialog: dialogStub } } })
    await flushPromises()
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe(client.id)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const request = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')!
    expect(JSON.parse(String(request[1]?.body))).toMatchObject({ client_id: client.id, roles: ['cliente'] })
  })
})
