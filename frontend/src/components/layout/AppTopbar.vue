<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { LogOut, Menu } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'

defineEmits<{ toggleMenu: [] }>()

const auth = useAuthStore()
const router = useRouter()
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
  <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur md:px-8">
    <a href="#conteudo" class="sr-only focus:not-sr-only">Pular para o conteúdo</a>
    <button
      type="button"
      class="rounded-md p-2 text-muted hover:bg-canvas hover:text-ink lg:hidden"
      @click="$emit('toggleMenu')"
    >
      <Menu class="size-5" aria-hidden="true" />
      <span class="sr-only">Abrir menu</span>
    </button>

    <div class="ml-auto flex items-center gap-3">
      <div class="hidden text-right sm:block">
        <p class="text-sm font-semibold leading-tight">{{ auth.user?.name }}</p>
        <p class="text-xs text-muted">{{ roleLabel }}</p>
      </div>
      <span
        class="flex size-9 items-center justify-center rounded-full bg-primary-soft text-sm font-bold text-primary"
        aria-hidden="true"
      >
        {{ initials }}
      </span>
      <button
        type="button"
        class="rounded-md p-2 text-muted hover:bg-canvas hover:text-ink disabled:opacity-50"
        :disabled="loggingOut"
        @click="logout"
      >
        <LogOut class="size-5" aria-hidden="true" />
        <span class="sr-only">Sair</span>
      </button>
    </div>
  </header>
</template>
