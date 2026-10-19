<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { HomologationProcess } from '@/types/api'

const props = defineProps<{ process: HomologationProcess; initial?: string }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const transitions = computed(() => props.process.allowed_transitions.filter(t => !['enviado', 'vistoria_solicitada'].includes(t.stage_type ?? t.value)))
const status = ref(props.initial ?? transitions.value[0]?.value ?? '')
const reason = ref('')
const submitting = ref(false)
const error = ref<ApiError | null>(null)

const selected = computed(() => transitions.value.find(t => t.value === status.value))
const preparesSubmission = computed(() => (selected.value?.stage_type ?? status.value) === 'pronto_para_envio')
const submissionTransition = computed(() => transitions.value.find(t => (t.stage_type ?? t.value) === 'pronto_para_envio'))
const submissionReasons = computed(() => [...new Set([
  ...(submissionTransition.value?.reasons ?? []),
  ...(submissionTransition.value ? props.process.readiness_issues ?? [] : []),
])])
const blockingReasons = computed(() => [...new Set([
  ...(selected.value?.reasons ?? []),
  ...(preparesSubmission.value ? submissionReasons.value : []),
])])
const blocked = computed(() => !selected.value || selected.value.available === false || blockingReasons.value.length > 0)
const options = computed(() => transitions.value.map(t => ({
  value: t.value,
  label: t.label,
  disabled: t.available === false || ((t.stage_type ?? t.value) === 'pronto_para_envio' && (props.process.readiness_issues?.length ?? 0) > 0),
})))
const needsReason = computed(() => ['cancelado', 'reprovado', 'pendencia_distribuidora', 'rascunho'].includes(selected.value?.stage_type ?? status.value))

async function submit() {
  if (blocked.value) return
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
  <BaseDialog title="Alterar etapa do processo" submit-label="Confirmar" :submitting="submitting" :submit-disabled="blocked" :error="error" @close="emit('close')" @submit="submit">
    <p class="text-sm text-muted">
      Etapa atual: <strong class="text-ink">{{ process.status_label }}</strong>. Cada mudança fica registrada no histórico e na auditoria.
    </p>
    <SelectField
      v-model="status"
      label="Nova etapa"
      :options="options"
      :error="error?.firstError('status')"
    />
    <div v-if="submissionReasons.length" class="rounded-lg bg-warning-soft p-3 text-sm" role="status">
      <p class="font-semibold">Para avançar para “{{ submissionTransition?.label }}”, complete os requisitos obrigatórios:</p>
      <ul class="mt-2 list-disc space-y-1 pl-5">
        <li v-for="reason in submissionReasons" :key="reason">{{ reason }}</li>
      </ul>
    </div>
    <TextareaField
      v-model="reason"
      :label="needsReason ? 'Motivo' : 'Observação (opcional)'"
      :required="needsReason"
      :rows="3"
      :error="error?.firstError('reason')"
    />
  </BaseDialog>
</template>
