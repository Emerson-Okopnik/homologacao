<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { HomologationProcess } from '@/types/api'

const props = defineProps<{ process: HomologationProcess; initial?: string }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const transitions = props.process.allowed_transitions.filter(t => !['enviado', 'vistoria_solicitada'].includes(t.stage_type ?? t.value))
const status = ref(props.initial ?? transitions[0]?.value ?? '')
const reason = ref('')
const submitting = ref(false)
const error = ref<ApiError | null>(null)

const needsReason = computed(() => ['cancelado', 'reprovado', 'pendencia_distribuidora', 'rascunho'].includes(transitions.find(t=>t.value===status.value)?.stage_type ?? status.value))

async function submit() {
  submitting.value = true
  error.value = null
  try {
    await api(`/processes/${props.process.id}/transitions`, {
      method: 'POST',
      body: { status: status.value, reason: reason.value || null },
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
  <BaseDialog title="Alterar etapa do processo" submit-label="Confirmar" :submitting="submitting" :error="error" @close="emit('close')" @submit="submit">
    <p class="text-sm text-muted">
      Etapa atual: <strong class="text-ink">{{ process.status_label }}</strong>. Cada mudança fica registrada no histórico e na auditoria.
    </p>
    <SelectField
      v-model="status"
      label="Nova etapa"
      :options="transitions.map((t) => ({ value: t.value, label: t.label }))"
      :error="error?.firstError('status')"
    />
    <TextareaField
      v-model="reason"
      :label="needsReason ? 'Motivo' : 'Observação (opcional)'"
      :required="needsReason"
      :rows="3"
      :error="error?.firstError('reason')"
    />
  </BaseDialog>
</template>
