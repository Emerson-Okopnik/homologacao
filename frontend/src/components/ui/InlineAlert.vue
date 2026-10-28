<script setup lang="ts">
import { CircleAlert, CircleCheck } from '@lucide/vue'

withDefaults(defineProps<{ tone?: 'danger' | 'success'; correlationId?: string | null }>(), { tone: 'danger' })
</script>

<template>
  <div
    :role="tone === 'danger' ? 'alert' : 'status'"
    class="flex items-start gap-3 rounded-lg border px-4 py-3.5 text-xs leading-6"
    :class="tone === 'danger' ? 'border-danger/15 bg-danger-soft text-danger' : 'border-success/15 bg-success-soft text-success'"
  >
    <CircleAlert v-if="tone === 'danger'" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
    <CircleCheck v-else class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
    <div class="min-w-0 break-words">
      <slot />
      <p v-if="correlationId" class="mt-0.5 font-mono text-[11px] opacity-75">ref: {{ correlationId }}</p>
    </div>
  </div>
</template>
