<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { LogOut, Plus, Sun } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const leaving = ref(false)

async function logout() {
  leaving.value = true
  try {
    await auth.logout()
  } finally {
    await router.replace({ name: 'login' })
  }
}
</script>

<template>
  <div class="flex min-h-dvh flex-col bg-canvas">
    <header class="border-b border-line bg-surface">
      <div class="mx-auto flex h-16 max-w-5xl items-center gap-4 px-4 md:px-6">
        <RouterLink to="/portal" class="flex items-center gap-2 font-semibold text-ink">
          <span class="flex size-8 items-center justify-center rounded-lg bg-primary text-white">
            <Sun class="size-4" aria-hidden="true" />
          </span>
          <span class="hidden sm:inline">Homologa Solar</span>
          <span class="rounded-md bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary">Portal do cliente</span>
        </RouterLink>

        <nav aria-label="Portal" class="ml-auto flex items-center gap-2">
          <RouterLink
            to="/portal/solicitacoes/nova"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3 text-sm font-semibold text-white hover:bg-primary-hover"
          >
            <Plus class="size-4" aria-hidden="true" />
            <span class="hidden sm:inline">Nova solicitação</span>
            <span class="sm:hidden">Nova</span>
          </RouterLink>
          <div class="hidden text-right md:block">
            <p class="text-sm font-medium leading-tight text-ink">{{ auth.user?.name }}</p>
            <p class="text-xs text-muted">{{ auth.portalClient?.name }}</p>
          </div>
          <button
            type="button"
            class="inline-flex size-9 items-center justify-center rounded-lg text-muted hover:bg-canvas hover:text-ink"
            :disabled="leaving"
            @click="logout"
          >
            <LogOut class="size-4" aria-hidden="true" />
            <span class="sr-only">Sair</span>
          </button>
        </nav>
      </div>
    </header>

    <main id="conteudo" class="mx-auto w-full max-w-5xl flex-1 px-4 py-6 md:px-6 md:py-8">
      <RouterView />
    </main>
  </div>
</template>
