<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { History, RotateCcw, ShieldCheck } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { auditEventOptions } from '@/lib/audit'
import { api, ApiError } from '@/lib/http'
import type { AuditLog, Paginated } from '@/types/api'
import AuditLogItem from './AuditLogItem.vue'

const filters = reactive({ event: '', from: '', to: '', page: 1, per_page: 25 })
const expanded = ref<string | null>(null)
const dateError = computed(() => filters.from && filters.to && filters.from > filters.to ? 'A data final deve ser igual ou posterior à data inicial.' : '')
const filtered = computed(() => !!(filters.event || filters.from || filters.to))
watch([() => filters.event, () => filters.from, () => filters.to, () => filters.per_page], () => (filters.page = 1))
const query = computed(() => ({ ...filters }))
watch(query, () => (expanded.value = null))

const { data, error, loading } = useApiQuery(query, (q) => {
  if (q.from && q.to && q.from > q.to) return Promise.reject(new ApiError(422, 'A data final deve ser igual ou posterior à data inicial.'))
  return api<Paginated<AuditLog>>('/audit-logs', {
    query: { ...q, event_match: q.event ? (q.event.endsWith('.') ? 'prefix' : 'exact') : undefined },
  })
})

function clearFilters() {
  filters.event = ''
  filters.from = ''
  filters.to = ''
}
</script>

<template>
  <div class="mx-auto w-full max-w-[1240px]">
    <PageHeader title="Auditoria" description="Acompanhe acessos, alterações e ações realizadas no ambiente.">
      <template #actions>
        <span class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-[11px] text-muted"><ShieldCheck class="size-4 text-primary" aria-hidden="true" />Registros somente para consulta</span>
      </template>
    </PageHeader>

    <div class="vg-panel overflow-hidden">
      <div class="grid gap-4 border-b border-line p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-[minmax(200px,1.5fr)_minmax(150px,1fr)_minmax(150px,1fr)_auto] lg:items-start">
        <SelectField v-model="filters.event" label="Evento" placeholder="Todos os eventos" :options="auditEventOptions" />
        <FormField v-model="filters.from" label="Data inicial" type="date" :error="error?.firstError('from')" />
        <FormField v-model="filters.to" label="Data final" type="date" :error="dateError || error?.firstError('to')" />
        <BaseButton variant="secondary" class="self-end lg:mt-[26px] lg:self-start" :disabled="!filtered" @click="clearFilters"><RotateCcw class="size-3.5" aria-hidden="true" />Limpar filtros</BaseButton>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-4 sm:px-5">
        <h2 class="flex items-center gap-2 text-xs font-semibold"><History class="size-4 text-primary" aria-hidden="true" />Histórico de atividades</h2>
        <p v-if="data" class="text-[11px] text-muted" role="status">{{ data.meta.total }} {{ data.meta.total === 1 ? 'evento encontrado' : 'eventos encontrados' }}</p>
      </div>

      <div v-if="error && !dateError" class="px-4 pb-4 sm:px-5"><InlineAlert :correlation-id="error.correlationId">{{ error.message }}</InlineAlert></div>
      <p v-else-if="loading && !data" class="border-t border-line px-4 py-14 text-center text-xs text-muted" role="status">Carregando eventos de auditoria…</p>
      <template v-else>
        <div class="hidden grid-cols-[160px_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,.8fr)_24px] gap-5 border-y border-line bg-canvas px-5 py-3 text-[10px] font-medium uppercase tracking-wide text-muted lg:grid" aria-hidden="true">
          <span>Data e hora</span><span>Evento / recurso</span><span>Responsável</span><span>Endereço IP</span><span />
        </div>
        <ul class="divide-y divide-line" :aria-busy="loading" aria-label="Eventos de auditoria">
          <AuditLogItem v-for="log in data?.data ?? []" :key="log.id" :log="log" :expanded="expanded === log.id" :loading="loading || !!dateError" @toggle="expanded = expanded === log.id ? null : log.id" />
          <li v-if="!loading && data && data.data.length === 0" class="flex flex-col items-center gap-2 px-4 py-14 text-center text-xs text-muted">
            <History class="mb-2 size-7 text-muted" aria-hidden="true" />
            <p class="font-medium text-ink">{{ filtered ? 'Nenhum evento encontrado para estes filtros.' : 'Nenhum evento registrado.' }}</p>
            <p>{{ filtered ? 'Ajuste o evento ou o período para consultar outras atividades.' : 'Os próximos acessos e alterações aparecerão aqui.' }}</p>
          </li>
        </ul>
      </template>

      <PaginationBar v-if="data" v-model:per-page="filters.per_page" :meta="data.meta" :disabled="loading || !!error" @change="filters.page = $event" />
    </div>
  </div>
</template>
