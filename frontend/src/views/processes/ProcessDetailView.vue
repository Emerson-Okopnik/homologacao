<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { AlertTriangle, ArrowLeft, CheckCircle2, CircleDashed, Download, Pencil, Upload, XCircle } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import InteractionDialog from './InteractionDialog.vue'
import PendencyDialog from './PendencyDialog.vue'
import ReviewDialog from './ReviewDialog.vue'
import TransitionDialog from './TransitionDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, buildUrl, toApiError, upload, type ApiError } from '@/lib/http'
import { formatDate, formatDateTime, formatDocument, formatNumber, statusTone } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { HomologationProcess, Pendency, ProcessDocument } from '@/types/api'

const route = useRoute()
const auth = useAuthStore()
const version = ref(0)
const source = computed(() => ({ id: String(route.params.id), version: version.value }))
const { data: process, error, loading } = useApiQuery(source, async (s) => (await api<{ data: HomologationProcess }>(`/processes/${s.id}`)).data)

const transition = shallowRef<{ initial?: string } | null>(null)
const pendencyDialog = shallowRef<{ resolving: Pendency | null } | null>(null)
const interactionOpen = ref(false)
const reviewing = shallowRef<ProcessDocument | null>(null)
const uploadError = ref<ApiError | null>(null)
const uploadingType = ref<string | null>(null)

const canManage = computed(() => auth.can('homologations.manage'))
const checklistDone = computed(() => (process.value?.checklist ?? []).filter((c) => c.required && c.document?.review_status === 'aprovado').length)
const checklistRequired = computed(() => (process.value?.checklist ?? []).filter((c) => c.required).length)
const openPendencies = computed(() => (process.value?.pendencies ?? []).filter((p) => p.status === 'aberta'))

function reload() {
  transition.value = null
  pendencyDialog.value = null
  interactionOpen.value = false
  reviewing.value = null
  version.value++
}

async function onFile(type: string, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !process.value) return
  uploadingType.value = type
  uploadError.value = null
  const form = new FormData()
  form.append('document_type', type)
  form.append('file', file)
  try {
    await upload(`/processes/${process.value.id}/documents`, form)
    version.value++
  } catch (e) {
    uploadError.value = toApiError(e)
  } finally {
    uploadingType.value = null
    input.value = ''
  }
}

const statusLabels: Record<string, string> = {
  rascunho: 'Rascunho',
  em_preparacao: 'Em preparação',
  pronto_para_envio: 'Pronto para envio',
  enviado: 'Enviado',
  em_analise: 'Em análise',
  pendencia_distribuidora: 'Pendência da distribuidora',
  aprovado: 'Aprovado',
  vistoria_solicitada: 'Vistoria solicitada',
  conectado: 'Conectado',
  reprovado: 'Reprovado',
  cancelado: 'Cancelado',
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
      <header class="mb-6 flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-3">
            <h1 class="font-mono text-2xl font-bold tracking-tight">{{ process.code }}</h1>
            <StatusBadge :tone="statusTone(process.status)">{{ process.status_label }}</StatusBadge>
          </div>
          <p class="mt-1 text-sm text-muted">
            <RouterLink v-if="process.project?.client" :to="`/clientes/${process.project.client.id}`" class="font-medium text-ink hover:underline">
              {{ process.project.client.name }}
            </RouterLink>
            · UC {{ process.project?.consumer_unit?.number }} · {{ process.distributor?.name }}
          </p>
          <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-4">
            <div>
              <dt class="text-xs text-muted">Protocolo</dt>
              <dd class="font-mono font-medium">{{ process.protocol_number ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-muted">Prazo</dt>
              <dd class="font-medium tabular-nums">{{ formatDate(process.due_date) }}</dd>
            </div>
            <div>
              <dt class="text-xs text-muted">Enviado em</dt>
              <dd class="font-medium tabular-nums">{{ formatDate(process.submitted_at) }}</dd>
            </div>
            <div>
              <dt class="text-xs text-muted">Responsável</dt>
              <dd class="font-medium">{{ process.assignee?.name ?? '—' }}</dd>
            </div>
          </dl>
        </div>
        <div v-if="canManage && process.allowed_transitions.length" class="flex shrink-0 flex-wrap gap-2">
          <BaseButton
            v-for="t in process.allowed_transitions.filter((t) => t.value !== 'cancelado').slice(0, 2)"
            :key="t.value"
            @click="transition = { initial: t.value }"
          >
            {{ t.label }}
          </BaseButton>
          <BaseButton variant="secondary" @click="transition = {}">Outra etapa</BaseButton>
        </div>
      </header>

      <InlineAlert v-if="process.readiness_issues?.length && ['rascunho', 'em_preparacao', 'pronto_para_envio'].includes(process.status)" class="mb-6">
        <p class="font-semibold">Antes de enviar à distribuidora:</p>
        <ul class="mt-1 list-disc pl-5">
          <li v-for="issue in process.readiness_issues" :key="issue">{{ issue }}</li>
        </ul>
      </InlineAlert>

      <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="flex min-w-0 flex-col gap-6">
          <section class="rounded-2xl border border-line bg-surface" aria-labelledby="sec-docs">
            <header class="flex items-center justify-between border-b border-line px-6 py-4">
              <h2 id="sec-docs" class="font-semibold">Checklist de documentos</h2>
              <span class="text-sm text-muted tabular-nums">{{ checklistDone }}/{{ checklistRequired }} obrigatórios aprovados</span>
            </header>
            <div v-if="uploadError" class="px-6 pt-4">
              <InlineAlert :correlation-id="uploadError.correlationId">{{ uploadError.firstError('file') ?? uploadError.message }}</InlineAlert>
            </div>
            <ul class="divide-y divide-line">
              <li v-for="item in process.checklist ?? []" :key="item.type" class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center">
                <component
                  :is="item.document?.review_status === 'aprovado' ? CheckCircle2 : item.document?.review_status === 'reprovado' ? XCircle : CircleDashed"
                  class="size-5 shrink-0"
                  :class="item.document?.review_status === 'aprovado' ? 'text-success' : item.document?.review_status === 'reprovado' ? 'text-danger' : 'text-muted'"
                  aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium">
                    {{ item.label }}
                    <span v-if="!item.required" class="text-xs font-normal text-muted">(opcional)</span>
                  </p>
                  <p v-if="item.document" class="truncate text-xs text-muted">
                    v{{ item.document.version }} · {{ item.document.original_name }} · {{ formatDateTime(item.document.created_at) }}
                  </p>
                  <p v-if="item.document?.review_status === 'reprovado' && item.document.review_notes" class="mt-1 text-xs text-danger">
                    {{ item.document.review_notes }}
                  </p>
                  <p v-else-if="!item.document" class="text-xs text-muted">Aguardando envio</p>
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
                    v-if="process.editable && auth.can('documents.manage')"
                    class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-medium hover:bg-canvas focus-within:ring-2 focus-within:ring-primary"
                  >
                    <Upload class="size-4" aria-hidden="true" />
                    {{ uploadingType === item.type ? 'Enviando…' : item.document ? 'Nova versão' : 'Enviar' }}
                    <input
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png"
                      class="sr-only"
                      :disabled="uploadingType !== null"
                      :aria-label="`Enviar ${item.label}`"
                      @change="onFile(item.type, $event)"
                    />
                  </label>
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
              <BaseButton v-if="canManage" variant="secondary" @click="pendencyDialog = { resolving: null }">Registrar</BaseButton>
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
                v-if="process.project && auth.can('projects.manage') && process.editable"
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
                <dt class="text-muted">Enquadramento</dt>
                <dd class="capitalize">{{ process.project.generation_type }}geração</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Modalidade</dt>
                <dd class="text-right">{{ process.project.modality_label }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Módulos</dt>
                <dd class="tabular-nums">{{ formatNumber(process.project.installed_power_kwp, 'kWp') }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Inversores</dt>
                <dd class="tabular-nums">{{ formatNumber(process.project.inverter_power_kw, 'kW') }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Bateria</dt>
                <dd>{{ process.project.has_battery ? 'Sim' : 'Não' }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Titular</dt>
                <dd class="tabular-nums">{{ process.project.client ? formatDocument(process.project.client.document) : '—' }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">Resp. técnico</dt>
                <dd class="text-right">{{ process.project.technical_responsible?.name ?? '—' }}</dd>
              </div>
            </dl>
            <ul v-if="process.project?.equipment?.length" class="mt-4 flex flex-col gap-1 border-t border-line pt-4 text-xs text-muted">
              <li v-for="e in process.project.equipment" :key="e.id">{{ e.quantity }}× {{ e.manufacturer }} {{ e.model }}</li>
            </ul>
          </section>

          <section class="rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-hist">
            <h2 id="sec-hist" class="font-semibold">Histórico de etapas</h2>
            <ol class="mt-4 flex flex-col gap-4 border-l border-line pl-4">
              <li v-for="(h, index) in process.history ?? []" :key="index" class="relative">
                <span class="absolute top-1.5 -left-[1.3rem] size-2 rounded-full bg-primary" aria-hidden="true" />
                <p class="text-sm font-medium">{{ statusLabels[h.to_status] ?? h.to_status }}</p>
                <p class="text-xs text-muted">{{ h.user ?? 'Sistema' }} · {{ formatDateTime(h.created_at) }}</p>
                <p v-if="h.reason" class="mt-1 text-xs text-muted">{{ h.reason }}</p>
              </li>
            </ol>
          </section>
        </aside>
      </div>

      <TransitionDialog v-if="transition" :process="process" :initial="transition.initial" @close="transition = null" @saved="reload" />
      <PendencyDialog v-if="pendencyDialog" :process-id="process.id" :resolving="pendencyDialog.resolving" @close="pendencyDialog = null" @saved="reload" />
      <InteractionDialog v-if="interactionOpen" :process-id="process.id" @close="interactionOpen = false" @saved="reload" />
      <ReviewDialog v-if="reviewing" :document="reviewing" @close="reviewing = null" @saved="reload" />
    </template>
  </div>
</template>
