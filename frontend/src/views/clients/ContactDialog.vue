<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { ClientContact } from '@/types/api'

const props = defineProps<{ clientId: string; contact: ClientContact | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const form = reactive({
  name: props.contact?.name ?? '',
  email: props.contact?.email ?? '',
  phone: props.contact?.phone ?? '',
  role: props.contact?.role ?? '',
  is_legal_representative: props.contact?.is_legal_representative ?? false,
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  try {
    if (props.contact) await api(`/contacts/${props.contact.id}`, { method: 'PUT', body: form })
    else await api(`/clients/${props.clientId}/contacts`, { method: 'POST', body: form })
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog
    :title="contact ? 'Editar contato' : 'Novo contato'"
    :submitting="submitting"
    :error="error"
    @close="emit('close')"
    @submit="submit"
  >
    <FormField v-model="form.name" label="Nome" required :error="error?.firstError('name')" />
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField v-model="form.email" label="E-mail" type="email" :error="error?.firstError('email')" />
      <FormField v-model="form.phone" label="Telefone" type="tel" :error="error?.firstError('phone')" />
    </div>
    <FormField v-model="form.role" label="Função" :error="error?.firstError('role')" />
    <label class="flex items-center gap-2 text-sm">
      <input v-model="form.is_legal_representative" type="checkbox" class="size-4 accent-primary" />
      Representante legal / procurador
    </label>
  </BaseDialog>
</template>
