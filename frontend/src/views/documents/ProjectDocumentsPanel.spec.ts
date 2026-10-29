import { createPinia } from 'pinia'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ProcessDocument } from '@/types/api'
import ProjectDocumentsPanel from './ProjectDocumentsPanel.vue'

enableAutoUnmount(afterEach)
afterEach(() => vi.unstubAllEnvs())

const document: ProcessDocument = {
  id: 'document-1', document_type: 'PROJECT', type_label: 'Projeto', version: 1,
  is_current: true, original_name: 'projeto.pdf', mime_type: 'application/pdf',
  size_bytes: 9, sha256: 'checksum', review_status: 'pendente', review_notes: null,
  uploaded_by: null, reviewed_by: null, reviewed_at: null, created_at: null, owner: null,
}

describe('download de documentos', () => {
  it.each([
    ['', '/api/documents/document-1/download'],
    ['https://api.empresa.com.br/api/', 'https://api.empresa.com.br/api/documents/document-1/download'],
  ])('aponta o link de download para a API configurada em %s', (base, expected) => {
    vi.stubEnv('VITE_API_URL', base)
    const wrapper = mount(ProjectDocumentsPanel, {
      props: { endpoint: '/projects/project-1/documents', documents: [document] },
      global: { plugins: [createPinia()] },
    })

    expect(wrapper.get('a').attributes('href')).toBe(expected)
    expect(wrapper.get('a').text()).toBe('Baixar')
  })
})
