import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PaginationBar from './PaginationBar.vue'

const meta = { current_page: 1, last_page: 12, per_page: 20, total: 231, from: 1, to: 20 }

describe('paginação', () => {
  it('mostra o intervalo e permite ir diretamente para a última página', async () => {
    const wrapper = mount(PaginationBar, { props: { meta } })
    expect(wrapper.get('[role="status"]').text()).toBe('Exibindo 1–20 de 231 registros')
    expect(wrapper.get('[aria-current="page"]').text()).toBe('1')
    expect(wrapper.findAll('button[aria-label]:not([role="combobox"])').map(button => button.text())).toEqual(['1', '2', '3', '4', '5', '12'])
    await wrapper.get('[aria-label="Página 12"]').trigger('click')
    expect(wrapper.emitted('change')).toEqual([[12]])
  })

  it('mantém a página atual e suas vizinhas entre as reticências', () => {
    const wrapper = mount(PaginationBar, { props: { meta: { ...meta, current_page: 7 } } })
    expect(wrapper.findAll('button[aria-label]:not([role="combobox"])').map(button => button.text())).toEqual(['1', '6', '7', '8', '12'])
    expect(wrapper.get('[aria-current="page"]').text()).toBe('7')
    expect(wrapper.findAll('span[aria-hidden="true"]').map(span => span.text())).toEqual(['…', '…'])
  })

  it('desabilita anterior e próxima nos limites e todos os controles durante a consulta', async () => {
    const wrapper = mount(PaginationBar, { props: { meta } })
    const buttons = () => wrapper.findAll('button:not([role="combobox"])')
    expect(buttons()[0]!.attributes('disabled')).toBeDefined()
    await wrapper.setProps({ meta: { ...meta, current_page: 12 } })
    expect(buttons().at(-1)!.attributes('disabled')).toBeDefined()
    await wrapper.setProps({ disabled: true })
    expect(buttons().every(button => button.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.get('select').attributes('disabled')).toBeDefined()
    await wrapper.get('[aria-label="Página 1"]').trigger('click')
    expect(wrapper.emitted('change')).toBeUndefined()
  })

  it('emite a quantidade selecionada preservando o tamanho atual da API', async () => {
    const wrapper = mount(PaginationBar, { props: { meta: { ...meta, per_page: 15 } } })
    expect(wrapper.get('select').element.value).toBe('15')
    await wrapper.get('select').setValue('50')
    expect(wrapper.emitted('update:perPage')).toEqual([[50]])
  })

  it('mostra o estado vazio e volta para uma página válida depois da atualização', async () => {
    const wrapper = mount(PaginationBar, {
      props: { meta: { ...meta, current_page: 3, last_page: 2, total: 40, from: null, to: null }, disabled: true },
    })
    expect(wrapper.emitted('change')).toBeUndefined()
    await wrapper.setProps({ disabled: false })
    expect(wrapper.emitted('change')).toEqual([[2]])
    await wrapper.setProps({ meta: { ...meta, last_page: 1, total: 0, from: null, to: null } })
    expect(wrapper.get('[role="status"]').text()).toBe('Nenhum registro')
    expect(wrapper.findAll('button[aria-label]:not([role="combobox"])')).toHaveLength(1)
  })
})
