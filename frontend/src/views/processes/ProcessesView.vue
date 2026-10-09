<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { AlertTriangle, CalendarClock } from '@lucide/vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import SelectField from '@/components/ui/SelectField.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { useDebouncedRef } from '@/composables/useDebouncedRef'
import { api } from '@/lib/http'
import { formatDate, formatNumber, statusTone } from '@/lib/format'
import type { HomologationProcess, Paginated, ProcessStatusMeta, StageCatalog } from '@/types/api'
import { processDeadline, processGuidance } from './processPresentation'

const route = useRoute()
const mode = computed<'board' | 'list'>(() => (route.name === 'kanban' ? 'board' : 'list'))

const search = ref('')
const debounced = useDebouncedRef(search, 300)
const status = ref('')
const scope = ref('active')
const page = ref(1)
watch([debounced, status, scope, mode], () => { page.value = 1 })
watch(scope, () => { status.value = '' })
const statusOptions = computed(() => (data.value?.statuses ?? []).filter((s) => {
  const closed = ['conectado', 'cancelado'].includes(s.stage_type ?? s.value)
  return scope.value === 'all' || (scope.value === 'closed' ? closed : !closed)
}).map((s) => ({ value: s.value, label: s.label })))

const source = computed(() => ({ search: debounced.value, status: mode.value === 'list' ? status.value : '', scope: scope.value, page: page.value, mode: mode.value }))
const { data, error, loading } = useApiQuery(source, async (s) => {
  const [catalog, processes] = await Promise.all([
    s.mode === 'board'
      ? api<{ data: StageCatalog }>('/process-stages').then((r) => ({ stages: r.data.stages, statuses: [] as ProcessStatusMeta[] }))
      : api<{ data: ProcessStatusMeta[] }>('/process-statuses').then((r) => ({ stages: [] as StageCatalog['stages'], statuses: r.data })),
    api<{ data: HomologationProcess[]; meta?: Paginated<HomologationProcess>['meta'] }>('/processes', {
      query: { search: s.search || undefined, status: s.status || undefined, scope: s.mode === 'list' ? s.scope : undefined, page: s.page, board: s.mode === 'board' ? 1 : undefined, per_page: 20 },
    }),
  ])
  return { ...catalog, processes: processes.data, meta: processes.meta }
})

const columns = computed(() => {
  const items = data.value?.processes ?? []
  return (data.value?.stages ?? [])
    .map((s) => ({ ...s, items: items.filter((p) => p.stage === s.value) }))
})

const isOverdue = (p: HomologationProcess) => processDeadline(p)?.overdue
</script>

<template>
  <div class="mx-auto" :class="mode === 'board' ? 'max-w-none' : 'max-w-6xl'">
    <PageHeader
      :title="mode === 'board' ? 'Kanban de homologações' : 'Processos de homologação'"
      :description="mode === 'board' ? 'Processos em andamento nas mesmas etapas do Dashboard. Abra um card para ver os detalhes e o próximo passo.' : 'Veja a etapa atual e o próximo passo. Abra um processo para trabalhar nele.'"
    />

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
      <div class="sm:w-80">
        <SearchInput v-model="search" label="Buscar processos" placeholder="Código, protocolo, cliente ou UC" />
      </div>
      <div v-if="mode === 'list'" class="sm:w-48">
        <SelectField v-model="scope" label="Exibir" :options="[{ value: 'active', label: 'Em andamento' }, { value: 'closed', label: 'Encerrados' }, { value: 'all', label: 'Todos os processos' }]" />
      </div>
      <div v-if="mode === 'list'" class="sm:w-60">
        <SelectField
          v-model="status"
          label="Etapa"
          placeholder="Todas as etapas"
          :options="statusOptions"
        />
      </div>
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
              <p class="truncate text-xs text-muted">
                UC {{ p.project?.consumer_unit?.number }} · {{ p.distributor?.name }}
              </p>
              <StatusBadge class="mt-2" :tone="statusTone(p.status)">{{ p.status_label }}</StatusBadge>
              <div class="mt-3 flex items-center justify-between text-xs">
                <span class="tabular-nums font-medium">{{ formatNumber(p.project?.installed_power_kwp, 'kWp') }}</span>
                <span v-if="processDeadline(p)" :title="processDeadline(p)?.label" class="inline-flex items-center gap-1 tabular-nums" :class="isOverdue(p) ? 'font-semibold text-danger' : 'text-muted'">
                  <CalendarClock class="size-3.5" aria-hidden="true" />
                  {{ formatDate(processDeadline(p)?.date) }}<span v-if="isOverdue(p)" class="sr-only"> (atrasado)</span>
                </span>
              </div>
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
      <ul class="divide-y divide-line md:hidden" :aria-busy="loading">
        <li v-for="p in data?.processes ?? []" :key="p.id" class="p-4">
          <RouterLink :to="`/processos/${p.id}`" class="font-semibold hover:text-primary hover:underline">{{ p.project?.client?.name ?? p.code }}</RouterLink>
          <p class="mb-2 text-xs text-muted">{{ p.code }} · UC {{ p.project?.consumer_unit?.number ?? '—' }}</p>
          <StatusBadge :tone="statusTone(p.status)">{{ p.status_label }}</StatusBadge>
          <p class="mt-3 text-sm font-medium">{{ processGuidance(p).title }}</p>
          <p class="text-xs text-muted">{{ processGuidance(p).responsibility }}</p>
          <p v-if="p.open_pendencies_count" class="mt-1 text-xs text-warning">{{ p.open_pendencies_count }} {{ p.open_pendencies_count === 1 ? 'pendência aberta' : 'pendências abertas' }}</p>
          <p v-if="processDeadline(p)" class="mt-2 text-xs" :class="isOverdue(p) ? 'text-danger' : 'text-muted'">{{ processDeadline(p)?.label }} · {{ formatDate(processDeadline(p)?.date) }}<span v-if="isOverdue(p)"> · vencido</span></p>
        </li>
        <li v-if="!loading && data && data.processes.length === 0" class="px-4 py-8 text-sm text-muted">Nenhum processo encontrado para estes filtros.</li>
      </ul>
      <table class="hidden w-full text-left text-sm md:table" :aria-busy="loading">
        <thead class="bg-canvas text-xs font-semibold uppercase tracking-wider text-muted">
          <tr>
            <th scope="col" class="px-4 py-3">Processo</th>
            <th scope="col" class="px-4 py-3">Etapa atual</th>
            <th scope="col" class="px-4 py-3">Próximo passo</th>
            <th scope="col" class="px-4 py-3">Prazo</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="p in data?.processes ?? []" :key="p.id">
            <td class="px-4 py-3">
              <RouterLink :to="`/processos/${p.id}`" class="font-semibold hover:text-primary hover:underline">{{ p.project?.client?.name ?? p.code }}</RouterLink>
              <p class="mt-1 text-xs text-muted">{{ p.code }} · UC {{ p.project?.consumer_unit?.number ?? '—' }}</p>
              <p class="text-xs text-muted">{{ p.distributor?.name }}</p>
            </td>
            <td class="px-4 py-3"><StatusBadge :tone="statusTone(p.status)">{{ p.status_label }}</StatusBadge></td>
            <td class="px-4 py-3">
              <p class="font-medium">{{ processGuidance(p).title }}</p>
              <p class="text-xs text-muted">{{ processGuidance(p).responsibility }}</p>
              <p v-if="p.open_pendencies_count" class="mt-1 text-xs text-warning">{{ p.open_pendencies_count }} {{ p.open_pendencies_count === 1 ? 'pendência aberta' : 'pendências abertas' }}</p>
            </td>
            <td class="px-4 py-3 tabular-nums" :class="isOverdue(p) ? 'font-semibold text-danger' : 'text-muted'">
              <template v-if="processDeadline(p)">
                <p>{{ formatDate(processDeadline(p)?.date) }}</p>
                <p class="text-xs">{{ processDeadline(p)?.label }}<span v-if="isOverdue(p)"> · vencido</span></p>
              </template>
              <span v-else class="text-xs">Sem prazo em aberto</span>
            </td>
          </tr>
          <tr v-if="!loading && data && data.processes.length === 0">
            <td colspan="4" class="px-4 py-12 text-center text-muted">Nenhum processo encontrado para estes filtros.</td>
          </tr>
        </tbody>
      </table>
      <PaginationBar v-if="data?.meta" :meta="data.meta" @change="page = $event" />
    </div>
  </div>
</template>
