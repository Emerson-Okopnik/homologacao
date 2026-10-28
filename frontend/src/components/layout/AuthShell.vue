<script setup lang="ts">
import { Activity, LockKeyhole } from '@lucide/vue'
import BrandLogo from './BrandLogo.vue'
import BrandPlanes from './BrandPlanes.vue'
import ThemeToggle from '@/components/ui/ThemeToggle.vue'

defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
  <div class="auth-shell grid min-h-dvh bg-surface lg:grid-cols-[minmax(0,.92fr)_minmax(0,1.08fr)]">
    <aside class="auth-story relative hidden min-h-dvh flex-col overflow-hidden bg-navy px-12 py-16 text-white lg:flex xl:px-16 xl:py-20">
      <div class="relative z-10 mt-4 max-w-lg">
        <p class="text-[10px] font-medium tracking-[.15em] text-[#a1dec9]">DA SOLICITAÇÃO À CONEXÃO</p>
        <p class="mt-10 text-[clamp(2.5rem,3.3vw,3.4rem)] font-light leading-[1.35] tracking-[-.04em]">
          O próximo passo<br />da sua energia.
        </p>
        <p class="mt-8 max-w-md text-sm leading-7 text-[#c8d5ee] xl:text-[15px]">
          Projetos, documentos e pessoas conectados em um único fluxo de homologação.
        </p>
      </div>

      <BrandPlanes class="pointer-events-none absolute right-[-18%] bottom-[10%] w-[83%]" />

      <div class="relative z-10 mt-auto pt-56">
        <div class="rounded-xl border border-white/15 bg-white/5 p-5 backdrop-blur-[1px]">
          <div class="flex items-start gap-4">
            <Activity class="mt-1 size-5 shrink-0 text-[#a1dec9]" aria-hidden="true" />
            <div>
              <p class="text-sm font-medium">Visibilidade em cada etapa</p>
              <p class="mt-2 text-xs leading-6 text-[#c8d5ee]">Do projeto técnico à conexão com a rede, acompanhe o que importa para avançar.</p>
            </div>
          </div>
        </div>
        <p class="mt-10 text-[9px] font-medium tracking-[.15em] text-[#a8bbdd]">VERIGRID / ENGENHARIA CONECTADA</p>
      </div>
    </aside>

    <main class="relative flex min-h-dvh flex-col items-center justify-center px-6 py-12 sm:px-10 lg:px-12 lg:py-20">
      <ThemeToggle class="absolute top-4 right-4 sm:top-6 sm:right-6" />
      <div class="w-full max-w-[420px]">
        <BrandLogo class="mb-12 max-w-[250px] lg:mb-16" />
        <h1 class="text-[26px] font-semibold leading-tight tracking-[-.035em] sm:text-[29px]">{{ title }}</h1>
        <p v-if="subtitle" class="mt-3 text-xs leading-6 text-muted sm:text-[13px]">{{ subtitle }}</p>
        <div class="mt-9 lg:mt-11">
          <slot />
        </div>

        <div class="mt-9 flex items-center gap-2.5 border-t border-line pt-5 text-[10px] leading-5 text-muted">
          <LockKeyhole class="size-4 shrink-0 text-primary" aria-hidden="true" />
          Acesso seguro ao seu ambiente de trabalho
        </div>

        <div v-if="$slots.footer" class="mt-9 lg:mt-12">
          <slot name="footer" />
        </div>
      </div>

      <p class="mt-12 text-center text-[9px] text-muted lg:absolute lg:inset-x-6 lg:bottom-6 lg:mt-0">© {{ new Date().getFullYear() }} VERIGRID · Todos os direitos reservados</p>
    </main>
  </div>
</template>

<style scoped>
.auth-story::before {
  position: absolute;
  inset: 140px 0 0 48px;
  content: '';
  pointer-events: none;
  background-image: linear-gradient(#ffffff08 1px, transparent 1px), linear-gradient(90deg, #ffffff08 1px, transparent 1px);
  background-size: 80px 80px;
}
</style>
