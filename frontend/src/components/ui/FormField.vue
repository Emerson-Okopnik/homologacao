<script setup lang="ts">
import { ref, useId } from 'vue'
import { Eye, EyeOff, Mail } from '@lucide/vue'

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
const revealPassword = ref(false)

function onInput(event: Event) {
  model.value = (event.target as HTMLInputElement).value
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-[11px] font-medium leading-5 text-muted">{{ label }}<span v-if="required" aria-hidden="true"> *</span></label>
    <div class="relative">
      <input
      :id="id"
      :value="model"
      :type="type === 'password' && revealPassword ? 'text' : (type ?? 'text')"
      :autocomplete="autocomplete"
      :required="required"
      :readonly="readonly"
      :min="min"
      :max="max"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="error || hint ? `${id}-desc` : undefined"
      class="h-[46px] w-full rounded-[7px] border bg-surface px-3.5 text-xs text-ink transition-shadow placeholder:text-muted/80 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15"
      :class="[error ? 'border-danger' : 'border-line', (type === 'password' || type === 'email') && 'pr-11']"
      @input="onInput"
      />
      <button v-if="type === 'password'" type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-[7px] text-muted hover:text-ink" :aria-label="revealPassword ? 'Ocultar senha' : 'Mostrar senha'" :aria-pressed="revealPassword" @click="revealPassword = !revealPassword">
        <component :is="revealPassword ? EyeOff : Eye" class="size-[18px]" aria-hidden="true" />
      </button>
      <Mail v-else-if="type === 'email'" class="pointer-events-none absolute top-3.5 right-3.5 size-[18px] text-muted" aria-hidden="true" />
    </div>
    <p v-if="error" :id="`${id}-desc`" class="text-[10px] leading-5 text-danger">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-desc`" class="text-[10px] leading-5 text-muted">{{ hint }}</p>
  </div>
</template>
