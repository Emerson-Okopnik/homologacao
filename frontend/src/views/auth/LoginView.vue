<script setup lang="ts">
import { reactive, ref } from 'vue'
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
  <AuthShell title="Entrar" subtitle="Acesse com seu e-mail corporativo.">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error && error.status !== 422" :correlation-id="error.correlationId">
        {{ error.message }}
      </InlineAlert>

      <FormField
        v-model="form.email"
        label="E-mail"
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

      <div class="flex items-center justify-between text-sm">
        <label class="flex items-center gap-2 text-muted">
          <input v-model="form.remember" type="checkbox" class="size-4 rounded border-line accent-primary" />
          Manter conectado
        </label>
        <RouterLink :to="{ name: 'forgot-password' }" class="font-medium text-primary hover:underline">
          Esqueci a senha
        </RouterLink>
      </div>

      <BaseButton type="submit" block :loading="submitting">Entrar</BaseButton>
    </form>
  </AuthShell>
</template>
