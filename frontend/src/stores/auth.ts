import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api, ApiError } from '@/lib/http'
import type { Me, PermissionKey } from '@/types/api'

type Status = 'unknown' | 'authenticated' | 'guest'

/**
 * As permissões aqui servem apenas para esconder elementos de UI.
 * Toda autorização real é aplicada pelo backend (policies + tenant scope).
 */
export const useAuthStore = defineStore('auth', () => {
  const me = ref<Me | null>(null)
  const status = ref<Status>('unknown')
  let pending: Promise<void> | null = null

  const user = computed(() => me.value?.user ?? null)
  const tenant = computed(() => me.value?.tenant ?? null)
  const permissions = computed(() => new Set(me.value?.permissions ?? []))
  const isSuperAdmin = computed(() => me.value?.is_super_admin === true)

  function can(permission: PermissionKey): boolean {
    return permissions.value.has(permission)
  }

  function setSession(data: Me | null): void {
    me.value = data
    status.value = data ? 'authenticated' : 'guest'
  }

  async function ensureLoaded(): Promise<void> {
    if (status.value !== 'unknown') return
    pending ??= api<{ data: Me }>('/auth/me')
      .then((res) => setSession(res.data))
      .catch((error: unknown) => {
        if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
          setSession(null)
          return
        }
        throw error
      })
      .finally(() => {
        pending = null
      })
    return pending
  }

  async function login(email: string, password: string, remember: boolean): Promise<void> {
    const res = await api<{ data: Me }>('/auth/login', {
      method: 'POST',
      body: { email, password, remember },
    })
    setSession(res.data)
  }

  async function logout(): Promise<void> {
    try {
      await api('/auth/logout', { method: 'POST' })
    } finally {
      setSession(null)
    }
  }

  return { me, status, user, tenant, isSuperAdmin, can, ensureLoaded, login, logout, setSession }
})
