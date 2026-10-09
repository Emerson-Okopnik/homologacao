<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ArrowLeft, CircleCheck, CircleDashed, Download, FolderPlus, HardHat, Send, UserRound, XCircle } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import SelectField from '@/components/ui/SelectField.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, buildUrl, toApiError, type ApiError } from '@/lib/http'
import { formatDateTime, formatNumber } from '@/lib/format'
import { compensationOptions, installationOptions, labelOf, requestTone } from '@/lib/requestOptions'
import { useAuthStore } from '@/stores/auth'
import type { ClientRequest, TechnicalResponsible } from '@/types/api'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const id = computed(() => String(route.params.id))

const refreshKey = ref(0)
const { data, error, loading } = useApiQuery(
  () => [id.value, refreshKey.value] as const,
  ([rid]) => api<{ data: ClientRequest }>(`/client-requests/${rid}`),
)
const local = shallowRef<ClientRequest | null>(null)
watch(data, () => (local.value = null))
const current = computed(() => local.value ?? data.value?.data ?? null)
const isOpen = computed(() => !!current.value && !['CONVERTED', 'CANCELLED'].includes(current.value.status.value))

const { data: rts } = useApiQuery(
  () => 1,
  () => api<{ data: TechnicalResponsible[] }>('/technical-responsibles', { query: { active: true } }),
)
const rtOptions = computed(() =>
  (rts.value?.data ?? []).map((r) => ({ value: r.id, label: `${r.name} (${r.council} ${r.registration})` })),
)
const rtId = ref('')
watch(current, (c) => {
  if (c?.technical_responsible && !rtId.value) rtId.value = c.technical_responsible.id
})

const busy = ref<string | null>(null)
const actionError = ref<ApiError | null>(null)
async function act(name: string, path: string, body: Record<string, unknown>) {
  busy.value = name
  actionError.value = null
  try {
    const res = await api<{ data: ClientRequest }>(`/client-requests/${id.value}/${path}`, { method: 'POST', body })
    local.value = res.data
    return true
  } catch (e) {
    actionError.value = toApiError(e)
    return false
  } finally {
    busy.value = null
  }
}

const message = ref('')
const askInfo = ref(false)
async function sendMessage() {
  if (!message.value.trim()) return
  if (await act('message', 'messages', { text: message.value.trim(), needs_info: askInfo.value })) {
    message.value = ''
    askInfo.value = false
  }
}

const cancelReason = ref('')
const showCancel = ref(false)
async function cancel() {
  if (await act('cancel', 'cancel', { reason: cancelReason.value.trim() })) showCancel.value = false
}

function convert() {
  router.push({ path: '/projetos/novo', query: { solicitacao: id.value } })
}

function reviewLabel(status: string) {
  return { APPROVED: 'Aprovado', REJECTED: 'Reprovado', PENDING: 'A conferir' }[status] ?? status
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <RouterLink to="/solicitacoes" class="inline-flex items-center gap-1.5 self-start text-sm text-muted hover:text-ink">
      <ArrowLeft class="size-4" aria-hidden="true" />Solicitações de clientes
    </RouterLink>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading && !current" class="text-sm text-muted">Carregando...</p>

    <template v-if="current">
      <header class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div class="flex flex-col gap-1">
          <div class="flex flex-wrap items-center gap-2">
            <h1 class="font-mono text-2xl font-semibold text-ink">{{ current.code }}</h1>
            <StatusBadge :tone="requestTone(current.status.value)">{{ current.status.label }}</StatusBadge>
          </div>
          <p class="text-sm text-muted">
            {{ current.client?.name }} · UC {{ current.consumer_unit?.number }} · {{ current.consumer_unit?.distributor?.name }}
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <RouterLink
            v-if="current.project"
            :to="current.project.process ? `/processos/${current.project.process.id}` : `/projetos/${current.project.id}`"
            class="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold text-ink hover:bg-canvas"
          >
            Abrir projeto {{ current.project.code }}
          </RouterLink>
          <template v-else-if="isOpen">
            <BaseButton v-if="auth.can('projects.manage')" variant="secondary" @click="showCancel = !showCancel">
              <XCircle class="size-4" aria-hidden="true" />Cancelar
            </BaseButton>
            <BaseButton v-if="auth.can('projects.manage')" @click="convert">
              <FolderPlus class="size-4" aria-hidden="true" />Converter em projeto
            </BaseButton>
          </template>
        </div>
      </header>

      <InlineAlert v-if="actionError" :correlation-id="actionError.correlationId">{{ actionError.message }}</InlineAlert>

      <form v-if="showCancel" class="flex flex-col gap-3 rounded-2xl border border-danger/30 bg-surface p-4" @submit.prevent="cancel">
        <label for="reason" class="text-sm font-medium text-ink">Motivo do cancelamento (o cliente verá esta mensagem)</label>
        <textarea id="reason" v-model="cancelReason" rows="2" class="rounded-lg border border-line bg-surface p-3 text-sm text-ink focus:border-primary focus:outline-none" />
        <div class="flex gap-2">
          <BaseButton type="submit" variant="primary" :loading="busy === 'cancel'" :disabled="cancelReason.trim().length < 5">Confirmar cancelamento</BaseButton>
          <BaseButton variant="ghost" @click="showCancel = false">Voltar</BaseButton>
        </div>
      </form>

      <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="flex flex-col gap-6">
          <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-5" aria-labelledby="sys">
            <h2 id="sys" class="font-semibold text-ink">Dados informados pelo cliente</h2>
            <dl class="grid gap-4 text-sm md:grid-cols-2">
              <div><dt class="text-muted">Titular</dt><dd class="text-ink">{{ current.client?.name }} · {{ current.client?.document }}</dd><dd class="text-muted">{{ current.client?.email }} {{ current.client?.phone }}</dd></div>
              <div><dt class="text-muted">Endereço da UC</dt><dd class="text-ink">{{ current.consumer_unit?.full_address }}</dd></div>
              <div><dt class="text-muted">Módulos</dt><dd v-for="(m, i) in current.system.modules" :key="i" class="text-ink">{{ m.quantity }}× {{ m.brand }} {{ m.model }} ({{ m.power_w }} W)</dd></div>
              <div><dt class="text-muted">Inversores</dt><dd v-for="(inv, i) in current.system.inverters" :key="i" class="text-ink">{{ inv.quantity }}× {{ inv.brand }} {{ inv.model }} ({{ inv.power_kw }} kW)</dd></div>
              <div><dt class="text-muted">Potência declarada</dt><dd class="font-medium tabular-nums text-ink">{{ formatNumber(current.declared_powers.modules_kwp, 'kWp') }} · inversores {{ formatNumber(current.declared_powers.inverters_kw, 'kW') }}</dd></div>
              <div><dt class="text-muted">Modalidade</dt><dd class="text-ink">{{ labelOf(compensationOptions, current.system.compensation_mode) }}</dd></div>
              <div><dt class="text-muted">Instalação</dt><dd class="text-ink">{{ labelOf(installationOptions, current.system.installation_type) }}<span v-if="current.system.roof_material"> · {{ current.system.roof_material }}</span></dd></div>
              <div><dt class="text-muted">Consumo médio</dt><dd class="text-ink">{{ formatNumber(current.system.average_consumption_kwh, 'kWh/mês') }} · {{ current.system.is_property_owner ? 'Proprietário' : 'Inquilino' }}</dd></div>
              <div v-if="current.system.has_battery"><dt class="text-muted">Bateria</dt><dd class="text-ink">{{ formatNumber(current.system.storage_energy_kwh, 'kWh') }}</dd></div>
              <div v-if="current.system.integrator"><dt class="text-muted">Instaladora</dt><dd class="text-ink">{{ current.system.integrator }}</dd></div>
              <div v-if="current.system.beneficiaries.length" class="md:col-span-2"><dt class="text-muted">UCs beneficiárias</dt>
                <dd v-for="b in current.system.beneficiaries" :key="b.uc_number" class="text-ink">UC {{ b.uc_number }} {{ b.holder_name ? `· ${b.holder_name}` : '' }} {{ b.percentage ? `· ${b.percentage}%` : '' }}</dd>
              </div>
              <div v-if="current.system.notes" class="md:col-span-2"><dt class="text-muted">Observações</dt><dd class="whitespace-pre-line text-ink">{{ current.system.notes }}</dd></div>
            </dl>
          </section>

          <div class="grid gap-6 md:grid-cols-2">
            <section class="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-5" aria-labelledby="cli-docs">
              <h2 id="cli-docs" class="flex items-center gap-2 text-sm font-semibold text-ink">
                <UserRound class="size-4 text-primary" aria-hidden="true" />Obrigações do cliente
                <span class="ml-auto tabular-nums text-muted">{{ current.client_progress.sent }}/{{ current.client_progress.required }}</span>
              </h2>
              <ul class="flex flex-col gap-2 text-sm">
                <li v-for="o in current.client_obligations" :key="o.type" class="flex items-start gap-2">
                  <CircleCheck v-if="o.document" class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />
                  <CircleDashed v-else class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />
                  <div class="flex min-w-0 flex-1 flex-col">
                    <span class="text-ink">{{ o.label }} <span v-if="!o.required" class="text-xs text-muted">(opcional)</span></span>
                    <span v-if="o.document" class="truncate text-xs text-muted">{{ o.document.original_name }} · v{{ o.document.version }} · {{ reviewLabel(o.document.review_status) }}</span>
                    <span v-else class="text-xs text-muted">{{ o.reason }}</span>
                  </div>
                  <a v-if="o.document" :href="buildUrl(`/documents/${o.document.id}/download`)" class="inline-flex size-8 items-center justify-center rounded-lg text-muted hover:bg-canvas hover:text-ink">
                    <Download class="size-4" aria-hidden="true" /><span class="sr-only">Baixar {{ o.label }}</span>
                  </a>
                </li>
              </ul>
            </section>

            <section class="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-5" aria-labelledby="rt-docs">
              <h2 id="rt-docs" class="flex items-center gap-2 text-sm font-semibold text-ink">
                <HardHat class="size-4 text-primary" aria-hidden="true" />Obrigações do responsável técnico
              </h2>
              <ul class="flex flex-col gap-2 text-sm">
                <li v-for="t in current.technical_obligations ?? []" :key="t.type" class="flex items-start gap-2 text-ink">
                  <CircleDashed class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />{{ t.label }}
                </li>
              </ul>
              <p class="border-t border-line pt-3 text-xs text-muted">
                Produzidos pelo RT depois da conversão. O checklist exato do projeto é gerado pelas regras da distribuidora.
              </p>
            </section>
          </div>

          <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-5" aria-labelledby="conv">
            <h2 id="conv" class="font-semibold text-ink">Conversa com o cliente</h2>
            <ol v-if="current.messages.length" class="flex flex-col gap-3">
              <li
                v-for="(m, i) in current.messages"
                :key="i"
                class="max-w-[85%] rounded-xl px-4 py-3 text-sm"
                :class="m.from === 'team' ? 'self-end bg-primary-soft' : m.from === 'system' ? 'self-center bg-canvas text-xs text-muted' : 'self-start bg-canvas'"
              >
                <p v-if="m.from !== 'system'" class="mb-1 text-xs font-semibold text-muted">{{ m.author }} · {{ formatDateTime(m.at) }}</p>
                <p class="whitespace-pre-line text-pretty text-ink">{{ m.text }}</p>
              </li>
            </ol>
            <p v-else class="text-sm text-muted">Nenhuma mensagem ainda.</p>
            <form v-if="isOpen" class="flex flex-col gap-2" @submit.prevent="sendMessage">
              <label for="staff-msg" class="sr-only">Mensagem</label>
              <textarea
                id="staff-msg"
                v-model="message"
                rows="2"
                maxlength="2000"
                placeholder="Escreva para o cliente"
                class="rounded-lg border border-line bg-surface p-3 text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
              />
              <div class="flex flex-wrap items-center justify-between gap-2">
                <label class="flex items-center gap-2 text-sm text-ink">
                  <input v-model="askInfo" type="checkbox" class="size-4 accent-primary" />
                  Pedir correção ao cliente (status “Aguardando cliente”)
                </label>
                <BaseButton type="submit" :loading="busy === 'message'" :disabled="!message.trim()">
                  <Send class="size-4" aria-hidden="true" />Enviar
                </BaseButton>
              </div>
            </form>
          </section>
        </div>

        <aside class="flex flex-col gap-4">
          <section class="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-5" aria-labelledby="assign">
            <h2 id="assign" class="text-sm font-semibold text-ink">Responsável técnico</h2>
            <p class="text-xs text-muted">Engenheiro da equipe que assume o projeto. Ele vira o RT de projeto e execução ao converter.</p>
            <template v-if="isOpen && auth.can('projects.manage')">
              <SelectField v-model="rtId" label="Designar RT" :options="rtOptions" placeholder="Selecione" />
              <BaseButton
                :loading="busy === 'assign'"
                :disabled="!rtId || rtId === current.technical_responsible?.id"
                @click="act('assign', 'assign', { technical_responsible_id: rtId })"
              >
                {{ current.technical_responsible ? 'Trocar responsável' : 'Designar e iniciar análise' }}
              </BaseButton>
            </template>
            <p v-else-if="current.technical_responsible" class="text-sm text-ink">
              {{ current.technical_responsible.name }} · {{ current.technical_responsible.council }} {{ current.technical_responsible.registration }}
            </p>
            <p v-else class="text-sm text-muted">Não designado.</p>
          </section>

          <section class="flex flex-col gap-2 rounded-2xl border border-line bg-surface p-5 text-sm" aria-labelledby="dates">
            <h2 id="dates" class="font-semibold text-ink">Datas</h2>
            <p class="flex justify-between"><span class="text-muted">Enviada</span><span class="text-ink">{{ formatDateTime(current.submitted_at ?? current.created_at) }}</span></p>
            <p class="flex justify-between"><span class="text-muted">Designada</span><span class="text-ink">{{ formatDateTime(current.assigned_at) }}</span></p>
            <p class="flex justify-between"><span class="text-muted">Convertida</span><span class="text-ink">{{ formatDateTime(current.converted_at) }}</span></p>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>
