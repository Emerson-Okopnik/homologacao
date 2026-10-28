import { computed, readonly, ref } from 'vue'

export type ThemePreference = 'light' | 'dark' | 'system'
const storageKey = 'verigrid.theme'
const preference = ref<ThemePreference>('system')
const resolved = ref<'light' | 'dark'>('light')
let media: MediaQueryList | undefined
let dispose: (() => void) | undefined

function readPreference(): ThemePreference {
  try {
    const saved = localStorage.getItem(storageKey)
    if (saved === 'light' || saved === 'dark') return saved
  } catch { /* A aparência continua disponível quando o armazenamento está bloqueado. */ }
  return 'system'
}

function applyTheme() {
  resolved.value = preference.value === 'system' ? (media?.matches ? 'dark' : 'light') : preference.value
  const root = document.documentElement
  root.dataset.theme = resolved.value
  root.style.colorScheme = resolved.value
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', resolved.value === 'dark' ? '#0d1523' : '#f5f8fb')
}

export function initializeTheme() {
  if (dispose) return dispose
  media = window.matchMedia?.('(prefers-color-scheme: dark)')
  preference.value = readPreference()
  applyTheme()
  const onSystemChange = () => { if (preference.value === 'system') applyTheme() }
  const onStorage = (event: StorageEvent) => {
    if (event.key === storageKey || event.key === null) {
      preference.value = readPreference()
      applyTheme()
    }
  }
  media?.addEventListener('change', onSystemChange)
  window.addEventListener('storage', onStorage)
  dispose = () => {
    media?.removeEventListener('change', onSystemChange)
    window.removeEventListener('storage', onStorage)
    dispose = undefined
  }
  return dispose
}

export function useTheme() {
  initializeTheme()
  function setTheme(value: ThemePreference) {
    preference.value = value
    try {
      if (value === 'system') localStorage.removeItem(storageKey)
      else localStorage.setItem(storageKey, value)
    } catch { /* A seleção permanece ativa nesta sessão. */ }
    applyTheme()
  }
  return {
    preference: readonly(preference),
    isDark: computed(() => resolved.value === 'dark'),
    setTheme,
    toggleTheme: () => setTheme(resolved.value === 'dark' ? 'light' : 'dark'),
  }
}
