<script setup lang="ts">
import { reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import AuthShell from '@/components/layout/AuthShell.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { api, ApiError } from '@/lib/http'

const route = useRoute()

const form = reactive({
  email: typeof route.query.email === 'string' ? route.query.email : '',
  password: '',
  password_confirmation: '',
})
const submitting = ref(false)
const done = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  try {
    await api('/auth/reset-password', {
      method: 'POST',
      body: { ...form, token: String(route.params.token) },
    })
    done.value = true
  } catch (e) {
    error.value = e instanceof ApiError ? e : new ApiError(0, 'Falha de conexão com o servidor.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthShell title="Redefinir senha" subtitle="Mínimo de 10 caracteres, com maiúsculas, minúsculas e números.">
    <div v-if="done" class="flex flex-col gap-6">
      <InlineAlert tone="success">Senha redefinida. Entre com a nova senha.</InlineAlert>
      <RouterLink
        :to="{ name: 'login' }"
        class="inline-flex h-10 items-center justify-center rounded-lg bg-primary text-sm font-semibold text-white hover:bg-primary-hover"
      >
        Ir para o login
      </RouterLink>
    </div>

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error && error.status !== 422" :correlation-id="error.correlationId">
        {{ error.message }}
      </InlineAlert>
      <InlineAlert v-else-if="error?.firstError('token')">{{ error.firstError('token') }}</InlineAlert>
      <FormField v-model="form.email" label="E-mail" type="email" autocomplete="username" required :error="error?.firstError('email')" />
      <FormField
        v-model="form.password"
        label="Nova senha"
        type="password"
        autocomplete="new-password"
        required
        :error="error?.firstError('password')"
      />
      <FormField
        v-model="form.password_confirmation"
        label="Confirmar senha"
        type="password"
        autocomplete="new-password"
        required
      />
      <BaseButton type="submit" block :loading="submitting">Redefinir senha</BaseButton>
    </form>
  </AuthShell>
</template>
