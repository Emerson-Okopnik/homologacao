<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
  <div class="grid min-h-dvh lg:grid-cols-2">
    <aside class="hidden flex-col justify-between bg-sidebar p-12 text-white lg:flex">
      <div class="flex items-center gap-3">
        <span class="flex size-10 items-center justify-center rounded-xl bg-primary text-sm font-extrabold">HS</span>
        <span class="text-lg font-bold">Homologa Solar</span>
      </div>
      <div class="max-w-md">
        <p class="text-3xl font-bold leading-tight text-balance">
          Do projeto à ligação: cada homologação rastreada, cada documento no lugar.
        </p>
        <p class="mt-4 text-sm text-sidebar-muted text-pretty">
          Checklist por distribuidora, prazos regulatórios e histórico auditável para integradores fotovoltaicos.
        </p>
      </div>
      <p class="text-xs text-sidebar-muted">© {{ new Date().getFullYear() }} Homologa Solar</p>
    </aside>

    <main class="flex items-center justify-center px-6 py-12">
      <div class="w-full max-w-sm">
        <div class="mb-8 flex items-center gap-3 lg:hidden">
          <span class="flex size-10 items-center justify-center rounded-xl bg-primary text-sm font-extrabold text-white">HS</span>
          <span class="text-lg font-bold">Homologa Solar</span>
        </div>
        <h1 class="text-2xl font-bold tracking-tight">{{ title }}</h1>
        <p v-if="subtitle" class="mt-1 text-sm text-muted">{{ subtitle }}</p>
        <div class="mt-8">
          <slot />
        </div>
      </div>
    </main>
  </div>
</template>
