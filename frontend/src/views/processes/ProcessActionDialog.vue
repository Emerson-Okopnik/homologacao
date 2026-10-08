<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { HomologationProcess, ProcessActionKey, StageCatalog } from '@/types/api'

const props = defineProps<{ process: HomologationProcess; action: ProcessActionKey; catalog: StageCatalog | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const today = new Date().toISOString().slice(0, 10)
const nowLocal = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)

const protocol = ref(props.process.protocol_number ?? '')
const items = ref('')
const notes = ref('')
const networkStatus = ref<string>(props.action === 'approve_access' ? 'NOT_REQUIRED' : props.process.network_work_status)
const startedAt = ref('')
const completedAt = ref(today)
const scheduledFor = ref('')
const inspectionApproved = ref<'true' | 'false'>('true')
const eventType = ref(props.catalog?.connection_events[0]?.value ?? '')
const occurredAt = ref(nowLocal)
const meterNumber = ref('')
const reason = ref('')

const submitting = ref(false)
const error = ref<ApiError | null>(null)

const openInspection = computed(() => props.process.inspections?.find((i) => i.is_open) ?? null)

const meta = computed<{ title: string; submit: string; description: string; danger?: boolean }>(() => {
  switch (props.action) {
    case 'submit':
      return { title: 'Protocolar na distribuidora', submit: 'Registrar protocolo', description: 'Informe o protocolo gerado no portal. A versão atual do projeto será congelada e o prazo de análise começa a contar.' }
    case 'register_correction':
      return { title: 'Registrar exigências da distribuidora', submit: 'Registrar exigências', description: 'Cada item vira uma pendência. O processo volta para correção e o prazo da distribuidora é suspenso.' }
    case 'approve_access':
      return { title: 'Registrar parecer de acesso aprovado', submit: 'Registrar aprovação', description: 'Indique se a distribuidora exigiu obra na rede. Isso define se a execução depende da liberação.' }
    case 'update_network_work':
      return { title: 'Atualizar obra na rede', submit: 'Atualizar', description: 'Acompanhe a obra de responsabilidade da distribuidora.' }
    case 'report_execution':
      return { title: 'Informar conclusão da instalação', submit: 'Registrar execução', description: 'Após registrar, anexe a ART/TRT de execução para liberar a solicitação de vistoria.' }
    case 'request_inspection':
      return { title: 'Solicitar vistoria', submit: 'Solicitar', description: 'O prazo de vistoria da distribuidora passa a contar a partir de agora.' }
    case 'record_inspection':
      return { title: 'Resultado da vistoria', submit: 'Registrar resultado', description: 'Se reprovada, uma nova vistoria poderá ser solicitada após as correções.' }
    case 'record_connection_event':
      return { title: 'Registrar evento de conexão', submit: 'Registrar', description: 'Troca de medidor, energização ou outro marco da conexão.' }
    case 'complete':
      return { title: 'Concluir processo', submit: 'Concluir', description: 'Encerra o processo como conectado. Esta ação não pode ser desfeita.' }
    case 'cancel':
      return { title: 'Cancelar processo', submit: 'Cancelar processo', description: 'O processo é encerrado e fica somente para consulta.', danger: true }
  }
  return { title: '', submit: '', description: '' }
})

const routes: Record<ProcessActionKey, string> = {
  submit: 'submit',
  register_correction: 'register-correction',
  approve_access: 'approve-access',
  update_network_work: 'network-work',
  report_execution: 'execution',
  request_inspection: 'request-inspection',
  record_inspection: '',
  record_connection_event: 'connection-event',
  complete: 'complete',
  cancel: 'cancel',
}

function payload(): Record<string, unknown> {
  switch (props.action) {
    case 'submit':
      return { protocol_number: protocol.value }
    case 'register_correction':
      return { items: items.value.split('\n').map((s) => s.trim()).filter(Boolean), notes: notes.value || null }
    case 'approve_access':
    case 'update_network_work':
      return { network_work_status: networkStatus.value, notes: notes.value || null }
    case 'report_execution':
      return { started_at: startedAt.value || null, completed_at: completedAt.value, notes: notes.value || null }
    case 'request_inspection':
      return { scheduled_for: scheduledFor.value || null }
    case 'record_inspection':
      return { approved: inspectionApproved.value === 'true', notes: notes.value || null }
    case 'record_connection_event':
      return { type: eventType.value, occurred_at: occurredAt.value, meter_number: meterNumber.value || null, notes: notes.value || null }
    case 'cancel':
      return { reason: reason.value }
    default:
      return {}
  }
}

async function submit() {
  submitting.value = true
  error.value = null
  try {
    const url = props.action === 'record_inspection'
      ? `/inspections/${openInspection.value?.id}/result`
      : `/processes/${props.process.id}/actions/${routes[props.action]}`
    await api(url, { method: 'POST', body: payload() })
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}

const networkOptions = computed(() => (props.catalog?.network_work ?? []).map((o) => ({ value: o.value, label: o.label })))
const eventOptions = computed(() => (props.catalog?.connection_events ?? []).map((o) => ({ value: o.value, label: o.label })))
</script>

<template>
  <BaseDialog :title="meta.title" :submit-label="meta.submit" :submitting="submitting" :error="error" @close="emit('close')" @submit="submit">
    <p class="text-sm text-muted">{{ meta.description }}</p>

    <FormField v-if="action === 'submit'" v-model="protocol" label="Número do protocolo" required :error="error?.firstError('protocol_number')" />

    <template v-if="action === 'register_correction'">
      <TextareaField v-model="items" label="Exigências (uma por linha)" required :rows="5" :error="error?.firstError('items')" />
      <TextareaField v-model="notes" label="Observações (opcional)" />
    </template>

    <template v-if="action === 'approve_access' || action === 'update_network_work'">
      <SelectField v-model="networkStatus" label="Obra na rede" :options="networkOptions" required :error="error?.firstError('network_work_status')" />
      <TextareaField v-model="notes" label="Observações (opcional)" />
    </template>

    <template v-if="action === 'report_execution'">
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField v-model="startedAt" type="date" label="Início (opcional)" :error="error?.firstError('started_at')" />
        <FormField v-model="completedAt" type="date" label="Conclusão" required :error="error?.firstError('completed_at')" />
      </div>
      <TextareaField v-model="notes" label="Observações (opcional)" />
    </template>

    <FormField
      v-if="action === 'request_inspection'"
      v-model="scheduledFor"
      type="date"
      label="Data agendada (opcional)"
      :error="error?.firstError('scheduled_for')"
    />

    <template v-if="action === 'record_inspection'">
      <p v-if="openInspection" class="text-sm">Vistoria nº {{ openInspection.sequence }}</p>
      <SelectField
        v-model="inspectionApproved"
        label="Resultado"
        :options="[{ value: 'true', label: 'Aprovada' }, { value: 'false', label: 'Reprovada' }]"
      />
      <TextareaField
        v-model="notes"
        :label="inspectionApproved === 'false' ? 'Motivo da reprovação' : 'Observações (opcional)'"
        :required="inspectionApproved === 'false'"
        :error="error?.firstError('notes')"
      />
    </template>

    <template v-if="action === 'record_connection_event'">
      <SelectField v-model="eventType" label="Evento" :options="eventOptions" required :error="error?.firstError('type')" />
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField v-model="occurredAt" type="datetime-local" label="Ocorrido em" required :error="error?.firstError('occurred_at')" />
        <FormField v-model="meterNumber" label="Nº do medidor (opcional)" />
      </div>
      <TextareaField v-model="notes" label="Observações (opcional)" />
    </template>

    <TextareaField
      v-if="action === 'cancel'"
      v-model="reason"
      label="Motivo do cancelamento"
      required
      hint="Mínimo de 10 caracteres."
      :error="error?.firstError('reason')"
    />
  </BaseDialog>
</template>
