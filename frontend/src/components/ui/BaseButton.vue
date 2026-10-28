<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue'

withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'navy'
    type?: 'button' | 'submit'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  { variant: 'primary', type: 'button', loading: false, disabled: false, block: false },
)

const variants = {
  primary: 'border border-transparent bg-primary text-on-primary hover:bg-primary-hover',
  secondary: 'border border-line bg-surface text-ink hover:border-corporate-blue/25 hover:bg-canvas',
  ghost: 'border border-transparent text-muted hover:bg-canvas hover:text-ink',
  navy: 'border border-transparent bg-navy text-white hover:bg-navy/85',
}
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading || undefined"
    class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-xs font-medium leading-5 transition-colors disabled:cursor-not-allowed disabled:opacity-55 md:min-h-[42px]"
    :class="[variants[variant], block && 'w-full']"
  >
    <LoaderCircle v-if="loading" class="size-4 animate-spin" aria-hidden="true" />
    <slot />
  </button>
</template>
