<script setup lang="ts">
import { Search } from '@lucide/vue'

defineProps<{ label: string; placeholder?: string }>()
const model = defineModel<string>({ required: true })
</script>

<template>
  <label class="relative block w-full max-w-sm">
    <span class="sr-only">{{ label }}</span>
    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
    <input
      v-model="model"
      type="search"
      :placeholder="placeholder ?? label"
      class="h-10 w-full rounded-lg border border-line bg-canvas pr-3 pl-9 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
    />
  </label>
</template>
