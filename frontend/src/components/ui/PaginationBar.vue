<script setup lang="ts">
import { computed, watch } from 'vue'
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import BaseSelect from './BaseSelect.vue'
import type { Paginated } from '@/types/api'

const props = withDefaults(defineProps<{
  meta: Paginated<unknown>['meta']
  disabled?: boolean
  perPage?: number
}>(), { disabled: false })
const emit = defineEmits<{
  change: [page: number]
  'update:perPage': [perPage: number]
}>()

const lastPage = computed(() => Math.max(1, props.meta.last_page))
const currentPage = computed(() => Math.min(Math.max(1, props.meta.current_page), lastPage.value))
const pageSizes = computed(() => [...new Set([10, 20, 25, 50, 100, props.perPage ?? props.meta.per_page])].sort((a, b) => a - b))
const pages = computed(() => {
  const start = Math.max(2, Math.min(currentPage.value - 1, lastPage.value - 4))
  const end = Math.min(lastPage.value - 1, Math.max(currentPage.value + 1, 5))
  const items: (number | string)[] = [1]
  if (start > 2) items.push('before')
  for (let page = start; page <= end; page++) items.push(page)
  if (end < lastPage.value - 1) items.push('after')
  if (lastPage.value > 1) items.push(lastPage.value)
  return items
})

function changePage(page: number) {
  if (!props.disabled && page >= 1 && page <= lastPage.value && page !== props.meta.current_page) emit('change', page)
}

function changePageSize(size: number) {
  if (!props.disabled && size !== (props.perPage ?? props.meta.per_page)) emit('update:perPage', size)
}

// Uma revisão ou exclusão pode remover a última linha da página atual.
watch(() => [props.meta, props.disabled] as const, () => {
  if (!props.disabled && props.meta.current_page > lastPage.value) emit('change', lastPage.value)
}, { immediate: true })
</script>

<template>
  <nav class="flex flex-col gap-4 border-t border-line px-4 py-4 text-xs text-muted sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:px-5" aria-label="Paginação" :aria-busy="disabled">
    <p class="tabular-nums" role="status" aria-live="polite" aria-atomic="true">
      <template v-if="meta.total > 0 && meta.from !== null && meta.to !== null">
        Exibindo <span class="font-medium text-ink">{{ meta.from }}–{{ meta.to }}</span> de <span class="font-medium text-ink">{{ meta.total }}</span> registros
      </template>
      <template v-else-if="meta.total === 0">Nenhum registro</template>
      <template v-else>{{ meta.total }} registros</template>
    </p>

    <div class="flex flex-wrap items-center justify-between gap-4 sm:justify-end">
      <div class="flex items-center gap-2 whitespace-nowrap">
        Por página
        <BaseSelect
          :model-value="perPage ?? meta.per_page"
          :options="pageSizes.map(value => ({ value, label: String(value) }))"
          :disabled="disabled"
          aria-label="Registros por página"
          size="sm"
          class="min-w-[68px]"
          @update:model-value="changePageSize"
        />
      </div>

      <div class="flex items-center gap-1">
        <button
          type="button"
          class="flex size-9 shrink-0 items-center justify-center rounded-md border border-line bg-surface transition-colors hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="disabled || currentPage <= 1"
          @click="changePage(currentPage - 1)"
        >
          <ChevronLeft class="size-4" aria-hidden="true" />
          <span class="sr-only">Página anterior</span>
        </button>
        <span class="px-2 tabular-nums sm:hidden">{{ currentPage }} / {{ lastPage }}</span>
        <div class="hidden items-center gap-1 sm:flex">
          <template v-for="page in pages" :key="page">
            <button
              v-if="typeof page === 'number'"
              type="button"
              :aria-label="`Página ${page}`"
              :aria-current="page === currentPage ? 'page' : undefined"
              :disabled="disabled"
              class="flex size-9 items-center justify-center rounded-md border text-xs font-medium tabular-nums transition-colors disabled:cursor-not-allowed disabled:opacity-40"
              :class="page === currentPage ? 'border-primary bg-primary text-on-primary' : 'border-line bg-surface text-muted hover:bg-canvas hover:text-ink'"
              @click="changePage(page)"
            >{{ page }}</button>
            <span v-else class="px-1" aria-hidden="true">…</span>
          </template>
        </div>
        <button
          type="button"
          class="flex size-9 shrink-0 items-center justify-center rounded-md border border-line bg-surface transition-colors hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="disabled || currentPage >= lastPage"
          @click="changePage(currentPage + 1)"
        >
          <ChevronRight class="size-4" aria-hidden="true" />
          <span class="sr-only">Próxima página</span>
        </button>
      </div>
    </div>
  </nav>
</template>
