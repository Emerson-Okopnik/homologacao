<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { Building2, Pencil, Plus, ShieldCheck, Trash2, UserPlus } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { api, ApiError, toApiError } from '@/lib/http'
import { useAuthStore } from '@/stores/auth'
import type { Paginated, PermissionDefinition, PermissionKey, Role, Tenant, User } from '@/types/api'
import RoleDialog from './RoleDialog.vue'
import TenantDialog from './TenantDialog.vue'
import TenantUserDialog from './TenantUserDialog.vue'

const auth = useAuthStore()

const tenants = ref<Tenant[]>([])
const tenantSearch = ref('')
const loadingTenants = ref(true)
const selectedId = ref<string | null>(null)
const selected = computed(() => tenants.value.find((t) => t.id === selectedId.value) ?? null)

const permissions = ref<PermissionDefinition[]>([])
const roles = ref<Role[]>([])
const selectedRoleSlug = ref<string | null>(null)
const selectedRole = computed(() => roles.value.find((r) => r.slug === selectedRoleSlug.value) ?? null)
const draft = ref<Set<PermissionKey>>(new Set())
const savingMatrix = ref(false)
const matrixMessage = ref<string | null>(null)

const users = ref<Paginated<User> | null>(null)
const userSearch = ref('')
const userPage = ref(1)

const tab = ref<'roles' | 'users'>('roles')
const error = ref<ApiError | null>(null)

const tenantDialog = ref<{ tenant: Tenant | null } | null>(null)
const roleDialog = ref<{ role: Role | null } | null>(null)
const userDialog = ref<{ user: User | null } | null>(null)

const groups = computed(() => {
  const map = new Map<string, { label: string; items: PermissionDefinition[] }>()
  for (const p of permissions.value) {
    if (!map.has(p.group)) map.set(p.group, { label: p.group_label, items: [] })
    map.get(p.group)!.items.push(p)
  }
  return [...map.entries()].map(([key, value]) => ({ key, ...value }))
})

const dirty = computed(() => {
  const original = new Set(selectedRole.value?.permissions ?? [])
  if (original.size !== draft.value.size) return true
  return [...draft.value].some((k) => !original.has(k))
})

async function loadTenants() {
  loadingTenants.value = true
  try {
    const res = await api<Paginated<Tenant>>('/admin/tenants', {
      query: { search: tenantSearch.value || undefined, per_page: 100 },
    })
    tenants.value = res.data
    if (!selectedId.value || !res.data.some((t) => t.id === selectedId.value)) {
      selectedId.value = res.data.find((t) => t.is_current)?.id ?? res.data[0]?.id ?? null
    }
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    loadingTenants.value = false
  }
}

async function loadRoles(keepSlug?: string) {
  if (!selectedId.value) return
  const res = await api<{ data: Role[] }>(`/admin/tenants/${selectedId.value}/roles`)
  roles.value = res.data
  const slug = keepSlug ?? selectedRoleSlug.value
  selectedRoleSlug.value = res.data.some((r) => r.slug === slug) ? slug! : (res.data[0]?.slug ?? null)
  resetDraft()
}

async function loadUsers() {
  if (!selectedId.value) return
  users.value = await api<Paginated<User>>(`/admin/tenants/${selectedId.value}/users`, {
    query: { search: userSearch.value || undefined, page: userPage.value },
  })
}

function resetDraft() {
  draft.value = new Set(selectedRole.value?.permissions ?? [])
  matrixMessage.value = null
}

function toggle(key: PermissionKey) {
  const next = new Set(draft.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  draft.value = next
}

function toggleGroup(items: PermissionDefinition[]) {
  const allOn = items.every((p) => draft.value.has(p.key))
  const next = new Set(draft.value)
  for (const p of items) {
    if (allOn) next.delete(p.key)
    else next.add(p.key)
  }
  draft.value = next
}

async function saveMatrix() {
  if (!selectedRole.value || !selectedId.value) return
  savingMatrix.value = true
  error.value = null
  try {
    await api(`/admin/tenants/${selectedId.value}/roles/${selectedRole.value.slug}`, {
      method: 'PATCH',
      body: { permissions: [...draft.value] },
    })
    await loadRoles(selectedRole.value.slug)
    matrixMessage.value = 'Permissões salvas.'
    if (selected.value?.is_current) await refreshSession()
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    savingMatrix.value = false
  }
}

async function refreshSession() {
  const res = await api<{ data: typeof auth.me }>('/auth/me')
  auth.setSession(res.data)
}

async function deleteRole(role: Role) {
  if (!selectedId.value || !window.confirm(`Excluir o perfil "${role.name}"?`)) return
  error.value = null
  try {
    await api(`/admin/tenants/${selectedId.value}/roles/${role.slug}`, { method: 'DELETE' })
    await loadRoles()
  } catch (e) {
    error.value = toApiError(e)
  }
}

async function toggleTenantActive(tenant: Tenant) {
  const action = tenant.active ? 'desativar' : 'reativar'
  if (!window.confirm(`Deseja ${action} o tenant "${tenant.name}"?`)) return
  error.value = null
  try {
    await api(`/admin/tenants/${tenant.id}`, { method: 'PATCH', body: { active: !tenant.active } })
    await loadTenants()
  } catch (e) {
    error.value = toApiError(e)
  }
}

async function onTenantSaved(tenant: Tenant) {
  tenantDialog.value = null
  selectedId.value = tenant.id
  await loadTenants()
}

async function onRoleSaved(role: Role) {
  roleDialog.value = null
  await loadRoles(role.slug)
}

async function onUserSaved() {
  userDialog.value = null
  await Promise.all([loadUsers(), loadRoles(), loadTenants()])
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(tenantSearch, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadTenants, 300)
})
watch(userSearch, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    userPage.value = 1
    loadUsers()
  }, 300)
})
watch(selectedId, async () => {
  roles.value = []
  users.value = null
  userPage.value = 1
  userSearch.value = ''
  error.value = null
  try {
    await Promise.all([loadRoles(), loadUsers()])
  } catch (e) {
    error.value = toApiError(e)
  }
})
watch(selectedRoleSlug, resetDraft)

onMounted(async () => {
  try {
    const res = await api<{ data: PermissionDefinition[] }>('/admin/permissions')
    permissions.value = res.data
  } catch (e) {
    error.value = toApiError(e)
  }
  await loadTenants()
})
</script>

<template>
  <div>
    <PageHeader title="Super Admin" description="Gerencie empresas (tenants), perfis de acesso e usuários da plataforma.">
      <template #actions>
        <BaseButton @click="tenantDialog = { tenant: null }">
          <Plus class="size-4" aria-hidden="true" />
          Novo tenant
        </BaseButton>
      </template>
    </PageHeader>

    <InlineAlert v-if="error && error.status !== 422" class="mb-4" :correlation-id="error.correlationId">
      {{ error.message }}
    </InlineAlert>

    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
      <aside class="flex w-full flex-col rounded-2xl border border-line bg-surface lg:sticky lg:top-6 lg:w-80 lg:shrink-0">
        <div class="border-b border-line p-4">
          <SearchInput v-model="tenantSearch" label="Buscar tenant" />
        </div>
        <p v-if="loadingTenants && tenants.length === 0" class="p-4 text-sm text-muted">Carregando…</p>
        <p v-else-if="tenants.length === 0" class="p-4 text-sm text-muted">Nenhum tenant encontrado.</p>
        <ul class="flex max-h-[60dvh] flex-col overflow-y-auto p-2" aria-label="Tenants">
          <li v-for="t in tenants" :key="t.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors"
              :class="t.id === selectedId ? 'bg-primary-soft' : 'hover:bg-canvas'"
              :aria-current="t.id === selectedId ? 'true' : undefined"
              @click="selectedId = t.id"
            >
              <span
                class="flex size-9 shrink-0 items-center justify-center rounded-lg text-xs font-bold"
                :class="t.id === selectedId ? 'bg-primary text-white' : 'bg-canvas text-muted'"
              >
                {{ t.name.slice(0, 2).toUpperCase() }}
              </span>
              <span class="min-w-0 flex-1">
                <span class="flex items-center gap-1.5">
                  <span class="truncate text-sm font-semibold">{{ t.name }}</span>
                  <span v-if="t.is_current" class="rounded bg-primary/10 px-1.5 text-[10px] font-semibold text-primary">ATUAL</span>
                </span>
                <span class="block truncate text-xs text-muted">{{ t.users_count ?? 0 }} usuários · {{ t.slug }}</span>
              </span>
              <span v-if="!t.active" class="size-2 shrink-0 rounded-full bg-danger" aria-label="Inativo" />
            </button>
          </li>
        </ul>
      </aside>

      <section v-if="selected" class="min-w-0 flex-1" :aria-label="`Tenant ${selected.name}`">
        <div class="mb-4 flex flex-col gap-4 rounded-2xl border border-line bg-surface p-5 sm:flex-row sm:items-center">
          <div class="flex min-w-0 flex-1 items-center gap-3">
            <span class="flex size-11 items-center justify-center rounded-xl bg-primary-soft text-primary">
              <Building2 class="size-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <h2 class="truncate text-lg font-bold">{{ selected.name }}</h2>
                <StatusBadge :tone="selected.active ? 'success' : 'danger'">{{ selected.active ? 'Ativo' : 'Inativo' }}</StatusBadge>
              </div>
              <p class="text-xs text-muted">
                {{ selected.slug }} · {{ selected.roles_count ?? roles.length }} perfis · {{ selected.users_count ?? 0 }} usuários
              </p>
            </div>
          </div>
          <div class="flex gap-2">
            <BaseButton variant="secondary" @click="tenantDialog = { tenant: selected }">
              <Pencil class="size-4" aria-hidden="true" />
              Editar
            </BaseButton>
            <BaseButton v-if="!selected.is_current" variant="secondary" @click="toggleTenantActive(selected)">
              {{ selected.active ? 'Desativar' : 'Reativar' }}
            </BaseButton>
          </div>
        </div>

        <div class="mb-4 flex gap-1 rounded-xl bg-canvas p-1" role="tablist">
          <button
            v-for="item in [{ id: 'roles', label: 'Perfis e permissões' }, { id: 'users', label: 'Usuários' }] as const"
            :key="item.id"
            type="button"
            role="tab"
            :aria-selected="tab === item.id"
            class="flex-1 rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
            :class="tab === item.id ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink'"
            @click="tab = item.id"
          >
            {{ item.label }}
          </button>
        </div>

        <div v-if="tab === 'roles'" class="flex flex-col gap-4 xl:flex-row xl:items-start">
          <div class="flex w-full flex-col rounded-2xl border border-line bg-surface xl:w-64 xl:shrink-0">
            <div class="flex items-center justify-between border-b border-line px-4 py-3">
              <h3 class="text-sm font-semibold">Perfis</h3>
              <button
                type="button"
                class="rounded-md p-1 text-primary hover:bg-primary-soft"
                @click="roleDialog = { role: null }"
              >
                <Plus class="size-4" aria-hidden="true" />
                <span class="sr-only">Novo perfil</span>
              </button>
            </div>
            <ul class="flex flex-col p-2">
              <li v-for="role in roles" :key="role.slug" class="group flex items-center">
                <button
                  type="button"
                  class="flex min-w-0 flex-1 flex-col rounded-lg px-3 py-2 text-left"
                  :class="role.slug === selectedRoleSlug ? 'bg-primary-soft' : 'hover:bg-canvas'"
                  @click="selectedRoleSlug = role.slug"
                >
                  <span class="flex items-center gap-1.5 text-sm font-medium">
                    <ShieldCheck v-if="role.is_system" class="size-3.5 text-muted" aria-label="Perfil padrão" />
                    <span class="truncate">{{ role.name }}</span>
                  </span>
                  <span class="text-xs text-muted">
                    {{ role.permissions?.length ?? 0 }} permissões · {{ role.users_count ?? 0 }} usuários
                  </span>
                </button>
              </li>
            </ul>
          </div>

          <div v-if="selectedRole" class="min-w-0 flex-1 rounded-2xl border border-line bg-surface">
            <div class="flex flex-col gap-3 border-b border-line px-5 py-4 sm:flex-row sm:items-center">
              <div class="min-w-0 flex-1">
                <h3 class="font-semibold">{{ selectedRole.name }}</h3>
                <p class="text-xs text-muted">{{ selectedRole.description || 'Sem descrição.' }}</p>
              </div>
              <div class="flex gap-1">
                <BaseButton variant="ghost" @click="roleDialog = { role: selectedRole }">
                  <Pencil class="size-4" aria-hidden="true" />
                  <span class="sr-only sm:not-sr-only">Editar</span>
                </BaseButton>
                <BaseButton v-if="!selectedRole.is_system" variant="ghost" @click="deleteRole(selectedRole)">
                  <Trash2 class="size-4 text-danger" aria-hidden="true" />
                  <span class="sr-only sm:not-sr-only">Excluir</span>
                </BaseButton>
              </div>
            </div>

            <div class="grid gap-4 p-5 md:grid-cols-2">
              <fieldset v-for="group in groups" :key="group.key" class="rounded-xl border border-line p-4">
                <legend class="sr-only">{{ group.label }}</legend>
                <div class="mb-2 flex items-center justify-between">
                  <span class="text-sm font-semibold" aria-hidden="true">{{ group.label }}</span>
                  <button type="button" class="text-xs font-medium text-primary hover:underline" @click="toggleGroup(group.items)">
                    {{ group.items.every((p) => draft.has(p.key)) ? 'Desmarcar todos' : 'Marcar todos' }}
                  </button>
                </div>
                <label
                  v-for="p in group.items"
                  :key="p.key"
                  class="flex cursor-pointer items-center gap-2 rounded-md px-1 py-1.5 text-sm hover:bg-canvas"
                >
                  <input
                    type="checkbox"
                    class="size-4 accent-primary"
                    :checked="draft.has(p.key)"
                    @change="toggle(p.key)"
                  />
                  <span class="flex-1">{{ p.label }}</span>
                  <code class="hidden text-[11px] text-muted sm:inline">{{ p.key }}</code>
                </label>
              </fieldset>
            </div>

            <div class="sticky bottom-0 flex items-center justify-between gap-3 rounded-b-2xl border-t border-line bg-surface px-5 py-3">
              <p class="text-sm" :class="dirty ? 'text-warning' : 'text-muted'" role="status">
                {{ dirty ? 'Alterações não salvas' : (matrixMessage ?? `${draft.size} de ${permissions.length} permissões`) }}
              </p>
              <div class="flex gap-2">
                <BaseButton variant="secondary" :disabled="!dirty" @click="resetDraft">Descartar</BaseButton>
                <BaseButton :disabled="!dirty" :loading="savingMatrix" @click="saveMatrix">Salvar permissões</BaseButton>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="rounded-2xl border border-line bg-surface">
          <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center sm:justify-between">
            <SearchInput v-model="userSearch" label="Buscar usuário" placeholder="Nome ou e-mail" />
            <BaseButton @click="userDialog = { user: null }">
              <UserPlus class="size-4" aria-hidden="true" />
              Novo usuário
            </BaseButton>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase tracking-wide text-muted">
                <tr class="border-b border-line">
                  <th scope="col" class="px-4 py-3 font-semibold">Usuário</th>
                  <th scope="col" class="px-4 py-3 font-semibold">Perfis</th>
                  <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                  <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in users?.data ?? []" :key="u.id" class="border-b border-line last:border-0">
                  <td class="px-4 py-3">
                    <span class="flex items-center gap-1.5 font-medium">
                      {{ u.name }}
                      <span v-if="u.is_super_admin" class="rounded bg-ink px-1.5 text-[10px] font-semibold text-white">SUPER</span>
                    </span>
                    <span class="block text-xs text-muted">{{ u.email }}</span>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                      <StatusBadge v-for="r in u.roles ?? []" :key="r.slug" tone="info">{{ r.name }}</StatusBadge>
                      <span v-if="!u.roles?.length" class="text-xs text-muted">Sem perfil</span>
                    </div>
                  </td>
                  <td class="px-4 py-3">
                    <StatusBadge :tone="u.active ? 'success' : 'neutral'">{{ u.active ? 'Ativo' : 'Inativo' }}</StatusBadge>
                  </td>
                  <td class="px-4 py-3 text-right">
                    <BaseButton variant="ghost" @click="userDialog = { user: u }">Gerenciar</BaseButton>
                  </td>
                </tr>
                <tr v-if="users && users.data.length === 0">
                  <td colspan="4" class="px-4 py-8 text-center text-muted">Nenhum usuário encontrado.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <PaginationBar
            v-if="users"
            :meta="users.meta"
            @change="(page) => { userPage = page; loadUsers() }"
          />
        </div>
      </section>
    </div>

    <TenantDialog
      v-if="tenantDialog"
      :tenant="tenantDialog.tenant"
      @close="tenantDialog = null"
      @saved="onTenantSaved"
    />
    <RoleDialog
      v-if="roleDialog && selectedId"
      :tenant-id="selectedId"
      :role="roleDialog.role"
      @close="roleDialog = null"
      @saved="onRoleSaved"
    />
    <TenantUserDialog
      v-if="userDialog && selectedId"
      :tenant-id="selectedId"
      :user="userDialog.user"
      :roles="roles"
      :self-id="auth.user?.id"
      @close="userDialog = null"
      @saved="onUserSaved"
    />
  </div>
</template>
