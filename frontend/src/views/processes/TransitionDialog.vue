<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { HomologationProcess } from '@/types/api'

const props = defineProps<{ process: HomologationProcess; initial?: string }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const status = ref(props.initial ?? props.process.allowed_transitions[0]?.value ?? '')
const reason = ref('')
const protocol = ref(props.process.protocol_number ?? '')
const submitting = ref(false)
const error = ref<ApiError | null>(null)

const needsProtocol = computed(() => status.value === 'enviado')
const needsReason = computed(() => ['cancelado', 'reprovado', 'pendencia_distribuidora'].includes(status.value))

async function submit() {
  submitting.value = true
  error.value = null
  try {
    await api(`/processes/${props.process.id}/transitions`, {
      method: 'POST',
      body: { status: status.value, reason: reason.value || null, protocol_number: needsProtocol.value ? protocol.value || null : undefined },
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
      :options="process.allowed_transitions.map((t) => ({ value: t.value, label: t.label }))"
      :error="error?.firstError('status')"
    />
    <FormField
      v-if="needsProtocol"
      v-model="protocol"
      label="Número do protocolo"
      required
      hint="Protocolo gerado pelo portal da distribuidora."
      :error="error?.firstError('protocol_number')"
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
