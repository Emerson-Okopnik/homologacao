<script setup lang="ts">
import { ref, useId } from 'vue'
import { FileCheck2, UploadCloud } from '@lucide/vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { formatBytes } from '@/lib/format'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, upload, toApiError, type ApiError } from '@/lib/http'
import type { ProcessDocument } from '@/types/api'
const props = defineProps<{ endpoint: string; initialType?: string; initialFile?: File; fixedType?: boolean; owner?: { type: string; id: string } }>()
const emit = defineEmits<{ close: []; saved: [document: ProcessDocument] }>()
const type = ref(props.initialType ?? 'outro')
const issued = ref(''),
  expires = ref(''),
  file = ref<File | null>(props.initialFile ?? null)
const submitting = ref(false),
  error = ref<ApiError | null>(null)
const fileId = useId()
const dragActive = ref(false)
const fileError = ref('')
function selectFile(selected: File | null) {
  fileError.value = ''
  if (selected && !/\.(pdf|jpe?g|png)$/i.test(selected.name)) {
    fileError.value = 'Selecione um arquivo PDF, JPG ou PNG.'
    file.value = null
  } else if (selected && selected.size > 20 * 1024 * 1024) {
    fileError.value = 'O arquivo deve ter até 20 MB.'
    file.value = null
  } else file.value = selected
}
function dropFile(event: DragEvent) {
  dragActive.value = false
  selectFile(event.dataTransfer?.files[0] ?? null)
}
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
    if (props.owner) {
      form.append('owner_type', props.owner.type)
      form.append('owner_id', props.owner.id)
    }
    if (file.value) form.append('file', file.value)
    if (issued.value) form.append('issued_at', issued.value)
    if (expires.value) form.append('expires_at', expires.value)
    const result = await upload<{ data: ProcessDocument }>(props.endpoint, form)
    emit('saved', result.data)
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>
<template>
  <BaseDialog title="Enviar documento" submit-label="Enviar documento" :submitting="submitting" :submit-disabled="!file || !!fileError" :error="error" @submit="submit" @close="emit('close')">
    <p class="text-xs leading-6 text-muted">Adicione o arquivo e mantenha as versões do projeto organizadas.</p>
    <SelectField v-model="type" label="Tipo de documento" :options="types ?? []" :disabled="fixedType" :error="error?.firstError('document_type')" />
    <div>
      <label :for="fileId" class="relative flex cursor-pointer flex-col items-center gap-3 rounded-xl border border-dashed px-5 py-7 text-center transition-colors focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary" :class="dragActive ? 'border-primary bg-primary-soft' : 'border-line bg-canvas'" @dragover.prevent="dragActive = true" @dragleave.prevent="dragActive = false" @drop.prevent="dropFile">
        <UploadCloud class="size-7 text-primary" aria-hidden="true" />
        <span class="text-xs font-medium">Arraste o arquivo ou clique para selecionar</span>
        <span class="text-[11px] text-muted">PDF, JPG ou PNG · Até 20 MB</span>
        <input :id="fileId" type="file" accept=".pdf,.jpg,.jpeg,.png" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" :aria-invalid="!!(fileError || error?.firstError('file')) || undefined" @change="selectFile(($event.target as HTMLInputElement).files?.[0] ?? null)" />
      </label>
      <p v-if="fileError || error?.firstError('file')" class="mt-2 text-xs text-danger" role="alert">{{ fileError || error?.firstError('file') }}</p>
      <div v-if="file" class="mt-4 flex items-start gap-3 rounded-lg bg-primary-soft p-3.5" role="status">
        <FileCheck2 class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
        <div class="min-w-0"><p class="break-all text-xs font-medium">{{ file.name }}</p><p class="mt-1 text-[11px] text-muted">{{ formatBytes(file.size) }} · Pronto para enviar</p></div>
      </div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField v-model="issued" type="date" label="Data de emissão" :error="error?.firstError('issued_at')" />
      <FormField v-model="expires" type="date" label="Validade (opcional)" :error="error?.firstError('expires_at')" />
    </div>
    <p class="text-sm text-muted">
      Cada substituição cria uma nova versão e exige revisão. Os arquivos enviados anteriormente permanecem no histórico.
    </p>
  </BaseDialog>
</template>
