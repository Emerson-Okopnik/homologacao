import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ProcessDocument } from '@/types/api'
import ReviewDialog from './ReviewDialog.vue'

enableAutoUnmount(afterEach)

const objectUrlDescriptor = Object.getOwnPropertyDescriptor(URL, 'createObjectURL')
const revokeDescriptor = Object.getOwnPropertyDescriptor(URL, 'revokeObjectURL')
const documentFixture = {
  id: 'document-1', type_label: 'Diagrama unifilar', original_name: 'diagrama.pdf', version: 2,
  mime_type: 'application/pdf', size_bytes: 1024,
} as ProcessDocument
const dialogStub = { emits: ['submit', 'close'], template: '<form @submit.prevent="$emit(\'submit\')"><slot /><button type="submit">Registrar revisão</button></form>' }

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token'
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:document-preview') })
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() })
})
afterEach(() => {
  vi.restoreAllMocks()
  if (objectUrlDescriptor) Object.defineProperty(URL, 'createObjectURL', objectUrlDescriptor)
  else Reflect.deleteProperty(URL, 'createObjectURL')
  if (revokeDescriptor) Object.defineProperty(URL, 'revokeObjectURL', revokeDescriptor)
  else Reflect.deleteProperty(URL, 'revokeObjectURL')
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('prévia na revisão documental', () => {
  it('carrega o PDF autenticado e libera o objeto ao fechar', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('%PDF-1.4 test', { headers: { 'Content-Type': 'application/pdf' } }))
    const wrapper = mount(ReviewDialog, { props: { document: documentFixture }, global: { stubs: { BaseDialog: dialogStub } } })
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledWith('/api/documents/document-1/download', expect.objectContaining({ credentials: 'include', signal: expect.any(AbortSignal) }))
    expect(wrapper.get('iframe').attributes('src')).toBe('blob:document-preview')
    expect(wrapper.get('iframe').attributes('title')).toBe('Prévia de diagrama.pdf')
    expect(URL.createObjectURL).toHaveBeenCalledOnce()
    wrapper.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:document-preview')
  })

  it('renderiza imagem autenticada como imagem, sem iframe', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('png-data', { headers: { 'Content-Type': 'image/png' } }))
    const wrapper = mount(ReviewDialog, { props: { document: { ...documentFixture, original_name: 'medidor.png', mime_type: 'image/png' } }, global: { stubs: { BaseDialog: dialogStub } } })
    await flushPromises()
    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.get('img').attributes('src')).toBe('blob:document-preview')
    expect(wrapper.get('img').attributes('alt')).toBe('Prévia de medidor.png')
  })

  it('mantém o link do arquivo e permite registrar revisão quando a prévia falha', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (_url, init) => {
      if (init?.method === 'POST') return new Response(JSON.stringify({ data: {} }))
      return new Response('Unavailable', { status: 503 })
    })
    const wrapper = mount(ReviewDialog, { props: { document: documentFixture }, global: { stubs: { BaseDialog: dialogStub } } })
    await flushPromises()
    expect(wrapper.text()).toContain('Não foi possível carregar a prévia')
    expect(wrapper.get('a').attributes('href')).toBe('/api/documents/document-1/download')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const reviewCall = fetchMock.mock.calls.find(([url]) => url === '/api/documents/document-1/review')
    expect(JSON.parse(String(reviewCall?.[1]?.body))).toEqual({ review_status: 'aprovado', review_notes: null })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })
})

