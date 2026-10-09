import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import SelectField from '@/components/ui/SelectField.vue'
import { useAuthStore } from '@/stores/auth'
import type { HomologationProcess, Me, PermissionKey } from '@/types/api'
import ProcessDetailView from './ProcessDetailView.vue'
import ProcessesView from './ProcessesView.vue'

enableAutoUnmount(afterEach)
afterEach(() => vi.restoreAllMocks())

const process = {
  id: 'proc-1', code: 'HML-001', status: 'em_preparacao', status_label: 'Em preparação', stage: 'PREPARATION', stage_label: 'Preparação',
  editable: true, protocol_number: null, due_date: null, open_deadline: null,
  allowed_transitions: [{ value: 'pronto_para_envio', label: 'Pronto para envio', stage_type: 'pronto_para_envio' }],
  assignee: { id: 'user-1', name: 'Operador de homologação' },
  project: { id: 'project-1', code: 'PRJ-001', client: { id: 'client-1', name: 'Cliente teste', document: '52998224725' }, consumer_unit: { number: '123' }, technical_responsible: { name: 'Engenheira do projeto' } },
  checklist: [{ type: 'diagrama_unifilar', label: 'Diagrama unifilar', required: true, document: null }],
  readiness_issues: ['Falta completar o ponto de conexão', 'Falta o diagrama unifilar aprovado'],
  pendencies: [{ id: 'pending-1', title: 'Corrigir potência', status: 'aberta', origin: 'interna' }, { id: 'pending-2', title: 'Documento já corrigido', status: 'resolvida', origin: 'interna' }],
  interactions: [{ id: 'note-1', type: 'nota', description: 'Nota interna da equipe', occurred_at: '2026-10-08T12:00:00Z' }],
  history: [], phase_checklist: { items: [], satisfied: 0, total: 0, blocking: [] },
} as unknown as HomologationProcess

async function setup(path: string, permissions: PermissionKey[]) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/processos/:id?', name: 'processes', component: { template: '<div />' } }, { path: '/kanban', name: 'kanban', component: { template: '<div />' } }, { path: '/:pathMatch(.*)*', component: { template: '<div />' } }] })
  await router.push(path)
  const pinia = createPinia()
  useAuthStore(pinia).setSession({ user: { id: 'user-1' }, tenant: null, permissions } as Me)
  return { plugins: [pinia, router], stubs: { ProjectDocumentsPanel: true, BaseDialog: { template: '<div role="dialog"><slot /></div>' } } }
}

function mockDetail(record = process) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => {
    const path = String(url).split('?')[0]
    const data = path?.endsWith('/tracking')
      ? { external: null, submissions: [], events: [], pending_items: [], budgets: [], inspections: [], assignments: [], stage_history: [] }
      : path?.endsWith('/technical-data') ? { documents: [] }
      : path === '/api/process-stages' ? { stages: [], network_work: [], connection_events: [] }
      : record
    return new Response(JSON.stringify({ data }))
  })
}

describe('experiência do processo', () => {
  it('agrupa o kanban nas seis fases do Dashboard, incluindo situações internas e etapas personalizadas', async () => {
    const stages = [
      { value: 'PREPARATION', label: 'Preparação' },
      { value: 'EXTERNAL_ANALYSIS', label: 'Análise da distribuidora' },
      { value: 'CORRECTION', label: 'Correção' },
      { value: 'EXECUTION', label: 'Execução' },
      { value: 'INSPECTION', label: 'Vistoria' },
      { value: 'CONNECTION', label: 'Conexão' },
    ]
    const records = [
      { id: 'draft', code: 'HML-RASCUNHO', stage: 'PREPARATION', status: 'rascunho' },
      { id: 'preparing', code: 'HML-REVISAO', stage: 'PREPARATION', status: 'em_preparacao', stage_code: 'revisao_tecnica' },
      { id: 'ready', code: 'HML-PRONTO', stage: 'PREPARATION', status: 'pronto_para_envio' },
      { id: 'sent', code: 'HML-ENVIADO', stage: 'EXTERNAL_ANALYSIS', status: 'enviado' },
      { id: 'analysis', code: 'HML-ANALISE', stage: 'EXTERNAL_ANALYSIS', status: 'em_analise' },
      { id: 'correction', code: 'HML-CORRECAO', stage: 'CORRECTION', status: 'pendencia_distribuidora' },
      { id: 'execution', code: 'HML-EXECUCAO', stage: 'EXECUTION', status: 'aprovado' },
      { id: 'inspection', code: 'HML-VISTORIA', stage: 'INSPECTION', status: 'vistoria_solicitada' },
      { id: 'connection', code: 'HML-CONEXAO', stage: 'CONNECTION', status: 'vistoria_solicitada' },
    ].map(record => ({ ...process, ...record }))
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => {
      const request = new URL(String(url), 'http://localhost')
      if (request.pathname === '/api/process-stages') return new Response(JSON.stringify({ data: { stages, network_work: [], connection_events: [] } }))
      if (request.pathname === '/api/processes') {
        expect(request.searchParams.get('board')).toBe('1')
        return new Response(JSON.stringify({ data: records }))
      }
      throw new Error(`Consulta inesperada: ${request.pathname}`)
    })
    const wrapper = mount(ProcessesView, { global: await setup('/kanban', ['homologations.view']) })
    await flushPromises()
    const columns = wrapper.findAll('section[aria-labelledby]')
    expect(columns.map(column => column.get('h2').text())).toEqual(stages.map(stage => stage.label))
    expect(columns.map(column => column.findAll('li a').length)).toEqual([3, 2, 1, 1, 1, 1])
    expect(columns[0]!.text()).toContain('HML-REVISAO')
    expect(columns[5]!.text()).toContain('HML-CONEXAO')
    expect(wrapper.findAll('li a')).toHaveLength(records.length)
    expect(wrapper.find('a[href="/processos/connection"]').exists()).toBe(true)
    expect(fetchMock.mock.calls.some(([url]) => String(url).startsWith('/api/process-statuses'))).toBe(false)
  })

  it('mostra a próxima ação e só carrega o acompanhamento ao abrir a seção', async () => {
    const fetchMock = mockDetail()
    const wrapper = mount(ProcessDetailView, { global: await setup('/processos/proc-1', ['homologations.view', 'projects.manage', 'documents.manage']) })
    await flushPromises()
    expect(wrapper.text()).toContain('Preparar o projeto')
    expect(wrapper.text()).toContain('Engenheira do projeto')
    expect(wrapper.text()).toContain('Operador de homologação')
    expect(wrapper.text()).toContain('Corrigir potência')
    expect(wrapper.text()).not.toContain('Documento já corrigido')
    expect(wrapper.text()).not.toContain('Falta completar o ponto de conexão')
    expect(wrapper.text()).not.toContain('Nota interna da equipe')
    expect(wrapper.text()).not.toContain('Alterar etapa do processo')
    expect(fetchMock).toHaveBeenCalledTimes(1)

    await wrapper.findAll('button').find(b => b.text() === 'Documentos e dados técnicos')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Diagrama unifilar')
    expect(wrapper.text()).toContain('Falta completar o ponto de conexão')
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/tracking'))).toBe(false)

    await wrapper.findAll('button').find(b => b.text() === 'Histórico')!.trigger('click')
    expect(wrapper.text()).toContain('Documento já corrigido')
    expect(wrapper.text()).toContain('Nota interna da equipe')

    await wrapper.findAll('button').find(b => b.text() === 'Distribuidora')!.trigger('click')
    await flushPromises()
    expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/tracking'))).toBe(true)
    expect(wrapper.text()).toContain('Envios e acompanhamento da distribuidora')
    expect(wrapper.text()).not.toContain('Preparar envio')
  })

  it('preserva a alteração de etapa do homologador e não promete conexão ao aprovar o projeto', async () => {
    mockDetail({ ...process, status: 'aprovado', status_label: 'Aprovado', stage: 'EXECUTION' })
    const wrapper = mount(ProcessDetailView, { global: await setup('/processos/proc-1', ['homologations.view', 'homologations.manage']) })
    await flushPromises()
    expect(wrapper.text()).toContain('Preparar a vistoria')
    expect(wrapper.text()).not.toContain('Sistema conectado')
    await wrapper.findAll('button').find(b => b.text() === 'Alterar etapa do processo')!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
  })

  it('pagina os processos e reinicia a página ao mudar os filtros', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => {
      const request = new URL(String(url), 'http://localhost')
      if (request.pathname.endsWith('/process-statuses')) return new Response(JSON.stringify({ data: [{ value: 'em_preparacao', label: 'Em preparação', stage_type: 'em_preparacao' }, { value: 'conectado', label: 'Conectado', stage_type: 'conectado' }] }))
      const page = Number(request.searchParams.get('page'))
      return new Response(JSON.stringify({ data: [process], meta: { current_page: page, last_page: 3, total: 45, per_page: 20, from: (page - 1) * 20 + 1, to: page * 20 } }))
    })
    const wrapper = mount(ProcessesView, { global: await setup('/processos', ['homologations.view']) })
    await flushPromises()
    const requests = () => fetchMock.mock.calls.map(([url]) => new URL(String(url), 'http://localhost')).filter(url => url.pathname === '/api/processes')
    expect(requests().at(-1)?.searchParams.get('scope')).toBe('active')
    expect(wrapper.text()).toContain('Próximo passo')
    await wrapper.findAll('button').find(b => b.text() === 'Próxima página')!.trigger('click')
    await flushPromises()
    expect(requests().at(-1)?.searchParams.get('page')).toBe('2')
    await wrapper.findAllComponents(SelectField).find(c => c.props('label') === 'Exibir')!.get('select').setValue('closed')
    await flushPromises()
    expect(requests().at(-1)?.searchParams.get('page')).toBe('1')
    expect(requests().at(-1)?.searchParams.get('scope')).toBe('closed')
  })
})
