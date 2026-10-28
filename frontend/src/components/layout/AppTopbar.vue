<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, LogOut, Menu } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'
import ThemeToggle from '@/components/ui/ThemeToggle.vue'

defineEmits<{ toggleMenu: [] }>()

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const loggingOut = ref(false)

const initials = computed(() =>
  (auth.user?.name ?? '')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join(''),
)

const roleLabel = computed(() => auth.user?.roles?.map((role) => role.name).join(', ') ?? '')

async function logout() {
  loggingOut.value = true
  try {
    await auth.logout()
  } finally {
    loggingOut.value = false
    await router.replace({ name: 'login' })
  }
}
</script>

<template>
  <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-line bg-surface/95 px-4 backdrop-blur md:h-20 md:px-8">
    <a href="#conteudo" class="sr-only focus:not-sr-only">Pular para o conteúdo</a>
    <button
      type="button"
      class="flex size-10 shrink-0 items-center justify-center rounded-lg text-muted hover:bg-canvas hover:text-ink lg:hidden"
      @click="$emit('toggleMenu')"
    >
      <Menu class="size-5" aria-hidden="true" />
      <span class="sr-only">Abrir menu</span>
    </button>

    <div class="flex min-w-0 items-center gap-2.5 text-[11px] md:gap-3.5">
      <span class="hidden text-muted xl:inline">Workspace</span>
      <ChevronRight class="hidden size-3 text-muted xl:block" aria-hidden="true" />
      <p class="truncate font-medium">{{ auth.tenant?.name || 'VERIGRID' }}</p>
      <span class="hidden text-line md:inline">/</span>
      <p class="hidden truncate text-muted md:block">{{ route.meta.title || 'Homologação' }}</p>
    </div>

    <div class="ml-auto flex shrink-0 items-center gap-2 border-l border-line pl-3 md:gap-3 md:pl-5">
      <ThemeToggle />
      <span
        class="vg-avatar hidden sm:flex"
        aria-hidden="true"
      >
        {{ initials }}
      </span>
      <div class="hidden max-w-40 sm:block">
        <p class="truncate text-[11px] font-medium leading-tight">{{ auth.user?.name }}</p>
        <p class="mt-1 truncate text-[9px] text-muted">{{ roleLabel }}</p>
      </div>
      <button
        type="button"
        class="flex size-9 items-center justify-center rounded-lg text-muted transition-colors hover:bg-canvas hover:text-ink disabled:opacity-50"
        :disabled="loggingOut"
        @click="logout"
      >
        <LogOut class="size-5" aria-hidden="true" />
        <span class="sr-only">Sair</span>
      </button>
    </div>
  </header>
</template>
