<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="mx-auto flex max-w-md flex-col items-center py-24 text-center">
    <p class="text-5xl font-extrabold text-line">404</p>
    <h1 class="mt-4 text-xl font-bold">Página não encontrada</h1>
    <p class="mt-2 text-sm text-muted">O endereço pode ter mudado ou ainda não existe nesta versão.</p>
    <RouterLink to="/" class="mt-6 text-sm font-semibold text-primary hover:underline">Voltar ao início</RouterLink>
  </div>
</template>
