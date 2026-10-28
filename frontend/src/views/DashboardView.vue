<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowRight, CalendarX, ChevronRight, FileSearch, FolderOpen, Hourglass, ListChecks, PlugZap, Plus, Timer, Zap } from '@lucide/vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import { formatDateTime, formatNumber, stageTone, statusTone } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { DashboardData } from '@/types/api'

const auth = useAuthStore()
const firstName = computed(() => auth.user?.name.split(' ')[0] ?? '')
const { data, error, loading } = useApiQuery(
  () => 'dashboard',
  () => api<{ data: DashboardData }>('/dashboard').then((r) => r.data),
)
const phaseColors = ['#1E3A8A', '#0A1F5C', '#7187B6', '#0E9F6E', '#19B98A', '#96DCC5']
const phaseTotal = computed(() => data.value?.by_stage.reduce((sum, phase) => sum + phase.total, 0) ?? 0)
const kpis = computed(() => {
  const t = data.value?.totals
  if (!t) return []
  return [
    { label: 'Processos ativos', value: formatNumber(t.active), icon: FolderOpen, foot: 'Homologações em andamento', featured: true },
    { label: 'Na distribuidora', value: formatNumber(t.waiting_distributor), icon: Hourglass, foot: 'Análise externa e vistoria' },
    { label: 'Em correção', value: formatNumber(t.in_correction), icon: ListChecks, foot: 'Projetos em fase de ajustes' },
    { label: 'Processos concluídos', value: formatNumber(t.completed), icon: PlugZap, foot: 'Sistemas conectados à rede' },
  ]
})
const supportingKpis = computed(() => {
  const t = data.value?.totals
  if (!t) return []
  return [
    { label: 'Documentos para revisar', value: formatNumber(t.documents_to_review), icon: FileSearch },
    { label: 'Prazos vencidos', value: formatNumber(t.overdue), icon: CalendarX },
    { label: 'Potência concluída', value: formatNumber(t.completed_power_kw, 'kW'), icon: Zap },
    { label: 'Tempo médio até aprovação', value: t.avg_approval_days === null ? '—' : `${formatNumber(t.avg_approval_days)} dias`, icon: Timer },
  ]
})
const attention = computed(() => {
  const t = data.value?.totals
  if (!t) return []
  return [
    { title: 'Revisar documentos', description: `${formatNumber(t.documents_to_review)} arquivos aguardam revisão`, icon: FileSearch, to: '/documentos', permission: 'documents.view' as const },
    { title: 'Acompanhar prazos', description: `${formatNumber(t.overdue)} processos com prazo vencido`, icon: CalendarX, to: '/processos', permission: 'homologations.view' as const },
    { title: 'Tratar pendências', description: `${formatNumber(t.open_pendencies)} pendências abertas na operação`, icon: ListChecks, to: '/kanban', permission: 'homologations.view' as const },
  ].filter((item) => auth.can(item.permission))
})
</script>

<template>
  <div class="mx-auto w-full max-w-[1240px]">
    <PageHeader :title="`Olá, ${firstName}.`" description="Uma visão clara do que precisa avançar na sua operação.">
      <template v-if="auth.can('projects.manage')" #actions>
        <RouterLink to="/projetos/novo" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-primary px-5 text-sm font-medium text-on-primary transition-colors hover:bg-primary-hover">
          <Plus class="size-4" aria-hidden="true" />Novo projeto
        </RouterLink>
      </template>
    </PageHeader>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading && !data" class="vg-panel py-16 text-center text-sm text-muted" role="status">Carregando indicadores…</p>

    <template v-else-if="data">
      <section class="dashboard-hero mb-6 flex flex-col justify-between gap-5 rounded-xl px-6 py-7 text-white sm:px-7 lg:flex-row lg:items-center" aria-label="Resumo da operação">
        <div class="relative z-10">
          <p class="mb-3 text-[10px] font-medium tracking-[0.16em] text-[#A1DEC9]">SUA OPERAÇÃO, CONECTADA</p>
          <h2 class="text-xl font-medium tracking-tight sm:text-2xl">Cada etapa, mais perto da conexão.</h2>
          <p class="mt-3 max-w-2xl text-xs leading-relaxed text-[#D3DFF6] sm:text-sm">
            {{ formatNumber(data.totals.open_pendencies) }} pendências abertas e {{ formatNumber(data.totals.documents_to_review) }} documentos para revisar. Comece pelo que depende da equipe.
          </p>
        </div>
        <RouterLink v-if="auth.can('homologations.view')" to="/processos" class="relative z-10 inline-flex min-h-11 shrink-0 items-center justify-center gap-3 self-start rounded-lg bg-white px-5 text-xs font-medium text-[#0A1F5C] transition-colors hover:bg-[#EDF2FC] lg:self-auto">
          <ArrowRight class="size-4" aria-hidden="true" />Ver homologações
        </RouterLink>
      </section>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicadores principais">
        <article v-for="kpi in kpis" :key="kpi.label" class="relative min-w-0 rounded-xl border p-5" :class="kpi.featured ? 'border-[#0A1F5C] bg-[#0A1F5C] text-white' : 'border-line bg-surface'">
          <span class="absolute right-4 top-4 flex size-8 items-center justify-center rounded-lg" :class="kpi.featured ? 'bg-white/10 text-[#96DCC5]' : 'bg-canvas text-primary'">
            <component :is="kpi.icon" class="size-4" aria-hidden="true" />
          </span>
          <p class="pr-9 text-xs" :class="kpi.featured ? 'text-[#D3DFF6]' : 'text-muted'">{{ kpi.label }}</p>
          <p class="mt-4 text-3xl font-semibold leading-none tracking-tight tabular-nums">{{ kpi.value }}</p>
          <p class="mt-4 text-[11px] leading-relaxed" :class="kpi.featured ? 'text-[#A1DEC9]' : 'text-muted'">{{ kpi.foot }}</p>
        </article>
      </section>

      <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="vg-panel p-5 sm:p-6" aria-labelledby="by-stage">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="by-stage" class="text-sm font-semibold">Processos por fase</h2>
            <RouterLink v-if="auth.can('homologations.view')" to="/kanban" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">Abrir Kanban<ChevronRight class="size-3.5" aria-hidden="true" /></RouterLink>
          </div>
          <p class="mt-2 text-xs text-muted">{{ formatNumber(phaseTotal) }} homologações em andamento</p>
          <div class="mt-7 flex h-6 gap-0.5 overflow-hidden rounded-sm bg-canvas" aria-hidden="true">
            <div v-for="(phase, index) in data.by_stage" :key="phase.stage" :style="{ width: phaseTotal ? `${phase.total / phaseTotal * 100}%` : '0%', backgroundColor: phaseColors[index % phaseColors.length] }" />
          </div>
          <ul class="mt-6 grid gap-x-6 gap-y-4 sm:grid-cols-2">
            <li v-for="(phase, index) in data.by_stage" :key="phase.stage" class="flex min-w-0 items-center gap-2.5 text-xs">
              <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: phaseColors[index % phaseColors.length] }" aria-hidden="true" />
              <span class="min-w-0 flex-1 text-muted">{{ phase.label }}</span>
              <span class="font-semibold tabular-nums">{{ formatNumber(phase.total) }}</span>
            </li>
          </ul>
        </section>

        <section class="vg-panel p-5 sm:p-6" aria-labelledby="attention">
          <h2 id="attention" class="text-sm font-semibold">Precisa da sua atenção</h2>
          <p class="mt-2 text-xs text-muted">Priorize os próximos passos da equipe.</p>
          <ul class="mt-4 divide-y divide-line">
            <li v-for="item in attention" :key="item.title">
              <RouterLink :to="item.to" class="group flex items-center gap-3 rounded-lg py-3.5">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-canvas text-primary"><component :is="item.icon" class="size-4" aria-hidden="true" /></span>
                <span class="min-w-0 flex-1"><span class="block text-xs font-medium group-hover:text-primary">{{ item.title }}</span><span class="mt-1 block text-[11px] text-muted">{{ item.description }}</span></span>
                <ChevronRight class="size-4 shrink-0 text-muted" aria-hidden="true" />
              </RouterLink>
            </li>
          </ul>
          <p v-if="!attention.length" class="py-8 text-sm text-muted">Os indicadores acima resumem o andamento da operação.</p>
        </section>
      </div>

      <section class="vg-panel mt-6 overflow-hidden" aria-labelledby="recent">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5 sm:px-6">
          <h2 id="recent" class="text-sm font-semibold">Movimentações recentes</h2>
          <RouterLink v-if="auth.can('homologations.view')" to="/processos" class="inline-flex items-center gap-2 text-xs font-medium text-primary hover:underline">Ver homologações<ArrowRight class="size-4" aria-hidden="true" /></RouterLink>
        </div>
        <p v-if="data.recent.length === 0" class="px-6 pb-8 text-sm text-muted">Nenhum processo ainda. Cadastre um cliente e crie o primeiro projeto.</p>
        <div v-else class="overflow-x-auto">
          <table class="vg-table w-full min-w-[660px] text-left text-xs">
            <thead class="bg-canvas text-[10px] font-medium uppercase tracking-wide text-muted"><tr><th scope="col" class="px-6 py-3">Processo</th><th scope="col" class="px-6 py-3">Cliente</th><th scope="col" class="px-6 py-3">Fase</th><th scope="col" class="px-6 py-3">Situação</th><th scope="col" class="px-6 py-3">Atualização</th></tr></thead>
            <tbody class="divide-y divide-line">
              <tr v-for="record in data.recent" :key="record.id" class="transition-colors hover:bg-canvas/60">
                <td class="px-6 py-4"><RouterLink :to="`/processos/${record.id}`" class="font-medium hover:text-primary hover:underline">{{ record.code }}</RouterLink></td>
                <td class="px-6 py-4 font-medium">{{ record.client ?? '—' }}</td>
                <td class="px-6 py-4"><StatusBadge :tone="stageTone(record.stage, record.status)">{{ record.stage_label }}</StatusBadge></td>
                <td class="px-6 py-4"><StatusBadge :tone="statusTone(record.status)">{{ record.status_label }}</StatusBadge></td>
                <td class="px-6 py-4 text-muted tabular-nums">{{ formatDateTime(record.stage_changed_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicadores complementares">
        <article v-for="kpi in supportingKpis" :key="kpi.label" class="vg-panel flex min-w-0 items-start gap-3 p-4">
          <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-canvas text-primary"><component :is="kpi.icon" class="size-4" aria-hidden="true" /></span>
          <div class="min-w-0"><p class="text-[11px] leading-relaxed text-muted">{{ kpi.label }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ kpi.value }}</p></div>
        </article>
      </section>
    </template>
  </div>
</template>

<style scoped>
.dashboard-hero { position: relative; overflow: hidden; background: #0a1f5c; }
.dashboard-hero::before { content: ''; position: absolute; inset: 0 0 0 60%; background: #1e3a8a; opacity: .28; clip-path: polygon(45% 0, 100% 0, 100% 100%, 0 100%); }
.dashboard-hero::after { content: ''; position: absolute; right: 12%; bottom: -60px; width: 120px; height: 180px; background: #0e9f6e; opacity: .1; transform: skew(-28deg); }
</style>
