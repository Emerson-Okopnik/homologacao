<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import { formatDateTime, formatEvent } from '@/lib/format'
import type { AuditLog, Paginated } from '@/types/api'

const filters = reactive({ event: '', page: 1 })
const query = computed(() => ({ ...filters }))

const { data, error, loading } = useApiQuery(query, (q) =>
  api<Paginated<AuditLog>>('/audit-logs', { query: { event: q.event, page: q.page } }),
)

const expanded = ref<string | null>(null)

function toggle(id: string) {
  expanded.value = expanded.value === id ? null : id
}

function changes(log: AuditLog): string {
  return JSON.stringify({ antes: log.old_values, depois: log.new_values, metadados: log.metadata }, null, 2)
}
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <PageHeader title="Auditoria" description="Registro imutável das ações realizadas no ambiente." />

    <div class="overflow-hidden rounded-2xl border border-line bg-surface">
      <div class="border-b border-line p-4">
        <label class="flex max-w-xs flex-col gap-1.5 text-sm">
          <span class="font-medium">Evento</span>
          <select
            v-model="filters.event"
            class="h-10 rounded-lg border border-line bg-canvas px-3 focus:border-primary focus:outline-none"
            @change="filters.page = 1"
          >
            <option value="">Todos</option>
            <option value="auth.login">Login</option>
            <option value="auth.login_failed">Falha de login</option>
            <option value="auth.logout">Logout</option>
            <option value="user.created">Usuário criado</option>
            <option value="user.updated">Usuário alterado</option>
          </select>
        </label>
      </div>

      <div v-if="error" class="p-4">
        <InlineAlert :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
      </div>

      <ul v-else class="divide-y divide-line" :aria-busy="loading">
        <li v-for="log in data?.data ?? []" :key="log.id" :class="loading && 'opacity-60'">
          <button
            type="button"
            class="flex w-full flex-col gap-1 px-4 py-3 text-left hover:bg-canvas sm:flex-row sm:items-center sm:gap-4"
            :aria-expanded="expanded === log.id"
            @click="toggle(log.id)"
          >
            <span class="w-36 shrink-0 text-xs text-muted tabular-nums">{{ formatDateTime(log.created_at) }}</span>
            <span class="font-medium">{{ formatEvent(log.event) }}</span>
            <span class="text-sm text-muted">{{ log.actor?.name ?? 'Sistema' }}</span>
            <span class="text-xs text-muted sm:ml-auto">{{ log.ip_address }}</span>
          </button>
          <div v-if="expanded === log.id" class="bg-canvas px-4 pb-4">
            <p v-if="log.justification" class="pt-3 text-sm"><strong>Justificativa:</strong> {{ log.justification }}</p>
            <pre class="mt-3 overflow-x-auto rounded-lg bg-sidebar p-3 font-mono text-xs text-white">{{ changes(log) }}</pre>
            <p v-if="log.correlation_id" class="mt-2 font-mono text-[11px] text-muted">ref: {{ log.correlation_id }}</p>
          </div>
        </li>
        <li v-if="!loading && data && data.data.length === 0" class="px-4 py-12 text-center text-sm text-muted">
          Nenhum evento registrado.
        </li>
      </ul>

      <PaginationBar v-if="data" :meta="data.meta" @change="filters.page = $event" />
    </div>
  </div>
</template>
