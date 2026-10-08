<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { Plus } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ClientFormDialog from './ClientFormDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { useDebouncedRef } from '@/composables/useDebouncedRef'
import { api } from '@/lib/http'
import { formatDocument } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { Client, Paginated } from '@/types/api'

const auth = useAuthStore()
const canManage = computed(() => auth.can('clients.manage'))

const search = ref('')
const debouncedSearch = useDebouncedRef(search, 300)
const filters = reactive({ page: 1, type: '', status: '', version: 0 })

watch([debouncedSearch, () => filters.type, () => filters.status], () => (filters.page = 1))

const query = computed(() => ({ search: debouncedSearch.value, ...filters }))
const { data, error, loading } = useApiQuery(query, (q) =>
  api<Paginated<Client>>('/clients', { query: { search: q.search, page: q.page, type: q.type, status: q.status } }),
)

const dialogOpen = ref(false)

function onSaved() {
  dialogOpen.value = false
  filters.version++
}
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <PageHeader title="Clientes" description="Titulares das unidades consumidoras e dos projetos fotovoltaicos.">
      <template v-if="canManage" #actions>
        <BaseButton @click="dialogOpen = true">
          <Plus class="size-4" aria-hidden="true" />
          Novo cliente
        </BaseButton>
      </template>
    </PageHeader>

    <div class="overflow-hidden rounded-2xl border border-line bg-surface">
      <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center">
        <SearchInput v-model="search" label="Buscar clientes" placeholder="Nome, documento ou e-mail" />
        <select v-model="filters.type" aria-label="Tipo de pessoa" class="h-10 rounded-lg border border-line bg-canvas px-3 text-sm">
          <option value="">Todos os tipos</option>
          <option value="PF">Pessoa física</option>
          <option value="PJ">Pessoa jurídica</option>
        </select>
        <select v-model="filters.status" aria-label="Situação" class="h-10 rounded-lg border border-line bg-canvas px-3 text-sm">
          <option value="">Todas as situações</option>
          <option value="active">Ativos</option>
          <option value="inactive">Inativos</option>
        </select>
      </div>

      <div v-if="error" class="p-4">
        <InlineAlert :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm" :aria-busy="loading">
          <thead class="bg-canvas text-xs font-semibold uppercase tracking-wider text-muted">
            <tr>
              <th scope="col" class="px-4 py-3">Cliente</th>
              <th scope="col" class="px-4 py-3">Documento</th>
              <th scope="col" class="px-4 py-3 text-right">UCs</th>
              <th scope="col" class="px-4 py-3 text-right">Projetos</th>
              <th scope="col" class="px-4 py-3">Situação</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="client in data?.data ?? []" :key="client.id" class="hover:bg-canvas/60" :class="loading && 'opacity-60'">
              <td class="px-4 py-3">
                <RouterLink :to="`/clientes/${client.id}`" class="font-medium hover:text-primary hover:underline">
                  {{ client.name }}
                </RouterLink>
                <p class="text-xs text-muted">{{ client.email ?? client.trade_name ?? '—' }}</p>
              </td>
              <td class="px-4 py-3 font-mono text-xs tabular-nums">
                <span class="mr-2 rounded bg-canvas px-1.5 py-0.5 font-sans font-semibold text-muted">{{ client.type }}</span>
                {{ formatDocument(client.document) }}
              </td>
              <td class="px-4 py-3 text-right tabular-nums">{{ client.consumer_units_count ?? 0 }}</td>
              <td class="px-4 py-3 text-right tabular-nums">{{ client.projects_count ?? 0 }}</td>
              <td class="px-4 py-3">
                <StatusBadge :tone="client.status === 'active' ? 'success' : 'neutral'">
                  {{ client.status === 'active' ? 'Ativo' : 'Inativo' }}
                </StatusBadge>
              </td>
            </tr>
            <tr v-if="!loading && data && data.data.length === 0">
              <td colspan="5" class="px-4 py-12 text-center text-muted">Nenhum cliente encontrado.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar v-if="data" :meta="data.meta" @change="filters.page = $event" />
    </div>

    <ClientFormDialog v-if="dialogOpen" :client="null" @close="dialogOpen = false" @saved="onSaved" />
  </div>
</template>
