<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { Plus } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import SelectField from '@/components/ui/SelectField.vue'
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
const filters = reactive({ page: 1, per_page: 20, type: '', status: '', version: 0 })

watch([debouncedSearch, () => filters.type, () => filters.status], () => (filters.page = 1))

watch(() => filters.per_page, () => (filters.page = 1))

const query = computed(() => ({ search: debouncedSearch.value, ...filters }))
const { data, error, loading } = useApiQuery(query, (q) =>
  api<Paginated<Client>>('/clients', { query: { search: q.search, page: q.page, per_page: q.per_page, type: q.type, status: q.status } }),
)

const dialogOpen = ref(false)

function onSaved() {
  dialogOpen.value = false
  filters.version++
}
</script>

<template>
  <div class="mx-auto max-w-[1240px]">
    <PageHeader title="Clientes" description="Titulares das unidades consumidoras e dos projetos fotovoltaicos.">
      <template v-if="canManage" #actions>
        <BaseButton @click="dialogOpen = true">
          <Plus class="size-4" aria-hidden="true" />
          Novo cliente
        </BaseButton>
      </template>
    </PageHeader>

    <div class="vg-panel overflow-hidden">
      <div class="flex flex-col gap-3 border-b border-line p-5 sm:flex-row sm:items-center">
        <SearchInput v-model="search" label="Buscar clientes" placeholder="Nome, documento ou e-mail" />
        <SelectField v-model="filters.type" label="Tipo de pessoa" hide-label placeholder="Todos os tipos" class="sm:min-w-44" :options="[{ value: 'PF', label: 'Pessoa física' }, { value: 'PJ', label: 'Pessoa jurídica' }]" />
        <SelectField v-model="filters.status" label="Situação" hide-label placeholder="Todas as situações" class="sm:min-w-44" :options="[{ value: 'active', label: 'Ativos' }, { value: 'inactive', label: 'Inativos' }]" />
      </div>

      <div v-if="error" class="p-4">
        <InlineAlert :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
      </div>

      <div v-else class="vg-table !rounded-none !border-0">
        <table class="min-w-[640px] text-left" :aria-busy="loading">
          <thead class="bg-canvas text-muted">
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
                <RouterLink :to="`/clientes/${client.id}`" class="font-medium underline-offset-4 hover:text-primary hover:underline">
                  {{ client.name }}
                </RouterLink>
                <p class="mt-1 text-[10px] text-muted">{{ client.email ?? client.trade_name ?? '—' }}</p>
              </td>
              <td class="px-4 py-3 font-mono text-xs tabular-nums">
                <span class="mr-2 rounded-md bg-blue-soft px-2 py-1 font-sans text-[9px] font-medium text-corporate-blue">{{ client.type.toUpperCase() }}</span>
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
              <td colspan="5" class="!py-14 text-center text-muted">Nenhum cliente encontrado.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar v-if="data" v-model:per-page="filters.per_page" :meta="data.meta" :disabled="loading || !!error" @change="filters.page = $event" />
    </div>

    <ClientFormDialog v-if="dialogOpen" :client="null" @close="dialogOpen = false" @saved="onSaved" />
  </div>
</template>
