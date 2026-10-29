import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { initializeTheme, useTheme } from './theme'

let dark = false
let changes: EventTarget
let cleanup: (() => void) | undefined
beforeEach(() => {
  localStorage.clear()
  dark = false
  changes = new EventTarget()
  vi.stubGlobal('matchMedia', () => ({
    get matches() { return dark },
    addEventListener: changes.addEventListener.bind(changes),
    removeEventListener: changes.removeEventListener.bind(changes),
  }))
})
afterEach(() => {
  cleanup?.()
  cleanup = undefined
  localStorage.clear()
  delete document.documentElement.dataset.theme
  document.documentElement.style.colorScheme = ''
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('tema', () => {
  it('acompanha a preferência do sistema antes da escolha explícita', () => {
    cleanup = initializeTheme()
    expect(document.documentElement.dataset.theme).toBe('light')
    dark = true
    changes.dispatchEvent(new Event('change'))
    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(document.documentElement.style.colorScheme).toBe('dark')
  })

  it('restaura e salva a escolha, mantendo-a independente do sistema', () => {
    localStorage.setItem('verigrid.theme', 'dark')
    cleanup = initializeTheme()
    const theme = useTheme()
    expect(theme.isDark.value).toBe(true)
    changes.dispatchEvent(new Event('change'))
    expect(theme.isDark.value).toBe(true)
    theme.toggleTheme()
    expect(localStorage.getItem('verigrid.theme')).toBe('light')
    expect(theme.isDark.value).toBe(false)
    theme.setTheme('system')
    expect(localStorage.getItem('verigrid.theme')).toBeNull()
  })

  it('sincroniza a escolha de outra aba', () => {
    cleanup = initializeTheme()
    localStorage.setItem('verigrid.theme', 'dark')
    window.dispatchEvent(new StorageEvent('storage', { key: 'verigrid.theme' }))
    expect(document.documentElement.dataset.theme).toBe('dark')
  })

  it('permite alternar quando o navegador bloqueia o armazenamento', () => {
    cleanup = initializeTheme()
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('Bloqueado') })
    expect(() => useTheme().toggleTheme()).not.toThrow()
    expect(document.documentElement.dataset.theme).toBe('dark')
  })
})
