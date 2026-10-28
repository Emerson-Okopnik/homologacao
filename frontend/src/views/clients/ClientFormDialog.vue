<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { Client } from '@/types/api'

const props = defineProps<{ client: Client | null }>()
const emit = defineEmits<{ close: []; saved: [client: Client] }>()

const form = reactive({
  type: props.client?.type?.toUpperCase() ?? 'PF',
  document: props.client?.document ?? '',
  name: props.client?.name ?? '',
  trade_name: props.client?.trade_name ?? '',
  email: props.client?.email ?? '',
  phone: props.client?.phone ?? '',
  status: props.client?.status ?? 'active',
  notes: props.client?.notes ?? '',
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  const body = { ...form, document: form.document.replace(/\D/g, '') }
  try {
    const result = props.client
      ? await api<{ data: Client }>(`/clients/${props.client.id}`, { method: 'PUT', body })
      : await api<{ data: Client }>('/clients', { method: 'POST', body })
    emit('saved', result.data)
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog
    :title="client ? 'Editar cliente' : 'Novo cliente'"
    :submitting="submitting"
    :error="error"
    size="lg"
    @close="emit('close')"
    @submit="submit"
  >
    <div class="grid gap-4 sm:grid-cols-2">
      <SelectField
        v-model="form.type"
        label="Tipo de pessoa"
        :options="[
          { value: 'PF', label: 'Pessoa física' },
          { value: 'PJ', label: 'Pessoa jurídica' },
        ]"
        :error="error?.firstError('type')"
      />
      <FormField
        v-model="form.document"
        :label="form.type === 'PF' ? 'CPF' : 'CNPJ'"
        required
        autocomplete="off"
        :error="error?.firstError('document')"
      />
    </div>
    <FormField v-model="form.name" :label="form.type === 'PF' ? 'Nome completo' : 'Razão social'" required :error="error?.firstError('name')" />
    <FormField v-if="form.type === 'PJ'" v-model="form.trade_name" label="Nome fantasia" :error="error?.firstError('trade_name')" />
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField v-model="form.email" label="E-mail" type="email" :error="error?.firstError('email')" />
      <FormField v-model="form.phone" label="Telefone" type="tel" :error="error?.firstError('phone')" />
    </div>
    <SelectField
      v-if="client"
      v-model="form.status"
      label="Situação"
      :options="[
        { value: 'active', label: 'Ativo' },
        { value: 'inactive', label: 'Inativo' },
      ]"
    />
    <TextareaField v-model="form.notes" label="Observações" :error="error?.firstError('notes')" />
  </BaseDialog>
</template>
