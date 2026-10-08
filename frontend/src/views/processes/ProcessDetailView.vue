<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { AlertTriangle, ArrowLeft, CheckCircle2, CircleDashed, Clock, Download, Lock, MinusCircle, Pencil, Upload, XCircle } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import InteractionDialog from './InteractionDialog.vue'
import PendencyDialog from './PendencyDialog.vue'
import ProcessActionDialog from './ProcessActionDialog.vue'
import ReviewDialog from './ReviewDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, buildUrl, toApiError, upload, type ApiError } from '@/lib/http'
import { formatDate, formatDateTime, formatDocument, formatNumber, stageTone, statusTone } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { ChecklistItem, HomologationProcess, Pendency, ProcessActionKey, ProcessDocument, StageCatalog } from '@/types/api'

const route = useRoute()
const auth = useAuthStore()
const version = ref(0)
const source = computed(() => ({ id: String(route.params.id), version: version.value }))
const { data: process, error, loading } = useApiQuery(source, async (s) => (await api<{ data: HomologationProcess }>(`/processes/${s.id}`)).data)
const { data: catalog } = useApiQuery(() => 'process-stages', async () => (await api<{ data: StageCatalog }>('/process-stages')).data)

const activeAction = shallowRef<ProcessActionKey | null>(null)
const pendencyDialog = shallowRef<{ resolving: Pendency | null } | null>(null)
const interactionOpen = ref(false)
const reviewing = shallowRef<ProcessDocument | null>(null)
const uploadError = ref<ApiError | null>(null)
const uploadingCode = ref<string | null>(null)

const canManage = computed(() => auth.can('homologations.manage'))
const isActive = computed(() => process.value?.status === 'ACTIVE')
const openPendencies = computed(() => (process.value?.pendencies ?? []).filter((p) => p.status === 'aberta'))
const projectEditable = computed(() => ['PREPARATION', 'CORRECTION'].includes(process.value?.stage ?? ''))

const actionLabels: Record<ProcessActionKey, string> = {
  submit: 'Protocolar',
  register_correction: 'Registrar exigências',
  approve_access: 'Parecer aprovado',
  update_network_work: 'Atualizar obra na rede',
  report_execution: 'Informar execução',
  request_inspection: 'Solicitar vistoria',
  record_inspection: 'Resultado da vistoria',
  record_connection_event: 'Evento de conexão',
  complete: 'Concluir',
  cancel: 'Cancelar',
}

const primaryActions = computed(() => {
  const gates = process.value?.actions
  if (!gates) return []
  return (Object.keys(actionLabels) as ProcessActionKey[])
    .filter((k) => k !== 'cancel' && gates[k]?.available)
})

const blockedHints = computed(() => {
  const gates = process.value?.actions
  if (!gates || primaryActions.value.length) return []
  return (Object.keys(gates) as ProcessActionKey[])
    .filter((k) => k !== 'cancel' && gates[k]?.reasons?.length)
    .slice(0, 2)
    .map((k) => ({ label: actionLabels[k], reasons: gates[k].reasons }))
})

const stageIndex = computed(() => (catalog.value?.stages ?? []).findIndex((s) => s.value === process.value?.stage))

function ownerFor(item: ChecklistItem): { type: string; id: string } | null {
  const p = process.value
  if (!p) return null
  switch (item.document_owner) {
    case 'project':
      return p.project ? { type: 'project', id: p.project.id } : null
    case 'execution':
      return p.execution ? { type: 'execution', id: p.execution.id } : null
    case 'inspection': {
      const insp = p.inspections?.find((i) => i.is_open) ?? p.inspections?.[0]
      return insp ? { type: 'inspection', id: insp.id } : null
    }
    case 'connection_event': {
      const ev = p.connection_events?.[0]
      return ev ? { type: 'connection_event', id: ev.id } : null
    }
    case 'process':
      return { type: 'process', id: p.id }
    default:
      return null
  }
}

function canUpload(item: ChecklistItem): boolean {
  if (!isActive.value || !item.document_type || !auth.can('documents.manage')) return false
  if (item.document_owner === 'project' && !projectEditable.value) return false
  return ownerFor(item) !== null
}

function itemIcon(item: ChecklistItem) {
  if (item.outcome === 'NOT_APPLICABLE' || item.outcome === 'WAIVED') return MinusCircle
  if (item.document?.review_status === 'reprovado') return XCircle
  if (item.satisfied) return CheckCircle2
  if (item.document) return Clock
  return CircleDashed
}

function itemTone(item: ChecklistItem) {
  if (item.document?.review_status === 'reprovado') return 'text-danger'
  if (item.satisfied) return 'text-success'
  if (item.document) return 'text-warning'
  return 'text-muted'
}

function reload() {
  activeAction.value = null
  pendencyDialog.value = null
  interactionOpen.value = false
  reviewing.value = null
  version.value++
}

async function onFile(item: ChecklistItem, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  const owner = ownerFor(item)
  if (!file || !owner || !item.document_type) return
  uploadingCode.value = item.code
  uploadError.value = null
  const form = new FormData()
  form.append('owner_type', owner.type)
  form.append('owner_id', owner.id)
  form.append('document_type', item.document_type)
  form.append('file', file)
  try {
    await upload('/documents', form)
    version.value++
  } catch (e) {
    uploadError.value = toApiError(e)
  } finally {
    uploadingCode.value = null
    input.value = ''
  }
}

const interactionLabels: Record<string, string> = {
  nota: 'Nota interna',
  envio: 'Envio à distribuidora',
  resposta_distribuidora: 'Resposta da distribuidora',
  contato: 'Contato com cliente',
}
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <RouterLink to="/kanban" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink">
      <ArrowLeft class="size-4" aria-hidden="true" />
      Kanban
    </RouterLink>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="!process && loading" class="py-12 text-center text-muted" role="status">Carregando processo…</p>

    <template v-else-if="process">
      <header class="mb-6 flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
              <h1 class="font-mono text-2xl font-bold tracking-tight">{{ process.code }}</h1>
              <StatusBadge :tone="stageTone(process.stage)">{{ process.stage_label }}</StatusBadge>
              <StatusBadge v-if="process.status !== 'ACTIVE'" :tone="statusTone(process.status)">{{ process.status_label }}</StatusBadge>
            </div>
            <p class="mt-1 text-sm text-muted">
              <RouterLink v-if="process.project?.client" :to="`/clientes/${process.project.client.id}`" class="font-medium text-ink hover:underline">
                {{ process.project.client.name }}
              </RouterLink>
              · UC {{ process.project?.consumer_unit?.number }} · {{ process.distributor?.name }}
            </p>
          </div>
          <div v-if="canManage && isActive" class="flex shrink-0 flex-wrap gap-2">
            <BaseButton v-for="(key, i) in primaryActions" :key="key" :variant="i === 0 ? 'primary' : 'secondary'" @click="activeAction = key">
              {{ actionLabels[key] }}
            </BaseButton>
            <BaseButton v-if="process.actions?.cancel?.available" variant="ghost" @click="activeAction = 'cancel'">Cancelar</BaseButton>
          </div>
        </div>

        <ol v-if="catalog" class="flex flex-wrap gap-1.5" aria-label="Etapas do processo">
          <li
            v-for="(stage, i) in catalog.stages"
            :key="stage.value"
            class="flex-1 rounded-lg px-3 py-2 text-xs font-medium"
            :class="i < stageIndex ? 'bg-primary/10 text-primary' : i === stageIndex ? 'bg-primary text-white' : 'bg-canvas text-muted'"
            :aria-current="i === stageIndex ? 'step' : undefined"
          >
            {{ stage.label }}
          </li>
        </ol>

        <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-5">
          <div>
            <dt class="text-xs text-muted">Protocolo</dt>
            <dd class="font-mono font-medium">{{ process.protocol_number ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Prazo em curso</dt>
            <dd class="font-medium tabular-nums" :class="process.open_deadline?.overdue ? 'text-danger' : ''">
              <template v-if="process.open_deadline">
                {{ formatDate(process.open_deadline.due_at) }}
                <span class="block text-xs font-normal text-muted">{{ process.open_deadline.label }}</span>
              </template>
              <template v-else>—</template>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Obra na rede</dt>
            <dd class="font-medium">{{ process.network_work_label }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Protocolado em</dt>
            <dd class="font-medium tabular-nums">{{ formatDate(process.submitted_at) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Responsável</dt>
            <dd class="font-medium">{{ process.assignee?.name ?? '—' }}</dd>
          </div>
        </dl>
      </header>

      <InlineAlert v-if="isActive && blockedHints.length" class="mb-6">
        <p class="font-semibold">Para avançar:</p>
        <ul class="mt-1 list-disc pl-5">
          <template v-for="hint in blockedHints" :key="hint.label">
            <li v-for="r in hint.reasons" :key="r">{{ r }}</li>
          </template>
        </ul>
      </InlineAlert>

      <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="flex min-w-0 flex-col gap-6">
          <section v-if="process.checklist" class="rounded-2xl border border-line bg-surface" aria-labelledby="sec-docs">
            <header class="flex flex-col gap-2 border-b border-line px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h2 id="sec-docs" class="font-semibold">Requisitos — {{ process.checklist.label }}</h2>
                <p class="text-xs text-muted">Calculado pelas regras vigentes para este projeto.</p>
              </div>
              <div class="flex items-center gap-3">
                <div class="h-2 w-28 overflow-hidden rounded-full bg-canvas" aria-hidden="true">
                  <div class="h-full bg-primary" :style="{ width: `${process.checklist.percent}%` }" />
                </div>
                <span class="text-sm text-muted tabular-nums">{{ process.checklist.satisfied }}/{{ process.checklist.total }}</span>
              </div>
            </header>
            <div v-if="uploadError" class="px-6 pt-4">
              <InlineAlert :correlation-id="uploadError.correlationId">{{ uploadError.firstError('file') ?? uploadError.message }}</InlineAlert>
            </div>
            <p v-if="!process.checklist.items.length" class="px-6 py-8 text-center text-sm text-muted">Nenhum requisito nesta fase.</p>
            <ul class="divide-y divide-line">
              <li v-for="item in process.checklist.items" :key="item.code" class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center">
                <component :is="itemIcon(item)" class="size-5 shrink-0" :class="itemTone(item)" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium">
                    {{ item.label }}
                    <span v-if="item.outcome === 'OPTIONAL'" class="text-xs font-normal text-muted">(opcional)</span>
                    <span v-else-if="item.outcome === 'WAIVED'" class="text-xs font-normal text-muted">(dispensado)</span>
                  </p>
                  <p v-if="item.reason" class="text-xs text-muted">{{ item.reason }}</p>
                  <p v-if="item.document" class="truncate text-xs text-muted">
                    v{{ item.document.version }} · {{ item.document.original_name }} · {{ formatDateTime(item.document.created_at) }}
                  </p>
                  <p v-if="item.document?.review_status === 'reprovado' && item.document.review_notes" class="mt-1 text-xs text-danger">
                    {{ item.document.review_notes }}
                  </p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                  <StatusBadge v-if="item.document" :tone="statusTone(item.document.review_status)" class="capitalize">
                    {{ item.document.review_status }}
                  </StatusBadge>
                  <a
                    v-if="item.document"
                    :href="buildUrl(`/documents/${item.document.id}/download`)"
                    target="_blank"
                    rel="noopener"
                    class="rounded-lg p-2 text-muted hover:bg-canvas hover:text-ink"
                  >
                    <Download class="size-4" aria-hidden="true" />
                    <span class="sr-only">Baixar {{ item.label }}</span>
                  </a>
                  <BaseButton
                    v-if="item.document?.review_status === 'pendente' && auth.can('documents.manage')"
                    variant="ghost"
                    @click="reviewing = item.document"
                  >
                    Revisar
                  </BaseButton>
                  <label
                    v-if="canUpload(item)"
                    class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-medium hover:bg-canvas focus-within:ring-2 focus-within:ring-primary"
                  >
                    <Upload class="size-4" aria-hidden="true" />
                    {{ uploadingCode === item.code ? 'Enviando…' : item.document ? 'Nova versão' : 'Enviar' }}
                    <input
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png"
                      class="sr-only"
                      :disabled="uploadingCode !== null"
                      :aria-label="`Enviar ${item.label}`"
                      @change="onFile(item, $event)"
                    />
                  </label>
                  <span
                    v-else-if="item.document_owner === 'project' && isActive && !projectEditable && !item.document"
                    class="inline-flex items-center gap-1 text-xs text-muted"
                  >
                    <Lock class="size-3.5" aria-hidden="true" />
                    Projeto bloqueado
                  </span>
                </div>
              </li>
            </ul>
          </section>

          <section class="rounded-2xl border border-line bg-surface" aria-labelledby="sec-pend">
            <header class="flex items-center justify-between border-b border-line px-6 py-4">
              <h2 id="sec-pend" class="font-semibold">
                Pendências
                <span v-if="openPendencies.length" class="ml-1 text-sm font-normal text-warning">({{ openPendencies.length }} abertas)</span>
              </h2>
              <BaseButton v-if="canManage && isActive" variant="secondary" @click="pendencyDialog = { resolving: null }">Registrar</BaseButton>
            </header>
            <p v-if="!process.pendencies?.length" class="px-6 py-8 text-center text-sm text-muted">Nenhuma pendência registrada.</p>
            <ul v-else class="divide-y divide-line">
              <li v-for="p in process.pendencies" :key="p.id" class="flex gap-3 px-6 py-4">
                <AlertTriangle v-if="p.status === 'aberta'" class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                <CheckCircle2 v-else class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium">{{ p.title }}</p>
                  <p class="text-xs text-muted">
                    {{ p.origin === 'distribuidora' ? 'Distribuidora' : 'Interna' }} · {{ formatDate(p.created_at) }}
                    <template v-if="p.due_date"> · prazo {{ formatDate(p.due_date) }}</template>
                  </p>
                  <p v-if="p.description" class="mt-1 whitespace-pre-line text-sm text-muted">{{ p.description }}</p>
                  <p v-if="p.resolution" class="mt-2 rounded-lg bg-success-soft px-3 py-2 text-sm text-success">
                    {{ p.resolution }} — {{ p.resolved_by }}
                  </p>
                </div>
                <BaseButton v-if="canManage && p.status === 'aberta'" variant="ghost" @click="pendencyDialog = { resolving: p }">Resolver</BaseButton>
              </li>
            </ul>
          </section>

          <section v-if="process.inspections?.length || process.connection_events?.length" class="rounded-2xl border border-line bg-surface" aria-labelledby="sec-field">
            <header class="border-b border-line px-6 py-4">
              <h2 id="sec-field" class="font-semibold">Vistorias e conexão</h2>
            </header>
            <ul class="divide-y divide-line">
              <li v-for="i in process.inspections" :key="i.id" class="flex items-start justify-between gap-4 px-6 py-3 text-sm">
                <div>
                  <p class="font-medium">Vistoria nº {{ i.sequence }}</p>
                  <p class="text-xs text-muted">
                    Solicitada {{ formatDate(i.requested_at) }}
                    <template v-if="i.scheduled_for"> · agendada {{ formatDate(i.scheduled_for) }}</template>
                    <template v-if="i.result_at"> · resultado {{ formatDate(i.result_at) }}</template>
                  </p>
                  <p v-if="i.result_notes" class="mt-1 text-xs text-muted">{{ i.result_notes }}</p>
                </div>
                <StatusBadge :tone="statusTone(i.status)">{{ i.status_label }}</StatusBadge>
              </li>
              <li v-for="e in process.connection_events" :key="e.id" class="px-6 py-3 text-sm">
                <p class="font-medium">{{ e.type_label }}</p>
                <p class="text-xs text-muted">
                  {{ formatDateTime(e.occurred_at) }}
                  <template v-if="e.meter_number"> · medidor {{ e.meter_number }}</template>
                </p>
              </li>
            </ul>
          </section>

          <section class="rounded-2xl border border-line bg-surface" aria-labelledby="sec-int">
            <header class="flex items-center justify-between border-b border-line px-6 py-4">
              <h2 id="sec-int" class="font-semibold">Interações</h2>
              <BaseButton v-if="canManage" variant="secondary" @click="interactionOpen = true">Registrar</BaseButton>
            </header>
            <p v-if="!process.interactions?.length" class="px-6 py-8 text-center text-sm text-muted">Nenhuma interação registrada.</p>
            <ul v-else class="divide-y divide-line">
              <li v-for="i in process.interactions" :key="i.id" class="px-6 py-4">
                <p class="text-xs text-muted">
                  <span class="font-semibold text-ink">{{ interactionLabels[i.type] ?? i.type }}</span>
                  · {{ i.channel }} · {{ i.user ?? 'Sistema' }} · {{ formatDateTime(i.occurred_at) }}
                </p>
                <p class="mt-1 whitespace-pre-line text-sm">{{ i.description }}</p>
              </li>
            </ul>
          </section>
        </div>

        <aside class="flex flex-col gap-6">
          <section class="rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-proj">
            <div class="flex items-center justify-between">
              <h2 id="sec-proj" class="font-semibold">Projeto</h2>
              <RouterLink
                v-if="process.project && auth.can('projects.manage') && isActive && projectEditable"
                :to="`/projetos/${process.project.id}`"
                class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink"
              >
                <Pencil class="size-4" aria-hidden="true" />
                <span class="sr-only">Editar projeto</span>
              </RouterLink>
            </div>
            <dl v-if="process.project" class="mt-4 flex flex-col gap-3 text-sm">
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Código</dt>
                <dd class="font-mono">{{ process.project.code }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Classificação</dt>
                <dd class="text-right">{{ process.project.classification_label }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Compensação</dt>
                <dd class="text-right">{{ process.project.compensation_mode_label }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Módulos</dt>
                <dd class="tabular-nums">{{ formatNumber(process.project.modules_power_kwp, 'kWp') }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Inversores</dt>
                <dd class="tabular-nums">{{ formatNumber(process.project.inverters_power_kw, 'kW') }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Potência considerada</dt>
                <dd class="font-medium tabular-nums">{{ formatNumber(process.project.considered_power_kw, 'kW') }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Fast track</dt>
                <dd>{{ process.project.fast_track_eligible ? 'Elegível' : 'Não elegível' }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Titular</dt>
                <dd class="tabular-nums">{{ process.project.client ? formatDocument(process.project.client.document) : '—' }}</dd>
              </div>
              <div v-for="r in process.project.responsibilities ?? []" :key="r.purpose" class="flex justify-between gap-4">
                <dt class="text-muted">RT {{ r.purpose_label.toLowerCase() }}</dt>
                <dd class="text-right">{{ r.responsible.name }}</dd>
              </div>
            </dl>
            <ul v-if="process.project?.equipment?.length" class="mt-4 flex flex-col gap-1 border-t border-line pt-4 text-xs text-muted">
              <li v-for="e in process.project.equipment" :key="e.id">{{ e.quantity }}× {{ e.manufacturer }} {{ e.model }}</li>
            </ul>
            <p v-if="process.current_version" class="mt-4 border-t border-line pt-4 text-xs text-muted">
              Versão protocolada v{{ process.current_version.version }} · {{ formatDateTime(process.current_version.created_at) }}
            </p>
          </section>

          <section v-if="process.deadlines?.length" class="rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-deadlines">
            <h2 id="sec-deadlines" class="font-semibold">Prazos</h2>
            <ul class="mt-4 flex flex-col gap-3 text-sm">
              <li v-for="(d, i) in process.deadlines" :key="i" class="flex items-start justify-between gap-3">
                <div>
                  <p class="font-medium">{{ d.label }}</p>
                  <p class="text-xs text-muted">{{ d.days }} dias {{ d.day_count === 'BUSINESS' ? 'úteis' : 'corridos' }} · até {{ formatDate(d.due_at) }}</p>
                </div>
                <StatusBadge :tone="d.overdue ? 'danger' : statusTone(d.status)">{{ d.overdue ? 'Vencido' : d.status }}</StatusBadge>
              </li>
            </ul>
          </section>

          <section class="rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-hist">
            <h2 id="sec-hist" class="font-semibold">Linha do tempo</h2>
            <p v-if="!process.timeline?.length" class="mt-4 text-sm text-muted">Sem eventos.</p>
            <ol v-else class="mt-4 flex flex-col gap-4 border-l border-line pl-4">
              <li v-for="(h, index) in process.timeline" :key="index" class="relative">
                <span class="absolute top-1.5 -left-[1.3rem] size-2 rounded-full bg-primary" aria-hidden="true" />
                <p class="text-sm font-medium">{{ h.title }}</p>
                <p class="text-xs text-muted">{{ h.user ?? 'Sistema' }} · {{ formatDateTime(h.occurred_at) }}</p>
                <p v-if="h.description" class="mt-1 whitespace-pre-line text-xs text-muted">{{ h.description }}</p>
              </li>
            </ol>
          </section>
        </aside>
      </div>

      <ProcessActionDialog
        v-if="activeAction"
        :process="process"
        :action="activeAction"
        :catalog="catalog ?? null"
        @close="activeAction = null"
        @saved="reload"
      />
      <PendencyDialog v-if="pendencyDialog" :process-id="process.id" :resolving="pendencyDialog.resolving" @close="pendencyDialog = null" @saved="reload" />
      <InteractionDialog v-if="interactionOpen" :process-id="process.id" @close="interactionOpen = false" @saved="reload" />
      <ReviewDialog v-if="reviewing" :document="reviewing" @close="reviewing = null" @saved="reload" />
    </template>
  </div>
</template>
