<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AuthShell from '@/components/layout/AuthShell.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { ApiError } from '@/lib/http'
import { safeRedirect } from '@/router/redirect'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const portalAccess = computed(() => typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/portal'))

const form = reactive({ email: '', password: '', remember: false })
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  try {
    await auth.login(form.email, form.password, form.remember)
    await router.replace(safeRedirect(route.query.redirect))
  } catch (e) {
    error.value = e instanceof ApiError ? e : new ApiError(0, 'Falha de conexão com o servidor.')
    form.password = ''
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthShell :title="portalAccess ? 'Acesse seu portal' : 'Bem-vindo de volta'" :subtitle="portalAccess ? 'Entre para acompanhar sua solicitação e seus documentos.' : 'Acesse seu workspace para continuar.'">
    <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error && error.status !== 422" :correlation-id="error.correlationId">
        {{ error.message }}
      </InlineAlert>

      <FormField
        v-model="form.email"
        label="E-mail profissional"
        type="email"
        autocomplete="username"
        required
        :error="error?.firstError('email')"
      />
      <FormField
        v-model="form.password"
        label="Senha"
        type="password"
        autocomplete="current-password"
        required
        :error="error?.firstError('password')"
      />

      <div class="flex flex-wrap items-center justify-between gap-3 text-[11px]">
        <label class="flex items-center gap-2 text-muted">
          <input v-model="form.remember" type="checkbox" class="size-4 rounded border-line accent-primary" />
          Manter conectado
        </label>
        <RouterLink :to="{ name: 'forgot-password' }" class="rounded-md bg-canvas px-3 py-2 font-medium text-ink hover:bg-blue-soft">
          Esqueci minha senha
        </RouterLink>
      </div>

      <BaseButton type="submit" block class="mt-2 !h-12" :loading="submitting">{{ portalAccess ? 'Entrar no portal' : 'Entrar na plataforma' }}</BaseButton>
    </form>

    <template #footer>
      <p class="mb-4 text-[11px] leading-5 text-muted">{{ portalAccess ? 'Faz parte da equipe de homologação?' : 'É cliente e deseja acompanhar sua solicitação?' }}</p>
      <RouterLink
        :to="{ name: 'login', query: portalAccess ? {} : { redirect: '/portal' } }"
        class="flex min-h-11 items-center justify-center rounded-lg border border-line bg-surface px-4 text-xs font-medium text-ink transition-colors hover:bg-canvas"
      >
        {{ portalAccess ? 'Acessar ambiente da equipe' : 'Acessar portal do cliente' }}
      </RouterLink>
    </template>
  </AuthShell>
</template>
