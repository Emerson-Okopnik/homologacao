<script setup lang="ts">
import { useId } from 'vue'

withDefaults(defineProps<{ label: string; required?: boolean; error?: string; hint?: string; rows?: number }>(), {
  rows: 3,
})

const model = defineModel<string>({ required: true })
const id = useId()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-sm font-medium text-ink">{{ label }}</label>
    <textarea
      :id="id"
      v-model="model"
      :rows="rows"
      :required="required"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="error || hint ? `${id}-desc` : undefined"
      class="rounded-lg border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
      :class="error ? 'border-danger' : 'border-line'"
    />
    <p v-if="error" :id="`${id}-desc`" class="text-xs text-danger">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-desc`" class="text-xs text-muted">{{ hint }}</p>
  </div>
</template>
