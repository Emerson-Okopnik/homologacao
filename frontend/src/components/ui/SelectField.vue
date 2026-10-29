<script setup lang="ts">
import { useId } from 'vue'
import BaseSelect from './BaseSelect.vue'

defineProps<{
  label: string
  options: Array<{ value: string; label: string; disabled?: boolean }>
  required?: boolean
  disabled?: boolean
  error?: string
  hint?: string
  placeholder?: string
  name?: string
  hideLabel?: boolean
}>()

const model = defineModel<string>({ required: true })
const id = useId()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :id="`${id}-label`" :for="id" :class="hideLabel ? 'sr-only' : 'text-[11px] font-medium leading-5 text-muted'">{{ label }}<span v-if="required" aria-hidden="true"> *</span></label>
    <BaseSelect
      :id="id"
      v-model="model"
      :name="name"
      :options="options"
      :placeholder="placeholder"
      :required="required"
      :disabled="disabled"
      :invalid="!!error"
      :aria-labelledby="`${id}-label`"
      :aria-describedby="error || hint ? `${id}-desc` : undefined"
    />
    <p v-if="error" :id="`${id}-desc`" class="text-[10px] leading-5 text-danger">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-desc`" class="text-[10px] leading-5 text-muted">{{ hint }}</p>
  </div>
</template>
