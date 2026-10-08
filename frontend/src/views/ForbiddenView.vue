<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ShieldX } from '@lucide/vue'
</script>

<template>
  <div class="mx-auto flex max-w-md flex-col items-center py-24 text-center">
    <ShieldX class="size-10 text-danger" aria-hidden="true" />
    <h1 class="mt-4 text-xl font-bold">Acesso negado</h1>
    <p class="mt-2 text-sm text-muted">Seu papel não permite acessar esta área. Fale com o administrador da empresa.</p>
    <RouterLink to="/" class="mt-6 text-sm font-semibold text-primary hover:underline">Voltar ao início</RouterLink>
  </div>
</template>
