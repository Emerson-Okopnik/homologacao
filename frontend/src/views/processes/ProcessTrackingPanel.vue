<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ProjectDocumentsPanel from '@/views/documents/ProjectDocumentsPanel.vue'
import TrackingActionDialog from './TrackingActionDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, buildUrl, toApiError, type ApiError } from '@/lib/http'
import { formatDateTime, formatNumber } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { HomologationProcess } from '@/types/api'
import type { Tracking, ProjectTechnicalResponse, Submission } from '@/types/homologation'
const props = defineProps<{ process: HomologationProcess; revision: number }>()
const emit = defineEmits<{ saved: [] }>()
const auth = useAuthStore(),
  dialog = ref<{ action: string; submission?: Submission; pendingId?: string; inspectionId?: string } | null>(null),
  downloadError = ref<ApiError | null>(null)
const source = computed(() => ({ id: props.process.id, revision: props.revision }))
const { data, error } = useApiQuery(source, async (s) => {
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
const portal = computed(() => {
  const url = data.value?.external?.portal_url ?? props.process.distributor?.portal_url
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
    <template v-if="data">
      <section class="rounded-2xl border border-line bg-surface p-5">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <h2 class="font-semibold">Envios e acompanhamento da distribuidora</h2>
          <BaseButton
            v-if="canManage && ['pronto_para_envio', 'pendencia_distribuidora', 'aprovado'].includes(process.status)"
            @click="dialog = { action: 'prepare' }"
            >Preparar envio</BaseButton
          >
        </header>
        <p class="mb-4 text-sm text-muted">Canal assistido: envie o dossiê pelo portal e registre os comprovantes aqui.</p>
        <a v-if="portal" :href="portal" target="_blank" rel="noopener" class="mb-3 inline-block text-sm font-medium text-primary"
          >Abrir portal da distribuidora</a
        >
        <div class="mb-4 flex flex-wrap gap-3 text-sm">
          <span>Protocolo: {{ data.external?.protocol_number ?? '—' }}</span
          ><span>Situação externa: {{ data.external?.status ?? 'Ainda não registrada' }}</span
          ><BaseButton v-if="canManage" variant="ghost" @click="dialog = { action: 'status' }">Atualizar situação externa</BaseButton>
        </div>
        <p v-if="!data.submissions.length" class="text-sm text-muted">Nenhum envio preparado.</p>
        <ul class="divide-y divide-line">
          <li v-for="s in data.submissions" :key="s.id" class="py-4 text-sm">
            <p class="font-semibold">{{ labels[s.kind] }} · versão {{ s.version }} · {{ labels[s.status] ?? s.status }}</p>
            <p class="mt-1 text-muted">
              {{ s.change_reason }}<template v-if="s.external_receipt"> · comprovante {{ s.external_receipt }}</template>
            </p>
            <p class="mt-1 break-all text-xs text-muted">SHA-256 do envio: {{ s.request_hash }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <a
                :href="buildUrl(`/processes/${process.id}/submissions/${s.id}/dossier`)"
                target="_blank"
                rel="noopener"
                class="text-primary hover:underline"
                >Baixar dossiê (ZIP)</a
              ><BaseButton variant="secondary" @click="download(s)">Baixar manifesto</BaseButton
              ><BaseButton v-if="canManage && s.status !== 'sent'" @click="dialog = { action: 'confirm', submission: s }">{{
                s.status === 'failed' ? 'Confirmar nova tentativa' : 'Confirmar envio'
              }}</BaseButton
              ><BaseButton v-if="canManage && s.status !== 'sent'" variant="ghost" @click="dialog = { action: 'fail', submission: s }"
                >Registrar falha</BaseButton
              >
            </div>
          </li>
        </ul>
        <RouterLink
          v-if="process.project"
          :to="`/projetos/${process.project.id}/dados-tecnicos`"
          class="mt-4 inline-block text-sm text-primary"
          >Dados técnicos e versões do projeto</RouterLink
        >
      </section>
      <ProjectDocumentsPanel
        :endpoint="`/processes/${process.id}/documents`"
        :documents="data.documents"
        :editable="!['conectado', 'cancelado'].includes(process.status)"
        @saved="saved"
      />
      <section class="rounded-2xl border border-line bg-surface p-5">
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
      <section class="rounded-2xl border border-line bg-surface p-5">
        <header class="mb-3 flex justify-between">
          <h2 class="font-semibold">Orçamento de conexão</h2>
          <BaseButton v-if="canManage" variant="secondary" @click="dialog = { action: 'budget' }">Registrar orçamento</BaseButton>
        </header>
        <p v-if="!data.budgets.length" class="text-sm text-muted">Nenhum orçamento registrado.</p>
        <ul class="text-sm">
          <li v-for="b in data.budgets" :key="b.id" class="py-2">
            {{ b.issued_at }} · R$ {{ formatNumber(b.amount) }} · {{ b.works_required ? 'Exige obras' : 'Sem obras'
            }}<template v-if="b.expires_at"> · validade {{ b.expires_at }}</template>
          </li>
        </ul>
      </section>
      <section class="rounded-2xl border border-line bg-surface p-5">
        <header class="mb-3 flex justify-between">
          <h2 class="font-semibold">Vistorias e conexão</h2>
          <BaseButton
            v-if="canManage && data.submissions.some((s) => s.kind === 'inspection' && s.status === 'sent')"
            variant="secondary"
            @click="dialog = { action: 'inspection' }"
            >Registrar vistoria</BaseButton
          >
        </header>
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
      <section class="rounded-2xl border border-line bg-surface p-5">
        <header class="mb-3 flex justify-between">
          <h2 class="font-semibold">Responsáveis e etapas</h2>
          <BaseButton v-if="canManage" variant="ghost" @click="dialog = { action: 'assignment' }">Responsável e prioridade</BaseButton>
        </header>
        <ul class="mb-4 text-sm">
          <li v-for="a in data.assignments" :key="a.id">{{ a.name }} · {{ a.role }} · {{ a.active ? 'Atual' : 'Anterior' }}</li>
        </ul>
        <ol class="space-y-2 text-sm">
          <li v-for="(h, index) in data.stage_history" :key="index">
            {{ h.stage }} · {{ formatDateTime(h.entered_at) }} · {{ h.actor ?? 'Registro anterior' }}
          </li>
        </ol>
      </section>
      <details class="rounded-2xl border border-line bg-surface p-5">
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
    </template>
  </div>
</template>
