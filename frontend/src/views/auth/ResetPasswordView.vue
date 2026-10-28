<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
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
const invalidLink = computed(() => Boolean(error.value?.firstError('token')))
const recoveryLink = computed(() => ({ name: 'forgot-password', query: form.email ? { email: form.email } : {} }))

async function submit() {
  if (submitting.value || done.value) return
  if (form.password !== form.password_confirmation) {
    error.value = new ApiError(422, 'Confira a confirmação de senha.', {
      password_confirmation: ['A confirmação de senha não confere.'],
    })
    return
  }
  submitting.value = true
  error.value = null
  try {
    await api('/auth/reset-password', {
      method: 'POST',
      body: { ...form, token: String(route.params.token) },
    })
    form.password = ''
    form.password_confirmation = ''
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
        class="inline-flex h-10 items-center justify-center rounded-lg bg-primary text-sm font-semibold text-on-primary hover:bg-primary-hover"
      >
        Ir para a tela de acesso
      </RouterLink>
    </div>

    <div v-else-if="invalidLink" class="flex flex-col gap-5">
      <InlineAlert>{{ error?.firstError('token') }}</InlineAlert>
      <RouterLink :to="recoveryLink" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 text-xs font-medium text-on-primary hover:bg-primary-hover">
        Solicitar novo link
      </RouterLink>
    </div>

    <form v-else class="flex flex-col gap-4" :aria-busy="submitting || undefined" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error && error.status !== 422" :correlation-id="error.correlationId">
        {{ error.message }}
      </InlineAlert>
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
        :error="error?.firstError('password_confirmation')"
      />
      <BaseButton type="submit" block :loading="submitting">Redefinir senha</BaseButton>
    </form>

    <RouterLink v-if="!done && !invalidLink" :to="recoveryLink" class="mt-6 inline-block text-sm font-medium text-primary hover:underline">
      Precisa de outro link? Solicite novamente
    </RouterLink>
  </AuthShell>
</template>
