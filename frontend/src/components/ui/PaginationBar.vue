<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import type { Paginated } from '@/types/api'

defineProps<{ meta: Paginated<unknown>['meta'] }>()
defineEmits<{ change: [page: number] }>()
</script>

<template>
  <nav class="flex items-center justify-between border-t border-line px-4 py-3 text-sm text-muted" aria-label="Paginação">
    <p>
      <template v-if="meta.total > 0">{{ meta.from }}–{{ meta.to }} de {{ meta.total }}</template>
      <template v-else>Nenhum registro</template>
    </p>
    <div class="flex items-center gap-1">
      <button
        type="button"
        class="rounded-md p-1.5 hover:bg-canvas disabled:opacity-40"
        :disabled="meta.current_page <= 1"
        @click="$emit('change', meta.current_page - 1)"
      >
        <ChevronLeft class="size-4" aria-hidden="true" />
        <span class="sr-only">Página anterior</span>
      </button>
      <span class="px-2 tabular-nums">{{ meta.current_page }} / {{ meta.last_page }}</span>
      <button
        type="button"
        class="rounded-md p-1.5 hover:bg-canvas disabled:opacity-40"
        :disabled="meta.current_page >= meta.last_page"
        @click="$emit('change', meta.current_page + 1)"
      >
        <ChevronRight class="size-4" aria-hidden="true" />
        <span class="sr-only">Próxima página</span>
      </button>
    </div>
  </nav>
</template>
