<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'success' | 'warning' | 'danger' | 'neutral' | 'info' }>(), { tone: 'neutral' })

const tones = {
  success: 'bg-success-soft text-success',
  warning: 'bg-warning-soft text-warning',
  danger: 'bg-danger-soft text-danger',
  info: 'bg-blue-soft text-corporate-blue',
  neutral: 'bg-canvas text-muted',
}
</script>

<template>
  <span class="inline-flex max-w-full items-center rounded-md px-2.5 py-1 text-[10px] font-medium leading-[18px]" :class="tones[tone]">
    <slot />
  </span>
</template>
