<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Download, ExternalLink, FileArchive } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ProjectDocumentsPanel from '@/views/documents/ProjectDocumentsPanel.vue'
import DocumentUploadDialog from '@/views/documents/DocumentUploadDialog.vue'
import ReviewDialog from './ReviewDialog.vue'
import TrackingActionDialog from './TrackingActionDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, buildUrl, toApiError, type ApiError } from '@/lib/http'
import { formatDateTime, formatNumber } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { HomologationProcess, ProcessDocument } from '@/types/api'
import type { Tracking, ProjectTechnicalResponse, Submission } from '@/types/homologation'
const props = defineProps<{ process: HomologationProcess; revision: number }>()
const emit = defineEmits<{ saved: [] }>()
const auth = useAuthStore(),
  dialog = ref<{ action: string; submission?: Submission; pendingId?: string; inspectionId?: string } | null>(null),
  downloadError = ref<ApiError | null>(null)
const source = computed(() => ({ id: props.process.id, revision: props.revision }))
const { data, error, loading } = useApiQuery(source, async (s) => {
  const [tracking, technical] = await Promise.all([
    api<{ data: Tracking }>(`/processes/${s.id}/tracking`),
    api<{ data: ProjectTechnicalResponse }>(`/projects/${props.process.project!.id}/technical-data`),
  ])
  return { ...tracking.data, documents: technical.data.documents.filter((d) => !d.process_id || d.process_id === s.id) }
})
const labels: Record<string, string> = {
  initial: 'Solicitação inicial',
  correction: 'Correção',
  inspection: 'Vistoria',
  prepared: 'Preparado',
  failed: 'Falha registrada',
  sent: 'Enviado',
  prepared_event: 'Dossiê preparado',
  assisted_failure: 'Falha no envio',
  assisted_confirmation: 'Envio confirmado',
  assisted_status: 'Situação externa atualizada',
  external_pending: 'Pendência recebida',
  assisted_inspection: 'Vistoria registrada',
}
const canManage = computed(() => auth.can('homologations.manage'))
const canUploadReport = computed(() => auth.can('documents.manage') && !['conectado', 'cancelado'].includes(props.process.status))
const uploadingReport = ref(false)
const reviewingReport = ref<ProcessDocument | null>(null)
const reports = computed(() => (data.value?.documents ?? []).filter(doc => doc.is_current && ['relatorio_vistoria', 'INSPECTION_REPORT'].includes(doc.document_type)))
function reportUploaded(document: ProcessDocument) {
  uploadingReport.value = false
  reviewingReport.value = document
  emit('saved')
}
function reportReviewed() {
  reviewingReport.value = null
  emit('saved')
}
const portal = computed(() => {
  const url = data.value?.external?.portal_url ?? props.process.distributor?.portal_url ?? props.process.project?.consumer_unit?.distributor?.portal_url
  return url && /^https?:\/\//i.test(url) ? url : null
})
function saved() {
  dialog.value = null
  emit('saved')
}
async function download(submission: Submission) {
  downloadError.value = null
  try {
    const result = await api<unknown>(`/processes/${props.process.id}/submissions/${submission.id}/manifest`)
    const url = URL.createObjectURL(new Blob([JSON.stringify(result, null, 2)], { type: 'application/json' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `${props.process.code}-v${submission.version}.json`
    link.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (e) {
    downloadError.value = toApiError(e)
  }
}
</script>
<template>
  <div class="space-y-6">
    <InlineAlert v-if="error">{{ error.message }}</InlineAlert
    ><InlineAlert v-if="downloadError">{{ downloadError.message }}</InlineAlert>
    <p v-if="loading && !data" class="rounded-xl border border-line bg-surface px-6 py-12 text-center text-sm text-muted" role="status">Carregando acompanhamento da distribuidora…</p>
    <template v-if="data">
      <section class="rounded-xl border border-line bg-surface p-5">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div><p class="mb-2 text-[10px] font-medium uppercase tracking-[0.14em] text-primary">Envio assistido</p><h2 class="text-base font-semibold">Envios e acompanhamento da distribuidora</h2></div>
          <BaseButton
            v-if="canManage && ['pronto_para_envio', 'pendencia_distribuidora', 'aprovado'].includes(process.status)"
            @click="dialog = { action: 'prepare' }"
            >Preparar envio</BaseButton
          >
        </header>
        <p class="mb-5 max-w-3xl text-xs leading-6 text-muted">Envie o dossiê pelo portal da distribuidora e registre o protocolo e os comprovantes para acompanhar cada retorno.</p>
        <div class="mb-6 flex flex-wrap items-start gap-x-10 gap-y-5 rounded-lg bg-canvas p-4">
          <div><p class="text-[10px] font-medium uppercase tracking-wider text-muted">Protocolo</p><p class="mt-2 break-all text-sm font-semibold">{{ data.external?.protocol_number ?? 'Não registrado' }}</p></div>
          <div class="min-w-0 flex-1"><p class="text-[10px] font-medium uppercase tracking-wider text-muted">Situação externa</p><p class="mt-2 break-words text-xs font-medium">{{ data.external?.status ?? 'Ainda não registrada' }}</p></div>
          <a v-if="portal" :href="portal" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-line bg-surface px-3 text-xs font-medium text-ink hover:border-primary"><ExternalLink class="size-4" aria-hidden="true" />Abrir portal da distribuidora</a>
          <BaseButton v-if="canManage" variant="secondary" @click="dialog = { action: 'status' }">Atualizar situação externa</BaseButton>
        </div>
        <p v-if="!data.submissions.length" class="rounded-lg border border-dashed border-line px-4 py-8 text-center text-xs text-muted">Nenhum envio preparado. Os dossiês e os comprovantes aparecerão aqui.</p>
        <ul class="grid gap-4 lg:grid-cols-2">
          <li v-for="s in data.submissions" :key="s.id" class="rounded-lg border border-line p-4 text-xs">
            <div class="flex flex-wrap items-start justify-between gap-3"><div class="flex items-center gap-3"><span class="inline-flex size-9 items-center justify-center rounded-lg bg-primary-soft text-primary"><FileArchive class="size-5" aria-hidden="true" /></span><div><p class="font-semibold">{{ labels[s.kind] }}</p><p class="mt-1 text-[10px] text-muted">Versão {{ s.version }}</p></div></div><StatusBadge :tone="s.status === 'sent' ? 'success' : s.status === 'failed' ? 'danger' : 'info'">{{ labels[s.status] ?? s.status }}</StatusBadge></div>
            <p v-if="s.change_reason || s.external_receipt" class="mt-4 break-words text-xs leading-6 text-muted">
              {{ s.change_reason }}<template v-if="s.external_receipt"> · comprovante {{ s.external_receipt }}</template>
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <a
                :href="buildUrl(`/processes/${process.id}/submissions/${s.id}/dossier`)"
                target="_blank"
                rel="noopener"
                class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-line bg-surface px-3 text-xs font-medium text-ink hover:bg-canvas"
                ><Download class="size-4" aria-hidden="true" />Baixar dossiê (ZIP)</a
              ><BaseButton v-if="canManage && s.status !== 'sent'" @click="dialog = { action: 'confirm', submission: s }">{{
                s.status === 'failed' ? 'Confirmar nova tentativa' : 'Confirmar envio'
              }}</BaseButton
              ><BaseButton v-if="canManage && s.status !== 'sent'" variant="ghost" @click="dialog = { action: 'fail', submission: s }"
                >Registrar falha</BaseButton
              >
            </div>
            <details class="mt-3 text-xs text-muted">
              <summary class="cursor-pointer">Dados técnicos do envio</summary>
              <p class="my-3 break-all">SHA-256 do envio: {{ s.request_hash }}</p>
              <BaseButton variant="secondary" @click="download(s)">Baixar manifesto</BaseButton>
            </details>
          </li>
        </ul>
        <RouterLink
          v-if="process.project"
          :to="`/projetos/${process.project.id}/dados-tecnicos`"
          class="mt-4 inline-block text-sm text-primary"
          >Dados técnicos e versões do projeto</RouterLink
        >
      </section>
      <details class="rounded-xl border border-line bg-surface p-5">
        <summary class="cursor-pointer font-semibold">Comprovantes e anexos do acompanhamento</summary>
        <p class="my-3 text-sm text-muted">Envie e revise comprovantes, respostas às exigências e relatórios utilizados no acompanhamento.</p>
        <ProjectDocumentsPanel
          :endpoint="`/processes/${process.id}/documents`"
          :documents="data.documents"
          :editable="!['conectado', 'cancelado'].includes(process.status)"
          @saved="saved"
        />
      </details>
      <section v-if="data.pending_items.length || ['enviado', 'em_analise', 'pendencia_distribuidora', 'vistoria_solicitada'].includes(process.status)" class="rounded-xl border border-line bg-surface p-5">
        <header class="mb-3 flex justify-between">
          <h2 class="font-semibold">Exigências da distribuidora</h2>
          <BaseButton
            v-if="canManage && ['enviado', 'em_analise', 'pendencia_distribuidora', 'vistoria_solicitada'].includes(process.status)"
            variant="secondary"
            @click="dialog = { action: 'pending' }"
            >Registrar exigência</BaseButton
          >
        </header>
        <p v-if="!data.pending_items.length" class="text-sm text-muted">Nenhuma exigência recebida.</p>
        <ul class="divide-y divide-line text-sm">
          <li v-for="p in data.pending_items" :key="p.id" class="py-3">
            <p>{{ p.code }} · {{ p.description }}</p>
            <p class="text-muted">
              {{ p.status }}<template v-if="p.due_at"> · prazo {{ formatDateTime(p.due_at) }}</template>
            </p>
            <BaseButton
              v-if="canManage && p.status === 'aberta'"
              variant="ghost"
              @click="dialog = { action: 'response', pendingId: p.id }"
              >{{ p.response_document_id ? 'Trocar documento de resposta' : 'Vincular resposta revisada' }}</BaseButton
            >
          </li>
        </ul>
      </section>
      <details class="rounded-xl border border-line bg-surface p-5">
        <summary class="cursor-pointer font-semibold">Orçamento de conexão<span v-if="data.budgets.length"> · {{ data.budgets.length }} registro(s)</span></summary>
        <header class="mb-3 flex justify-between">
          <BaseButton v-if="canManage" variant="secondary" @click="dialog = { action: 'budget' }">Registrar orçamento</BaseButton>
        </header>
        <p v-if="!data.budgets.length" class="text-sm text-muted">Nenhum orçamento registrado.</p>
        <ul class="text-sm">
          <li v-for="b in data.budgets" :key="b.id" class="py-2">
            {{ b.issued_at }} · R$ {{ formatNumber(b.amount) }} · {{ b.works_required ? 'Exige obras' : 'Sem obras'
            }}<template v-if="b.expires_at"> · validade {{ b.expires_at }}</template>
          </li>
        </ul>
      </details>
      <section v-if="data.inspections.length || ['aprovado', 'vistoria_solicitada', 'conectado'].includes(process.status)" class="rounded-xl border border-line bg-surface p-5">
        <header class="mb-3 flex flex-wrap items-center justify-between gap-3">
          <h2 class="font-semibold">Vistorias e conexão</h2>
          <div class="flex flex-wrap gap-2">
            <BaseButton v-if="canUploadReport" variant="secondary" @click="uploadingReport = true">Anexar relatório</BaseButton>
            <BaseButton
              v-if="canManage && data.submissions.some((s) => s.kind === 'inspection' && s.status === 'sent')"
              variant="secondary"
              @click="dialog = { action: 'inspection' }"
              >Registrar vistoria</BaseButton
            >
          </div>
        </header>
        <p class="mb-3 text-sm text-muted">Para registrar a vistoria como realizada, anexe e aprove o relatório e informe a data de realização.</p>
        <ul v-if="reports.length" class="mb-4 space-y-2 rounded-xl border border-line p-3 text-sm">
          <li v-for="report in reports" :key="report.id" class="flex flex-wrap items-center justify-between gap-2">
            <span>{{ report.original_name }} · {{ report.review_status === 'aprovado' ? 'Relatório aprovado' : report.review_status === 'reprovado' ? 'Relatório reprovado' : 'Aguardando revisão' }}</span>
            <div class="flex items-center gap-3">
              <a :href="buildUrl(`/documents/${report.id}/download`)" target="_blank" rel="noopener" class="text-primary">Abrir relatório</a>
              <BaseButton v-if="report.review_status === 'pendente' && canUploadReport" variant="ghost" @click="reviewingReport = report">Revisar relatório</BaseButton>
            </div>
          </li>
        </ul>
        <p v-if="!data.inspections.length" class="text-sm text-muted">Nenhuma vistoria registrada.</p>
        <ul class="text-sm">
          <li v-for="i in data.inspections" :key="i.id" class="flex items-center justify-between gap-3 py-3">
            <span
              >{{ i.status }} · {{ formatDateTime(i.performed_at ?? i.scheduled_at ?? i.requested_at)
              }}<template v-if="i.connection_approved_at">
                · conexão aprovada {{ formatDateTime(i.connection_approved_at) }}</template
              ></span
            ><BaseButton
              v-if="canManage && process.status !== 'conectado'"
              variant="ghost"
              @click="dialog = { action: 'inspection', inspectionId: i.id }"
              >Atualizar</BaseButton
            >
          </li>
        </ul>
      </section>
      <details class="rounded-xl border border-line bg-surface p-5">
        <summary class="cursor-pointer font-semibold">Gestão do processo e atribuições</summary>
        <header class="mb-3 flex justify-between">
          <BaseButton v-if="canManage" variant="ghost" @click="dialog = { action: 'assignment' }">Responsável e prioridade</BaseButton>
        </header>
        <ul class="mb-4 text-sm">
          <li v-for="a in data.assignments" :key="a.id">{{ a.name }} · {{ a.role }} · {{ a.active ? 'Atual' : 'Anterior' }}</li>
        </ul>
        <details v-if="data.stage_history.length">
          <summary class="cursor-pointer text-sm text-muted">Histórico das atribuições de etapas</summary>
          <ol class="mt-3 space-y-2 text-sm">
            <li v-for="(h, index) in data.stage_history" :key="index">
              {{ h.stage }} · {{ formatDateTime(h.entered_at) }} · {{ h.actor ?? 'Registro anterior' }}
            </li>
          </ol>
        </details>
      </details>
      <details class="rounded-xl border border-line bg-surface p-5">
        <summary class="cursor-pointer font-semibold">Histórico dos envios e retornos</summary>
        <ul class="mt-4 space-y-3 text-sm">
          <li v-for="e in data.events" :key="e.id">
            <p>
              {{ labels[e.type] ?? (e.type === 'prepared' ? 'Dossiê preparado' : e.type) }} · {{ e.success ? 'Registrado' : 'Falha' }} ·
              {{ formatDateTime(e.occurred_at) }}
            </p>
            <p v-if="e.response?.reason" class="text-muted">{{ e.response.reason }}</p>
          </li>
        </ul>
      </details>
      <TrackingActionDialog
        v-if="dialog"
        :process="process"
        :tracking="data"
        :documents="data.documents"
        v-bind="dialog"
        @close="dialog = null"
        @saved="saved"
      />
      <DocumentUploadDialog v-if="uploadingReport" :endpoint="`/processes/${process.id}/documents`" initial-type="INSPECTION_REPORT" fixed-type @close="uploadingReport = false" @saved="reportUploaded" />
      <ReviewDialog v-if="reviewingReport" :document="reviewingReport" @close="reviewingReport = null" @saved="reportReviewed" />
    </template>
  </div>
</template>
