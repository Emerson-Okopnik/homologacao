<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import AuthShell from '@/components/layout/AuthShell.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { api, ApiError } from '@/lib/http'

const route = useRoute()
const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const submitting = ref(false)
const sent = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  if (submitting.value || sent.value) return
  submitting.value = true
  error.value = null
  try {
    await api('/auth/forgot-password', { method: 'POST', body: { email: email.value.trim() } })
    sent.value = true
  } catch (e) {
    error.value = e instanceof ApiError ? e : new ApiError(0, 'Falha de conexão com o servidor.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthShell title="Recuperar senha" subtitle="Solicite um link para definir uma nova senha.">
    <div v-if="sent" class="flex flex-col gap-4">
      <InlineAlert tone="success">
        Solicitação recebida. Se houver uma conta ativa para este e-mail, enviaremos um link para redefinir a senha.
      </InlineAlert>
      <p class="text-xs leading-6 text-muted">O envio pode levar alguns minutos. Confira também a pasta de spam. Se receber mais de um link, use o mais recente.</p>
    </div>

    <form v-else class="flex flex-col gap-4" :aria-busy="submitting || undefined" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error && (error.status !== 422 || !error.firstError('email'))" :correlation-id="error.correlationId">
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
      Voltar para a tela de acesso
    </RouterLink>
  </AuthShell>
</template>
