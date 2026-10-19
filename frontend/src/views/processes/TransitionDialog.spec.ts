import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { HomologationProcess } from '@/types/api'
import TransitionDialog from './TransitionDialog.vue'

enableAutoUnmount(afterEach)
beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token'
  Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value() { this.open = true } })
})
afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})
const base = { id: 'process-1', status: 'em_preparacao', status_label: 'Em preparação',
  allowed_transitions: [{ value: 'preparado_envio', label: 'Preparado para o envio', stage_type: 'pronto_para_envio', available: false, reasons: ['Conta de energia não aprovada.'] },
    { value: 'rascunho', label: 'Rascunho', available: true, reasons: [] }],
  readiness_issues: ['Complete os dados de conexão.', 'Conta de energia não aprovada.'],
} as HomologationProcess

describe('preparação para envio', () => {
  it('bloqueia a confirmação e informa as pendências inclusive para uma etapa personalizada', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
    const wrapper = mount(TransitionDialog, { props: { process: base } })
    expect(wrapper.get('option[value="preparado_envio"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Complete os dados de conexão.')
    expect(wrapper.text()).toContain('Conta de energia não aprovada.')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(fetchMock).not.toHaveBeenCalled()
    await wrapper.get('select').setValue('rascunho')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('libera a confirmação após atualizar os requisitos e envia a transição permitida', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: {} })))
    const wrapper = mount(TransitionDialog, { props: { process: base } })
    await wrapper.setProps({ process: { ...base, readiness_issues: [], allowed_transitions: [{ value: 'preparado_envio', label: 'Preparado para o envio', stage_type: 'pronto_para_envio', available: true, reasons: [] }] } })
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('option[value="preparado_envio"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/processes/process-1/transitions')
    expect(JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body))).toMatchObject({ status: 'preparado_envio' })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('também bloqueia com readiness_issues quando a resposta não possui metadados da transição', async () => {
    const wrapper = mount(TransitionDialog, { props: { process: { ...base, allowed_transitions: [{ value: 'pronto_para_envio', label: 'Pronto para envio' }] } } })
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
  })
})
