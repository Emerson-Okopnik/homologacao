<script setup lang="ts">
import { useId } from 'vue'

defineProps<{
  label: string
  options: Array<{ value: string; label: string }>
  required?: boolean
  disabled?: boolean
  error?: string
  hint?: string
  placeholder?: string
}>()

const model = defineModel<string>({ required: true })
const id = useId()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-sm font-medium text-ink">{{ label }}</label>
    <select
      :id="id"
      v-model="model"
      :required="required"
      :disabled="disabled"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="error || hint ? `${id}-desc` : undefined"
      class="h-10 rounded-lg border bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
      :class="error ? 'border-danger' : 'border-line'"
    >
      <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
      <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
    <p v-if="error" :id="`${id}-desc`" class="text-xs text-danger">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-desc`" class="text-xs text-muted">{{ hint }}</p>
  </div>
</template>
