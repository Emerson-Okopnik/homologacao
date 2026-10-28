<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Activity, ArrowLeft, Check, CircleCheck, Download, FileText, FileUp, HardHat, Pencil, RefreshCw, Send, ShieldCheck, UserRound } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, buildUrl, toApiError, upload, type ApiError } from '@/lib/http'
import { formatDateTime, formatNumber } from '@/lib/format'
import { compensationOptions, labelOf, requestTone } from '@/lib/requestOptions'
import type { ClientObligation, ClientRequest } from '@/types/api'

const route = useRoute()
const id = computed(() => String(route.params.id))

const refreshKey = ref(0)
const { data, error, loading } = useApiQuery(
  () => [id.value, refreshKey.value] as const,
  ([rid]) => api<{ data: ClientRequest }>(`/portal/requests/${rid}`),
)
function reload() {
  refreshKey.value++
}
const request = ref<ClientRequest | null>(null)
const current = computed(() => request.value ?? data.value?.data ?? null)

const uploading = ref<string | null>(null)
const actionError = ref<ApiError | null>(null)

async function sendFile(o: ClientObligation, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  uploading.value = o.type
  actionError.value = null
  try {
    const form = new FormData()
    form.append('document_type', o.type)
    form.append('file', file)
    const res = await upload<{ data: ClientRequest }>(`/portal/requests/${id.value}/documents`, form)
    request.value = res.data
  } catch (e) {
    actionError.value = toApiError(e)
  } finally {
    uploading.value = null
  }
}

const message = ref('')
const sending = ref(false)
async function sendMessage() {
  if (!message.value.trim()) return
  sending.value = true
  actionError.value = null
  try {
    const res = await api<{ data: ClientRequest }>(`/portal/requests/${id.value}/messages`, {
      method: 'POST',
      body: { text: message.value.trim() },
    })
    request.value = res.data
    message.value = ''
  } catch (e) {
    actionError.value = toApiError(e)
  } finally {
    sending.value = false
  }
}

const progressPct = computed(() => {
  const p = current.value?.client_progress
  return p && p.required ? Math.round((p.sent / p.required) * 100) : 100
})

function reviewTone(status: string) {
  if (status === 'APPROVED') return 'success' as const
  if (status === 'REJECTED') return 'danger' as const
  return 'neutral' as const
}
function reviewLabel(status: string) {
  return { APPROVED: 'Aprovado', REJECTED: 'Precisa reenviar', PENDING: 'Em conferência' }[status] ?? status
}
const phases = [
  { value: 'PREPARATION', label: 'Preparação' },
  { value: 'EXTERNAL_ANALYSIS', label: 'Distribuidora' },
  { value: 'CORRECTION', label: 'Ajustes' },
  { value: 'EXECUTION', label: 'Instalação' },
  { value: 'INSPECTION', label: 'Vistoria' },
  { value: 'CONNECTION', label: 'Conexão' },
]
const phaseIndex = computed(() => phases.findIndex(p => p.value === current.value?.project?.process?.stage.value))
const clientActions = computed(() => current.value?.client_obligations.filter(o => o.required && (!o.document || o.document.review_status === 'REJECTED')).length ?? 0)
const guidance = computed(() => {
  const r = current.value
  if (!r) return { title: '', description: '' }
  if (r.status.value === 'CANCELLED') return { title: 'Solicitação cancelada.', description: 'Os dados e registros desta solicitação continuam disponíveis para consulta.' }
  if (r.status.value === 'NEEDS_INFO') return { title: 'Precisamos de algumas informações.', description: 'Confira a orientação da equipe na conversa, complemente os dados e envie os documentos solicitados.' }
  if (r.project?.process?.status.value === 'conectado') return { title: 'Seu sistema está conectado à rede.', description: 'A homologação foi concluída. Os documentos e o histórico seguem disponíveis para você.' }
  const messages: Record<string, { title: string; description: string }> = {
    PREPARATION: { title: 'Estamos preparando seu projeto.', description: 'A equipe está reunindo os dados e documentos necessários para solicitar a análise da distribuidora.' },
    EXTERNAL_ANALYSIS: { title: 'Seu projeto está com a distribuidora.', description: 'Acompanhe os retornos e as atualizações registrados pela equipe durante a análise.' },
    CORRECTION: { title: 'Estamos ajustando seu projeto.', description: 'A equipe está revisando o que foi solicitado pela distribuidora para seguir com a homologação.' },
    EXECUTION: { title: 'É hora de preparar a instalação.', description: 'Com o parecer aprovado, a equipe acompanha os requisitos de execução e preparação para a vistoria.' },
    INSPECTION: { title: 'Acompanhe a vistoria da instalação.', description: 'A equipe registra o agendamento e o resultado da vistoria realizada pela distribuidora.' },
    CONNECTION: { title: 'Sua conexão está em andamento.', description: 'A equipe acompanha as últimas etapas e as evidências de conexão com a rede.' },
  }
  const phase = r.project?.process?.stage.value
  return (phase && messages[phase]) || { title: r.project ? 'Seu projeto está em elaboração.' : 'Recebemos sua solicitação.', description: 'Sua equipe está analisando as informações. Os próximos passos serão registrados aqui no portal.' }
})
watch(() => [route.hash, current.value], async () => {
  if (route.hash !== '#documentos') return
  await nextTick()
  document.getElementById('documentos')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
})
watch(id, () => { request.value = null })
</script>

<template>
  <div class="portal-detail">
    <RouterLink to="/portal#minhas-solicitacoes" class="portal-back"><ArrowLeft class="size-4" aria-hidden="true" />Minhas solicitações</RouterLink>
    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading && !current" role="status" class="portal-detail-loading">Carregando sua solicitação…</p>

    <template v-if="current">
      <header class="portal-detail-heading">
        <div>
          <div class="portal-detail-title"><h1>Seu projeto fotovoltaico</h1><StatusBadge :tone="requestTone(current.status.value)">{{ current.status.label }}</StatusBadge></div>
          <p>{{ current.code }} · UC {{ current.consumer_unit?.number }} · {{ current.consumer_unit?.distributor?.name }}</p>
        </div>
        <RouterLink v-if="current.editable" :to="`/portal/solicitacoes/${current.id}/editar`" class="portal-detail-edit"><Pencil class="size-4" aria-hidden="true" />Editar dados</RouterLink>
      </header>

      <ol v-if="phaseIndex >= 0" class="portal-phase-list" aria-label="Etapas da homologação">
        <li v-for="(phase, i) in phases" :key="phase.value" :class="{ current: i === phaseIndex, complete: i < phaseIndex }" :aria-current="i === phaseIndex ? 'step' : undefined">
          <span class="portal-phase-number" aria-hidden="true"><Check v-if="i < phaseIndex" class="size-3.5" /><template v-else>{{ i + 1 }}</template></span>
          <span>{{ phase.label }}</span>
        </li>
      </ol>

      <InlineAlert v-if="actionError" :correlation-id="actionError.correlationId">{{ actionError.message }}</InlineAlert>
      <div class="portal-detail-grid">
        <div class="portal-detail-main">
          <section class="portal-current-status" aria-labelledby="portal-current-status-title">
            <div class="portal-status-eyebrow"><Activity class="size-6" aria-hidden="true" /><span>O que está acontecendo</span></div>
            <h2 id="portal-current-status-title">{{ guidance.title }}</h2>
            <p>{{ guidance.description }}</p>
          </section>

          <section id="documentos" class="portal-detail-panel portal-document-section" aria-labelledby="docs-cliente">
            <div class="portal-panel-heading"><h2 id="docs-cliente"><UserRound class="size-4 text-primary" aria-hidden="true" />Seus documentos</h2><span>{{ current.client_progress.sent }} de {{ current.client_progress.required }} enviados</span></div>
            <p class="portal-panel-description">Documentos que só o titular da unidade pode fornecer. Aceitamos PDF, JPG ou PNG de até 20 MB.</p>
            <div class="portal-doc-progress" role="progressbar" :aria-valuenow="progressPct" aria-valuemin="0" aria-valuemax="100" aria-label="Documentos enviados"><div :style="{ width: `${progressPct}%` }" /></div>
            <ul class="portal-document-list">
              <li v-for="o in current.client_obligations" :key="o.type">
                <div class="portal-document-data">
                  <CircleCheck v-if="o.document?.review_status === 'APPROVED'" class="size-6 shrink-0 text-primary" aria-hidden="true" /><FileText v-else class="size-6 shrink-0 text-primary" aria-hidden="true" />
                  <div class="min-w-0">
                    <h3>{{ o.label }}<span v-if="!o.required">(se houver)</span></h3>
                    <p class="portal-document-reason">{{ o.reason }}</p>
                    <p v-if="o.document" class="portal-document-file">{{ o.document.original_name }} · v{{ o.document.version }}</p>
                    <p v-if="o.document?.review_notes" class="portal-document-note">{{ o.document.review_notes }}</p>
                    <StatusBadge v-if="o.document" :tone="reviewTone(o.document.review_status)">{{ reviewLabel(o.document.review_status) }}</StatusBadge>
                    <span v-else class="portal-document-needed">{{ o.required ? 'Envio necessário' : 'Envio opcional' }}</span>
                  </div>
                </div>
                <div class="portal-document-actions">
                  <a v-if="o.document" :href="buildUrl(`/portal/documents/${o.document.id}/download`)"><Download class="size-4" aria-hidden="true" />Baixar arquivo<span class="sr-only"> {{ o.label }}</span></a>
                  <label v-if="current.status.value !== 'CANCELLED'" class="portal-upload-button" :class="{ primary: !o.document, busy: uploading !== null }">
                    <FileUp class="size-4" aria-hidden="true" />{{ uploading === o.type ? 'Enviando…' : o.document ? 'Enviar nova versão' : 'Enviar documento' }}
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="sr-only" :disabled="uploading !== null" @change="sendFile(o, $event)" />
                  </label>
                </div>
              </li>
              <li v-if="!current.client_obligations.length" class="portal-no-documents">Nenhum documento do titular foi solicitado para esta instalação.</li>
            </ul>
          </section>

          <section class="portal-detail-panel" aria-labelledby="conversa">
            <div class="portal-panel-heading"><h2 id="conversa">Atualizações e conversa</h2><button type="button" class="portal-refresh" @click="request = null; reload()"><RefreshCw class="size-3.5" aria-hidden="true" />Atualizar</button></div>
            <p class="portal-panel-description">Orientações e mensagens trocadas com sua equipe técnica.</p>
            <ol v-if="current.messages.length" class="portal-messages">
              <li v-for="(m, i) in current.messages" :key="i" :class="m.from">
                <p class="portal-message-meta">{{ m.from === 'system' ? 'Atualização da solicitação' : m.author }} · {{ formatDateTime(m.at) }}</p>
                <p class="portal-message-text">{{ m.text }}</p>
              </li>
            </ol>
            <p v-else class="portal-no-messages">Nenhuma mensagem ainda. Você pode falar com a equipe por aqui.</p>
            <form v-if="current.status.value !== 'CANCELLED'" class="portal-message-form" @submit.prevent="sendMessage">
              <label class="sr-only" for="msg">Mensagem</label>
              <input id="msg" v-model="message" maxlength="2000" placeholder="Escreva uma mensagem para a equipe" />
              <BaseButton type="submit" :loading="sending" :disabled="!message.trim()"><Send class="size-4" aria-hidden="true" /><span class="sr-only md:not-sr-only">Enviar</span></BaseButton>
            </form>
          </section>
        </div>

        <aside class="portal-detail-aside">
          <section v-if="current.status.value !== 'CANCELLED'" class="portal-your-part" :class="{ pending: clientActions || current.status.value === 'NEEDS_INFO' }" aria-labelledby="portal-your-part-title">
            <div><ShieldCheck class="size-6 shrink-0" aria-hidden="true" /><h2 id="portal-your-part-title">{{ clientActions || current.status.value === 'NEEDS_INFO' ? 'Aguardando sua atenção' : 'Sua parte está em dia' }}</h2></div>
            <p v-if="clientActions">{{ clientActions }} {{ clientActions === 1 ? 'documento precisa' : 'documentos precisam' }} ser enviado(s) ou atualizado(s). Confira a lista de arquivos e a orientação da equipe.</p>
            <p v-else-if="current.status.value === 'NEEDS_INFO'">Veja a mensagem da equipe, complemente os dados ou responda pela conversa.</p>
            <p v-else>Seus arquivos foram recebidos. A equipe vai conferir os documentos e orientar os próximos passos por aqui.</p>
          </section>

          <section class="portal-detail-panel" aria-labelledby="dados">
            <h2 id="dados">Resumo da instalação</h2>
            <dl class="portal-installation-data">
              <div><dt>Unidade consumidora</dt><dd>{{ current.consumer_unit?.number ?? '—' }}</dd></div>
              <div><dt>Potência dos módulos</dt><dd>{{ formatNumber(current.declared_powers.modules_kwp, 'kWp') }}</dd></div>
              <div><dt>Potência dos inversores</dt><dd>{{ formatNumber(current.declared_powers.inverters_kw, 'kW') }}</dd></div>
              <div><dt>Uso da energia</dt><dd>{{ labelOf(compensationOptions, current.system.compensation_mode) }}</dd></div>
              <div class="full"><dt>Endereço da instalação</dt><dd>{{ current.consumer_unit?.full_address ?? '—' }}</dd></div>
              <div class="full"><dt>Módulos</dt><dd v-for="(m, i) in current.system.modules" :key="i">{{ m.quantity }}× {{ m.brand }} {{ m.model }} ({{ m.power_w }} W)</dd></div>
              <div class="full"><dt>Inversor</dt><dd v-for="(inv, i) in current.system.inverters" :key="i">{{ inv.quantity }}× {{ inv.brand }} {{ inv.model }} ({{ inv.power_kw }} kW)</dd></div>
              <div v-if="current.system.has_battery"><dt>Bateria</dt><dd>{{ formatNumber(current.system.storage_energy_kwh, 'kWh') }}</dd></div>
              <div v-if="current.system.beneficiaries.length" class="full"><dt>UCs beneficiárias</dt><dd v-for="b in current.system.beneficiaries" :key="b.uc_number">UC {{ b.uc_number }}<span v-if="b.percentage"> · {{ b.percentage }}%</span></dd></div>
            </dl>
          </section>

          <section class="portal-detail-panel" aria-labelledby="rt">
            <h2 id="rt" class="flex items-center gap-2"><HardHat class="size-4 text-primary" aria-hidden="true" />Responsável técnico</h2>
            <template v-if="current.technical_responsible">
              <p class="portal-rt-name">{{ current.technical_responsible.name }}</p>
              <p class="portal-rt-meta">{{ current.technical_responsible.council }} {{ current.technical_responsible.registration }}</p>
              <p v-if="current.technical_responsible.email" class="portal-rt-meta">{{ current.technical_responsible.email }}</p>
            </template>
            <p v-else class="portal-panel-description">Um profissional da equipe será designado para acompanhar seu projeto.</p>
            <p class="portal-technical-note">Projeto elétrico, memorial, ART / TRT e o trâmite com a distribuidora ficam com o responsável técnico.</p>
          </section>

          <section class="portal-detail-panel" aria-labelledby="andamento">
            <h2 id="andamento">Andamento</h2>
            <p v-if="current.project?.process" class="portal-panel-description">Projeto {{ current.project.code }} · <strong>{{ current.project.process.stage.label }}</strong></p>
            <p v-else-if="current.project" class="portal-panel-description">Projeto {{ current.project.code }} em elaboração pela equipe.</p>
            <p v-else class="portal-panel-description">Sua solicitação está em análise pela equipe técnica.</p>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>

<style scoped>
.portal-detail {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.portal-back {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  align-self: flex-start;
  font-size: 11px;
  color: var(--color-muted);
}

.portal-back:hover {
  color: var(--color-primary);
}

.portal-detail-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
}

.portal-detail-title {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 16px;
}

.portal-detail-heading h1 {
  font-size: 29px;
  font-weight: 600;
  letter-spacing: -.035em;
  line-height: 1.4;
}

.portal-detail-heading>div>p {
  margin-top: 10px;
  font-size: 12px;
  line-height: 1.8;
  color: var(--color-muted);
}

.portal-detail-edit {
  display: inline-flex;
  min-height: 42px;
  flex-shrink: 0;
  align-items: center;
  gap: 9px;
  border: 1px solid var(--color-line);
  border-radius: 8px;
  padding: 10px 18px;
  background: var(--color-surface);
  font-size: 11px;
  font-weight: 500;
}

.portal-detail-edit:hover {
  background: var(--color-primary-soft);
}

.portal-phase-list {
  display: grid;
  grid-template-columns: repeat(6,minmax(0,1fr));
  border: 1px solid var(--color-line);
  border-radius: 11px;
  padding: 22px 26px;
  background: var(--color-surface);
}

.portal-phase-list li {
  position: relative;
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 12px;
  font-size: 10px;
  color: var(--color-muted);
}

.portal-phase-list li:not(:last-child):after {
  position: absolute;
  left: 30px;
  right: 0;
  top: 13px;
  height: 2px;
  background: var(--color-line);
  content: '';
}

.portal-phase-list li.complete:after {
  background: var(--color-primary);
}

.portal-phase-number {
  position: relative;
  z-index: 1;
  display: flex;
  height: 28px;
  width: 28px;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--color-line);
  border-radius: 50%;
  background: var(--color-surface);
  font-size: 11px;
}

.portal-phase-list li.complete .portal-phase-number,
.portal-phase-list li.current .portal-phase-number {
  border-color: var(--color-primary);
  background: var(--color-primary);
  color: var(--color-on-primary);
}

.portal-phase-list li.current {
  font-weight: 600;
  color: var(--color-ink);
}

.portal-detail-grid {
  display: grid;
  grid-template-columns: minmax(0,1.96fr) minmax(300px,1fr);
  align-items: start;
  gap: 24px;
}

.portal-detail-main,
.portal-detail-aside {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 24px;
}

.portal-current-status {
  border-radius: 11px;
  padding: 24px;
  background: var(--color-navy);
  color: white;
}

.portal-status-eyebrow {
  display: flex;
  align-items: center;
  gap: 13px;
  font-size: 9px;
  letter-spacing: .13em;
  text-transform: uppercase;
  color: #8de0c4;
}

.portal-current-status h2 {
  margin-top: 24px;
  font-size: 25px;
  font-weight: 400;
  line-height: 1.4;
  letter-spacing: -.025em;
}

.portal-current-status>p {
  max-width: 720px;
  margin-top: 17px;
  font-size: 12px;
  line-height: 1.9;
  color: #d3dff6;
}

.portal-detail-panel {
  min-width: 0;
  border: 1px solid var(--color-line);
  border-radius: 11px;
  padding: 24px;
  background: var(--color-surface);
}

.portal-detail-panel h2 {
  font-size: 14px;
  font-weight: 600;
}

.portal-panel-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 15px;
}

.portal-panel-heading h2 {
  display: flex;
  align-items: center;
  gap: 9px;
}

.portal-panel-heading>span {
  font-size: 10px;
  color: var(--color-muted);
}

.portal-panel-description {
  margin-top: 10px;
  font-size: 11px;
  line-height: 1.9;
  color: var(--color-muted);
}

.portal-doc-progress {
  height: 5px;
  margin-top: 20px;
  overflow: hidden;
  border-radius: 4px;
  background: var(--color-canvas);
}

.portal-doc-progress>div {
  height: 100%;
  border-radius: 4px;
  background: var(--color-primary);
  transition: width .2s;
}

.portal-document-section {
  scroll-margin-top: 24px;
}

.portal-document-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-top: 25px;
}

.portal-document-list>li {
  border: 1px solid var(--color-line);
  border-radius: 9px;
  padding: 18px;
  background: var(--color-canvas);
}

.portal-document-data {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}

.portal-document-data h3 {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.8;
}

.portal-document-data h3>span {
  font-size: 9px;
  font-weight: 400;
  color: var(--color-muted);
}

.portal-document-reason {
  margin-top: 4px;
  font-size: 10px;
  line-height: 1.8;
  color: var(--color-muted);
}

.portal-document-file {
  overflow-wrap: anywhere;
  margin-top: 8px;
  font-size: 10px;
  color: var(--color-muted);
}

.portal-document-note {
  margin: 8px 0;
  font-size: 11px;
  line-height: 1.8;
  color: var(--color-danger);
}

.portal-document-data :deep(.inline-flex) {
  margin-top: 10px;
}

.portal-document-needed {
  display: inline-block;
  margin-top: 9px;
  font-size: 10px;
  color: var(--color-muted);
}

.portal-document-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 18px;
}

.portal-document-actions>a,
.portal-upload-button {
  display: inline-flex;
  min-height: 40px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 1px solid var(--color-line);
  border-radius: 7px;
  padding: 9px 15px;
  background: var(--color-surface);
  font-size: 11px;
  font-weight: 500;
  color: var(--color-ink);
}

.portal-upload-button {
  cursor: pointer;
}

.portal-document-actions>a:hover,
.portal-upload-button:hover {
  border-color: var(--color-primary);
}

.portal-upload-button.primary {
  border-color: var(--color-primary);
  background: var(--color-primary);
  color: var(--color-on-primary);
}

.portal-upload-button.busy {
  cursor: progress;
  opacity: .65;
}

.portal-upload-button:focus-within {
  outline: 2px solid var(--color-primary);
  outline-offset: 3px;
}

.portal-no-documents {
  font-size: 11px;
  line-height: 1.8;
  color: var(--color-muted);
}

.portal-refresh {
  display: flex;
  min-height: 30px;
  align-items: center;
  gap: 6px;
  font-size: 10px;
  font-weight: 500;
  color: var(--color-primary);
  cursor: pointer;
}

.portal-messages {
  display: flex;
  max-height: 520px;
  flex-direction: column;
  gap: 14px;
  overflow: auto;
  margin-top: 24px;
  padding-right: 3px;
}

.portal-messages li {
  max-width: 90%;
  border-radius: 9px;
  padding: 13px 16px;
  background: var(--color-canvas);
}

.portal-messages .client {
  align-self: flex-end;
  background: var(--color-primary-soft);
}

.portal-messages .team {
  align-self: flex-start;
}

.portal-messages .system {
  align-self: stretch;
  max-width: 100%;
  border-left: 2px solid var(--color-primary);
  border-radius: 0;
  background: transparent;
  padding: 7px 0 7px 16px;
}

.portal-message-meta {
  font-size: 9px;
  line-height: 1.8;
  color: var(--color-muted);
}

.portal-message-text {
  margin-top: 6px;
  white-space: pre-line;
  overflow-wrap: anywhere;
  font-size: 12px;
  line-height: 1.9;
}

.portal-no-messages {
  margin-top: 24px;
  font-size: 11px;
  line-height: 1.9;
  color: var(--color-muted);
}

.portal-message-form {
  display: flex;
  gap: 10px;
  margin-top: 24px;
}

.portal-message-form input {
  min-width: 0;
  flex: 1;
  border: 1px solid var(--color-line);
  border-radius: 7px;
  padding: 11px 13px;
  font-size: 12px;
  outline: none;
}

.portal-message-form input:focus {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px #0e9f6e15;
}

.portal-your-part {
  border-radius: 11px;
  padding: 24px;
  background: var(--color-primary-soft);
  color: var(--color-primary);
}

.portal-your-part>div {
  display: flex;
  align-items: center;
  gap: 12px;
}

.portal-your-part h2 {
  font-size: 17px;
  font-weight: 600;
  line-height: 1.5;
  color: var(--color-ink);
}

.portal-your-part>p {
  margin-top: 22px;
  font-size: 12px;
  line-height: 1.9;
  color: var(--color-muted);
}

.portal-your-part.pending {
  background: var(--color-blue-soft);
  color: var(--color-corporate-blue);
}

.portal-installation-data {
  display: flex;
  flex-direction: column;
  margin-top: 24px;
}

.portal-installation-data>div {
  display: flex;
  justify-content: space-between;
  gap: 18px;
  padding: 12px 0;
  border-bottom: 1px solid var(--color-line);
  font-size: 10px;
  line-height: 1.8;
}

.portal-installation-data>div:last-child {
  border-bottom: 0;
  padding-bottom: 0;
}

.portal-installation-data dt {
  color: var(--color-muted);
}

.portal-installation-data dd {
  max-width: 60%;
  text-align: right;
  font-weight: 500;
}

.portal-installation-data .full {
  flex-direction: column;
  gap: 7px;
}

.portal-installation-data .full dd {
  max-width: 100%;
  text-align: left;
}

.portal-rt-name {
  margin-top: 23px;
  font-size: 13px;
  font-weight: 500;
}

.portal-rt-meta {
  overflow-wrap: anywhere;
  margin-top: 7px;
  font-size: 11px;
  line-height: 1.8;
  color: var(--color-muted);
}

.portal-technical-note {
  margin-top: 22px;
  border-top: 1px solid var(--color-line);
  padding-top: 17px;
  font-size: 10px;
  line-height: 1.9;
  color: var(--color-muted);
}

.portal-detail-loading {
  padding: 48px 24px;
  text-align: center;
  font-size: 13px;
  color: var(--color-muted);
}

@media (max-width:1023px) {
  .portal-detail-grid {
    grid-template-columns: minmax(0,1fr) 300px;
  }

  .portal-detail-panel {
    padding: 20px;
  }

  .portal-panel-heading {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .portal-current-status h2 {
    font-size: 23px;
  }
}

@media (max-width:767px) {
  .portal-detail {
    gap: 20px;
  }

  .portal-back {
    font-size: 10px;
  }

  .portal-detail-heading {
    flex-direction: column;
    gap: 16px;
  }

  .portal-detail-title {
    gap: 12px;
  }

  .portal-detail-heading h1 {
    font-size: 24px;
  }

  .portal-detail-heading>div>p {
    font-size: 10px;
    line-height: 1.9;
  }

  .portal-detail-edit {
    min-height: 40px;
    font-size: 10px;
  }

  .portal-phase-list {
    padding: 18px 14px;
    border-radius: 10px;
  }

  .portal-phase-list li {
    gap: 10px;
    font-size: 7px;
    line-height: 1.6;
  }

  .portal-phase-number {
    width: 23px;
    height: 23px;
    font-size: 9px;
  }

  .portal-phase-list li:not(:last-child):after {
    left: 25px;
    top: 10px;
  }

  .portal-detail-grid {
    grid-template-columns: minmax(0,1fr);
    gap: 20px;
  }

  .portal-detail-main,
  .portal-detail-aside {
    gap: 20px;
  }

  .portal-current-status {
    padding: 18px;
  }

  .portal-status-eyebrow {
    gap: 10px;
    font-size: 8px;
  }

  .portal-current-status h2 {
    margin-top: 19px;
    font-size: 21px;
  }

  .portal-current-status>p {
    font-size: 11px;
    margin-top: 14px;
  }

  .portal-detail-panel {
    padding: 18px;
    border-radius: 10px;
  }

  .portal-panel-heading h2 {
    font-size: 14px;
  }

  .portal-panel-description {
    font-size: 10.5px;
  }

  .portal-document-list>li {
    padding: 15px;
  }

  .portal-document-data {
    gap: 12px;
  }

  .portal-document-actions {
    flex-direction: column;
  }

  .portal-document-actions>a,
  .portal-upload-button {
    min-height: 44px;
    font-size: 11px;
  }

  .portal-document-data h3 {
    font-size: 13px;
  }

  .portal-document-file {
    font-size: 10px;
  }

  .portal-message-text {
    font-size: 11px;
  }

  .portal-messages li {
    max-width: 95%;
  }

  .portal-your-part {
    padding: 20px;
  }

  .portal-your-part h2 {
    font-size: 16px;
  }

  .portal-your-part>p {
    font-size: 11px;
    margin-top: 16px;
  }

  .portal-message-form input {
    font-size: 11px;
  }
}

@media (prefers-reduced-motion:reduce) {
  .portal-doc-progress>div {
    transition: none;
  }
}
</style>
