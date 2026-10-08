<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { X } from '@lucide/vue'
import { navigation } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'

defineProps<{ open: boolean }>()
defineEmits<{ close: [] }>()

const auth = useAuthStore()

const sections = computed(() =>
  navigation
    .map((section) => ({ ...section, items: section.items.filter((item) => auth.can(item.permission) || item.to === '/') }))
    .filter((section) => section.items.length > 0),
)
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-30 bg-ink/50 lg:hidden"
    aria-hidden="true"
    @click="$emit('close')"
  />
  <aside
    class="fixed inset-y-0 left-0 z-40 flex w-60 shrink-0 flex-col bg-sidebar text-white transition-transform lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0"
    :class="open ? 'translate-x-0' : '-translate-x-full'"
    aria-label="Menu principal"
  >
    <div class="flex items-center gap-3 px-6 pt-6 pb-8">
      <span class="flex size-10 items-center justify-center rounded-xl bg-primary text-sm font-extrabold">HS</span>
      <div class="min-w-0">
        <p class="text-sm font-bold leading-tight">Homologa Solar</p>
        <p class="truncate text-xs text-sidebar-muted">{{ auth.tenant?.name }}</p>
      </div>
      <button
        type="button"
        class="ml-auto rounded-md p-1 text-sidebar-muted hover:text-white lg:hidden"
        @click="$emit('close')"
      >
        <X class="size-5" aria-hidden="true" />
        <span class="sr-only">Fechar menu</span>
      </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 pb-6">
      <div v-for="section in sections" :key="section.title" class="mb-6">
        <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-sidebar-muted">
          {{ section.title }}
        </p>
        <ul class="flex flex-col gap-0.5">
          <li v-for="item in section.items" :key="item.label">
            <RouterLink
              v-if="item.to"
              :to="item.to"
              class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-muted hover:bg-sidebar-active hover:text-white"
              exact-active-class="!bg-sidebar-active !text-white"
            >
              <component :is="item.icon" class="size-4" aria-hidden="true" />
              {{ item.label }}
            </RouterLink>
            <span
              v-else
              class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-muted/60"
              aria-disabled="true"
            >
              <component :is="item.icon" class="size-4" aria-hidden="true" />
              {{ item.label }}
              <span class="ml-auto rounded bg-white/5 px-1.5 py-0.5 text-[10px] font-semibold uppercase">Em breve</span>
            </span>
          </li>
        </ul>
      </div>
    </nav>
  </aside>
</template>
