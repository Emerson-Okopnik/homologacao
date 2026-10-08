<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { ScrollText, ShieldCheck, Users } from '@lucide/vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const firstName = computed(() => auth.user?.name.split(' ')[0] ?? '')

const shortcuts = computed(() =>
  [
    {
      to: '/usuarios',
      icon: Users,
      title: 'Usuários e papéis',
      description: 'Convide a equipe e defina o que cada pessoa pode fazer.',
      allowed: auth.can('users.view'),
    },
    {
      to: '/auditoria',
      icon: ScrollText,
      title: 'Trilha de auditoria',
      description: 'Consulte quem fez o quê, quando e de onde.',
      allowed: auth.can('audit.view'),
    },
  ].filter((item) => item.allowed),
)
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <PageHeader :title="`Olá, ${firstName}`" :description="`Ambiente ${auth.tenant?.name ?? ''}`" />

    <section class="rounded-2xl border border-line bg-surface p-6">
      <div class="flex items-start gap-4">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary">
          <ShieldCheck class="size-5" aria-hidden="true" />
        </span>
        <div>
          <h2 class="font-semibold">Fundação pronta</h2>
          <p class="mt-1 max-w-2xl text-sm text-muted text-pretty">
            Autenticação, isolamento por empresa, permissões e auditoria estão ativos. Clientes, projetos, kanban e
            homologações chegam nas próximas etapas e aparecerão no menu automaticamente.
          </p>
        </div>
      </div>
    </section>

    <section v-if="shortcuts.length" class="mt-6 grid gap-4 md:grid-cols-2" aria-label="Atalhos">
      <RouterLink
        v-for="item in shortcuts"
        :key="item.to"
        :to="item.to"
        class="group flex items-start gap-4 rounded-2xl border border-line bg-surface p-6 transition-colors hover:border-primary"
      >
        <component :is="item.icon" class="mt-0.5 size-5 text-muted group-hover:text-primary" aria-hidden="true" />
        <div>
          <h2 class="font-semibold">{{ item.title }}</h2>
          <p class="mt-1 text-sm text-muted">{{ item.description }}</p>
        </div>
      </RouterLink>
    </section>
  </div>
</template>
