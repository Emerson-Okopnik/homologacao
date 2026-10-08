<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { AlertTriangle, CalendarClock } from '@lucide/vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import SelectField from '@/components/ui/SelectField.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { useDebouncedRef } from '@/composables/useDebouncedRef'
import { api } from '@/lib/http'
import { formatDate, formatNumber, stageTone } from '@/lib/format'
import type { HomologationProcess, StageCatalog } from '@/types/api'

const route = useRoute()
const mode = computed<'board' | 'list'>(() => (route.name === 'kanban' ? 'board' : 'list'))

const search = ref('')
const debounced = useDebouncedRef(search, 300)
const stage = ref('')
const status = ref('')

const statusOptions = [
  { value: 'ACTIVE', label: 'Em andamento' },
  { value: 'COMPLETED', label: 'Concluído' },
  { value: 'CANCELLED', label: 'Cancelado' },
]

const source = computed(() => ({
  search: debounced.value,
  stage: mode.value === 'list' ? stage.value : '',
  status: mode.value === 'list' ? status.value : '',
  mode: mode.value,
}))

const { data, error, loading } = useApiQuery(source, async (s) => {
  const [catalog, processes] = await Promise.all([
    api<{ data: StageCatalog }>('/process-stages'),
    api<{ data: HomologationProcess[] }>('/processes', {
      query: {
        search: s.search || undefined,
        stage: s.stage || undefined,
        status: s.status || undefined,
        board: s.mode === 'board' ? 1 : undefined,
        per_page: 200,
      },
    }),
  ])
  return { stages: catalog.data.stages, processes: processes.data }
})

const columns = computed(() => {
  const items = data.value?.processes ?? []
  return (data.value?.stages ?? []).map((s) => ({ ...s, items: items.filter((p) => p.stage === s.value) }))
})

const isOverdue = (p: HomologationProcess) => !!p.open_deadline?.overdue
const badgeLabel = (p: HomologationProcess) => (p.status === 'ACTIVE' ? p.stage_label : p.status_label)
</script>

<template>
  <div class="mx-auto" :class="mode === 'board' ? 'max-w-none' : 'max-w-6xl'">
    <PageHeader
      :title="mode === 'board' ? 'Kanban de homologações' : 'Processos de homologação'"
      :description="mode === 'board' ? 'Processos em andamento agrupados pela etapa operacional. Clique em um card para ver detalhes e as próximas ações.' : 'Todos os processos, incluindo concluídos e cancelados.'"
    />

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
      <div class="sm:w-80">
        <SearchInput v-model="search" label="Buscar processos" placeholder="Código, protocolo, cliente ou UC" />
      </div>
      <template v-if="mode === 'list'">
        <div class="sm:w-56">
          <SelectField
            v-model="stage"
            label="Etapa"
            placeholder="Todas as etapas"
            :options="(data?.stages ?? []).map((s) => ({ value: s.value, label: s.label }))"
          />
        </div>
        <div class="sm:w-48">
          <SelectField v-model="status" label="Situação" placeholder="Todas" :options="statusOptions" />
        </div>
      </template>
    </div>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>

    <div v-else-if="mode === 'board'" class="flex gap-4 overflow-x-auto pb-4" :aria-busy="loading">
      <section
        v-for="column in columns"
        :key="column.value"
        class="flex w-72 shrink-0 flex-col rounded-2xl border border-line bg-canvas"
        :aria-labelledby="`col-${column.value}`"
      >
        <header class="flex items-center justify-between px-4 py-3">
          <h2 :id="`col-${column.value}`" class="text-sm font-semibold">{{ column.label }}</h2>
          <span class="rounded-full bg-surface px-2 py-0.5 text-xs font-semibold tabular-nums text-muted">{{ column.items.length }}</span>
        </header>
        <ul class="flex min-h-24 flex-col gap-2 px-3 pb-3">
          <li v-for="p in column.items" :key="p.id">
            <RouterLink
              :to="`/processos/${p.id}`"
              class="block rounded-xl border border-line bg-surface p-3 transition-colors hover:border-primary focus-visible:border-primary"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="font-mono text-xs font-semibold text-muted">{{ p.code }}</span>
                <span v-if="p.open_pendencies_count" class="inline-flex items-center gap-1 text-xs font-medium text-warning">
                  <AlertTriangle class="size-3.5" aria-hidden="true" />
                  {{ p.open_pendencies_count }}<span class="sr-only"> pendências abertas</span>
                </span>
              </div>
              <p class="mt-1 truncate text-sm font-semibold">{{ p.project?.client?.name }}</p>
              <p class="truncate text-xs text-muted">UC {{ p.project?.consumer_unit?.number }} · {{ p.distributor?.name }}</p>
              <div class="mt-3 flex items-center justify-between gap-2 text-xs">
                <span class="tabular-nums font-medium">
                  {{ formatNumber(p.project?.considered_power_kw, 'kW') }}
                  <span v-if="p.project?.classification_label" class="font-normal text-muted">· {{ p.project.classification_label }}</span>
                </span>
                <span
                  v-if="p.open_deadline"
                  class="inline-flex items-center gap-1 tabular-nums"
                  :class="isOverdue(p) ? 'font-semibold text-danger' : 'text-muted'"
                  :title="p.open_deadline.label"
                >
                  <CalendarClock class="size-3.5" aria-hidden="true" />
                  {{ formatDate(p.open_deadline.due_at) }}<span v-if="isOverdue(p)" class="sr-only"> (atrasado)</span>
                </span>
              </div>
              <p v-if="p.network_work_status !== 'NOT_REQUIRED'" class="mt-2 text-xs text-muted">Obra: {{ p.network_work_label }}</p>
              <p v-if="p.assignee" class="mt-2 truncate border-t border-line pt-2 text-xs text-muted">{{ p.assignee.name }}</p>
            </RouterLink>
          </li>
          <li v-if="!loading && column.items.length === 0" class="rounded-xl border border-dashed border-line px-3 py-6 text-center text-xs text-muted">
            Nenhum processo
          </li>
        </ul>
      </section>
    </div>

    <div v-else class="overflow-x-auto rounded-2xl border border-line bg-surface">
      <table class="w-full text-left text-sm" :aria-busy="loading">
        <thead class="bg-canvas text-xs font-semibold uppercase tracking-wider text-muted">
          <tr>
            <th scope="col" class="px-4 py-3">Processo</th>
            <th scope="col" class="px-4 py-3">Cliente / UC</th>
            <th scope="col" class="px-4 py-3">Distribuidora</th>
            <th scope="col" class="px-4 py-3">Protocolo</th>
            <th scope="col" class="px-4 py-3">Etapa</th>
            <th scope="col" class="px-4 py-3">Prazo</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="p in data?.processes ?? []" :key="p.id">
            <td class="px-4 py-3">
              <RouterLink :to="`/processos/${p.id}`" class="font-mono font-medium hover:text-primary hover:underline">{{ p.code }}</RouterLink>
            </td>
            <td class="px-4 py-3">
              <p class="font-medium">{{ p.project?.client?.name }}</p>
              <p class="text-xs text-muted">UC {{ p.project?.consumer_unit?.number }}</p>
            </td>
            <td class="px-4 py-3">{{ p.distributor?.name }}</td>
            <td class="px-4 py-3 font-mono text-xs">{{ p.protocol_number ?? '—' }}</td>
            <td class="px-4 py-3"><StatusBadge :tone="stageTone(p.stage, p.status)">{{ badgeLabel(p) }}</StatusBadge></td>
            <td class="px-4 py-3 tabular-nums" :class="isOverdue(p) ? 'font-semibold text-danger' : 'text-muted'">
              <template v-if="p.open_deadline">
                {{ formatDate(p.open_deadline.due_at) }}
                <span class="block text-xs font-normal text-muted">{{ p.open_deadline.label }}</span>
              </template>
              <template v-else>—</template>
            </td>
          </tr>
          <tr v-if="!loading && data && data.processes.length === 0">
            <td colspan="6" class="px-4 py-12 text-center text-muted">Nenhum processo encontrado.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
