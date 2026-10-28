import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { useAuthStore } from '@/stores/auth'
import type { Me, PermissionKey } from '@/types/api'
import type { WorkflowConfiguration } from '@/types/homologation'
import SettingsView from './SettingsView.vue'
import WorkflowConfigurationDialog from './WorkflowConfigurationDialog.vue'
import WorkflowConfigurationPanel from './WorkflowConfigurationPanel.vue'

enableAutoUnmount(afterEach)
beforeEach(() => { document.cookie = 'XSRF-TOKEN=test' })
afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

const phases: WorkflowConfiguration['phases'] = [
  { value: 'PREPARATION', label: 'Preparação' }, { value: 'EXTERNAL_ANALYSIS', label: 'Análise da distribuidora' },
  { value: 'CORRECTION', label: 'Correção' }, { value: 'EXECUTION', label: 'Execução' },
  { value: 'INSPECTION', label: 'Vistoria' }, { value: 'CONNECTION', label: 'Conexão' },
]
const statusTypes: WorkflowConfiguration['status_types'] = [
  { value: 'rascunho', label: 'Rascunho', phase: 'PREPARATION', terminal: false },
  { value: 'em_preparacao', label: 'Em preparação', phase: 'PREPARATION', terminal: false },
  { value: 'enviado', label: 'Enviado', phase: 'EXTERNAL_ANALYSIS', terminal: false },
  { value: 'pendencia_distribuidora', label: 'Pendência da distribuidora', phase: 'CORRECTION', terminal: false },
  { value: 'aprovado', label: 'Parecer aprovado', phase: 'EXECUTION', terminal: false },
  { value: 'vistoria_solicitada', label: 'Vistoria solicitada', phase: 'INSPECTION', terminal: false },
  { value: 'conectado', label: 'Conectado', phase: 'CONNECTION', terminal: true },
  { value: 'cancelado', label: 'Cancelado', phase: null, terminal: true },
]
const configuration: WorkflowConfiguration = {
  phases, status_types: statusTypes, requirements: [], credentials: [],
  stages: statusTypes.map((type, index) => ({ id: `stage-${index}`, code: type.value, name: type.label, stage_type: type.value, phase: type.phase, terminal: type.terminal, order: index, next: type.value === 'rascunho' ? ['em_preparacao', 'cancelado'] : [], active: true })),
}
const dialogStub = { emits: ['submit'], template: '<form role="dialog" @submit.prevent="$emit(\'submit\')"><slot /><button type="submit">Salvar</button></form>' }

async function setup(permissions: PermissionKey[] = ['workflow.configure']) {
  const pinia = createPinia()
  useAuthStore(pinia).setSession({ user: { id: 'user-1' }, permissions, tenant: null } as Me)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }] })
  await router.push('/configuracoes')
  return { plugins: [pinia, router], stubs: { BaseDialog: dialogStub } }
}

describe('configuração das fases', () => {
  it('apresenta as seis fases e remove a lista duplicada das situações antigas', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async url => {
      const endpoint = String(url)
      if (endpoint === '/api/workflow-configuration') return new Response(JSON.stringify({ data: configuration }))
      if (['/api/distributors', '/api/document-types'].includes(endpoint)) return new Response(JSON.stringify({ data: [] }))
      throw new Error(`Consulta inesperada: ${endpoint}`)
    })
    const wrapper = mount(SettingsView, { global: await setup() })
    await flushPromises()
    const groups = wrapper.findAll('details[data-phase]')
    expect(groups.map(group => group.get('h3').text())).toEqual(phases.map(phase => phase.label))
    expect(groups.every(group => !group.element.hasAttribute('open'))).toBe(true)
    expect(wrapper.findAll('h2').filter(h => h.text() === 'Etapas do processo')).toHaveLength(0)
    expect(wrapper.text()).not.toContain('Nova etapa')
    expect(fetchMock.mock.calls.some(([url]) => String(url) === '/api/process-statuses')).toBe(false)
    const preparation = groups[0]!
    expect(preparation.text()).toContain('Rascunho')
    await preparation.findAll('button').find(button => button.text() === 'Adicionar situação')!.trigger('click')
    await flushPromises()
    const dialog = wrapper.findComponent(WorkflowConfigurationDialog)
    expect(dialog.findAllComponents(SelectField).find(field => field.props('label') === 'Fase do processo')!.get('select').element.value).toBe('PREPARATION')
  })

  it('preserva a situação inicial, seu código e as transições ao editar o nome', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: configuration })))
    const initial = configuration.stages[0]!
    const wrapper = mount(WorkflowConfigurationDialog, { props: { kind: 'stage', stage: initial, stages: configuration.stages, phases, statusTypes, distributors: [], documentTypes: [] }, global: await setup() })
    expect(wrapper.findAllComponents(FormField).find(field => field.props('label') === 'Código')!.get('input').attributes('readonly')).toBeDefined()
    expect(wrapper.findAllComponents(SelectField).every(field => field.get('select').attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.get('input[type="checkbox"][disabled]').element).toBeDefined()
    await wrapper.findAllComponents(FormField).find(field => field.props('label') === 'Nome')!.get('input').setValue('Preparação inicial')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(fetchMock.mock.calls[0]![0]).toBe('/api/workflow-stages/stage-0')
    expect(JSON.parse(String(fetchMock.mock.calls[0]![1]?.body))).toEqual({ code: 'rascunho', name: 'Preparação inicial', stage_type: 'rascunho', order: 0, active: true, next: ['em_preparacao', 'cancelado'] })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('cria uma situação na fase escolhida e impede transições em situações de encerramento', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: configuration })))
    const wrapper = mount(WorkflowConfigurationDialog, { props: { kind: 'stage', phase: 'CORRECTION', stages: configuration.stages, phases, statusTypes, distributors: [], documentTypes: [] }, global: await setup() })
    const phaseField = wrapper.findAllComponents(SelectField).find(field => field.props('label') === 'Fase do processo')!
    const natureField = wrapper.findAllComponents(SelectField).find(field => field.props('label') === 'Situação base')!
    expect(natureField.get('select').element.value).toBe('pendencia_distribuidora')
    await wrapper.findAllComponents(FormField).find(field => field.props('label') === 'Nome')!.get('input').setValue('Conexão final')
    await wrapper.findAllComponents(FormField).find(field => field.props('label') === 'Código')!.get('input').setValue('conexao_final')
    await wrapper.get('input[type="checkbox"][value="em_preparacao"]').setValue(true)
    await phaseField.get('select').setValue('CONNECTION')
    await flushPromises()
    expect(natureField.get('select').element.value).toBe('conectado')
    expect(wrapper.find('fieldset').exists()).toBe(false)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(JSON.parse(String(fetchMock.mock.calls[0]![1]?.body))).toMatchObject({ code: 'conexao_final', stage_type: 'conectado', next: [], order: configuration.stages.length })
  })

  it('mantém a consulta das fases sem mostrar controles de configuração a quem não tem permissão', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: configuration })))
    const wrapper = mount(WorkflowConfigurationPanel, { props: { distributors: [], documentTypes: [] }, global: await setup(['projects.view']) })
    await flushPromises()
    expect(wrapper.findAll('details[data-phase]')).toHaveLength(6)
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.text()).toContain('Ver situações')
  })
})
