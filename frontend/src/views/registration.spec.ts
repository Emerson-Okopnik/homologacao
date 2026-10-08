import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import EquipmentDialog from './catalog/EquipmentDialog.vue'
import ProjectFormView from './projects/ProjectFormView.vue'

enableAutoUnmount(afterEach)

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token'
})

afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

function response(data: unknown, status = 200) {
  return new Response(JSON.stringify({ data }), { status })
}

describe('cadastro de equipamentos', () => {
  it.each([
    { type: 'module' as const, label: 'Potência (W)', field: 'power_w', value: 550.5 },
    { type: 'battery' as const, label: 'Capacidade (kWh)', field: 'energy_kwh', value: 10.24 },
  ])('envia os valores decimais de $type à API e confirma o cadastro', async ({ type, label, field, value }) => {
    const saved = { id: 'equipment-1', type }
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(saved, 201))
    const wrapper = mount(EquipmentDialog, {
      props: { item: null, defaultType: type },
      global: {
        stubs: {
          BaseDialog: {
            props: ['submitting'],
            emits: ['submit'],
            template: '<form @submit.prevent="$emit(\'submit\')"><slot /><button type="submit" :disabled="submitting">Salvar</button></form>',
          },
        },
      },
    })
    for (const [name, input] of [['Fabricante', 'Fabricante teste'], ['Modelo', 'Modelo teste'], [label, String(value)], ['Eficiência (%)', '21.5']]) {
      const component = wrapper.findAllComponents(FormField).find((candidate) => candidate.props('label') === name)!
      await component.get('input').setValue(input)
    }

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledOnce()
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe('/api/equipment')
    expect(init?.method).toBe('POST')
    expect(JSON.parse(String(init?.body))).toMatchObject({ type, [field]: value, efficiency: 21.5, certification: null })
    expect(wrapper.emitted('saved')).toEqual([[saved]])
  })
})

describe('cadastro de projetos', () => {
  it('calcula as potências do catálogo, envia as quantidades e abre o processo criado', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
      const path = String(url).split('?')[0]
      if (path === '/api/clients') return response([{ id: 'client-1', name: 'Cliente teste', document: null }])
      if (path === '/api/consumer-units') return response([{ id: 'unit-1', number: '123', distributor: { name: 'Distribuidora teste' }, address: { city: 'São Paulo', state: 'SP' } }])
      if (path === '/api/equipment') return response([{ id: 'module-1', type: 'module', manufacturer: 'Teste', model: 'M550', power_w: 550.5 }, { id: 'inverter-1', type: 'inverter', manufacturer: 'Teste', model: 'I5', power_w: 5250 }])
      if (path === '/api/technical-responsibles') return response([])
      if (path === '/api/projects' && init?.method === 'POST') return response({ id: 'project-1', process: { id: 'process-1' } }, 201)
      throw new Error(`Requisição inesperada: ${url}`)
    })
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/projetos/novo', component: ProjectFormView },
        { path: '/processos/:id', component: { template: '<div>Processo criado</div>' } },
        { path: '/projetos', component: { template: '<div />' } },
      ],
    })
    await router.push('/projetos/novo')
    const wrapper = mount(RouterView, { global: { plugins: [createPinia(), router] } })
    await flushPromises()
    const clientField = wrapper.findAllComponents(SelectField).find((candidate) => candidate.props('label') === 'Cliente')!
    await clientField.get('select').setValue('client-1')
    await flushPromises()
    for (const id of ['module-1','inverter-1']) {
      await wrapper.findAll('button').find(button => button.text().includes('Adicionar do catálogo'))!.trigger('click')
      const selects = wrapper.findAllComponents(SelectField).filter(field => String(field.props('label')).startsWith('Equipamento '))
      await selects.at(-1)!.get('select').setValue(id)
    }
    const quantity = wrapper.findAll('input[type="number"]').find(input => input.attributes('aria-labelledby') === 'qty-0')!
    await quantity.setValue('10')
    for (const [label, value] of [['Geração estimada (kWh/mês)', '720.5']]) {
      const component = wrapper.findAllComponents(FormField).find((candidate) => candidate.props('label') === label)!
      await component.get('input').setValue(value)
    }
    expect(wrapper.text()).toContain('Microgeração')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const request = fetchMock.mock.calls.find(([url, init]) => url === '/api/projects' && init?.method === 'POST')
    expect(request).toBeDefined()
    expect(JSON.parse(String(request![1]?.body))).toMatchObject({
      client_id: 'client-1', consumer_unit_id: 'unit-1', project_rt: null,
      equipment: [{ id: 'module-1', quantity: 10 }, { id: 'inverter-1', quantity: 1 }],
      compensation_mode: 'LOCAL_SELF_CONSUMPTION', estimated_generation_kwh_month: 720.5,
    })
    expect(router.currentRoute.value.path).toBe('/processos/process-1')
    expect(wrapper.text()).toBe('Processo criado')
  })
})
