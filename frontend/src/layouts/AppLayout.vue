<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import AppTopbar from '@/components/layout/AppTopbar.vue'

const mobileOpen = ref(false)
const route = useRoute()

watch(
  () => route.fullPath,
  () => {
    mobileOpen.value = false
  },
)
</script>

<template>
  <div class="flex min-h-dvh">
    <AppSidebar :open="mobileOpen" @close="mobileOpen = false" />
    <div class="flex min-w-0 flex-1 flex-col">
      <AppTopbar @toggle-menu="mobileOpen = !mobileOpen" />
      <main id="conteudo" class="flex-1 px-4 py-6 md:px-8 md:py-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
