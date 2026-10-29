import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import SelectField from '@/components/ui/SelectField.vue'
import type { AuditLog } from '@/types/api'
import { auditChanges } from '@/lib/audit'
import AuditView from './AuditView.vue'
import AuditLogItem from './AuditLogItem.vue'

enableAutoUnmount(afterEach)
afterEach(() => vi.restoreAllMocks())
const login: AuditLog = {
  id: 'log-1', event: 'auth.login', actor: { id: 'user-1', name: 'Administrador' }, subject_type: 'User',
  old_values: null, new_values: null, metadata: null, justification: null,
  ip_address: '172.19.0.1', correlation_id: '5a693222-95dd-4431-bfe1-3ae5f46e9c4b', created_at: '2026-10-13T22:43:00Z',
}
function mockLogs(logs = [login]) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async url => {
    const query = new URL(String(url), 'http://localhost').searchParams
    return new Response(JSON.stringify({ data: logs, meta: { total: logs.length, current_page: Number(query.get('page') ?? 1), last_page: 1, per_page: 25, from: 1, to: logs.length } }))
  })
}

describe('auditoria', () => {
  it('mostra o contexto do login e deixa os dados técnicos recolhidos', async () => {
    mockLogs()
    const wrapper = mount(AuditView)
    await flushPromises()
    await wrapper.getComponent(AuditLogItem).get('button').trigger('click')
    expect(wrapper.text()).toContain('Evento de autenticação. Não há alterações de dados associadas.')
    expect(wrapper.text()).toContain('172.19.0.1')
    expect(wrapper.text()).toContain(login.correlation_id)
    expect(wrapper.get('details').attributes('open')).toBeUndefined()
    expect(wrapper.get('pre').classes()).toContain('text-ink')
    expect(wrapper.get('pre').classes()).not.toContain('text-white')
  })

  it('apresenta somente as alterações, com valores legíveis e conteúdo escapado', async () => {
    const update = { ...login, event: 'user.updated', old_values: { name: 'Nome anterior', active: false, email: 'igual@example.com' }, new_values: { name: '<script>alert(1)</script>', active: true, email: 'igual@example.com' }, metadata: { reason: 'Revisão de cadastro' }, justification: 'Ajuste autorizado' }
    mockLogs([update])
    const wrapper = mount(AuditView)
    await flushPromises()
    await wrapper.getComponent(AuditLogItem).get('button').trigger('click')
    expect(auditChanges(update).map(change => change.key)).toEqual(['name', 'active'])
    const changes = wrapper.get('[aria-label="Alterações registradas"]')
    expect(changes.text()).toContain('Nome anterior')
    expect(changes.text()).toContain('Sim')
    expect(changes.text()).toContain('Não')
    expect(changes.text()).toContain('<script>alert(1)</script>')
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.get('[aria-label="Informações adicionais"]').text()).toContain('Revisão de cadastro')
  })

  it('envia filtros exatos ou de grupo e não consulta um período inválido', async () => {
    const fetchMock = mockLogs()
    const wrapper = mount(AuditView)
    await flushPromises()
    const event = wrapper.getComponent(SelectField)
    await event.get('select').setValue('auth.login')
    await flushPromises()
    let query = new URL(String(fetchMock.mock.calls.at(-1)![0]), 'http://localhost').searchParams
    expect(query.get('event_match')).toBe('exact')
    await event.get('select').setValue('auth.')
    await flushPromises()
    query = new URL(String(fetchMock.mock.calls.at(-1)![0]), 'http://localhost').searchParams
    expect(query.get('event_match')).toBe('prefix')
    const dates = wrapper.findAll('input[type="date"]')
    await dates[0]!.setValue('2026-10-20')
    await flushPromises()
    const count = fetchMock.mock.calls.length
    await dates[1]!.setValue('2026-10-10')
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(count)
    expect(wrapper.text()).toContain('A data final deve ser igual ou posterior à data inicial.')
    await wrapper.findAll('button').find(button => button.text() === 'Limpar filtros')!.trigger('click')
    await flushPromises()
    query = new URL(String(fetchMock.mock.calls.at(-1)![0]), 'http://localhost').searchParams
    expect(query.has('from')).toBe(false)
    expect(query.has('to')).toBe(false)
    expect(query.has('event')).toBe(false)
  })
})
