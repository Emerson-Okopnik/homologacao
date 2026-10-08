<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { AlertTriangle, CalendarX, FileSearch, Hourglass, PlugZap, Plus, Timer, Zap } from '@lucide/vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import { formatDateTime, formatNumber, statusTone } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { DashboardData } from '@/types/api'

const auth = useAuthStore()
const firstName = computed(() => auth.user?.name.split(' ')[0] ?? '')

const { data, error, loading } = useApiQuery(
  () => 'dashboard',
  () => api<{ data: DashboardData }>('/dashboard').then((r) => r.data),
)

const kpis = computed(() => {
  const t = data.value?.totals
  if (!t) return []
  return [
    { label: 'Processos ativos', value: formatNumber(t.active), icon: Zap, tone: 'text-primary bg-primary-soft' },
    { label: 'Aguardando distribuidora', value: formatNumber(t.waiting_distributor), icon: Hourglass, tone: 'text-primary bg-primary-soft' },
    { label: 'Com pendências', value: formatNumber(t.with_pendencies), icon: AlertTriangle, tone: 'text-warning bg-warning-soft' },
    { label: 'Prazos vencidos', value: formatNumber(t.overdue), icon: CalendarX, tone: 'text-danger bg-danger-soft' },
    { label: 'Documentos para revisar', value: formatNumber(t.documents_to_review), icon: FileSearch, tone: 'text-warning bg-warning-soft' },
    { label: 'Sistemas conectados', value: formatNumber(t.connected), icon: PlugZap, tone: 'text-success bg-success-soft' },
    { label: 'Potência conectada', value: formatNumber(t.connected_power_kwp, 'kWp'), icon: Zap, tone: 'text-success bg-success-soft' },
    {
      label: 'Tempo médio até aprovação',
      value: t.avg_approval_days === null ? '—' : `${formatNumber(t.avg_approval_days)} dias`,
      icon: Timer,
      tone: 'text-muted bg-canvas',
    },
  ]
})

const maxStatus = computed(() => Math.max(1, ...(data.value?.by_status ?? []).map((s) => s.total)))
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <PageHeader :title="`Olá, ${firstName}`" description="Visão geral das homologações em andamento.">
      <template v-if="auth.can('projects.manage')" #actions>
        <RouterLink
          to="/projetos/novo"
          class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover"
        >
          <Plus class="size-4" aria-hidden="true" />
          Novo projeto
        </RouterLink>
      </template>
    </PageHeader>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading && !data" class="py-12 text-center text-muted" role="status">Carregando indicadores…</p>

    <template v-else-if="data">
      <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Indicadores">
        <div v-for="kpi in kpis" :key="kpi.label" class="flex items-center gap-4 rounded-2xl border border-line bg-surface p-5">
          <span class="flex size-10 shrink-0 items-center justify-center rounded-xl" :class="kpi.tone">
            <component :is="kpi.icon" class="size-5" aria-hidden="true" />
          </span>
          <div class="min-w-0">
            <p class="text-2xl font-bold tabular-nums leading-tight">{{ kpi.value }}</p>
            <p class="truncate text-xs text-muted">{{ kpi.label }}</p>
          </div>
        </div>
      </section>

      <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="rounded-2xl border border-line bg-surface p-6" aria-labelledby="by-status">
          <div class="flex items-center justify-between">
            <h2 id="by-status" class="font-semibold">Processos por etapa</h2>
            <RouterLink to="/kanban" class="text-sm font-medium text-primary hover:underline">Abrir kanban</RouterLink>
          </div>
          <ul class="mt-4 flex flex-col gap-3">
            <li v-for="s in data.by_status" :key="s.status" class="text-sm">
              <div class="flex justify-between">
                <span>{{ s.label }}</span>
                <span class="font-semibold tabular-nums">{{ s.total }}</span>
              </div>
              <div class="mt-1 h-2 overflow-hidden rounded-full bg-canvas" aria-hidden="true">
                <div class="h-full rounded-full bg-primary" :style="{ width: `${(s.total / maxStatus) * 100}%` }" />
              </div>
            </li>
          </ul>
        </section>

        <section class="rounded-2xl border border-line bg-surface p-6" aria-labelledby="recent">
          <h2 id="recent" class="font-semibold">Movimentações recentes</h2>
          <p v-if="data.recent.length === 0" class="mt-6 text-center text-sm text-muted">
            Nenhum processo ainda. Cadastre um cliente e crie o primeiro projeto.
          </p>
          <ul v-else class="mt-4 divide-y divide-line">
            <li v-for="r in data.recent" :key="r.id">
              <RouterLink :to="`/processos/${r.id}`" class="flex items-center gap-3 py-3 hover:opacity-80">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium">{{ r.client ?? '—' }}</p>
                  <p class="text-xs text-muted">
                    <span class="font-mono">{{ r.code }}</span> · {{ formatDateTime(r.status_changed_at) }}
                  </p>
                </div>
                <StatusBadge :tone="statusTone(r.status)">{{ r.status_label }}</StatusBadge>
              </RouterLink>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </div>
</template>
