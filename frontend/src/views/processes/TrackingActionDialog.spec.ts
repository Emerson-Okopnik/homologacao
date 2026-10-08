import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import TrackingActionDialog from './TrackingActionDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import type { HomologationProcess, ProcessDocument } from '@/types/api'
import type { Tracking, Submission } from '@/types/homologation'
enableAutoUnmount(afterEach)
beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test'
})
afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})
const process = { id: 'proc-1', status: 'pronto_para_envio', protocol_number: null, priority: 'normal' } as HomologationProcess
const tracking: Tracking = {
  external: null,
  submissions: [],
  events: [],
  pending_items: [],
  budgets: [],
  inspections: [],
  assignments: [],
  stage_history: [],
}
const stub = {
  props: ['submitting'],
  emits: ['submit'],
  template: '<form @submit.prevent="$emit(\'submit\')"><slot/><button type="submit">Salvar</button></form>',
}
describe('envio assistido', () => {
  it('mantém a mesma chave de idempotência ao tentar novamente após uma falha', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'Portal indisponível' }), { status: 503 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: 'submission-1' } }), { status: 201 }))
    const wrapper = mount(TrackingActionDialog, {
      props: { process, tracking, documents: [], action: 'prepare' },
      global: { stubs: { BaseDialog: stub } },
    })
    await wrapper.findComponent(TextareaField).get('textarea').setValue('Dossiê revisado')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('saved')).toBeUndefined()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const first = JSON.parse(String(fetchMock.mock.calls[0]![1]?.body)),
      second = JSON.parse(String(fetchMock.mock.calls[1]![1]?.body))
    expect(first.idempotency_key).toBeTruthy()
    expect(second).toEqual(first)
    expect(first.kind).toBe('initial')
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })
  it('confirma a submissão selecionada usando protocolo e comprovante revisado', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: { status: 'sent' } })))
    const submission = { id: 'submission-3', version: 3, change_reason: 'Versão revisada', status: 'prepared' } as Submission
    const receipt = {
      id: 'receipt-1',
      document_type: 'comprovante_envio',
      type_label: 'Comprovante',
      original_name: 'recibo.pdf',
      version: 1,
      review_status: 'aprovado',
    } as ProcessDocument
    const wrapper = mount(TrackingActionDialog, {
      props: { process, tracking, documents: [receipt], action: 'confirm', submission },
      global: { stubs: { BaseDialog: stub } },
    })
    const fields = wrapper.findAllComponents(FormField)
    await fields
      .find((f) => f.props('label') === 'Número do protocolo')!
      .get('input')
      .setValue('PROTO-2026')
    await fields
      .find((f) => f.props('label') === 'Número ou identificação do comprovante')!
      .get('input')
      .setValue('REC-001')
    await wrapper.findComponent(SelectField).get('select').setValue('receipt-1')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(fetchMock.mock.calls[0]![0]).toBe('/api/processes/proc-1/submissions/submission-3/confirm')
    expect(JSON.parse(String(fetchMock.mock.calls[0]![1]?.body))).toEqual({
      protocol_number: 'PROTO-2026',
      external_receipt: 'REC-001',
      receipt_document_id: 'receipt-1',
    })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })
})
