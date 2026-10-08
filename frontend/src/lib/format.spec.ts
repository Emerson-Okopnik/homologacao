import { describe, expect, it } from 'vitest'
import { formatBytes, formatDate, stageTone, statusTone } from './format'

describe('stageTone', () => {
  it('usa o tom da etapa enquanto o processo está ativo', () => {
    expect(stageTone('PREPARATION')).toBe('neutral')
    expect(stageTone('EXTERNAL_ANALYSIS')).toBe('info')
    expect(stageTone('CORRECTION')).toBe('warning')
    expect(stageTone('CONNECTION')).toBe('success')
  })

  it('prioriza o status final do processo', () => {
    expect(stageTone('CORRECTION', 'COMPLETED')).toBe('success')
    expect(stageTone('EXECUTION', 'CANCELLED')).toBe('danger')
  })

  it('cai para neutro em etapas desconhecidas', () => {
    expect(stageTone('UNKNOWN')).toBe('neutral')
  })
})

describe('statusTone', () => {
  it('mapeia status conhecidos e usa neutro como padrão', () => {
    expect(statusTone('reprovado')).toBe('danger')
    expect(statusTone('qualquer')).toBe('neutral')
  })
})

describe('formatadores', () => {
  it('trata datas vazias', () => {
    expect(formatDate(null)).toBe(formatDate(undefined))
  })

  it('formata bytes em unidades legíveis', () => {
    expect(formatBytes(2048)).toMatch(/KB/)
  })
})
