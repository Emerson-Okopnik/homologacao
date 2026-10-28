<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { AlertTriangle, CalendarClock, Columns3, List, Plus, Zap } from '@lucide/vue'
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
import { useAuthStore } from '@/stores/auth'
import type { HomologationProcess, Paginated, ProcessStatusMeta, StageCatalog } from '@/types/api'
import { processDeadline, processGuidance } from './processPresentation'

const route = useRoute()
const auth = useAuthStore()
const mode = computed<'board' | 'list'>(() => (route.name === 'kanban' ? 'board' : 'list'))

const search = ref('')
const debounced = useDebouncedRef(search, 300)
const status = ref('')
const scope = ref('active')
const page = ref(1)
const perPage = ref(20)
watch([debounced, status, scope, mode, perPage], () => { page.value = 1 })
watch(scope, () => { status.value = '' })
const statusOptions = computed(() => (data.value?.statuses ?? []).filter((s) => {
  const closed = ['conectado', 'cancelado'].includes(s.stage_type ?? s.value)
  return scope.value === 'all' || (scope.value === 'closed' ? closed : !closed)
}).map((s) => ({ value: s.value, label: s.label })))

const source = computed(() => ({ search: debounced.value, status: mode.value === 'list' ? status.value : '', scope: scope.value, page: page.value, per_page: perPage.value, mode: mode.value }))
const { data, error, loading } = useApiQuery(source, async (s) => {
  const [catalog, processes] = await Promise.all([
    s.mode === 'board'
      ? api<{ data: StageCatalog }>('/process-stages').then((r) => ({ stages: r.data.stages, statuses: [] as ProcessStatusMeta[] }))
      : api<{ data: ProcessStatusMeta[] }>('/process-statuses').then((r) => ({ stages: [] as StageCatalog['stages'], statuses: r.data })),
    api<{ data: HomologationProcess[]; meta?: Paginated<HomologationProcess>['meta'] }>('/processes', {
      query: { search: s.search || undefined, status: s.status || undefined, scope: s.mode === 'list' ? s.scope : undefined, page: s.page, board: s.mode === 'board' ? 1 : undefined, per_page: s.per_page },
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
const distributorName = (p: HomologationProcess) => p.distributor?.name ?? p.project?.consumer_unit?.distributor?.name
const initials = (name?: string) => name?.split(' ').filter(Boolean).slice(0, 2).map(part => part[0]).join('') || '—'
const stageColors: Record<string, string> = {
  PREPARATION: '#1E3A8A', EXTERNAL_ANALYSIS: '#0A1F5C', CORRECTION: '#6B83B7',
  EXECUTION: '#0E9F6E', INSPECTION: '#19B98A', CONNECTION: '#93DDC4',
}
</script>

<template>
  <div class="mx-auto max-w-[1440px]">
    <PageHeader
      :title="mode === 'board' ? 'Homologações em movimento' : 'Homologações'"
      :description="mode === 'board' ? 'Acompanhe cada processo, da preparação à conexão com a rede.' : 'Encontre processos, acompanhe prazos e organize a operação.'"
    >
      <template #actions>
        <RouterLink :to="mode === 'board' ? '/processos' : '/kanban'" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-line bg-surface px-4 text-xs font-medium text-ink transition-colors hover:bg-canvas">
          <component :is="mode === 'board' ? List : Columns3" class="size-4" aria-hidden="true" />
          {{ mode === 'board' ? 'Ver lista' : 'Ver Kanban' }}
        </RouterLink>
        <RouterLink v-if="auth.can('projects.manage')" to="/projetos/novo" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-xs font-semibold text-on-primary transition-colors hover:bg-primary-hover">
          <Plus class="size-4" aria-hidden="true" />Novo projeto
        </RouterLink>
      </template>
    </PageHeader>

    <div class="mb-6 flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 sm:flex-row sm:items-end">
      <div class="min-w-0 flex-1 sm:max-w-xl">
        <SearchInput v-model="search" label="Buscar processos" placeholder="Código, protocolo, cliente ou unidade consumidora" />
      </div>
      <div v-if="mode === 'list'" class="sm:w-44">
        <SelectField v-model="scope" label="Exibir" :options="[{ value: 'active', label: 'Em andamento' }, { value: 'closed', label: 'Encerrados' }, { value: 'all', label: 'Todos os processos' }]" />
      </div>
      <div v-if="mode === 'list'" class="sm:w-60">
        <SelectField v-model="status" label="Situação" placeholder="Todas as situações" :options="statusOptions" />
      </div>
    </div>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading && !data" class="rounded-xl border border-line bg-surface px-6 py-14 text-center text-sm text-muted" role="status">Carregando homologações…</p>

    <template v-else-if="mode === 'board'">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-2 text-xs text-muted">
        <p class="text-[10px] font-medium uppercase tracking-[0.14em]">{{ data?.processes.length ?? 0 }} processos em andamento</p>
        <p>Abra um cartão para ver o próximo passo.</p>
      </div>
      <div class="grid auto-cols-[minmax(11rem,1fr)] grid-flow-col gap-3 overflow-x-auto pb-4" :aria-busy="loading">
        <section v-for="column in columns" :key="column.value" class="flex min-h-[32rem] flex-col overflow-hidden rounded-xl border-t-[3px] bg-raised" :style="{ borderTopColor: stageColors[column.value] ?? '#0A1F5C' }" :aria-labelledby="`col-${column.value}`">
          <header class="flex min-h-[4.25rem] items-start justify-between gap-2 px-3 py-4">
            <div class="flex min-w-0 items-start gap-2">
              <span class="mt-1 size-1.5 shrink-0 rounded-full" :style="{ backgroundColor: stageColors[column.value] ?? '#0A1F5C' }" aria-hidden="true" />
              <h2 :id="`col-${column.value}`" class="text-[11px] font-semibold leading-4">{{ column.label }}</h2>
            </div>
            <span class="inline-flex min-w-6 shrink-0 items-center justify-center rounded-md bg-surface px-1.5 py-1 text-[10px] font-medium tabular-nums text-muted">{{ column.items.length }}</span>
          </header>
          <ul class="flex flex-col gap-3 px-2 pb-3">
            <li v-for="p in column.items" :key="p.id">
              <RouterLink :to="`/processos/${p.id}`" class="group block rounded-lg border border-line bg-surface p-3 transition duration-150 hover:-translate-y-0.5 hover:border-primary hover:shadow-sm focus-visible:border-primary">
                <div class="flex items-start justify-between gap-2">
                  <span class="text-[10px] font-medium text-muted">{{ p.code }}</span>
                  <span v-if="p.open_pendencies_count" class="inline-flex items-center gap-1 text-[10px] font-medium text-warning">
                    <AlertTriangle class="size-3" aria-hidden="true" />{{ p.open_pendencies_count }}<span class="sr-only"> pendências abertas</span>
                  </span>
                </div>
                <p class="mt-4 min-h-10 break-words text-xs font-semibold leading-5 text-ink">{{ p.project?.client?.name ?? p.code }}</p>
                <p class="mt-2 break-words text-[10px] text-muted">UC {{ p.project?.consumer_unit?.number ?? '—' }}</p>
                <p class="mt-1 break-words text-[10px] text-muted">{{ distributorName(p) ?? 'Distribuidora não informada' }}</p>
                <p class="mt-4 flex items-center gap-1.5 text-xs font-medium tabular-nums text-ink"><Zap class="size-3.5 text-primary" aria-hidden="true" />{{ formatNumber(p.project?.installed_power_kwp, 'kWp') }}</p>
                <StatusBadge class="mt-3 max-w-full !text-[10px]" :tone="statusTone(p.status)">{{ p.status_label }}</StatusBadge>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                  <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[9px] font-semibold text-primary" :title="p.assignee?.name ?? 'Sem responsável'">{{ initials(p.assignee?.name) }}</span>
                  <span v-if="processDeadline(p)" :title="processDeadline(p)?.label" class="inline-flex items-center gap-1 text-[10px] tabular-nums" :class="isOverdue(p) ? 'font-semibold text-danger' : 'text-muted'">
                    <CalendarClock class="size-3" aria-hidden="true" />{{ formatDate(processDeadline(p)?.date) }}<span v-if="isOverdue(p)" class="sr-only"> (atrasado)</span>
                  </span>
                  <span v-else class="text-[10px] text-muted">Sem prazo</span>
                </div>
              </RouterLink>
            </li>
            <li v-if="!loading && column.items.length === 0" class="rounded-lg border border-dashed border-line px-3 py-10 text-center text-xs text-muted">Nenhum processo nesta fase.</li>
          </ul>
        </section>
      </div>
    </template>

    <div v-else class="overflow-hidden rounded-xl border border-line bg-surface">
      <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
        <h2 class="text-sm font-semibold">{{ data?.meta?.total ?? data?.processes.length ?? 0 }} homologações encontradas</h2>
        <p class="text-xs text-muted">Fase, situação e próximo passo em uma só visão.</p>
      </header>
      <ul class="divide-y divide-line md:hidden" :aria-busy="loading">
        <li v-for="p in data?.processes ?? []" :key="p.id" class="p-4">
          <RouterLink :to="`/processos/${p.id}`" class="font-semibold hover:text-primary">{{ p.project?.client?.name ?? p.code }}</RouterLink>
          <p class="mt-1 text-xs text-muted">{{ p.code }} · UC {{ p.project?.consumer_unit?.number ?? '—' }}</p>
          <div class="mt-3 flex flex-wrap items-center gap-2"><span class="text-xs font-medium">{{ p.stage_label }}</span><StatusBadge :tone="statusTone(p.status)">{{ p.status_label }}</StatusBadge></div>
          <p class="mt-4 text-sm font-medium">{{ processGuidance(p).title }}</p><p class="mt-1 text-xs text-muted">{{ processGuidance(p).responsibility }}</p>
          <p v-if="p.open_pendencies_count" class="mt-2 text-xs text-warning">{{ p.open_pendencies_count }} {{ p.open_pendencies_count === 1 ? 'pendência aberta' : 'pendências abertas' }}</p>
          <p v-if="processDeadline(p)" class="mt-3 text-xs" :class="isOverdue(p) ? 'text-danger' : 'text-muted'">{{ processDeadline(p)?.label }} · {{ formatDate(processDeadline(p)?.date) }}<span v-if="isOverdue(p)"> · vencido</span></p>
        </li>
        <li v-if="!loading && data && data.processes.length === 0" class="px-4 py-12 text-center text-sm text-muted">Nenhum processo encontrado para estes filtros.</li>
      </ul>
      <div class="hidden overflow-x-auto md:block">
        <table class="w-full min-w-[900px] text-left text-xs" :aria-busy="loading">
          <thead class="bg-canvas text-[10px] font-medium uppercase tracking-wider text-muted">
            <tr><th scope="col" class="px-5 py-3.5">Processo / cliente</th><th scope="col" class="px-4 py-3.5">Fase</th><th scope="col" class="px-4 py-3.5">Situação</th><th scope="col" class="px-4 py-3.5">Próximo passo</th><th scope="col" class="px-4 py-3.5">Responsável</th><th scope="col" class="px-5 py-3.5">Prazo</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="p in data?.processes ?? []" :key="p.id" class="transition-colors hover:bg-canvas/60">
              <td class="px-5 py-4"><RouterLink :to="`/processos/${p.id}`" class="font-semibold text-ink hover:text-primary">{{ p.project?.client?.name ?? p.code }}</RouterLink><p class="mt-1.5 text-[10px] text-muted">{{ p.code }} · UC {{ p.project?.consumer_unit?.number ?? '—' }}</p><p class="mt-1 text-[10px] text-muted">{{ distributorName(p) ?? '—' }}</p></td>
              <td class="px-4 py-4 font-medium">{{ p.stage_label }}</td>
              <td class="px-4 py-4"><StatusBadge :tone="statusTone(p.status)">{{ p.status_label }}</StatusBadge></td>
              <td class="max-w-56 px-4 py-4"><p class="font-medium">{{ processGuidance(p).title }}</p><p class="mt-1 text-[10px] text-muted">{{ processGuidance(p).responsibility }}</p><p v-if="p.open_pendencies_count" class="mt-1 text-[10px] text-warning">{{ p.open_pendencies_count }} {{ p.open_pendencies_count === 1 ? 'pendência aberta' : 'pendências abertas' }}</p></td>
              <td class="px-4 py-4"><span class="flex items-center gap-2"><span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[9px] font-semibold text-primary">{{ initials(p.assignee?.name) }}</span><span class="text-[11px] text-muted">{{ p.assignee?.name ?? 'Sem responsável' }}</span></span></td>
              <td class="px-5 py-4 tabular-nums" :class="isOverdue(p) ? 'font-semibold text-danger' : 'text-muted'"><template v-if="processDeadline(p)"><p>{{ formatDate(processDeadline(p)?.date) }}</p><p class="mt-1 text-[10px]">{{ processDeadline(p)?.label }}<span v-if="isOverdue(p)"> · vencido</span></p></template><span v-else class="text-[10px]">Sem prazo em aberto</span></td>
            </tr>
            <tr v-if="!loading && data && data.processes.length === 0"><td colspan="6" class="px-4 py-14 text-center text-muted">Nenhum processo encontrado para estes filtros.</td></tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="data?.meta" v-model:per-page="perPage" :meta="data.meta" :disabled="loading || !!error" @change="page = $event" />
    </div>
  </div>
</template>
