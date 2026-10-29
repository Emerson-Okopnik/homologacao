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
    <label :for="id" class="text-[11px] font-medium leading-5 text-muted">{{ label }}<span v-if="required" aria-hidden="true"> *</span></label>
    <textarea
      :id="id"
      v-model="model"
      :rows="rows"
      :required="required"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="error || hint ? `${id}-desc` : undefined"
      class="resize-y rounded-[7px] border bg-surface px-3.5 py-3 text-xs leading-6 text-ink transition-shadow focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15"
      :class="error ? 'border-danger' : 'border-line'"
    />
    <p v-if="error" :id="`${id}-desc`" class="text-[10px] leading-5 text-danger">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-desc`" class="text-[10px] leading-5 text-muted">{{ hint }}</p>
  </div>
</template>
