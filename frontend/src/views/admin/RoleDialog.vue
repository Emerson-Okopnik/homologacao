<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import { api, ApiError, toApiError } from '@/lib/http'
import type { Role } from '@/types/api'

const props = defineProps<{ tenantId: string; role: Role | null }>()
const emit = defineEmits<{ close: []; saved: [role: Role] }>()

const form = reactive({
  name: props.role?.name ?? '',
  description: props.role?.description ?? '',
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  const body: Record<string, unknown> = { description: form.description || null }
  if (!props.role?.is_system) body.name = form.name
  try {
    const res = props.role
      ? await api<{ data: Role }>(`/admin/tenants/${props.tenantId}/roles/${props.role.slug}`, { method: 'PATCH', body })
      : await api<{ data: Role }>(`/admin/tenants/${props.tenantId}/roles`, { method: 'POST', body: { ...body, permissions: [] } })
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
    :title="role ? 'Editar perfil' : 'Novo perfil'"
    :submitting="submitting"
    :error="error"
    @close="emit('close')"
    @submit="submit"
  >
    <FormField
      v-if="!role?.is_system"
      v-model="form.name"
      label="Nome do perfil"
      required
      :error="error?.firstError('name')"
    />
    <p v-else class="rounded-lg bg-canvas px-3 py-2 text-sm text-muted">
      <span class="font-medium text-ink">{{ role.name }}</span> é um perfil padrão: o nome não pode ser alterado.
    </p>
    <FormField v-model="form.description" label="Descrição" :error="error?.firstError('description')" />
    <p v-if="!role" class="text-xs text-muted">Depois de criar, marque as permissões na matriz ao lado.</p>
  </BaseDialog>
</template>
