import { defineComponent, ref } from 'vue'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { useAuthStore } from '@/stores/auth'
import type { HomologationProcess, ProcessDocument, Me } from '@/types/api'
import type { Tracking } from '@/types/homologation'
import ProcessTrackingPanel from './ProcessTrackingPanel.vue'
import TrackingActionDialog from './TrackingActionDialog.vue'

enableAutoUnmount(afterEach)
beforeEach(() => { document.cookie = 'XSRF-TOKEN=test-token' })
afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})
const process = { id: 'process-1', code: 'HOM-001', status: 'vistoria_solicitada', editable: false, project: { id: 'project-1' } } as HomologationProcess
const tracking: Tracking = { external: null, submissions: [{ id: 'submission-1', kind: 'inspection', status: 'sent', version: 1, version_id: 'version-1', change_reason: 'Solicitação de vistoria', request_hash: 'hash', external_receipt: 'REC-001', receipt_document_id: null, submitted_at: '2026-10-12T12:00:00Z' }], events: [], pending_items: [], budgets: [], assignments: [], stage_history: [],
  inspections: [{ id: 'inspection-1', status: 'agendada', requested_at: '2026-10-12T12:00:00Z', scheduled_at: '2026-10-13T12:00:00Z', performed_at: null, connection_approved_at: null, report_document_id: null }],
}
const report = { id: 'report-1', document_type: 'INSPECTION_REPORT', type_label: 'Relatório de vistoria', original_name: 'vistoria.pdf', version: 1, is_current: true, review_status: 'pendente', size_bytes: 100, process_id: 'process-1' } as ProcessDocument
const stub = { emits: ['submit', 'close'], template: '<form @submit.prevent="$emit(\'submit\')"><slot /><button type="submit">Salvar</button></form>' }
const response = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), { status })

describe('relatório da vistoria', () => {
  it('anexa e revisa o relatório junto da vistoria e permite registrar a realização com o projeto congelado', async () => {
    let uploaded = false, approved = false, realized = false
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
      if (url === '/api/processes/process-1/tracking') return response({ ...tracking, inspections: tracking.inspections.map(i => ({ ...i, status: realized ? 'realizada' : 'agendada' })) })
      if (url === '/api/projects/project-1/technical-data') return response({ documents: uploaded ? [{ ...report, review_status: approved ? 'aprovado' : 'pendente' }] : [] })
      if (url === '/api/document-types') return response([{ value: 'INSPECTION_REPORT', label: 'Relatório de vistoria' }])
      if (url === '/api/processes/process-1/documents' && init?.method === 'POST') {
        expect((init.body as FormData).get('document_type')).toBe('INSPECTION_REPORT')
        expect((init.body as FormData).get('file')).toBeInstanceOf(File)
        uploaded = true
        return response(report, 201)
      }
      if (url === '/api/documents/report-1/review' && init?.method === 'POST') {
        expect(JSON.parse(String(init.body)).review_status).toBe('aprovado')
        approved = true
        return response({ ...report, review_status: 'aprovado' })
      }
      if (url === '/api/processes/process-1/inspections/inspection-1' && init?.method === 'PUT') {
        expect(JSON.parse(String(init.body))).toMatchObject({ status: 'realizada', report_document_id: report.id, submission_id: 'submission-1' })
        realized = true
        return response(tracking)
      }
      throw new Error(`Requisição inesperada: ${url}`)
    })
    const pinia = createPinia()
    useAuthStore(pinia).setSession({ user: { id: 'user-1' }, tenant: null, permissions: ['projects.view', 'homologations.manage', 'documents.manage'] } as Me)
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }] })
    await router.push('/')
    const parent = defineComponent({ components: { ProcessTrackingPanel }, setup: () => ({ process, revision: ref(0) }), template: '<ProcessTrackingPanel :process="process" :revision="revision" @saved="revision++" />' })
    const wrapper = mount(parent, { global: { plugins: [pinia, router], stubs: { BaseDialog: stub } } })
    await flushPromises()
    const button = (name: string) => wrapper.findAll('button').find(b => b.text() === name)!
    await button('Anexar relatório').trigger('click')
    await flushPromises()
    const type = wrapper.findAllComponents(SelectField).find(f => f.props('label') === 'Tipo de documento')!
    expect(type.get('select').attributes('disabled')).toBeDefined()
    expect((type.get('select').element as HTMLSelectElement).value).toBe('INSPECTION_REPORT')
    const fileInput = wrapper.get('input[type="file"]')
    Object.defineProperty(fileInput.element, 'files', { value: [new File(['%PDF-1.4'], 'vistoria.pdf', { type: 'application/pdf' })], configurable: true })
    await fileInput.trigger('change')
    await wrapper.findAll('form').at(-1)!.trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Aguardando revisão')
    await wrapper.findAll('form').at(-1)!.trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Relatório aprovado')
    await button('Atualizar').trigger('click')
    await flushPromises()
    const status = wrapper.findAllComponents(SelectField).find(f => f.props('label') === 'Situação da vistoria')!
    await status.get('select').setValue('realizada')
    const date = wrapper.findAllComponents(FormField).find(f => f.props('label') === 'Realizada em')!
    await date.get('input').setValue('2026-10-13T13:00')
    const reportField = wrapper.findAllComponents(SelectField).find(f => f.props('label') === 'Relatório revisado')!
    await reportField.get('select').setValue(report.id)
    await wrapper.findAll('form').at(-1)!.trigger('submit')
    await flushPromises()
    expect(realized).toBe(true)
    expect(wrapper.text()).toContain('realizada')
    expect(fetchMock.mock.calls.some(([url, init]) => url === '/api/processes/process-1/inspections/inspection-1' && init?.method === 'PUT')).toBe(true)
  })

  it.each(['INSPECTION_REPORT', 'relatorio_vistoria'])('seleciona o relatório aprovado %s e exclui versões antigas e não revisadas', async type => {
    const wrapper = mount(TrackingActionDialog, { props: { process, tracking, action: 'inspection', inspectionId: 'inspection-1', documents: [
      { ...report, document_type: type, review_status: 'aprovado' },
      { ...report, id: 'old', document_type: type, review_status: 'aprovado', is_current: false },
      { ...report, id: 'pending', document_type: type, review_status: 'pendente' },
    ] }, global: { stubs: { BaseDialog: stub } } })
    await flushPromises()
    const field = wrapper.findAllComponents(SelectField).find(f => f.props('label') === 'Relatório revisado')!
    expect(field.props('options').map((option: { value: string }) => option.value)).toEqual([report.id])
  })
})
