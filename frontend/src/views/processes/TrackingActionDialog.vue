<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import { useApiQuery } from '@/composables/useApiQuery'
import type { HomologationProcess, ProcessDocument } from '@/types/api'
import type { Tracking, Submission } from '@/types/homologation'
const props = defineProps<{
  process: HomologationProcess
  tracking: Tracking
  documents: ProcessDocument[]
  action: string
  submission?: Submission
  pendingId?: string
  inspectionId?: string
  checklistId?: string
}>()
const emit = defineEmits<{ close: []; saved: [] }>()
const inspection = props.tracking.inspections.find((i) => i.id === props.inspectionId)
const local = (v: string | null | undefined) => {
  if (!v) return ''
  const d = new Date(v)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}
const form = reactive({
  kind: props.process.status === 'aprovado' ? 'inspection' : props.process.status === 'pendencia_distribuidora' ? 'correction' : 'initial',
  reason: '',
  protocol: props.process.protocol_number ?? '',
  receipt: '',
  document: '',
  status:
    props.action === 'inspection'
      ? (inspection?.status ?? 'solicitada')
      : props.action === 'checklist'
        ? 'aprovado'
        : (props.tracking.external?.status ?? ''),
  portal: props.tracking.external?.portal_url ?? props.process.distributor?.portal_url ?? '',
  pending: props.pendingId ?? '',
  code: '',
  due: '',
  issued: '',
  expires: '',
  amount: '',
  works: false,
  requested: local(inspection?.requested_at ?? new Date().toISOString()),
  scheduled: local(inspection?.scheduled_at),
  performed: local(inspection?.performed_at),
  connected: local(inspection?.connection_approved_at),
  submission: props.tracking.submissions.find((s) => s.kind === 'inspection' && s.status === 'sent')?.id ?? '',
  user: props.process.assignee?.id ?? '',
  priority: props.process.priority ?? 'normal',
})
if (inspection?.report_document_id) form.document = inspection.report_document_id
const key = ref(crypto.randomUUID())
const error = ref<ApiError | null>(null),
  saving = ref(false)
const titles: Record<string, string> = {
  prepare: 'Preparar envio',
  confirm: 'Confirmar envio pelo portal',
  fail: 'Registrar falha no envio',
  status: 'Registrar situação na distribuidora',
  pending: 'Pendência da distribuidora',
  response: 'Vincular documento de resposta',
  budget: 'Orçamento de conexão',
  inspection: 'Registro da vistoria',
  assignment: 'Responsável e prioridade',
  checklist: 'Validar requisito',
}
const kindLabels: Record<string, string> = {
  initial: 'Solicitação inicial',
  correction: 'Resposta à pendência',
  inspection: 'Solicitação de vistoria',
}
const documentOptions = computed(() => {
  const type =
    props.action === 'confirm'
      ? 'comprovante_envio'
      : props.action === 'budget'
        ? 'orcamento_conexao'
        : props.action === 'inspection'
          ? 'relatorio_vistoria'
          : null
  return props.documents
    .filter((d) => d.review_status === 'aprovado' && (props.action !== 'response' || d.is_current) && (!type || d.document_type === type))
    .map((d) => ({ value: d.id, label: `${d.type_label} · ${d.original_name} · v${d.version}` }))
})
const { data: users } = useApiQuery(
  () => props.action,
  async (action) => (action === 'assignment' ? (await api<{ data: Array<{ id: string; name: string }> }>('/assignment-users')).data : []),
)
const date = (value: string) => (value ? new Date(value).toISOString() : null)
async function submit() {
  saving.value = true
  error.value = null
  try {
    const base = `/processes/${props.process.id}`
    let endpoint = '',
      method: 'POST' | 'PUT' | 'PATCH' = 'POST',
      body: unknown
    switch (props.action) {
      case 'prepare':
        endpoint = `${base}/submissions`
        body = {
          kind: form.kind,
          idempotency_key: key.value,
          change_reason: form.reason,
          pending_item_id: form.kind === 'correction' ? form.pending : null,
        }
        break
      case 'confirm':
        endpoint = `${base}/submissions/${props.submission?.id}/confirm`
        body = { protocol_number: form.protocol, external_receipt: form.receipt, receipt_document_id: form.document }
        break
      case 'fail':
        endpoint = `${base}/submissions/${props.submission?.id}/fail`
        body = { reason: form.reason }
        break
      case 'status':
        endpoint = `${base}/external-status`
        body = { status: form.status, portal_url: form.portal || null }
        break
      case 'pending':
        endpoint = `${base}/external-pendencies`
        body = { code: form.code || null, description: form.reason, due_at: date(form.due) }
        break
      case 'response':
        endpoint = `${base}/external-pendencies/${props.pendingId}/response`
        method = 'PUT'
        body = { response_document_id: form.document }
        break
      case 'budget':
        endpoint = `${base}/connection-budgets`
        body = {
          issued_at: form.issued,
          expires_at: form.expires || null,
          amount: form.amount.trim() ? Number(form.amount.replace(',', '.')) : null,
          works_required: form.works,
          document_id: form.document,
        }
        break
      case 'inspection':
        endpoint = `${base}/inspections${props.inspectionId ? `/${props.inspectionId}` : ''}`
        method = props.inspectionId ? 'PUT' : 'POST'
        body = {
          requested_at: date(form.requested),
          scheduled_at: date(form.scheduled),
          performed_at: date(form.performed),
          connection_approved_at: date(form.connected),
          status: form.status,
          report_document_id: form.document || null,
          submission_id: form.submission,
        }
        break
      case 'assignment':
        endpoint = base
        method = 'PATCH'
        body = { assigned_user_id: form.user || null, priority: form.priority }
        break
      case 'checklist':
        endpoint = `/checklist-items/${props.checklistId}/review`
        body = { status: form.status, notes: form.reason }
        break
    }
    await api(endpoint, { method, body })
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    saving.value = false
  }
}
</script>
<template>
  <BaseDialog :title="titles[action] ?? 'Registrar'" :submitting="saving" :error="error" @close="emit('close')" @submit="submit">
    <ul v-if="error && Object.keys(error.errors).length" class="list-disc pl-5 text-sm text-danger">
      <li v-for="(messages, field) in error.errors" :key="field">{{ messages[0] }}</li>
    </ul>
    <template v-if="action === 'prepare'">
      <p class="text-sm text-muted">
        Será congelada uma versão do projeto e dos documentos revisados. Baixe o dossiê preparado, envie pelo portal e registre a
        confirmação.
      </p>
      <SelectField v-model="form.kind" label="Tipo de envio" :options="[{ value: form.kind, label: kindLabels[form.kind]! }]" />
      <SelectField
        v-if="form.kind === 'correction'"
        v-model="form.pending"
        label="Pendência respondida"
        placeholder="Selecione"
        :options="
          tracking.pending_items
            .filter((p) => p.status === 'aberta')
            .map((p) => ({ value: p.id, label: `${p.code ?? ''} ${p.description}` }))
        "
      />
      <TextareaField v-model="form.reason" label="Motivo desta versão" required />
    </template>
    <template v-if="action === 'confirm'">
      <p class="text-sm text-muted">
        Confirme somente após o envio no portal da distribuidora. Este registro refere-se à versão {{ submission?.version }}:
        {{ submission?.change_reason }}.
      </p>
      <FormField v-model="form.protocol" label="Número do protocolo" required />
      <FormField v-model="form.receipt" label="Número ou identificação do comprovante" required />
      <SelectField v-model="form.document" label="Comprovante de envio revisado" placeholder="Selecione" :options="documentOptions" />
      <p class="text-xs text-muted">Adicione e aprove o comprovante em “Documentos e evidências” antes de confirmar.</p>
    </template>
    <TextareaField v-if="action === 'fail'" v-model="form.reason" label="Motivo da falha" required />
    <template v-if="action === 'status'"
      ><FormField v-model="form.status" label="Situação informada no portal" required /><FormField
        v-model="form.portal"
        label="Página do processo no portal (opcional)"
        type="url"
    /></template>
    <template v-if="action === 'pending'"
      ><FormField v-model="form.code" label="Código da pendência (opcional)" /><TextareaField
        v-model="form.reason"
        label="Exigência da distribuidora"
        required /><FormField v-model="form.due" label="Prazo" type="datetime-local"
    /></template>
    <SelectField
      v-if="action === 'response'"
      v-model="form.document"
      label="Documento revisado de resposta"
      placeholder="Selecione"
      :options="documentOptions"
    />
    <template v-if="action === 'budget'"
      ><FormField v-model="form.issued" label="Emissão" type="date" required /><FormField
        v-model="form.expires"
        label="Validade (opcional)"
        type="date"
      /><FormField v-model="form.amount" label="Valor (R$)" type="number" step="any" /><SelectField
        v-model="form.document"
        label="Documento do orçamento"
        placeholder="Selecione"
        :options="documentOptions"
      /><label class="flex items-center gap-2 text-sm"><input v-model="form.works" type="checkbox" />Exige obras</label></template
    >
    <template v-if="action === 'inspection'">
      <SelectField
        v-model="form.submission"
        label="Solicitação de vistoria enviada"
        placeholder="Selecione"
        :options="
          tracking.submissions
            .filter((s) => s.kind === 'inspection' && s.status === 'sent')
            .map((s) => ({ value: s.id, label: `Versão ${s.version} · ${s.external_receipt}` }))
        "
      />
      <SelectField
        v-model="form.status"
        label="Situação da vistoria"
        :options="[
          { value: 'solicitada', label: 'Solicitada' },
          { value: 'agendada', label: 'Agendada' },
          { value: 'realizada', label: 'Realizada' },
          { value: 'aprovada', label: 'Aprovada' },
          { value: 'reprovada', label: 'Reprovada' },
        ]"
      />
      <FormField v-model="form.requested" label="Solicitada em" type="datetime-local" required /><FormField
        v-model="form.scheduled"
        label="Agendada para"
        type="datetime-local"
      /><FormField v-model="form.performed" label="Realizada em" type="datetime-local" />
      <SelectField v-model="form.document" label="Relatório revisado" placeholder="Selecione" :options="documentOptions" /><FormField
        v-model="form.connected"
        label="Conexão aprovada em"
        type="datetime-local"
      />
    </template>
    <template v-if="action === 'assignment'"
      ><SelectField
        v-model="form.user"
        label="Responsável pelo processo"
        placeholder="Sem responsável"
        :options="(users ?? []).map((u) => ({ value: u.id, label: u.name }))" /><SelectField
        v-model="form.priority"
        label="Prioridade"
        :options="[
          { value: 'baixa', label: 'Baixa' },
          { value: 'normal', label: 'Normal' },
          { value: 'alta', label: 'Alta' },
          { value: 'urgente', label: 'Urgente' },
        ]"
    /></template>
    <template v-if="action === 'checklist'"
      ><SelectField
        v-model="form.status"
        label="Revisão do requisito"
        :options="[
          { value: 'aprovado', label: 'Atendido' },
          { value: 'reprovado', label: 'Não atendido' },
        ]" /><TextareaField v-model="form.reason" label="Evidência ou justificativa" required
    /></template>
  </BaseDialog>
</template>
