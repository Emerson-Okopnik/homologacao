<script setup lang="ts">
import { computed, nextTick, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ArrowRight, ChevronRight, FileText, FileUp, HardHat, Plus, ShieldCheck, Sun } from '@lucide/vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import { formatDate, formatNumber } from '@/lib/format'
import { requestTone } from '@/lib/requestOptions'
import { useAuthStore } from '@/stores/auth'
import type { ClientRequest, PortalSummary } from '@/types/api'

const auth = useAuthStore()
const route = useRoute()
const { data: summary, error: summaryError } = useApiQuery(() => 1, () => api<{ data: PortalSummary }>('/portal/summary'))
const { data, error, loading } = useApiQuery(() => 1, () => api<{ data: ClientRequest[] }>('/portal/requests'))
const requests = computed(() => data.value?.data ?? [])
const firstName = computed(() => auth.user?.name?.split(' ')[0] ?? '')
const primary = computed(() => requests.value.find(r => r.status.value !== 'CANCELLED') ?? requests.value[0])
const attention = computed(() => requests.value.filter(r => r.status.value !== 'CANCELLED' && (r.status.value === 'NEEDS_INFO' || pendingDocs(r) > 0)))
const responsible = computed(() => primary.value?.technical_responsible)
const cards = computed(() => [
  { label: 'Solicitações em andamento', value: summary.value?.data.requests_open },
  { label: 'Aguardando você', value: summary.value?.data.needs_info, highlight: true },
  { label: 'Viraram projeto', value: summary.value?.data.converted },
  { label: 'Unidades consumidoras', value: summary.value?.data.units },
])

function pendingDocs(r: ClientRequest): number {
  return Math.max(r.client_progress.required - r.client_progress.sent, 0) + r.client_obligations.filter(o => o.required && o.document?.review_status === 'REJECTED').length
}
function reviewLabel(status: string) {
  return { APPROVED: 'Aprovado', REJECTED: 'Precisa reenviar', PENDING: 'Em conferência' }[status] ?? status
}

watch(() => [route.hash, loading.value], async () => {
  if (!['#minhas-solicitacoes', '#documentos'].includes(route.hash)) return
  await nextTick()
  document.getElementById(route.hash.slice(1))?.scrollIntoView({ behavior: 'smooth', block: 'start' })
})
</script>

<template>
  <div class="portal-home">
    <header class="portal-page-heading">
      <h1>Olá, {{ firstName }}.</h1>
      <p>Acompanhe sua energia e encontre tudo o que precisa em um só lugar.</p>
    </header>

    <section class="portal-welcome" aria-labelledby="portal-welcome-title">
      <div>
        <p class="portal-eyebrow">Mais clareza para você</p>
        <h2 id="portal-welcome-title">Seu projeto, sempre por perto.</h2>
        <p>Veja as atualizações, envie documentos e acompanhe os próximos passos.</p>
      </div>
      <RouterLink to="/portal/solicitacoes/nova" class="portal-primary-link"><Plus class="size-4" aria-hidden="true" />Nova solicitação</RouterLink>
    </section>

    <InlineAlert v-if="summaryError" :correlation-id="summaryError.correlationId">{{ summaryError.message }}</InlineAlert>
    <section aria-label="Resumo" class="portal-summary">
      <div v-for="card in cards" :key="card.label" :class="{ attention: card.highlight && (card.value ?? 0) > 0 }">
        <p>{{ card.label }}</p>
        <strong>{{ card.value ?? '—' }}</strong>
      </div>
    </section>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading" role="status" class="portal-loading">Carregando suas solicitações…</p>

    <template v-else-if="!error">
      <div v-if="requests.length" class="portal-home-grid">
        <section class="portal-client-status" :class="{ 'needs-attention': attention.length > 0 }" aria-labelledby="client-status-title">
          <ShieldCheck class="size-7 shrink-0" aria-hidden="true" />
          <div>
            <h2 id="client-status-title">{{ attention.length ? 'Aguardando sua atenção' : 'Sua parte está em dia' }}</h2>
            <p v-if="attention.length">Há {{ attention.length }} {{ attention.length === 1 ? 'solicitação que precisa' : 'solicitações que precisam' }} de informações ou documentos. Abra o acompanhamento para ver o que falta.</p>
            <p v-else>Seus documentos foram recebidos. Acompanhe a conferência e os próximos passos registrados pela equipe.</p>
          </div>
        </section>

        <section class="portal-panel portal-team" aria-labelledby="portal-team-title">
          <h2 id="portal-team-title">Sua equipe de homologação</h2>
          <div class="portal-team-content">
            <span class="portal-team-avatar" aria-hidden="true"><HardHat class="size-5" /></span>
            <div>
              <p>{{ responsible?.name ?? 'Equipe VERIGRID' }}</p>
              <span>{{ responsible ? `${responsible.council} ${responsible.registration}` : 'Acompanhamento técnico da solicitação' }}</span>
            </div>
          </div>
        </section>

        <section id="minhas-solicitacoes" class="portal-panel portal-requests" aria-labelledby="minhas">
          <div class="portal-section-heading"><h2 id="minhas">Minhas solicitações</h2><span>{{ requests.length }} {{ requests.length === 1 ? 'solicitação' : 'solicitações' }}</span></div>
          <ul class="portal-request-list">
            <li v-for="r in requests" :key="r.id">
              <RouterLink :to="`/portal/solicitacoes/${r.id}`" class="portal-request-card">
                <div class="portal-request-top"><span>{{ r.code }}</span><StatusBadge :tone="requestTone(r.status.value)">{{ r.status.label }}</StatusBadge></div>
                <h3>UC {{ r.consumer_unit?.number ?? '—' }}</h3>
                <p>{{ r.consumer_unit?.distributor?.name ?? 'Distribuidora não informada' }} · {{ formatNumber(r.declared_powers.modules_kwp, 'kWp') }}</p>
                <div v-if="r.project?.process" class="portal-current-phase"><span>Etapa atual</span><strong>{{ r.project.process.stage.label }}</strong></div>
                <div class="portal-request-bottom">
                  <span v-if="pendingDocs(r) > 0 && r.status.value !== 'CANCELLED'" class="portal-docs-pending"><FileUp class="size-4" aria-hidden="true" />{{ pendingDocs(r) }} documento(s) pendente(s)</span>
                  <span v-else>Aberta em {{ formatDate(r.submitted_at ?? r.created_at) }}</span>
                  <span class="portal-request-action">Acompanhar solicitação<ArrowRight class="size-4" aria-hidden="true" /></span>
                </div>
              </RouterLink>
            </li>
          </ul>
        </section>

        <section v-if="primary" id="documentos" class="portal-panel portal-home-documents" aria-labelledby="portal-documents-title">
          <h2 id="portal-documents-title">Seus documentos</h2>
          <p>Arquivos da solicitação {{ primary.code }}</p>
          <ul>
            <li v-for="o in primary.client_obligations" :key="o.type">
              <FileText class="size-6 shrink-0 text-primary" aria-hidden="true" />
              <div><h3>{{ o.label }}</h3><StatusBadge v-if="o.document" :tone="o.document.review_status === 'APPROVED' ? 'success' : o.document.review_status === 'REJECTED' ? 'danger' : 'neutral'">{{ reviewLabel(o.document.review_status) }}</StatusBadge><span v-else class="portal-document-missing">{{ o.required ? 'Envio necessário' : 'Se houver' }}</span></div>
            </li>
            <li v-if="!primary.client_obligations.length" class="portal-document-missing">Os documentos serão definidos conforme os dados da solicitação.</li>
          </ul>
          <RouterLink :to="`/portal/solicitacoes/${primary.id}#documentos`" class="portal-secondary-link">Ver meus documentos<ChevronRight class="size-4" aria-hidden="true" /></RouterLink>
        </section>
      </div>

      <section v-else id="minhas-solicitacoes" class="portal-empty" aria-labelledby="minhas">
        <span id="documentos" aria-hidden="true" />
        <Sun class="size-9 text-primary" aria-hidden="true" />
        <h2 id="minhas">Sua energia começa aqui.</h2>
        <p>Você ainda não abriu nenhuma solicitação. Informe a unidade consumidora e os equipamentos; a equipe técnica orienta os próximos passos.</p>
        <RouterLink to="/portal/solicitacoes/nova" class="portal-primary-link"><Plus class="size-4" aria-hidden="true" />Abrir minha primeira solicitação</RouterLink>
      </section>
    </template>
  </div>
</template>

<style scoped>
.portal-home {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.portal-page-heading h1 {
  font-size: 30px;
  font-weight: 600;
  letter-spacing: -.035em;
}

.portal-page-heading p {
  margin-top: 9px;
  font-size: 13px;
  line-height: 1.8;
  color: var(--color-muted);
}

.portal-welcome {
  display: flex;
  min-height: 181px;
  align-items: center;
  justify-content: space-between;
  gap: 32px;
  overflow: hidden;
  border-radius: 12px;
  padding: 26px 30px;
  background: var(--color-navy);
  color: white;
}

.portal-welcome .portal-eyebrow {
  font-size: 9px;
  letter-spacing: .16em;
  text-transform: uppercase;
  color: #a1dec9;
}

.portal-welcome h2 {
  margin: 16px 0 14px;
  font-size: 28px;
  font-weight: 400;
  line-height: 1.35;
  letter-spacing: -.035em;
}

.portal-welcome>div>p:last-child {
  font-size: 12px;
  line-height: 1.8;
  color: #d3dff6;
}

.portal-primary-link,
.portal-secondary-link {
  display: inline-flex;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  gap: 10px;
  border-radius: 8px;
  padding: 12px 20px;
  font-size: 12px;
  font-weight: 500;
  line-height: 1.5;
  transition: background .15s;
}

.portal-primary-link {
  flex-shrink: 0;
  min-width: 226px;
  background: var(--color-primary);
  color: var(--color-on-primary);
}

.portal-primary-link:hover {
  background: var(--color-primary-hover);
}

.portal-secondary-link {
  border: 1px solid var(--color-line);
  background: var(--color-surface);
  color: var(--color-ink);
}

.portal-secondary-link:hover {
  background: var(--color-canvas);
}

.portal-summary {
  display: grid;
  grid-template-columns: repeat(4,minmax(0,1fr));
  gap: 16px;
}

.portal-summary>div {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  border: 1px solid var(--color-line);
  border-radius: 10px;
  padding: 16px 20px;
  background: var(--color-surface);
}

.portal-summary p {
  font-size: 10px;
  line-height: 1.7;
  color: var(--color-muted);
}

.portal-summary strong {
  font-size: 25px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.portal-summary .attention {
  border-color: var(--color-line);
  background: var(--color-blue-soft);
}

.portal-home-grid {
  display: grid;
  grid-template-columns: minmax(0,1.96fr) minmax(300px,1fr);
  align-items: start;
  gap: 24px;
}

.portal-panel {
  min-width: 0;
  border: 1px solid var(--color-line);
  border-radius: 11px;
  padding: 22px;
  background: var(--color-surface);
}

.portal-panel h2 {
  font-size: 14px;
  font-weight: 600;
}

.portal-client-status {
  display: flex;
  min-height: 147px;
  gap: 16px;
  border-radius: 11px;
  padding: 24px;
  background: var(--color-primary-soft);
  color: var(--color-primary);
}

.portal-client-status h2 {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-ink);
}

.portal-client-status p {
  max-width: 630px;
  margin-top: 14px;
  font-size: 12px;
  line-height: 1.9;
  color: var(--color-muted);
}

.portal-client-status.needs-attention {
  background: var(--color-blue-soft);
  color: var(--color-corporate-blue);
}

.portal-team {
  min-height: 147px;
}

.portal-team-content {
  display: flex;
  align-items: center;
  gap: 13px;
  margin-top: 23px;
}

.portal-team-avatar {
  display: flex;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--color-primary-soft);
  color: var(--color-primary);
}

.portal-team-content p {
  font-size: 12px;
  font-weight: 500;
}

.portal-team-content span:not(.portal-team-avatar) {
  display: block;
  margin-top: 5px;
  font-size: 10px;
  color: var(--color-muted);
}

.portal-requests {
  scroll-margin-top: 24px;
}

.portal-section-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.portal-section-heading>span {
  font-size: 10px;
  color: var(--color-muted);
}

.portal-request-list {
  margin-top: 24px;
}

.portal-request-list>li+li {
  border-top: 1px solid var(--color-line);
  margin-top: 24px;
  padding-top: 24px;
}

.portal-request-card {
  display: block;
  border-radius: 7px;
}

.portal-request-card:hover h3 {
  color: var(--color-primary);
}

.portal-request-top {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.portal-request-top>span:first-child {
  font-size: 10px;
  color: var(--color-muted);
}

.portal-request-card h3 {
  margin-top: 18px;
  font-size: 20px;
  font-weight: 600;
  letter-spacing: -.025em;
}

.portal-request-card>p {
  margin-top: 8px;
  font-size: 11px;
  line-height: 1.8;
  color: var(--color-muted);
}

.portal-current-phase {
  display: flex;
  flex-direction: column;
  gap: 9px;
  margin-top: 25px;
  border-left: 3px solid var(--color-primary);
  padding-left: 14px;
}

.portal-current-phase>span {
  font-size: 9px;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--color-muted);
}

.portal-current-phase strong {
  font-size: 13px;
  font-weight: 500;
}

.portal-request-bottom {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 15px;
  margin-top: 25px;
  font-size: 10px;
  color: var(--color-muted);
}

.portal-docs-pending {
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--color-warning);
}

.portal-request-action {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 11px;
  font-weight: 500;
  color: var(--color-primary);
}

.portal-home-documents>p {
  margin-top: 7px;
  font-size: 10px;
  line-height: 1.8;
  color: var(--color-muted);
}

.portal-home-documents ul {
  margin: 28px 0;
}

.portal-home-documents li {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}

.portal-home-documents li+li {
  margin-top: 18px;
  border-top: 1px solid var(--color-line);
  padding-top: 18px;
}

.portal-home-documents h3 {
  margin-bottom: 8px;
  font-size: 11px;
  font-weight: 500;
}

.portal-home-documents .portal-secondary-link {
  width: 100%;
  margin-top: 5px;
}

.portal-document-missing {
  font-size: 10px;
  line-height: 1.9;
  color: var(--color-muted);
}

.portal-loading {
  padding: 48px;
  text-align: center;
  color: var(--color-muted);
  font-size: 13px;
}

.portal-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
  min-height: 340px;
  justify-content: center;
  border: 1px dashed var(--color-line);
  border-radius: 12px;
  padding: 40px 24px;
  background: var(--color-surface);
  text-align: center;
}

.portal-empty h2 {
  font-size: 22px;
  font-weight: 500;
}

.portal-empty p {
  max-width: 600px;
  font-size: 13px;
  line-height: 1.9;
  color: var(--color-muted);
}

@media (max-width:1023px) {
  .portal-home-grid {
    grid-template-columns: minmax(0,1.6fr) minmax(280px,1fr);
  }

  .portal-summary {
    gap: 12px;
  }

  .portal-summary>div {
    padding: 14px;
  }

  .portal-welcome {
    gap: 24px;
  }

  .portal-welcome h2 {
    font-size: 25px;
  }
}

@media (max-width:767px) {
  .portal-home {
    gap: 20px;
  }

  .portal-page-heading h1 {
    font-size: 26px;
  }

  .portal-page-heading p {
    margin-top: 7px;
    font-size: 11px;
  }

  .portal-welcome {
    min-height: 0;
    align-items: stretch;
    flex-direction: column;
    gap: 20px;
    border-radius: 11px;
    padding: 20px 18px;
  }

  .portal-welcome .portal-eyebrow {
    font-size: 8px;
  }

  .portal-welcome h2 {
    margin: 11px 0;
    font-size: 21px;
  }

  .portal-welcome>div>p:last-child {
    font-size: 10px;
  }

  .portal-primary-link {
    width: 100%;
    min-width: 0;
  }

  .portal-summary {
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 10px;
  }

  .portal-summary>div {
    padding: 12px;
    gap: 12px;
  }

  .portal-summary p {
    font-size: 9px;
  }

  .portal-summary strong {
    font-size: 22px;
  }

  .portal-home-grid {
    grid-template-columns: minmax(0,1fr);
    gap: 20px;
  }

  .portal-client-status {
    min-height: 0;
    gap: 12px;
    padding: 18px;
  }

  .portal-client-status h2 {
    font-size: 15px;
  }

  .portal-client-status p {
    margin-top: 10px;
    font-size: 11px;
    line-height: 1.8;
  }

  .portal-team {
    display: none;
  }

  .portal-panel {
    padding: 18px;
    border-radius: 10px;
  }

  .portal-panel h2 {
    font-size: 14px;
  }

  .portal-requests {
    order: 2;
  }

  .portal-home-documents {
    order: 3;
  }

  .portal-request-card h3 {
    font-size: 17px;
  }

  .portal-request-card>p {
    font-size: 10px;
  }

  .portal-request-bottom {
    align-items: stretch;
    flex-direction: column;
  }

  .portal-request-action {
    min-height: 42px;
    justify-content: center;
    border: 1px solid var(--color-line);
    border-radius: 7px;
    color: var(--color-ink);
  }

  .portal-current-phase {
    margin-top: 20px;
  }

  .portal-section-heading>span {
    font-size: 9px;
  }

  .portal-home-documents ul {
    margin-top: 22px;
    margin-bottom: 22px;
  }
}
</style>
