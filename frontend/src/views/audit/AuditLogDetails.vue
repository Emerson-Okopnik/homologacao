<script setup lang="ts">
import { computed } from 'vue'
import { Info } from '@lucide/vue'
import { auditChanges, auditFieldLabel, auditSubject, auditValue } from '@/lib/audit'
import type { AuditLog } from '@/types/api'

const props = defineProps<{ log: AuditLog }>()
const changes = computed(() => auditChanges(props.log))
const metadata = computed(() => Object.entries(props.log.metadata ?? {}).filter(([, value]) => value !== null && value !== undefined))
const technical = computed(() => JSON.stringify({
  event: props.log.event, subject_type: props.log.subject_type,
  old_values: props.log.old_values, new_values: props.log.new_values,
  metadata: props.log.metadata, correlation_id: props.log.correlation_id,
}, null, 2))
</script>

<template>
  <div class="space-y-5 border-t border-line bg-canvas/60 p-4 sm:p-5">
    <dl class="grid gap-4 text-xs sm:grid-cols-2 xl:grid-cols-4">
      <div><dt class="text-[11px] text-muted">Responsável</dt><dd class="mt-1.5 break-words font-medium">{{ log.actor?.name ?? 'Sistema' }}</dd></div>
      <div><dt class="text-[11px] text-muted">Recurso</dt><dd class="mt-1.5 break-words font-medium">{{ auditSubject(log.subject_type) }}</dd></div>
      <div><dt class="text-[11px] text-muted">Endereço IP</dt><dd class="mt-1.5 break-all font-mono">{{ log.ip_address ?? 'Não informado' }}</dd></div>
      <div><dt class="text-[11px] text-muted">Referência</dt><dd class="mt-1.5 break-all font-mono text-[11px] leading-5">{{ log.correlation_id ?? 'Não informada' }}</dd></div>
    </dl>

    <div v-if="log.justification" class="rounded-lg border border-line bg-surface p-4">
      <h3 class="text-xs font-semibold">Justificativa</h3>
      <p class="mt-2 whitespace-pre-wrap break-words text-xs leading-6 text-muted">{{ log.justification }}</p>
    </div>

    <section v-if="changes.length" aria-label="Alterações registradas" class="overflow-hidden rounded-lg border border-line bg-surface">
      <h3 class="border-b border-line px-4 py-3 text-xs font-semibold">Alterações registradas <span class="ml-1 font-normal text-muted">({{ changes.length }})</span></h3>
      <div class="hidden grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_minmax(0,1.5fr)] gap-4 border-b border-line bg-canvas px-4 py-2.5 text-[10px] font-medium uppercase tracking-wide text-muted sm:grid" aria-hidden="true">
        <span>Campo</span><span>Antes</span><span>Depois</span>
      </div>
      <dl class="divide-y divide-line">
        <div v-for="change in changes" :key="change.key" class="grid min-w-0 gap-3 px-4 py-4 text-xs sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_minmax(0,1.5fr)] sm:gap-4">
          <dt class="break-words font-medium">{{ change.label }}</dt>
          <dd class="min-w-0"><span class="mb-1 block text-[10px] font-medium text-muted sm:sr-only">Antes</span><pre class="whitespace-pre-wrap break-all font-sans leading-6 text-muted">{{ change.before }}</pre></dd>
          <dd class="min-w-0"><span class="mb-1 block text-[10px] font-medium text-muted sm:sr-only">Depois</span><pre class="whitespace-pre-wrap break-all font-sans leading-6 text-ink">{{ change.after }}</pre></dd>
        </div>
      </dl>
    </section>

    <div v-else class="flex items-start gap-2.5 rounded-lg border border-line bg-surface px-4 py-3 text-xs leading-6 text-muted">
      <Info class="mt-1 size-4 shrink-0" aria-hidden="true" />
      <p>{{ log.event?.startsWith('auth.') ? 'Evento de autenticação. Não há alterações de dados associadas.' : 'Este evento não possui alterações de campos registradas.' }}</p>
    </div>

    <section v-if="metadata.length" aria-label="Informações adicionais" class="rounded-lg border border-line bg-surface p-4">
      <h3 class="text-xs font-semibold">Informações adicionais</h3>
      <dl class="mt-3 grid gap-4 sm:grid-cols-2">
        <div v-for="[key, value] in metadata" :key="key" class="min-w-0 text-xs">
          <dt class="text-[11px] text-muted">{{ auditFieldLabel(key) }}</dt>
          <dd class="mt-1.5 whitespace-pre-wrap break-all leading-6">{{ auditValue(value) }}</dd>
        </div>
      </dl>
    </section>

    <details class="group rounded-lg border border-line bg-surface">
      <summary class="px-4 py-3 text-[11px] font-medium text-muted hover:text-ink">Ver dados técnicos</summary>
      <pre class="max-h-80 overflow-auto border-t border-line bg-canvas p-4 font-mono text-[11px] leading-6 text-ink" tabindex="0" aria-label="Dados técnicos do evento">{{ technical }}</pre>
    </details>
  </div>
</template>
