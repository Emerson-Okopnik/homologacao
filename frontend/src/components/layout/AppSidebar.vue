<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { X, Zap } from '@lucide/vue'
import BrandLogo from './BrandLogo.vue'
import { navigation } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'

defineProps<{ open: boolean }>()
defineEmits<{ close: [] }>()

const auth = useAuthStore()
const route = useRoute()
const initials = computed(() => (auth.user?.name ?? '').split(' ').filter(Boolean).slice(0, 2).map(part => part[0]?.toUpperCase()).join(''))
const roleLabel = computed(() => auth.user?.roles?.map(role => role.name).join(', ') || 'Equipe de homologação')

function isActive(to: string) {
  return to === '/' ? route.path === '/' : route.path === to || route.path.startsWith(`${to}/`)
}

const sections = computed(() =>
  navigation
    .map((section) => ({ ...section, items: section.items.filter((item) =>
        item.superAdmin ? auth.isSuperAdmin : auth.can(item.permission) || item.to === '/',
      ) }))
    .filter((section) => section.items.length > 0),
)
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-30 bg-backdrop/60 lg:hidden"
    aria-hidden="true"
    @click="$emit('close')"
  />
  <aside
    class="fixed inset-y-0 left-0 z-40 flex w-60 shrink-0 flex-col border-r border-line bg-sidebar text-ink transition-transform duration-200 lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0"
    :class="open ? 'translate-x-0' : '-translate-x-full'"
    aria-label="Menu principal"
  >
    <div class="relative px-3 pt-5 pb-7">
      <RouterLink to="/" class="block w-[210px] rounded-md" aria-label="VERIGRID — Dashboard" @click="$emit('close')">
        <BrandLogo />
      </RouterLink>
      <p class="mt-2 px-3 text-[8px] font-medium tracking-[.13em] text-muted">HOMOLOGAÇÃO FOTOVOLTAICA</p>
      <button
        type="button"
        class="absolute top-3 right-3 flex size-8 items-center justify-center rounded-md bg-surface text-muted shadow-sm hover:bg-canvas hover:text-ink lg:hidden"
        @click="$emit('close')"
      >
        <X class="size-5" aria-hidden="true" />
        <span class="sr-only">Fechar menu</span>
      </button>
    </div>

    <nav class="min-h-0 flex-1 overflow-y-auto px-4 pb-5" aria-label="Navegação principal">
      <div v-for="section in sections" :key="section.title" class="mb-5 last:mb-0">
        <p class="px-3 pb-2 text-[9px] font-medium uppercase tracking-[.13em] text-sidebar-muted">
          {{ section.title }}
        </p>
        <ul class="flex flex-col gap-1">
          <li v-for="item in section.items" :key="item.label">
            <RouterLink
              v-if="item.to"
              :to="item.to"
              class="relative flex min-h-10 items-center gap-3 rounded-lg px-3 py-2.5 text-[11px] font-medium transition-colors"
              :class="isActive(item.to) ? 'bg-sidebar-active text-primary before:absolute before:inset-y-3 before:left-0 before:w-[3px] before:rounded-full before:bg-primary' : 'text-sidebar-muted hover:bg-canvas hover:text-ink'"
              :aria-current="isActive(item.to) ? 'page' : undefined"
              @click="$emit('close')"
            >
              <component :is="item.icon" class="size-4" aria-hidden="true" />
              {{ item.label }}
            </RouterLink>
            <span
              v-else
              class="flex min-h-10 cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-[11px] font-medium text-sidebar-muted/60"
              aria-disabled="true"
            >
              <component :is="item.icon" class="size-4" aria-hidden="true" />
              {{ item.label }}
              <span class="ml-auto rounded bg-canvas px-1.5 py-0.5 text-[9px] font-medium uppercase">Em breve</span>
            </span>
          </li>
        </ul>
      </div>
    </nav>

    <div class="mx-5 mb-5 hidden rounded-lg bg-canvas p-3.5 xl:block">
      <Zap class="mb-2 size-4 text-primary" aria-hidden="true" />
      <p class="text-[11px] font-semibold">Da solicitação à conexão.</p>
      <p class="mt-1 text-[10px] leading-relaxed text-muted">Clareza em cada etapa da sua homologação.</p>
    </div>

    <div class="mx-5 flex shrink-0 items-center gap-3 border-t border-line py-5">
      <span class="vg-avatar" aria-hidden="true">{{ initials }}</span>
      <div class="min-w-0">
        <p class="truncate text-[11px] font-medium">{{ auth.user?.name }}</p>
        <p class="mt-0.5 truncate text-[9px] text-muted">{{ roleLabel }}</p>
      </div>
    </div>
  </aside>
</template>
