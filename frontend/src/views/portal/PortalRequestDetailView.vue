<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ArrowLeft, CircleCheck, Download, FileUp, HardHat, Pencil, Send, UserRound } from '@lucide/vue'
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
</script>

<template>
  <div class="flex flex-col gap-6">
    <RouterLink to="/portal" class="inline-flex items-center gap-1.5 self-start text-sm text-muted hover:text-ink">
      <ArrowLeft class="size-4" aria-hidden="true" />Minhas solicitações
    </RouterLink>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading && !current" class="text-sm text-muted">Carregando...</p>

    <template v-if="current">
      <header class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div class="flex flex-col gap-1">
          <div class="flex flex-wrap items-center gap-2">
            <h1 class="font-mono text-2xl font-semibold text-ink">{{ current.code }}</h1>
            <StatusBadge :tone="requestTone(current.status.value)">{{ current.status.label }}</StatusBadge>
          </div>
          <p class="text-sm text-muted">
            UC {{ current.consumer_unit?.number }} · {{ current.consumer_unit?.distributor?.name }} ·
            {{ formatNumber(current.declared_powers.modules_kwp, 'kWp') }}
          </p>
        </div>
        <RouterLink
          v-if="current.editable"
          :to="`/portal/solicitacoes/${current.id}/editar`"
          class="inline-flex h-10 items-center gap-2 self-start rounded-lg border border-line bg-surface px-4 text-sm font-semibold text-ink hover:bg-canvas"
        >
          <Pencil class="size-4" aria-hidden="true" />Editar dados
        </RouterLink>
      </header>

      <InlineAlert v-if="current.status.value === 'NEEDS_INFO'">
        A equipe técnica precisa de mais informações. Veja a mensagem abaixo, ajuste os dados ou responda.
      </InlineAlert>
      <InlineAlert v-if="actionError" :correlation-id="actionError.correlationId">{{ actionError.message }}</InlineAlert>

      <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="flex flex-col gap-6">
          <!-- Documentos do cliente -->
          <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-5" aria-labelledby="docs-cliente">
            <div class="flex flex-col gap-2">
              <h2 id="docs-cliente" class="flex items-center gap-2 font-semibold text-ink">
                <UserRound class="size-4 text-primary" aria-hidden="true" />Seus documentos
              </h2>
              <p class="text-sm text-muted">Documentos que só o titular da unidade pode fornecer. Aceitamos PDF, JPG ou PNG de até 20 MB.</p>
              <div class="flex items-center gap-3">
                <div class="h-2 flex-1 overflow-hidden rounded-full bg-line" role="progressbar" :aria-valuenow="progressPct" aria-valuemin="0" aria-valuemax="100" aria-label="Documentos enviados">
                  <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${progressPct}%` }" />
                </div>
                <span class="text-sm tabular-nums text-muted">{{ current.client_progress.sent }}/{{ current.client_progress.required }}</span>
              </div>
            </div>

            <ul class="flex flex-col divide-y divide-line">
              <li v-for="o in current.client_obligations" :key="o.type" class="flex flex-col gap-3 py-3 md:flex-row md:items-center">
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                  <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                    <CircleCheck v-if="o.document" class="size-4 text-success" aria-hidden="true" />
                    {{ o.label }}
                    <span v-if="!o.required" class="text-xs font-normal text-muted">(se houver)</span>
                    <StatusBadge v-if="o.document" :tone="reviewTone(o.document.review_status)">{{ reviewLabel(o.document.review_status) }}</StatusBadge>
                  </p>
                  <p class="text-xs text-muted">{{ o.reason }}</p>
                  <p v-if="o.document" class="truncate text-xs text-muted">
                    {{ o.document.original_name }} · v{{ o.document.version }}
                    <span v-if="o.document.review_notes" class="text-danger"> · {{ o.document.review_notes }}</span>
                  </p>
                </div>
                <div v-if="current.status.value !== 'CANCELLED'" class="flex items-center gap-2">
                  <a
                    v-if="o.document"
                    :href="buildUrl(`/portal/documents/${o.document.id}/download`)"
                    class="inline-flex size-9 items-center justify-center rounded-lg text-muted hover:bg-canvas hover:text-ink"
                  >
                    <Download class="size-4" aria-hidden="true" /><span class="sr-only">Baixar {{ o.label }}</span>
                  </a>
                  <label
                    class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm font-semibold focus-within:ring-2 focus-within:ring-primary/30"
                    :class="o.document ? 'border border-line text-ink hover:bg-canvas' : 'bg-primary text-white hover:bg-primary-hover'"
                  >
                    <FileUp class="size-4" aria-hidden="true" />
                    {{ uploading === o.type ? 'Enviando...' : o.document ? 'Substituir' : 'Enviar' }}
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="sr-only" :disabled="uploading !== null" @change="sendFile(o, $event)" />
                  </label>
                </div>
              </li>
            </ul>
          </section>

          <!-- Dados informados -->
          <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-5" aria-labelledby="dados">
            <h2 id="dados" class="font-semibold text-ink">Dados informados</h2>
            <dl class="grid gap-4 text-sm md:grid-cols-2">
              <div><dt class="text-muted">Endereço da instalação</dt><dd class="text-ink">{{ current.consumer_unit?.full_address }}</dd></div>
              <div><dt class="text-muted">Uso da energia</dt><dd class="text-ink">{{ labelOf(compensationOptions, current.system.compensation_mode) }}</dd></div>
              <div><dt class="text-muted">Módulos</dt>
                <dd v-for="(m, i) in current.system.modules" :key="i" class="text-ink">{{ m.quantity }}× {{ m.brand }} {{ m.model }} ({{ m.power_w }} W)</dd>
              </div>
              <div><dt class="text-muted">Inversor</dt>
                <dd v-for="(inv, i) in current.system.inverters" :key="i" class="text-ink">{{ inv.quantity }}× {{ inv.brand }} {{ inv.model }} ({{ inv.power_kw }} kW)</dd>
              </div>
              <div v-if="current.system.has_battery"><dt class="text-muted">Bateria</dt><dd class="text-ink">{{ formatNumber(current.system.storage_energy_kwh, 'kWh') }}</dd></div>
              <div v-if="current.system.beneficiaries.length"><dt class="text-muted">UCs beneficiárias</dt>
                <dd v-for="b in current.system.beneficiaries" :key="b.uc_number" class="text-ink">UC {{ b.uc_number }} <span v-if="b.percentage">· {{ b.percentage }}%</span></dd>
              </div>
            </dl>
          </section>

          <!-- Conversa -->
          <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-5" aria-labelledby="conversa">
            <h2 id="conversa" class="font-semibold text-ink">Conversa com a equipe</h2>
            <ol v-if="current.messages.length" class="flex flex-col gap-3">
              <li
                v-for="(m, i) in current.messages"
                :key="i"
                class="max-w-[85%] rounded-xl px-4 py-3 text-sm"
                :class="m.from === 'client' ? 'self-end bg-primary-soft' : m.from === 'system' ? 'self-center bg-canvas text-xs text-muted' : 'self-start bg-canvas'"
              >
                <p v-if="m.from !== 'system'" class="mb-1 text-xs font-semibold text-muted">{{ m.author }} · {{ formatDateTime(m.at) }}</p>
                <p class="whitespace-pre-line text-pretty text-ink">{{ m.text }}</p>
              </li>
            </ol>
            <p v-else class="text-sm text-muted">Nenhuma mensagem ainda.</p>
            <form v-if="current.status.value !== 'CANCELLED'" class="flex gap-2" @submit.prevent="sendMessage">
              <label class="sr-only" for="msg">Mensagem</label>
              <input
                id="msg"
                v-model="message"
                maxlength="2000"
                placeholder="Escreva uma mensagem para a equipe técnica"
                class="h-10 flex-1 rounded-lg border border-line bg-surface px-3 text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
              />
              <BaseButton type="submit" :loading="sending" :disabled="!message.trim()">
                <Send class="size-4" aria-hidden="true" /><span class="sr-only md:not-sr-only">Enviar</span>
              </BaseButton>
            </form>
          </section>
        </div>

        <aside class="flex flex-col gap-4">
          <section class="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-5" aria-labelledby="rt">
            <h2 id="rt" class="flex items-center gap-2 text-sm font-semibold text-ink">
              <HardHat class="size-4 text-primary" aria-hidden="true" />Responsável técnico
            </h2>
            <template v-if="current.technical_responsible">
              <p class="font-medium text-ink">{{ current.technical_responsible.name }}</p>
              <p class="text-sm text-muted">{{ current.technical_responsible.council }} {{ current.technical_responsible.registration }}</p>
              <p v-if="current.technical_responsible.email" class="text-sm text-muted">{{ current.technical_responsible.email }}</p>
            </template>
            <p v-else class="text-sm text-muted">Em breve um engenheiro da nossa equipe será designado para o seu projeto.</p>
            <p class="border-t border-line pt-3 text-xs text-muted">
              O responsável técnico elabora o projeto elétrico, emite a ART, preenche os formulários e conduz todo o trâmite com a distribuidora.
            </p>
          </section>

          <section class="flex flex-col gap-2 rounded-2xl border border-line bg-surface p-5" aria-labelledby="andamento">
            <h2 id="andamento" class="text-sm font-semibold text-ink">Andamento</h2>
            <p v-if="current.project?.process" class="text-sm text-ink">
              Projeto {{ current.project.code }} — etapa
              <strong>{{ current.project.process.stage.label }}</strong>
            </p>
            <p v-else-if="current.project" class="text-sm text-ink">Projeto {{ current.project.code }} em elaboração pela equipe.</p>
            <p v-else class="text-sm text-muted">Sua solicitação está em análise pela equipe técnica.</p>
            <button type="button" class="self-start text-xs font-semibold text-primary hover:underline" @click="request = null; reload()">Atualizar</button>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>
