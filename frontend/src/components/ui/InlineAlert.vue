<script setup lang="ts">
import { CircleAlert, CircleCheck } from '@lucide/vue'

withDefaults(defineProps<{ tone?: 'danger' | 'success'; correlationId?: string | null }>(), { tone: 'danger' })
</script>

<template>
  <div
    :role="tone === 'danger' ? 'alert' : 'status'"
    class="flex items-start gap-2 rounded-lg px-3 py-2.5 text-sm"
    :class="tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success'"
  >
    <CircleAlert v-if="tone === 'danger'" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
    <CircleCheck v-else class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
    <div>
      <slot />
      <p v-if="correlationId" class="mt-0.5 font-mono text-[11px] opacity-75">ref: {{ correlationId }}</p>
    </div>
  </div>
</template>
