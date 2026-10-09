<script setup lang="ts">
import { useId } from 'vue'

defineProps<{
  label: string
  type?: string
  autocomplete?: string
  required?: boolean
  readonly?: boolean
  min?: number
  max?: number
  error?: string
  hint?: string
}>()

const model = defineModel<string>({ required: true })
const id = useId()

function onInput(event: Event) {
  model.value = (event.target as HTMLInputElement).value
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-sm font-medium text-ink">{{ label }}</label>
    <input
      :id="id"
      :value="model"
      :type="type ?? 'text'"
      :autocomplete="autocomplete"
      :required="required"
      :readonly="readonly"
      :min="min"
      :max="max"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="error || hint ? `${id}-desc` : undefined"
      class="h-10 rounded-lg border bg-surface px-3 text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
      :class="error ? 'border-danger' : 'border-line'"
      @input="onInput"
    />
    <p v-if="error" :id="`${id}-desc`" class="text-xs text-danger">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-desc`" class="text-xs text-muted">{{ hint }}</p>
  </div>
</template>
