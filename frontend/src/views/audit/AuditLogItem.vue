<script setup lang="ts">
import { useId } from 'vue'
import { ChevronDown, Clock3 } from '@lucide/vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { auditSubject, auditTone } from '@/lib/audit'
import { formatDateTime, formatEvent } from '@/lib/format'
import type { AuditLog } from '@/types/api'
import AuditLogDetails from './AuditLogDetails.vue'

defineProps<{ log: AuditLog; expanded: boolean; loading?: boolean }>()
defineEmits<{ toggle: [] }>()
const id = useId()
</script>

<template>
  <li :class="loading && 'opacity-60'">
    <button
      :id="`${id}-trigger`"
      type="button"
      class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-4 py-4 text-left transition-colors hover:bg-canvas sm:px-5 lg:grid-cols-[160px_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,.8fr)_24px] lg:gap-5"
      :aria-expanded="expanded"
      :aria-controls="expanded ? `${id}-details` : undefined"
      :disabled="loading"
      @click="$emit('toggle')"
    >
      <time :datetime="log.created_at" class="col-span-2 flex items-center gap-2 text-[11px] text-muted tabular-nums lg:col-span-1"><Clock3 class="size-3.5 lg:hidden" aria-hidden="true" />{{ formatDateTime(log.created_at) }}</time>
      <div class="min-w-0"><StatusBadge :tone="auditTone(log.event)">{{ formatEvent(log.event) }}</StatusBadge><p v-if="log.subject_type" class="mt-1.5 truncate text-[10px] text-muted">{{ auditSubject(log.subject_type) }}</p></div>
      <span class="row-span-2 justify-self-end text-muted lg:row-span-1 lg:col-start-5 lg:row-start-1"><ChevronDown class="size-4 transition-transform" :class="expanded && 'rotate-180'" aria-hidden="true" /><span class="sr-only">{{ expanded ? 'Ocultar detalhes' : 'Ver detalhes' }}</span></span>
      <span class="min-w-0 truncate text-xs lg:col-start-3 lg:row-start-1">{{ log.actor?.name ?? 'Sistema' }}</span>
      <span class="hidden break-all font-mono text-[11px] text-muted lg:col-start-4 lg:row-start-1 lg:block">{{ log.ip_address ?? '—' }}</span>
    </button>
    <AuditLogDetails v-if="expanded" :id="`${id}-details`" :log="log" role="region" :aria-labelledby="`${id}-trigger`" />
  </li>
</template>
