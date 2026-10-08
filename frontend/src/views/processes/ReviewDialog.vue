<script setup lang="ts">
import { ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, buildUrl, toApiError, type ApiError } from '@/lib/http'
import { formatBytes } from '@/lib/format'
import type { ProcessDocument } from '@/types/api'

const props = defineProps<{ document: ProcessDocument }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const status = ref<'aprovado' | 'reprovado'>('aprovado')
const notes = ref('')
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  try {
    await api(`/documents/${props.document.id}/review`, {
      method: 'POST',
      body: { review_status: status.value, review_notes: notes.value || null },
    })
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog title="Revisar documento" submit-label="Registrar revisão" :submitting="submitting" :error="error" @close="emit('close')" @submit="submit">
    <div class="rounded-lg bg-canvas p-3 text-sm">
      <p class="font-medium">{{ document.type_label }} · v{{ document.version }}</p>
      <p class="text-muted">{{ document.original_name }} · {{ formatBytes(document.size_bytes) }}</p>
      <a
        :href="buildUrl(`/documents/${document.id}/download`)"
        target="_blank"
        rel="noopener"
        class="mt-2 inline-block text-sm font-medium text-primary hover:underline"
      >
        Abrir arquivo
      </a>
    </div>
    <SelectField
      v-model="status"
      label="Resultado"
      :options="[
        { value: 'aprovado', label: 'Aprovar' },
        { value: 'reprovado', label: 'Reprovar' },
      ]"
    />
    <TextareaField
      v-model="notes"
      :label="status === 'reprovado' ? 'Motivo da reprovação' : 'Observações (opcional)'"
      :required="status === 'reprovado'"
      :rows="3"
      :error="error?.firstError('review_notes')"
    />
  </BaseDialog>
</template>
