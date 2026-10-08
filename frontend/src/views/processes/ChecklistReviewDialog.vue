<script setup lang="ts">
import { ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
const props = defineProps<{ itemId: string }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const status = ref('aprovado'),
  notes = ref(''),
  saving = ref(false),
  error = ref<ApiError | null>(null)
async function submit() {
  saving.value = true
  error.value = null
  try {
    await api(`/checklist-items/${props.itemId}/review`, { method: 'POST', body: { status: status.value, notes: notes.value } })
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    saving.value = false
  }
}
</script>
<template>
  <BaseDialog title="Validar requisito" :submitting="saving" :error="error" @close="emit('close')" @submit="submit"
    ><SelectField
      v-model="status"
      label="Resultado"
      :options="[
        { value: 'aprovado', label: 'Atendido' },
        { value: 'reprovado', label: 'Não atendido' },
      ]" /><TextareaField v-model="notes" label="Evidência ou justificativa" required :error="error?.firstError('notes')"
  /></BaseDialog>
</template>
