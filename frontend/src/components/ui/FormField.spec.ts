import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormField from './FormField.vue'

describe('FormField', () => {
  it.each(['text', 'number'])('mantém o valor textual ao digitar em um campo %s', async (type) => {
    const wrapper = mount(FormField, { props: { label: 'Potência', type, modelValue: '' } })

    await wrapper.get('input').setValue('550.5')

    expect(wrapper.emitted('update:modelValue')).toEqual([['550.5']])
    wrapper.unmount()
  })

  it('permite limpar um campo numérico opcional', async () => {
    const wrapper = mount(FormField, { props: { label: 'Eficiência', type: 'number', modelValue: '21.5' } })

    await wrapper.get('input').setValue('')

    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    wrapper.unmount()
  })
})
