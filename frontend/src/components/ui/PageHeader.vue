<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <div class="mb-7 flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between md:mb-8">
    <div class="min-w-0">
      <h1 class="text-[25px] font-semibold leading-tight tracking-[-.035em] text-ink text-balance md:text-[28px]">{{ title }}</h1>
      <p v-if="description" class="mt-2.5 max-w-3xl text-xs leading-6 text-muted text-pretty">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2 sm:pt-1 md:gap-3">
      <slot name="actions" />
    </div>
  </div>
</template>
