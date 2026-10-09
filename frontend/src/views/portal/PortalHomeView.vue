<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { ChevronRight, FileUp, Plus, Sun } from '@lucide/vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import { formatDate, formatNumber } from '@/lib/format'
import { requestTone } from '@/lib/requestOptions'
import { useAuthStore } from '@/stores/auth'
import type { ClientRequest, PortalSummary } from '@/types/api'

const auth = useAuthStore()
const { data: summary } = useApiQuery(
  () => 1,
  () => api<{ data: PortalSummary }>('/portal/summary'),
)
const { data, error, loading } = useApiQuery(
  () => 1,
  () => api<{ data: ClientRequest[] }>('/portal/requests'),
)

const requests = computed(() => data.value?.data ?? [])
const firstName = computed(() => auth.user?.name?.split(' ')[0] ?? '')

const cards = computed(() => [
  { label: 'Solicitações em andamento', value: summary.value?.data.requests_open ?? 0 },
  { label: 'Aguardando você', value: summary.value?.data.needs_info ?? 0, highlight: true },
  { label: 'Viraram projeto', value: summary.value?.data.converted ?? 0 },
  { label: 'Unidades consumidoras', value: summary.value?.data.units ?? 0 },
])

function pendingDocs(r: ClientRequest): number {
  return Math.max(r.client_progress.required - r.client_progress.sent, 0)
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <section class="flex flex-col gap-1">
      <h1 class="text-balance text-2xl font-semibold text-ink">Olá, {{ firstName }}</h1>
      <p class="text-pretty text-sm text-muted">
        Aqui você acompanha a homologação do seu sistema solar. Você informa a unidade e os equipamentos e envia
        seus documentos; nosso responsável técnico cuida do projeto e de todo o trâmite com a distribuidora.
      </p>
    </section>

    <section aria-label="Resumo" class="grid grid-cols-2 gap-3 md:grid-cols-4">
      <div
        v-for="card in cards"
        :key="card.label"
        class="rounded-xl border bg-surface p-4"
        :class="card.highlight && card.value > 0 ? 'border-danger/40' : 'border-line'"
      >
        <p class="text-xs text-muted">{{ card.label }}</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums" :class="card.highlight && card.value > 0 ? 'text-danger' : 'text-ink'">
          {{ card.value }}
        </p>
      </div>
    </section>

    <section aria-labelledby="minhas" class="flex flex-col gap-3">
      <h2 id="minhas" class="text-sm font-semibold uppercase tracking-wider text-muted">Minhas solicitações</h2>

      <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>

      <div
        v-else-if="!loading && requests.length === 0"
        class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-line bg-surface px-6 py-12 text-center"
      >
        <span class="flex size-12 items-center justify-center rounded-full bg-primary-soft text-primary">
          <Sun class="size-6" aria-hidden="true" />
        </span>
        <p class="font-medium text-ink">Você ainda não abriu nenhuma solicitação</p>
        <p class="max-w-md text-sm text-muted">
          Leva poucos minutos: informe a unidade consumidora, os módulos e o inversor que serão instalados.
        </p>
        <RouterLink
          to="/portal/solicitacoes/nova"
          class="mt-2 inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover"
        >
          <Plus class="size-4" aria-hidden="true" />
          Abrir minha primeira solicitação
        </RouterLink>
      </div>

      <ul v-else class="flex flex-col gap-3" :aria-busy="loading">
        <li v-for="r in requests" :key="r.id">
          <RouterLink
            :to="`/portal/solicitacoes/${r.id}`"
            class="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 transition-colors hover:border-primary/50 md:flex-row md:items-center"
          >
            <div class="flex min-w-0 flex-1 flex-col gap-1">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-sm font-semibold text-ink">{{ r.code }}</span>
                <StatusBadge :tone="requestTone(r.status.value)">{{ r.status.label }}</StatusBadge>
                <StatusBadge v-if="r.project?.process" tone="info">{{ r.project.process.stage.label }}</StatusBadge>
              </div>
              <p class="truncate text-sm text-muted">
                UC {{ r.consumer_unit?.number }} · {{ r.consumer_unit?.distributor?.name }} ·
                {{ formatNumber(r.declared_powers.modules_kwp, 'kWp') }}
              </p>
            </div>
            <div class="flex items-center gap-4 text-sm">
              <span v-if="pendingDocs(r) > 0 && r.status.value !== 'CANCELLED'" class="inline-flex items-center gap-1.5 text-warning">
                <FileUp class="size-4" aria-hidden="true" />
                {{ pendingDocs(r) }} documento(s) pendente(s)
              </span>
              <span v-else class="text-muted">Aberta em {{ formatDate(r.submitted_at ?? r.created_at) }}</span>
              <ChevronRight class="size-4 text-muted" aria-hidden="true" />
            </div>
          </RouterLink>
        </li>
      </ul>
    </section>
  </div>
</template>
