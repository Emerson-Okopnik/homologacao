<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import { api, ApiError, toApiError } from '@/lib/http'
import type { Tenant } from '@/types/api'

const props = defineProps<{ tenant: Tenant | null }>()
const emit = defineEmits<{ close: []; saved: [tenant: Tenant] }>()

const form = reactive({
  name: props.tenant?.name ?? '',
  slug: props.tenant?.slug ?? '',
  admin_name: '',
  admin_email: '',
  admin_password: '',
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  try {
    const res = props.tenant
      ? await api<{ data: Tenant }>(`/admin/tenants/${props.tenant.id}`, { method: 'PATCH', body: { name: form.name } })
      : await api<{ data: Tenant }>('/admin/tenants', { method: 'POST', body: { ...form } })
    emit('saved', res.data)
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog
    :title="tenant ? 'Editar tenant' : 'Novo tenant'"
    :submit-label="tenant ? 'Salvar' : 'Criar tenant'"
    :submitting="submitting"
    :error="error"
    @close="emit('close')"
    @submit="submit"
  >
    <FormField v-model="form.name" label="Nome da empresa" required :error="error?.firstError('name')" />
    <FormField
      v-if="!tenant"
      v-model="form.slug"
      label="Identificador (slug)"
      hint="Opcional. Gerado a partir do nome se ficar em branco."
      :error="error?.firstError('slug')"
    />

    <fieldset v-if="!tenant" class="flex flex-col gap-4 rounded-xl border border-line p-4">
      <legend class="px-1 text-sm font-semibold">Administrador inicial</legend>
      <p class="-mt-2 text-xs text-muted">
        Será criado com o perfil Administrador e todos os perfis padrão serão provisionados.
      </p>
      <FormField v-model="form.admin_name" label="Nome" autocomplete="off" required :error="error?.firstError('admin_name')" />
      <FormField
        v-model="form.admin_email"
        label="E-mail"
        type="email"
        autocomplete="off"
        required
        :error="error?.firstError('admin_email')"
      />
      <FormField
        v-model="form.admin_password"
        label="Senha inicial"
        type="password"
        autocomplete="new-password"
        required
        hint="Mínimo de 10 caracteres, com maiúsculas, minúsculas e números."
        :error="error?.firstError('admin_password')"
      />
    </fieldset>
  </BaseDialog>
</template>
