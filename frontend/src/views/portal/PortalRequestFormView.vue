<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Check, ChevronLeft, ChevronRight, Plus, ShieldCheck, Sun, Trash2, UserRound } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, toApiError, type ApiError } from '@/lib/http'
import { formatNumber } from '@/lib/format'
import {
  compensationOptions,
  emptySystem,
  installationOptions,
  labelOf,
  supplyOptions,
  systemPowers,
  voltageOptions,
} from '@/lib/requestOptions'
import type { ClientObligation, ClientRequest, ConsumerUnit, Distributor, RequestSystem } from '@/types/api'

const route = useRoute()
const router = useRouter()
const editingId = computed(() => (typeof route.params.id === 'string' ? route.params.id : null))

const steps = ['Unidade consumidora', 'Equipamentos', 'Uso da energia', 'Revisão'] as const
const step = ref(0)
const error = ref<ApiError | null>(null)
const submitting = ref(false)

const unitId = ref('')
const system = reactive<RequestSystem>(emptySystem())
const powers = computed(() => systemPowers(system))

const { data: units } = useApiQuery(
  () => 1,
  () => api<{ data: ConsumerUnit[] }>('/portal/units'),
)
const unitList = ref<ConsumerUnit[]>([])
watch(units, (v) => {
  unitList.value = v?.data ?? []
  if (!editingId.value && !unitId.value && unitList.value.length === 1) unitId.value = unitList.value[0]!.id
  if (unitList.value.length === 0) showNewUnit.value = true
})

const { data: distributors } = useApiQuery(
  () => 1,
  () => api<{ data: Distributor[] }>('/portal/distributors'),
)
const distributorOptions = computed(() =>
  (distributors.value?.data ?? []).map((d) => ({ value: d.id, label: `${d.name} (${d.state})` })),
)

// Edição: carrega a solicitação existente.
watch(
  editingId,
  async (id) => {
    if (!id) return
    try {
      const res = await api<{ data: ClientRequest }>(`/portal/requests/${id}`)
      if (!res.data.editable) {
        await router.replace(`/portal/solicitacoes/${id}`)
        return
      }
      unitId.value = res.data.consumer_unit?.id ?? ''
      Object.assign(system, emptySystem(), res.data.system)
    } catch (e) {
      error.value = toApiError(e)
    }
  },
  { immediate: true },
)

// Cadastro rápido de UC
const showNewUnit = ref(false)
const savingUnit = ref(false)
const unitErrors = ref<ApiError | null>(null)
const newUnit = reactive({
  distributor_id: '',
  number: '',
  zip: '',
  street: '',
  address_number: '',
  district: '',
  city: '',
  state: '',
  voltage_class: 'BT',
  supply_type: 'monofasico',
  breaker_a: '',
})

async function saveUnit() {
  savingUnit.value = true
  unitErrors.value = null
  try {
    const res = await api<{ data: ConsumerUnit }>('/portal/units', {
      method: 'POST',
      body: { ...newUnit, breaker_a: newUnit.breaker_a ? Number(newUnit.breaker_a) : null },
    })
    unitList.value = [...unitList.value, res.data]
    unitId.value = res.data.id
    showNewUnit.value = false
  } catch (e) {
    unitErrors.value = toApiError(e)
  } finally {
    savingUnit.value = false
  }
}

const selectedUnit = computed(() => unitList.value.find((u) => u.id === unitId.value) ?? null)

// Equipamentos
function addModule() {
  system.modules.push({ brand: '', model: '', power_w: null, quantity: null })
}
function addInverter() {
  system.inverters.push({ brand: '', model: '', power_kw: null, quantity: 1 })
}
function addBeneficiary() {
  system.beneficiaries.push({ uc_number: '', holder_name: '', percentage: null })
}
const needsBeneficiaries = computed(() => system.compensation_mode !== 'LOCAL_SELF_CONSUMPTION')
const beneficiaryTotal = computed(() => system.beneficiaries.reduce((s, b) => s + (Number(b.percentage) || 0), 0))

// Validação local por etapa (o backend valida de novo).
const stepProblem = computed<string | null>(() => {
  if (step.value === 0 && !unitId.value) return 'Selecione ou cadastre a unidade consumidora.'
  if (step.value === 1) {
    if (system.modules.some((m) => !m.brand || !m.model || !m.power_w || !m.quantity))
      return 'Preencha marca, modelo, potência e quantidade de todos os módulos.'
    if (system.inverters.some((i) => !i.brand || !i.model || !i.power_kw || !i.quantity))
      return 'Preencha marca, modelo, potência e quantidade de todos os inversores.'
    if (system.has_battery && !system.storage_energy_kwh) return 'Informe a capacidade da bateria em kWh.'
  }
  if (step.value === 2 && needsBeneficiaries.value) {
    if (system.beneficiaries.length === 0) return 'Informe ao menos uma unidade que vai receber os créditos.'
    if (system.beneficiaries.some((b) => !b.uc_number)) return 'Informe o número de todas as UCs beneficiárias.'
    if (beneficiaryTotal.value > 100) return 'A soma dos percentuais não pode passar de 100%.'
  }
  return null
})

const tried = ref(false)
function next() {
  tried.value = true
  if (stepProblem.value) return
  tried.value = false
  step.value = Math.min(step.value + 1, steps.length - 1)
}
function back() {
  tried.value = false
  step.value = Math.max(step.value - 1, 0)
}

// Prévia de documentos do cliente
const obligations = ref<ClientObligation[]>([])
watch(step, async (s) => {
  if (s !== 3) return
  try {
    const res = await api<{ data: ClientObligation[] }>('/portal/obligations', { method: 'POST', body: { system } })
    obligations.value = res.data
  } catch {
    obligations.value = []
  }
})

const technicalDuties = [
  'Projeto elétrico, memorial descritivo e diagrama unifilar',
  'ART/TRT de projeto e de execução',
  'Formulário de solicitação de acesso da distribuidora',
  'Protocolo, acompanhamento de prazos e respostas à distribuidora',
  'Acompanhamento da vistoria e da troca do medidor',
]

function payloadSystem(): RequestSystem {
  return {
    ...system,
    beneficiaries: needsBeneficiaries.value ? system.beneficiaries : [],
    storage_energy_kwh: system.has_battery ? system.storage_energy_kwh : null,
  }
}

async function submit() {
  submitting.value = true
  error.value = null
  try {
    const body = { consumer_unit_id: unitId.value, system: payloadSystem() }
    const res = editingId.value
      ? await api<{ data: ClientRequest }>(`/portal/requests/${editingId.value}`, { method: 'PUT', body })
      : await api<{ data: ClientRequest }>('/portal/requests', { method: 'POST', body })
    await router.push(`/portal/solicitacoes/${res.data.id}`)
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}

const inputClass =
  'h-11 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20'

watch(step, async () => {
  await nextTick()
  document.getElementById('request-stepper')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
})
</script>

<template>
  <div class="portal-request-form flex flex-col gap-6">
    <header class="request-heading">
      <h1>{{ editingId ? 'Editar solicitação' : 'Nova solicitação' }}</h1>
      <p class="text-sm text-muted">
        Preencha o que você sabe. Se tiver dúvidas sobre algum equipamento, consulte a proposta do seu instalador.
      </p>
    </header>

    <ol id="request-stepper" class="request-stepper" aria-label="Etapas">
      <li v-for="(label, i) in steps" :key="label" :class="{ current: i === step, complete: i < step }" :aria-current="i === step ? 'step' : undefined">
        <span class="request-step-number" aria-hidden="true"><Check v-if="i < step" class="size-3.5" /><template v-else>{{ i + 1 }}</template></span>
        <span class="request-step-label">
          {{ label }}
        </span>
      </li>
    </ol>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>

    <div class="request-layout">
    <section class="request-content rounded-xl border border-line bg-surface p-5 md:p-6">
      <!-- Etapa 1: UC -->
      <div v-if="step === 0" class="flex flex-col gap-4">
        <div>
          <h2 class="font-semibold text-ink">Onde o sistema será instalado?</h2>
          <p class="text-sm text-muted">O número da UC aparece na sua conta de luz, normalmente como “Unidade consumidora” ou “Instalação”.</p>
        </div>

        <fieldset v-if="unitList.length" class="flex flex-col gap-2">
          <legend class="sr-only">Unidades consumidoras</legend>
          <label
            v-for="u in unitList"
            :key="u.id"
            class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors"
            :class="unitId === u.id ? 'border-primary bg-primary-soft' : 'border-line hover:border-primary/40'"
          >
            <input v-model="unitId" type="radio" name="unit" :value="u.id" class="mt-1 accent-primary" />
            <span class="flex flex-col">
              <span class="font-medium text-ink">UC {{ u.number }} · {{ u.distributor?.name }}</span>
              <span class="text-sm text-muted">{{ u.full_address }}</span>
            </span>
          </label>
        </fieldset>

        <button
          v-if="!showNewUnit"
          type="button"
          class="inline-flex items-center gap-2 self-start text-sm font-semibold text-primary hover:underline"
          @click="showNewUnit = true"
        >
          <Plus class="size-4" aria-hidden="true" />
          Cadastrar outra unidade consumidora
        </button>

        <form v-else class="flex flex-col gap-4 rounded-xl border border-dashed border-line p-4" @submit.prevent="saveUnit">
          <h3 class="text-sm font-semibold text-ink">Nova unidade consumidora</h3>
          <InlineAlert v-if="unitErrors && !Object.keys(unitErrors.errors).length">{{ unitErrors.message }}</InlineAlert>
          <div class="grid gap-4 md:grid-cols-2">
            <SelectField v-model="newUnit.distributor_id" label="Distribuidora de energia" :options="distributorOptions" placeholder="Selecione" required :error="unitErrors?.firstError('distributor_id')" />
            <FormField v-model="newUnit.number" label="Número da UC" required :error="unitErrors?.firstError('number')" hint="Como aparece na conta de luz" />
            <FormField v-model="newUnit.zip" label="CEP" autocomplete="postal-code" :error="unitErrors?.firstError('zip')" />
            <FormField v-model="newUnit.street" label="Rua / logradouro" autocomplete="address-line1" required :error="unitErrors?.firstError('street')" />
            <FormField v-model="newUnit.address_number" label="Número" :error="unitErrors?.firstError('address_number')" />
            <FormField v-model="newUnit.district" label="Bairro" :error="unitErrors?.firstError('district')" />
            <FormField v-model="newUnit.city" label="Cidade" autocomplete="address-level2" required :error="unitErrors?.firstError('city')" />
            <FormField v-model="newUnit.state" label="UF" autocomplete="address-level1" required :error="unitErrors?.firstError('state')" />
            <SelectField v-model="newUnit.supply_type" label="Tipo de ligação" :options="supplyOptions" required hint="Veja na conta de luz: mono, bi ou trifásico" />
            <SelectField v-model="newUnit.voltage_class" label="Tensão" :options="voltageOptions" required />
            <FormField v-model="newUnit.breaker_a" label="Disjuntor do padrão (A)" type="number" hint="Opcional. Ex.: 40, 50, 63" :error="unitErrors?.firstError('breaker_a')" />
          </div>
          <div class="flex gap-2">
            <BaseButton type="submit" :loading="savingUnit">Salvar unidade</BaseButton>
            <BaseButton v-if="unitList.length" variant="ghost" @click="showNewUnit = false">Cancelar</BaseButton>
          </div>
        </form>
      </div>

      <!-- Etapa 2: Equipamentos -->
      <div v-else-if="step === 1" class="flex flex-col gap-6">
        <div>
          <h2 class="font-semibold text-ink">Quais equipamentos serão instalados?</h2>
          <p class="text-sm text-muted">Essas informações estão na proposta comercial ou no orçamento do instalador.</p>
        </div>

        <fieldset class="flex flex-col gap-3">
          <legend class="mb-2 text-sm font-semibold text-ink">Módulos (placas solares)</legend>
          <div v-for="(m, i) in system.modules" :key="`m${i}`" class="request-equipment-row grid items-end gap-3 rounded-xl bg-canvas p-3">
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Marca<input v-model="m.brand" :class="inputClass" placeholder="Ex.: Canadian Solar" /></label>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Modelo<input v-model="m.model" :class="inputClass" placeholder="Ex.: CS6W-550MS" /></label>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Potência (W)<input v-model.number="m.power_w" type="number" min="10" :class="inputClass" placeholder="550" /></label>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Quantidade<input v-model.number="m.quantity" type="number" min="1" :class="inputClass" placeholder="10" /></label>
            <button v-if="system.modules.length > 1" type="button" class="inline-flex size-10 items-center justify-center rounded-lg text-muted hover:bg-surface hover:text-danger" @click="system.modules.splice(i, 1)">
              <Trash2 class="size-4" aria-hidden="true" /><span class="sr-only">Remover módulo</span>
            </button>
          </div>
          <button type="button" class="inline-flex items-center gap-2 self-start text-sm font-semibold text-primary hover:underline" @click="addModule">
            <Plus class="size-4" aria-hidden="true" />Adicionar outro modelo de módulo
          </button>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
          <legend class="mb-2 text-sm font-semibold text-ink">Inversor</legend>
          <div v-for="(inv, i) in system.inverters" :key="`i${i}`" class="request-equipment-row grid items-end gap-3 rounded-xl bg-canvas p-3">
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Marca<input v-model="inv.brand" :class="inputClass" placeholder="Ex.: Growatt" /></label>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Modelo<input v-model="inv.model" :class="inputClass" placeholder="Ex.: MIN 5000TL-X" /></label>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Potência (kW)<input v-model.number="inv.power_kw" type="number" min="0.1" step="0.1" :class="inputClass" placeholder="5" /></label>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Quantidade<input v-model.number="inv.quantity" type="number" min="1" :class="inputClass" /></label>
            <button v-if="system.inverters.length > 1" type="button" class="inline-flex size-10 items-center justify-center rounded-lg text-muted hover:bg-surface hover:text-danger" @click="system.inverters.splice(i, 1)">
              <Trash2 class="size-4" aria-hidden="true" /><span class="sr-only">Remover inversor</span>
            </button>
          </div>
          <button type="button" class="inline-flex items-center gap-2 self-start text-sm font-semibold text-primary hover:underline" @click="addInverter">
            <Plus class="size-4" aria-hidden="true" />Adicionar outro inversor
          </button>
        </fieldset>

        <div class="grid gap-4 md:grid-cols-2">
          <label class="flex items-center gap-3 rounded-xl border border-line p-4 text-sm text-ink">
            <input v-model="system.has_battery" type="checkbox" class="size-4 accent-primary" />
            O sistema terá bateria (armazenamento)
          </label>
          <label v-if="system.has_battery" class="flex flex-col gap-1.5 text-sm font-medium text-ink">
            Capacidade da bateria (kWh)
            <input v-model.number="system.storage_energy_kwh" type="number" min="0" step="0.1" :class="inputClass" />
          </label>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
          <SelectField v-model="system.installation_type" label="Local de instalação" :options="installationOptions" required />
          <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">
            Tipo de telha / estrutura
            <input v-model="system.roof_material" :class="inputClass" placeholder="Ex.: cerâmica, metálica" />
          </label>
          <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">
            Empresa instaladora
            <input v-model="system.integrator" :class="inputClass" placeholder="Opcional" />
          </label>
        </div>

        <div class="flex flex-wrap gap-6 rounded-xl bg-primary-soft px-4 py-3 text-sm">
          <p><span class="text-muted">Potência dos módulos:</span> <strong class="tabular-nums text-ink">{{ formatNumber(powers.modules, 'kWp') }}</strong></p>
          <p><span class="text-muted">Potência dos inversores:</span> <strong class="tabular-nums text-ink">{{ formatNumber(powers.inverters, 'kW') }}</strong></p>
        </div>
      </div>

      <!-- Etapa 3: Uso da energia -->
      <div v-else-if="step === 2" class="flex flex-col gap-5">
        <div>
          <h2 class="font-semibold text-ink">Como você vai usar a energia gerada?</h2>
          <p class="text-sm text-muted">Isso define como a distribuidora distribui os créditos de energia.</p>
        </div>

        <fieldset class="flex flex-col gap-2">
          <legend class="sr-only">Modalidade de compensação</legend>
          <label
            v-for="opt in compensationOptions"
            :key="opt.value"
            class="flex cursor-pointer items-center gap-3 rounded-xl border p-4 text-sm transition-colors"
            :class="system.compensation_mode === opt.value ? 'border-primary bg-primary-soft' : 'border-line hover:border-primary/40'"
          >
            <input v-model="system.compensation_mode" type="radio" :value="opt.value" class="accent-primary" />
            <span class="text-ink">{{ opt.label }}</span>
          </label>
        </fieldset>

        <div v-if="needsBeneficiaries" class="flex flex-col gap-3">
          <h3 class="text-sm font-semibold text-ink">Unidades que vão receber os créditos</h3>
          <div v-for="(b, i) in system.beneficiaries" :key="`b${i}`" class="grid items-end gap-3 rounded-xl bg-canvas p-3 md:grid-cols-[10rem_1fr_8rem_auto]">
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Nº da UC<input v-model="b.uc_number" :class="inputClass" /></label>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Titular<input v-model="b.holder_name" :class="inputClass" placeholder="Nome na conta de luz" /></label>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">Percentual (%)<input v-model.number="b.percentage" type="number" min="1" max="100" :class="inputClass" /></label>
            <button type="button" class="inline-flex size-10 items-center justify-center rounded-lg text-muted hover:bg-surface hover:text-danger" @click="system.beneficiaries.splice(i, 1)">
              <Trash2 class="size-4" aria-hidden="true" /><span class="sr-only">Remover beneficiária</span>
            </button>
          </div>
          <div class="flex items-center justify-between">
            <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-primary hover:underline" @click="addBeneficiary">
              <Plus class="size-4" aria-hidden="true" />Adicionar unidade
            </button>
            <span class="text-sm tabular-nums" :class="beneficiaryTotal > 100 ? 'text-danger' : 'text-muted'">Total: {{ beneficiaryTotal }}%</span>
          </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <label class="flex flex-col gap-1.5 text-sm font-medium text-ink">
            Consumo médio mensal (kWh)
            <input v-model.number="system.average_consumption_kwh" type="number" min="0" :class="inputClass" placeholder="Veja na conta de luz" />
          </label>
          <fieldset class="flex flex-col gap-1.5">
            <legend class="text-sm font-medium text-ink">Você é o proprietário do imóvel?</legend>
            <div class="flex gap-2">
              <label v-for="opt in [{ v: true, l: 'Sim' }, { v: false, l: 'Não, sou inquilino(a)' }]" :key="String(opt.v)" class="flex h-10 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm" :class="system.is_property_owner === opt.v ? 'border-primary bg-primary-soft' : 'border-line'">
                <input v-model="system.is_property_owner" type="radio" :value="opt.v" class="accent-primary" />{{ opt.l }}
              </label>
            </div>
          </fieldset>
        </div>

        <TextareaField
          :model-value="system.notes ?? ''"
          label="Observações para a equipe técnica"
          hint="Opcional. Ex.: melhor horário para vistoria, acesso ao telhado..."
          @update:model-value="(v: string) => (system.notes = v || null)"
        />
      </div>

      <!-- Etapa 4: Revisão -->
      <div v-else class="flex flex-col gap-6">
        <div>
          <h2 class="font-semibold text-ink">Confira e envie</h2>
          <p class="text-sm text-muted">Depois de enviar, você poderá anexar seus documentos na página da solicitação.</p>
        </div>

        <dl class="grid gap-4 rounded-xl bg-canvas p-4 text-sm md:grid-cols-2">
          <div><dt class="text-muted">Unidade consumidora</dt><dd class="font-medium text-ink">UC {{ selectedUnit?.number }} · {{ selectedUnit?.distributor?.name }}</dd></div>
          <div><dt class="text-muted">Endereço</dt><dd class="text-ink">{{ selectedUnit?.full_address }}</dd></div>
          <div><dt class="text-muted">Módulos</dt><dd class="text-ink">{{ system.modules.map((m) => `${m.quantity}× ${m.brand} ${m.model} (${m.power_w} W)`).join(', ') }}</dd></div>
          <div><dt class="text-muted">Inversor</dt><dd class="text-ink">{{ system.inverters.map((i) => `${i.quantity}× ${i.brand} ${i.model} (${i.power_kw} kW)`).join(', ') }}</dd></div>
          <div><dt class="text-muted">Potência</dt><dd class="font-medium tabular-nums text-ink">{{ formatNumber(powers.modules, 'kWp') }} · inversor {{ formatNumber(powers.inverters, 'kW') }}</dd></div>
          <div><dt class="text-muted">Uso da energia</dt><dd class="text-ink">{{ labelOf(compensationOptions, system.compensation_mode) }}</dd></div>
        </dl>

        <div class="flex flex-col gap-4">
          <section class="flex flex-col gap-3 rounded-xl border border-line p-4" aria-labelledby="sua-parte">
            <h3 id="sua-parte" class="flex items-center gap-2 text-sm font-semibold text-ink">
              <UserRound class="size-4 text-primary" aria-hidden="true" />Sua parte (documentos do titular)
            </h3>
            <ul class="flex flex-col gap-2 text-sm">
              <li v-for="o in obligations" :key="o.type" class="flex flex-col">
                <span class="text-ink">{{ o.label }} <span v-if="!o.required" class="text-muted">(se houver)</span></span>
                <span class="text-xs text-muted">{{ o.reason }}</span>
              </li>
              <li v-if="!obligations.length" class="text-muted">Calculando...</li>
            </ul>
          </section>
        </div>
      </div>

      <p v-if="tried && stepProblem" role="alert" class="mt-4 text-sm text-danger">{{ stepProblem }}</p>

      <div class="request-actions mt-6 flex items-center justify-between gap-3 border-t border-line pt-5">
        <BaseButton v-if="step > 0" variant="secondary" @click="back"><ChevronLeft class="size-4" aria-hidden="true" />Voltar</BaseButton>
        <BaseButton v-else variant="secondary" @click="router.push('/portal')">Cancelar</BaseButton>
        <span class="request-step-count">Etapa {{ step + 1 }} de {{ steps.length }}</span>
        <BaseButton v-if="step < steps.length - 1" @click="next">Continuar<ChevronRight class="size-4" aria-hidden="true" /></BaseButton>
        <BaseButton v-else :loading="submitting" @click="submit">{{ editingId ? 'Salvar alterações' : 'Enviar solicitação' }}</BaseButton>
      </div>
    </section>
    <aside class="request-guide" aria-label="Orientações para a solicitação">
      <div class="request-guide-heading"><Sun class="size-6" aria-hidden="true" /><h2>Cada informação ajuda a avançar.</h2></div>
      <section><h3>Sua parte</h3><ul><li v-for="duty in ['Informar a instalação e os equipamentos', 'Enviar os documentos do titular', 'Complementar dados, se solicitado']" :key="duty"><Check class="size-4 shrink-0" aria-hidden="true" /><span>{{ duty }}</span></li></ul></section>
      <section><h3>Fica com o responsável técnico</h3><ul><li v-for="duty in technicalDuties" :key="duty"><ShieldCheck class="size-4 shrink-0" aria-hidden="true" /><span>{{ duty }}</span></li></ul></section>
    </aside>
    </div>
  </div>
</template>

<style scoped>
.request-equipment-row {
  grid-template-columns: minmax(0,1.3fr) minmax(0,1.5fr) minmax(76px,.7fr) minmax(70px,.6fr) auto;
}

.request-equipment-row label {
  min-width: 0;
}

.request-equipment-row input {
  min-width: 0;
}

@media (max-width:1199px) {
  .request-equipment-row {
    grid-template-columns: repeat(2,minmax(0,1fr));
  }
}

@media (max-width:767px) {
  .request-equipment-row>label:nth-child(-n+2) {
    grid-column: 1/-1;
  }
}

.request-heading h1 {
  font-size: 29px;
  font-weight: 600;
  letter-spacing: -.035em;
}

.request-heading p {
  margin-top: 9px;
  max-width: 860px;
  font-size: 12px;
  line-height: 1.9;
  color: var(--color-muted);
}

.request-stepper {
  display: grid;
  grid-template-columns: repeat(4,minmax(0,1fr));
  gap: 0;
  border: 1px solid var(--color-line);
  border-radius: 11px;
  padding: 24px 26px;
  background: var(--color-surface);
  scroll-margin-top: 20px;
}

.request-stepper li {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 13px;
  min-width: 0;
}

.request-stepper li:not(:last-child):after {
  position: absolute;
  top: 13px;
  left: 30px;
  right: 0;
  height: 2px;
  background: var(--color-line);
  content: '';
}

.request-stepper li.complete:after {
  background: var(--color-primary);
}

.request-step-number {
  position: relative;
  z-index: 1;
  display: flex;
  width: 28px;
  height: 28px;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--color-line);
  border-radius: 50%;
  background: var(--color-surface);
  font-size: 11px;
  font-weight: 500;
  color: var(--color-muted);
}

.request-stepper .current .request-step-number,
.request-stepper .complete .request-step-number {
  border-color: var(--color-primary);
  background: var(--color-primary);
  color: var(--color-on-primary);
}

.request-step-label {
  max-width: 95%;
  font-size: 10px;
  line-height: 1.6;
  color: var(--color-muted);
}

.request-stepper .current .request-step-label {
  font-weight: 600;
  color: var(--color-ink);
}

.request-layout {
  display: grid;
  grid-template-columns: minmax(0,1.96fr) minmax(300px,1fr);
  align-items: start;
  gap: 24px;
}

.request-content {
  min-width: 0;
  padding: 26px;
}

.request-content h2 {
  font-size: 20px;
  line-height: 1.5;
  letter-spacing: -.025em;
  font-weight: 600;
}

.request-content h2+p {
  margin-top: 10px;
  font-size: 11px;
  line-height: 1.9;
}

.request-content :deep(input),
.request-content :deep(select),
.request-content :deep(textarea) {
  font-size: 12px;
}

.request-content :deep(label) {
  line-height: 1.7;
}

.request-content>div>fieldset {
  margin-top: 8px;
}

.request-actions {
  flex-wrap: wrap;
}

.request-step-count {
  margin-right: auto;
  font-size: 10px;
  color: var(--color-muted);
}

.request-guide {
  position: sticky;
  top: 24px;
  overflow: hidden;
  border: 1px solid var(--color-line);
  border-radius: 11px;
  background: var(--color-surface);
}

.request-guide-heading {
  padding: 24px;
  background: var(--color-navy);
  color: #8de0c4;
}

.request-guide-heading h2 {
  margin-top: 20px;
  font-size: 15px;
  line-height: 1.7;
  font-weight: 500;
  color: white;
}

.request-guide section {
  margin: 24px;
  padding-bottom: 26px;
}

.request-guide section+section {
  border-top: 1px solid var(--color-line);
  padding-top: 25px;
  padding-bottom: 0;
}

.request-guide h3 {
  font-size: 13px;
  font-weight: 600;
}

.request-guide ul {
  display: flex;
  flex-direction: column;
  gap: 22px;
  margin-top: 24px;
}

.request-guide li {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  font-size: 11px;
  line-height: 1.8;
  color: var(--color-muted);
}

.request-guide li svg {
  margin-top: 2px;
  color: var(--color-primary);
}

@media (max-width:1100px) {
  .request-layout {
    grid-template-columns: minmax(0,1fr) 300px;
  }

  .request-content {
    padding: 22px;
  }

  .request-content :deep(.md\:grid-cols-4) {
    grid-template-columns: repeat(2,minmax(0,1fr));
  }

  .request-content :deep(.md\:grid-cols-3) {
    grid-template-columns: repeat(2,minmax(0,1fr));
  }
}

@media (max-width:900px) {
  .request-layout {
    grid-template-columns: minmax(0,1fr);
  }

  .request-guide {
    position: static;
  }

  .request-guide {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }

  .request-guide-heading {
    grid-column: 1/-1;
  }

  .request-guide section+section {
    margin-left: 0;
    border-top: 0;
    border-left: 1px solid var(--color-line);
    padding-top: 0;
    padding-left: 24px;
  }

  .request-guide-heading {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 24px;
  }

  .request-guide-heading h2 {
    margin: 0;
  }
}

@media (max-width:767px) {
  .portal-request-form {
    gap: 20px;
  }

  .request-heading h1 {
    font-size: 25px;
  }

  .request-heading p {
    font-size: 11px;
  }

  .request-stepper {
    border-radius: 10px;
    padding: 18px 14px;
  }

  .request-step-number {
    width: 24px;
    height: 24px;
    font-size: 10px;
  }

  .request-stepper li {
    gap: 10px;
  }

  .request-stepper li:not(:last-child):after {
    top: 11px;
    left: 26px;
  }

  .request-step-label {
    font-size: 8px;
    line-height: 1.6;
  }

  .request-content {
    padding: 18px;
  }

  .request-content h2 {
    font-size: 18px;
  }

  .request-content h2+p {
    font-size: 10.5px;
  }

  .request-content :deep(input),
  .request-content :deep(select),
  .request-content :deep(textarea) {
    font-size: 13px;
  }

  .request-step-count {
    display: none;
  }

  .request-actions {
    flex-wrap: nowrap;
    align-items: stretch;
  }

  .request-actions :deep(button) {
    min-height: 44px;
    flex: 1;
    font-size: 11px;
  }

  .request-guide {
    display: block;
  }

  .request-guide section {
    margin: 20px;
  }

  .request-guide section+section {
    margin: 20px;
    border-top: 1px solid var(--color-line);
    border-left: 0;
    padding-top: 20px;
    padding-left: 0;
  }

  .request-guide ul {
    gap: 15px;
    margin-top: 18px;
  }

  .request-guide-heading h2 {
    font-size: 13px;
  }
}
</style>
