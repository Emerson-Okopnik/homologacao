<script setup lang="ts">
import { ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, upload, toApiError, type ApiError } from '@/lib/http'
const props = defineProps<{ endpoint: string; initialType?: string; initialFile?: File }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const type = ref(props.initialType ?? 'outro')
const issued = ref(''),
  expires = ref(''),
  file = ref<File | null>(props.initialFile ?? null)
const submitting = ref(false),
  error = ref<ApiError | null>(null)
const { data: types } = useApiQuery(
  () => 'document-types',
  async () => (await api<{ data: Array<{ value: string; label: string }> }>('/document-types')).data,
)
async function submit() {
  submitting.value = true
  error.value = null
  try {
    const form = new FormData()
    form.append('document_type', type.value)
    if (file.value) form.append('file', file.value)
    if (issued.value) form.append('issued_at', issued.value)
    if (expires.value) form.append('expires_at', expires.value)
    await upload(props.endpoint, form)
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>
<template>
  <BaseDialog title="Adicionar documento" :submitting="submitting" :error="error" @submit="submit" @close="emit('close')">
    <SelectField v-model="type" label="Tipo de documento" :options="types ?? []" :error="error?.firstError('document_type')" />
    <label class="flex flex-col gap-2 text-sm"
      >Arquivo (PDF, JPG ou PNG, até 20 MB)
      <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
      <span v-if="file" class="text-muted">{{ file.name }}</span>
      <span v-if="error?.firstError('file')" class="text-danger">{{ error.firstError('file') }}</span>
    </label>
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField v-model="issued" type="date" label="Data de emissão" :error="error?.firstError('issued_at')" />
      <FormField v-model="expires" type="date" label="Validade (opcional)" :error="error?.firstError('expires_at')" />
    </div>
    <p class="text-sm text-muted">
      Cada substituição cria uma nova versão e exige revisão. Os arquivos enviados anteriormente permanecem no histórico.
    </p>
  </BaseDialog>
</template>
