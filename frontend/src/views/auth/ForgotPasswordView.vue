<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import AuthShell from '@/components/layout/AuthShell.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { api, ApiError } from '@/lib/http'

const email = ref('')
const submitting = ref(false)
const sent = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  try {
    await api('/auth/forgot-password', { method: 'POST', body: { email: email.value } })
    sent.value = true
  } catch (e) {
    error.value = e instanceof ApiError ? e : new ApiError(0, 'Falha de conexão com o servidor.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthShell title="Recuperar senha" subtitle="Enviaremos um link de redefinição para o seu e-mail.">
    <InlineAlert v-if="sent" tone="success">
      Se houver uma conta ativa para este e-mail, você receberá o link em instantes.
    </InlineAlert>

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error && error.status !== 422" :correlation-id="error.correlationId">
        {{ error.message }}
      </InlineAlert>
      <FormField
        v-model="email"
        label="E-mail"
        type="email"
        autocomplete="email"
        required
        :error="error?.firstError('email')"
      />
      <BaseButton type="submit" block :loading="submitting">Enviar link</BaseButton>
    </form>

    <RouterLink :to="{ name: 'login' }" class="mt-6 inline-block text-sm font-medium text-primary hover:underline">
      Voltar para o login
    </RouterLink>
  </AuthShell>
</template>
