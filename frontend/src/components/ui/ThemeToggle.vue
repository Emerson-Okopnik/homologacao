<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue'
import { useTheme } from '@/lib/theme'

const { isDark, toggleTheme } = useTheme()
</script>

<template>
  <button
    type="button"
    class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-line bg-surface text-muted transition-colors hover:bg-canvas hover:text-ink"
    :aria-label="isDark ? 'Ativar tema claro' : 'Ativar tema escuro'"
    :title="isDark ? 'Ativar tema claro' : 'Ativar tema escuro'"
    :aria-pressed="isDark"
    @click="toggleTheme"
  >
    <Sun v-if="isDark" class="size-[18px]" aria-hidden="true" />
    <Moon v-else class="size-[18px]" aria-hidden="true" />
  </button>
</template>
