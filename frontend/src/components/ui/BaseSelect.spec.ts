import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import BaseSelect from './BaseSelect.vue'
import SelectField from './SelectField.vue'

enableAutoUnmount(afterEach)
const options = [
  { value: 'draft', label: 'Rascunho' },
  { value: 'locked', label: 'Indisponível', disabled: true },
  { value: 'analysis', label: 'Em análise' },
  { value: 'approved', label: 'Parecer aprovado' },
]

describe('BaseSelect', () => {
  it('abre o menu, destaca o valor atual e seleciona uma opção', async () => {
    const wrapper = mount(BaseSelect, { props: { modelValue: 'draft', options, ariaLabel: 'Situação' } })
    const trigger = wrapper.get('[role="combobox"]')
    expect(trigger.text()).toBe('Rascunho')
    expect(trigger.attributes('aria-expanded')).toBe('false')
    await trigger.trigger('click')
    expect(trigger.attributes('aria-controls')).toBe(wrapper.get('[role="listbox"]').attributes('id'))
    expect(wrapper.get('[aria-selected="true"]').text()).toBe('Rascunho')
    await wrapper.findAll('[role="option"]')[2]!.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([['analysis']])
    expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
  })

  it('navega por teclado ignorando opções desabilitadas e confirma com Enter', async () => {
    const wrapper = mount(BaseSelect, { props: { modelValue: 'draft', options } })
    const trigger = wrapper.get('[role="combobox"]')
    await trigger.trigger('keydown', { key: 'ArrowDown' })
    await trigger.trigger('keydown', { key: 'ArrowDown' })
    const active = wrapper.findAll('[role="option"]')[2]!
    expect(trigger.attributes('aria-activedescendant')).toBe(active.attributes('id'))
    await trigger.trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')).toEqual([['analysis']])
    await trigger.trigger('keydown', { key: 'End' })
    await trigger.trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['approved'])
  })

  it('localiza opções pela digitação, cancela com Escape e fecha com Tab', async () => {
    const wrapper = mount(BaseSelect, { props: { modelValue: 'draft', options } })
    const trigger = wrapper.get('[role="combobox"]')
    await trigger.trigger('keydown', { key: 'p' })
    expect(trigger.attributes('aria-activedescendant')).toBe(wrapper.findAll('[role="option"]')[3]!.attributes('id'))
    await trigger.trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await trigger.trigger('click')
    await trigger.trigger('keydown', { key: 'Tab' })
    expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
  })

  it('fecha ao clicar fora e não seleciona opções desabilitadas', async () => {
    const wrapper = mount(BaseSelect, { attachTo: document.body, props: { modelValue: 'draft', options } })
    await wrapper.get('[role="combobox"]').trigger('click')
    await wrapper.get('[aria-disabled="true"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
    await wrapper.setProps({ disabled: true })
    await wrapper.get('[role="combobox"]').trigger('click')
    expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
    expect(wrapper.get('select').attributes('disabled')).toBeDefined()
  })

  it('mantém valores numéricos na seleção usada pela paginação', async () => {
    const wrapper = mount(BaseSelect, { props: { modelValue: 20, options: [{ value: 20, label: '20' }, { value: 50, label: '50' }] } })
    await wrapper.get('[role="combobox"]').trigger('click')
    await wrapper.findAll('[role="option"]')[1]!.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([[50]])
  })

  it('oferece placeholder selecionável e informa quando não há opções', async () => {
    const wrapper = mount(BaseSelect, { props: { modelValue: '', options: [] as typeof options, placeholder: 'Todas as situações' } })
    expect(wrapper.get('[role="combobox"]').text()).toBe('Todas as situações')
    await wrapper.get('[role="combobox"]').trigger('click')
    expect(wrapper.findAll('[role="option"]')).toHaveLength(1)
    await wrapper.setProps({ placeholder: undefined })
    expect(wrapper.get('[role="listbox"]').text()).toBe('Nenhuma opção disponível')
  })
})

describe('SelectField', () => {
  it('associa rótulo, obrigatoriedade, erro e ajuda ao controle compartilhado', async () => {
    const wrapper = mount(SelectField, { props: { modelValue: '', options, label: 'Situação', required: true, hint: 'Selecione a etapa', error: 'Campo obrigatório' } })
    const trigger = wrapper.get('[role="combobox"]')
    expect(trigger.attributes('id')).toBe(wrapper.get('label').attributes('for'))
    expect(trigger.attributes('aria-labelledby')).toBe(wrapper.get('label').attributes('id'))
    expect(trigger.attributes('aria-required')).toBe('true')
    expect(trigger.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('p').attributes('id')).toBe(trigger.attributes('aria-describedby'))
    expect(wrapper.get('p').text()).toBe('Campo obrigatório')
    await wrapper.setProps({ error: undefined })
    expect(wrapper.get('p').text()).toBe('Selecione a etapa')
  })
})
