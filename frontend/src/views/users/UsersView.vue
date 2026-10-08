<script setup lang="ts">
import { computed, reactive, ref, shallowRef } from 'vue'
import { Plus, Search } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import UserFormDialog from './UserFormDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { useDebouncedRef } from '@/composables/useDebouncedRef'
import { api } from '@/lib/http'
import { formatDateTime } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { Paginated, Role, User } from '@/types/api'

const auth = useAuthStore()
const canManage = computed(() => auth.can('users.manage'))

const search = ref('')
const debouncedSearch = useDebouncedRef(search, 300)
const filters = reactive({ page: 1, version: 0 })

const query = computed(() => ({ search: debouncedSearch.value, page: filters.page, version: filters.version }))

const { data, error, loading } = useApiQuery(query, (q) =>
  api<Paginated<User>>('/users', { query: { search: q.search, page: q.page } }),
)

const roles = shallowRef<Role[]>([])
const editing = shallowRef<User | null>(null)
const dialogOpen = ref(false)

async function openDialog(user: User | null) {
  if (roles.value.length === 0) {
    roles.value = (await api<{ data: Role[] }>('/roles')).data
  }
  editing.value = user
  dialogOpen.value = true
}

function onSaved() {
  dialogOpen.value = false
  filters.version++
}

function onSearch() {
  filters.page = 1
}
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <PageHeader title="Usuários" description="Pessoas com acesso ao ambiente da empresa.">
      <template v-if="canManage" #actions>
        <BaseButton @click="openDialog(null)">
          <Plus class="size-4" aria-hidden="true" />
          Novo usuário
        </BaseButton>
      </template>
    </PageHeader>

    <div class="overflow-hidden rounded-2xl border border-line bg-surface">
      <div class="border-b border-line p-4">
        <label class="relative block max-w-sm">
          <span class="sr-only">Buscar usuários</span>
          <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
          <input
            v-model="search"
            type="search"
            placeholder="Buscar por nome ou e-mail"
            class="h-10 w-full rounded-lg border border-line bg-canvas pr-3 pl-9 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            @input="onSearch"
          />
        </label>
      </div>

      <div v-if="error" class="p-4">
        <InlineAlert :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm" :aria-busy="loading">
          <thead class="bg-canvas text-xs font-semibold uppercase tracking-wider text-muted">
            <tr>
              <th scope="col" class="px-4 py-3">Nome</th>
              <th scope="col" class="px-4 py-3">Papéis</th>
              <th scope="col" class="px-4 py-3">Status</th>
              <th scope="col" class="px-4 py-3">Último acesso</th>
              <th v-if="canManage" scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="user in data?.data ?? []" :key="user.id" :class="loading && 'opacity-60'">
              <td class="px-4 py-3">
                <p class="font-medium">{{ user.name }}</p>
                <p class="text-xs text-muted">{{ user.email }}</p>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-1">
                  <StatusBadge v-for="role in user.roles ?? []" :key="role.slug" tone="info">{{ role.name }}</StatusBadge>
                </div>
              </td>
              <td class="px-4 py-3">
                <StatusBadge :tone="user.active ? 'success' : 'neutral'">{{ user.active ? 'Ativo' : 'Inativo' }}</StatusBadge>
              </td>
              <td class="px-4 py-3 text-muted tabular-nums">{{ formatDateTime(user.last_login_at) }}</td>
              <td v-if="canManage" class="px-4 py-3 text-right">
                <BaseButton variant="ghost" @click="openDialog(user)">
                  Editar<span class="sr-only"> {{ user.name }}</span>
                </BaseButton>
              </td>
            </tr>
            <tr v-if="!loading && data && data.data.length === 0">
              <td :colspan="canManage ? 5 : 4" class="px-4 py-12 text-center text-muted">Nenhum usuário encontrado.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar v-if="data" :meta="data.meta" @change="filters.page = $event" />
    </div>

    <UserFormDialog
      v-if="dialogOpen"
      :user="editing"
      :roles="roles"
      @close="dialogOpen = false"
      @saved="onSaved"
    />
  </div>
</template>
