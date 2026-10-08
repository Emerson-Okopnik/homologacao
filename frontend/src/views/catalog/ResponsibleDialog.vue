<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { TechnicalResponsible } from '@/types/api'

const props = defineProps<{ item: TechnicalResponsible | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const form = reactive({
  name: props.item?.name ?? '',
  council: props.item?.council ?? 'CREA',
  registration: props.item?.registration ?? '',
  state: props.item?.state ?? '',
  email: props.item?.email ?? '',
  phone: props.item?.phone ?? '',
  registration_status: props.item?.registration_status ?? 'nao_verificado',
  active: props.item?.active ?? true,
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  const body = { ...form, state: form.state.toUpperCase() }
  try {
    if (props.item) await api(`/technical-responsibles/${props.item.id}`, { method: 'PUT', body })
    else await api('/technical-responsibles', { method: 'POST', body })
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
    :title="item ? 'Editar responsável técnico' : 'Novo responsável técnico'"
    :submitting="submitting"
    :error="error"
    @close="emit('close')"
    @submit="submit"
  >
    <FormField v-model="form.name" label="Nome" required :error="error?.firstError('name')" />
    <div class="grid gap-4 sm:grid-cols-[7rem_1fr_5rem]">
      <SelectField
        v-model="form.council"
        label="Conselho"
        :options="[
          { value: 'CREA', label: 'CREA' },
          { value: 'CFT', label: 'CFT' },
        ]"
      />
      <FormField v-model="form.registration" label="Registro" required :error="error?.firstError('registration')" />
      <FormField v-model="form.state" label="UF" required :error="error?.firstError('state')" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField v-model="form.email" label="E-mail" type="email" :error="error?.firstError('email')" />
      <FormField v-model="form.phone" label="Telefone" type="tel" :error="error?.firstError('phone')" />
    </div>
    <SelectField
      v-model="form.registration_status"
      label="Situação do registro"
      :options="[
        { value: 'nao_verificado', label: 'Não verificado' },
        { value: 'regular', label: 'Regular' },
        { value: 'irregular', label: 'Irregular' },
      ]"
      :error="error?.firstError('registration_status')"
    />
    <label v-if="item" class="flex items-center gap-2 text-sm">
      <input v-model="form.active" type="checkbox" class="size-4 accent-primary" />
      Ativo
    </label>
  </BaseDialog>
</template>
