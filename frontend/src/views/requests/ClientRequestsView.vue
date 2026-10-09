<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ChevronRight, Inbox } from '@lucide/vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import { formatDate, formatNumber } from '@/lib/format'
import { requestStatusTabs, requestTone } from '@/lib/requestOptions'
import type { ClientRequest, ClientRequestStatus } from '@/types/api'

const status = ref<ClientRequestStatus | ''>('SUBMITTED')
const search = ref('')
const mine = ref(false)

const { data, error, loading } = useApiQuery(
  () => ({ status: status.value, search: search.value, mine: mine.value }),
  (q) =>
    api<{ data: ClientRequest[] }>('/client-requests', {
      query: { status: q.status || undefined, search: q.search || undefined, mine: q.mine || undefined },
    }),
)
const requests = computed(() => data.value?.data ?? [])
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      title="Solicitações de clientes"
      description="Pedidos abertos pelos próprios clientes no portal. Faça a triagem, designe o responsável técnico e converta em projeto."
    />

    <div class="flex flex-col gap-3 md:flex-row md:items-center">
      <div role="tablist" aria-label="Status" class="flex flex-wrap gap-1 rounded-xl border border-line bg-surface p-1">
        <button
          v-for="tab in requestStatusTabs"
          :key="tab.value"
          role="tab"
          type="button"
          :aria-selected="status === tab.value"
          class="h-8 rounded-lg px-3 text-sm font-medium transition-colors"
          :class="status === tab.value ? 'bg-primary text-white' : 'text-muted hover:text-ink'"
          @click="status = tab.value"
        >
          {{ tab.label }}
        </button>
      </div>
      <label class="flex items-center gap-2 text-sm text-ink">
        <input v-model="mine" type="checkbox" class="size-4 accent-primary" />
        Só as minhas
      </label>
      <div class="md:ml-auto md:w-72">
        <SearchInput v-model="search" label="Buscar por código, cliente ou UC" />
      </div>
    </div>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>

    <div
      v-else-if="!loading && requests.length === 0"
      class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-line bg-surface px-6 py-12 text-center"
    >
      <Inbox class="size-8 text-muted" aria-hidden="true" />
      <p class="font-medium text-ink">Nenhuma solicitação aqui</p>
      <p class="text-sm text-muted">Quando um cliente abrir um pedido pelo portal, ele aparece nesta fila.</p>
    </div>

    <div v-else class="overflow-hidden rounded-2xl border border-line bg-surface" :aria-busy="loading">
      <table class="w-full text-sm">
        <thead class="border-b border-line bg-canvas text-left text-xs uppercase tracking-wider text-muted">
          <tr>
            <th scope="col" class="px-4 py-3 font-medium">Solicitação</th>
            <th scope="col" class="px-4 py-3 font-medium">Cliente / UC</th>
            <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">Potência</th>
            <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Docs do cliente</th>
            <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">RT</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Abrir</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="r in requests" :key="r.id" class="hover:bg-canvas">
            <td class="px-4 py-3">
              <div class="flex flex-col gap-1">
                <span class="font-mono font-semibold text-ink">{{ r.code }}</span>
                <StatusBadge :tone="requestTone(r.status.value)" class="self-start">{{ r.status.label }}</StatusBadge>
              </div>
            </td>
            <td class="px-4 py-3">
              <p class="font-medium text-ink">{{ r.client?.name }}</p>
              <p class="text-xs text-muted">UC {{ r.consumer_unit?.number }} · {{ r.consumer_unit?.distributor?.name }} · {{ formatDate(r.submitted_at ?? r.created_at) }}</p>
            </td>
            <td class="hidden px-4 py-3 tabular-nums text-ink md:table-cell">{{ formatNumber(r.declared_powers.modules_kwp, 'kWp') }}</td>
            <td class="hidden px-4 py-3 tabular-nums lg:table-cell" :class="r.client_progress.sent < r.client_progress.required ? 'text-warning' : 'text-success'">
              {{ r.client_progress.sent }}/{{ r.client_progress.required }}
            </td>
            <td class="hidden px-4 py-3 lg:table-cell">
              <span v-if="r.technical_responsible" class="text-ink">{{ r.technical_responsible.name }}</span>
              <span v-else class="text-muted">Não designado</span>
            </td>
            <td class="px-4 py-3 text-right">
              <RouterLink :to="`/solicitacoes/${r.id}`" class="inline-flex size-8 items-center justify-center rounded-lg text-muted hover:bg-surface hover:text-ink">
                <ChevronRight class="size-4" aria-hidden="true" /><span class="sr-only">Abrir {{ r.code }}</span>
              </RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
