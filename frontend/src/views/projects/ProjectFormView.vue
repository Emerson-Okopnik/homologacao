<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ArrowLeft, BatteryCharging, Cpu, Minus, PackagePlus, Plus, Sun, Trash2, UserPlus, Zap } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import ClientFormDialog from '@/views/clients/ClientFormDialog.vue'
import ConsumerUnitDialog from '@/views/clients/ConsumerUnitDialog.vue'
import EquipmentDialog from '@/views/catalog/EquipmentDialog.vue'
import ResponsibleDialog from '@/views/catalog/ResponsibleDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, toApiError, type ApiError } from '@/lib/http'
import { formatNumber } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { Client, ConsumerUnit, Distributor, Equipment, Paginated, Project, TechnicalResponsible } from '@/types/api'

const MICRO_LIMIT_KW = 75
const TYPE_LABEL: Record<Equipment['type'], string> = { module: 'Módulo', inverter: 'Inversor', battery: 'Bateria' }
const TYPE_ICON = { module: Sun, inverter: Cpu, battery: BatteryCharging }

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const projectId = computed(() => (route.params.id ? String(route.params.id) : null))

const { data: lookups, error: lookupError } = useApiQuery(
  () => projectId.value,
  async (id) => {
    const [clients, responsibles, equipment, project] = await Promise.all([
      api<Paginated<Client>>('/clients', { query: { per_page: 100, status: 'active' } }),
      api<{ data: TechnicalResponsible[] }>('/technical-responsibles', { query: { active: true } }),
      api<Paginated<Equipment>>('/equipment', { query: { per_page: 100, active: true } }),
      id ? api<{ data: Project }>(`/projects/${id}`) : Promise.resolve(null),
    ])
    return { clients: clients.data, responsibles: responsibles.data, equipment: equipment.data, project: project?.data ?? null }
  },
)

const createdClients = ref<Client[]>([])
const createdResponsibles = ref<TechnicalResponsible[]>([])
const createdEquipment = ref<Equipment[]>([])

const uniqueById = <T extends { id: string }>(list: T[]) => [...new Map(list.map((i) => [i.id, i])).values()]
const allClients = computed(() => uniqueById([...createdClients.value, ...(lookups.value?.clients ?? [])]))
const allResponsibles = computed(() => uniqueById([...createdResponsibles.value, ...(lookups.value?.responsibles ?? [])]))
const allEquipment = computed(() => uniqueById([...createdEquipment.value, ...(lookups.value?.equipment ?? [])]))
const equipmentById = computed(() => new Map(allEquipment.value.map((e) => [e.id, e])))

const form = reactive({
  client_id: typeof route.query.client === 'string' ? route.query.client : '',
  consumer_unit_id: '',
  technical_responsible_id: '',
  modality: 'autoconsumo_local',
  installed_power_kwp: '',
  inverter_power_kw: '',
  has_battery: false,
  estimated_generation_kwh_month: '',
  notes: '',
})
const items = ref<Array<{ id: string; quantity: string }>>([])
const units = shallowRef<ConsumerUnit[]>([])
const submitting = ref(false)
const error = ref<ApiError | null>(null)

const clientDialog = ref(false)
const unitDialog = ref(false)
const responsibleDialog = ref(false)
const equipmentDialog = shallowRef<{ type: Equipment['type']; rowIndex: number | null } | null>(null)
const distributors = shallowRef<Distributor[] | null>(null)
const notice = ref('')

watch(
  () => lookups.value?.project,
  (project) => {
    if (!project) return
    if (project.client && !lookups.value?.clients.some((c) => c.id === project.client!.id)) {
      createdClients.value.push(project.client as Client)
    }
    Object.assign(form, {
      client_id: project.client?.id ?? '',
      consumer_unit_id: project.consumer_unit?.id ?? '',
      technical_responsible_id: project.technical_responsible?.id ?? '',
      modality: project.modality,
      installed_power_kwp: String(project.installed_power_kwp),
      inverter_power_kw: String(project.inverter_power_kw),
      has_battery: project.has_battery,
      estimated_generation_kwh_month: project.estimated_generation_kwh_month?.toString() ?? '',
      notes: project.notes ?? '',
    })
    items.value = (project.equipment ?? []).map((e) => ({ id: e.id, quantity: String(e.quantity) }))
  },
)

watch(
  () => form.client_id,
  async (clientId, previous) => {
    if (previous !== undefined && previous !== clientId && !lookups.value?.project) form.consumer_unit_id = ''
    if (!clientId) {
      units.value = []
      return
    }
    const fetched = (await api<Paginated<ConsumerUnit>>('/consumer-units', { query: { client: clientId, active: true, per_page: 100 } })).data
    if (form.client_id !== clientId) return
    units.value = uniqueById([...units.value.filter((u) => u.client?.id === clientId), ...fetched])
    if (!form.consumer_unit_id && units.value.length === 1) form.consumer_unit_id = units.value[0]!.id
  },
  { immediate: true },
)

const clientOptions = computed(() =>
  allClients.value.map((c) => ({ value: c.id, label: c.document ? `${c.name} · ${c.document}` : c.name })),
)
const unitOptions = computed(() =>
  units.value.map((u) => ({ value: u.id, label: `UC ${u.number} · ${u.distributor?.name ?? ''} · ${u.address.city}/${u.address.state}` })),
)
const responsibleOptions = computed(() =>
  allResponsibles.value.map((r) => ({ value: r.id, label: `${r.name} (${r.council} ${r.registration})` })),
)
const equipmentOptions = computed(() =>
  allEquipment.value.map((e) => ({
    value: e.id,
    label: `${TYPE_LABEL[e.type]} · ${e.manufacturer} ${e.model}${e.power_w ? ` · ${formatNumber(e.power_w, 'W')}` : ''}`,
  })),
)

const numeric = (v: string) => (v.trim() === '' ? null : Number(v.replace(',', '.')))
const accessPower = computed(() => {
  const kwp = numeric(form.installed_power_kwp)
  const kw = numeric(form.inverter_power_kw)
  if (!kwp || !kw) return null
  return Math.min(kwp, kw)
})
const generationType = computed(() => (accessPower.value === null ? null : accessPower.value <= MICRO_LIMIT_KW ? 'Microgeração' : 'Minigeração'))

function totalKw(type: Equipment['type']) {
  const total = items.value.reduce((sum, item) => {
    const eq = equipmentById.value.get(item.id)
    return eq?.type === type && eq.power_w ? sum + (eq.power_w * Number(item.quantity || 0)) / 1000 : sum
  }, 0)
  return Math.round(total * 100) / 100
}
const moduleTotalKwp = computed(() => totalKw('module'))
const inverterTotalKw = computed(() => totalKw('inverter'))
const hasBatteryItem = computed(() => items.value.some((i) => equipmentById.value.get(i.id)?.type === 'battery'))

watch(moduleTotalKwp, (total, old) => {
  if (total && (form.installed_power_kwp === '' || numeric(form.installed_power_kwp) === old)) form.installed_power_kwp = String(total)
})
watch(inverterTotalKw, (total, old) => {
  if (total && (form.inverter_power_kw === '' || numeric(form.inverter_power_kw) === old)) form.inverter_power_kw = String(total)
})
watch(hasBatteryItem, (has) => {
  if (has) form.has_battery = true
})

function changeQuantity(index: number, delta: number) {
  const item = items.value[index]!
  item.quantity = String(Math.max(1, Number(item.quantity || 0) + delta))
}

async function ensureDistributors() {
  if (!distributors.value) {
    distributors.value = (await api<{ data: Distributor[] }>('/distributors', { query: { active: true } })).data
  }
}

async function openUnitDialog() {
  await ensureDistributors()
  unitDialog.value = true
}

async function onClientSaved(client: Client) {
  createdClients.value.unshift(client)
  clientDialog.value = false
  form.client_id = client.id
  notice.value = `Cliente ${client.name} cadastrado. Agora informe a unidade consumidora onde o sistema será instalado.`
  if (auth.can('clients.manage')) await openUnitDialog()
}

function onUnitSaved(unit: ConsumerUnit) {
  unitDialog.value = false
  units.value = uniqueById([unit, ...units.value])
  form.consumer_unit_id = unit.id
  notice.value = `UC ${unit.number} cadastrada e selecionada.`
}

function onResponsibleSaved(rt: TechnicalResponsible) {
  createdResponsibles.value.unshift(rt)
  responsibleDialog.value = false
  form.technical_responsible_id = rt.id
}

function onEquipmentSaved(eq: Equipment) {
  createdEquipment.value.unshift(eq)
  const rowIndex = equipmentDialog.value?.rowIndex ?? null
  equipmentDialog.value = null
  if (rowIndex !== null && items.value[rowIndex]) items.value[rowIndex]!.id = eq.id
  else items.value.push({ id: eq.id, quantity: eq.type === 'module' ? '10' : '1' })
}

function addRow() {
  items.value.push({ id: '', quantity: '1' })
}

async function submit() {
  submitting.value = true
  error.value = null
  const body = {
    ...form,
    technical_responsible_id: form.technical_responsible_id || null,
    installed_power_kwp: numeric(form.installed_power_kwp),
    inverter_power_kw: numeric(form.inverter_power_kw),
    estimated_generation_kwh_month: numeric(form.estimated_generation_kwh_month),
    equipment: items.value.filter((i) => i.id).map((i) => ({ id: i.id, quantity: Number(i.quantity) })),
  }
  try {
    const result = projectId.value
      ? await api<{ data: Project }>(`/projects/${projectId.value}`, { method: 'PUT', body })
      : await api<{ data: Project }>('/projects', { method: 'POST', body })
    const processId = result.data.process?.id
    await router.push(processId ? `/processos/${processId}` : `/projetos/${result.data.id}`)
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <RouterLink to="/projetos" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink">
      <ArrowLeft class="size-4" aria-hidden="true" />
      Projetos
    </RouterLink>
    <PageHeader
      :title="projectId ? `Editar projeto ${lookups?.project?.code ?? ''}` : 'Novo projeto'"
      description="Selecione ou cadastre tudo aqui mesmo. Ao salvar, o processo de homologação é aberto com o checklist de documentos."
    />

    <InlineAlert v-if="lookupError" :correlation-id="lookupError.correlationId">{{ lookupError.message }}</InlineAlert>

    <form v-else class="flex flex-col gap-6" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error && Object.keys(error.errors).length === 0" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
      <InlineAlert v-else-if="error">Revise os campos destacados.</InlineAlert>

      <p v-if="notice" class="rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-ink" role="status">{{ notice }}</p>

      <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-holder">
        <div class="flex items-center gap-3">
          <span class="flex size-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-white" aria-hidden="true">1</span>
          <h2 id="sec-holder" class="font-semibold">Titular e unidade consumidora</h2>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="flex flex-col gap-1.5">
            <SelectField
              v-model="form.client_id"
              label="Cliente"
              :placeholder="clientOptions.length ? 'Selecione o cliente' : 'Nenhum cliente cadastrado'"
              :options="clientOptions"
              required
              :error="error?.firstError('client_id')"
            />
            <button
              v-if="auth.can('clients.manage')"
              type="button"
              class="inline-flex items-center gap-1.5 self-start text-sm font-medium text-primary hover:underline"
              @click="clientDialog = true"
            >
              <UserPlus class="size-4" aria-hidden="true" />
              Cadastrar novo cliente
            </button>
          </div>

          <div class="flex flex-col gap-1.5">
            <SelectField
              v-model="form.consumer_unit_id"
              label="Unidade consumidora"
              :placeholder="!form.client_id ? 'Selecione o cliente primeiro' : unitOptions.length === 0 ? 'Cliente sem UCs — cadastre abaixo' : 'Selecione a UC'"
              :options="unitOptions"
              required
              :error="error?.firstError('consumer_unit_id')"
            />
            <button
              v-if="auth.can('clients.manage')"
              type="button"
              class="inline-flex items-center gap-1.5 self-start text-sm font-medium text-primary hover:underline disabled:cursor-not-allowed disabled:text-muted disabled:no-underline"
              :disabled="!form.client_id"
              @click="openUnitDialog"
            >
              <Zap class="size-4" aria-hidden="true" />
              Cadastrar nova UC
            </button>
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <SelectField
            v-model="form.technical_responsible_id"
            label="Responsável técnico"
            placeholder="Definir depois"
            :options="responsibleOptions"
            hint="Obrigatório antes do envio à distribuidora."
            :error="error?.firstError('technical_responsible_id')"
          />
          <button
            v-if="auth.can('technical_responsibles.manage')"
            type="button"
            class="inline-flex items-center gap-1.5 self-start text-sm font-medium text-primary hover:underline"
            @click="responsibleDialog = true"
          >
            <UserPlus class="size-4" aria-hidden="true" />
            Cadastrar responsável técnico
          </button>
        </div>
      </section>

      <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-eq">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="flex size-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-white" aria-hidden="true">2</span>
            <h2 id="sec-eq" class="font-semibold">Equipamentos</h2>
          </div>
          <BaseButton variant="secondary" :disabled="items.length >= 30" @click="addRow">
            <Plus class="size-4" aria-hidden="true" />
            Adicionar do catálogo
          </BaseButton>
        </div>

        <div v-if="auth.can('projects.manage')" class="flex flex-col gap-2 rounded-xl border border-dashed border-line bg-canvas p-4">
          <p class="flex items-center gap-2 text-sm font-medium">
            <PackagePlus class="size-4 text-primary" aria-hidden="true" />
            Não encontrou o equipamento? Cadastre e ele já entra no projeto:
          </p>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="type in (['module', 'inverter', 'battery'] as const)"
              :key="type"
              type="button"
              class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-sm font-medium hover:border-primary hover:text-primary"
              :disabled="items.length >= 30"
              @click="equipmentDialog = { type, rowIndex: null }"
            >
              <component :is="TYPE_ICON[type]" class="size-4" aria-hidden="true" />
              Novo {{ TYPE_LABEL[type].toLowerCase() }}
            </button>
          </div>
        </div>

        <p v-if="items.length === 0" class="text-sm text-muted">Nenhum equipamento vinculado ainda.</p>

        <ul v-else class="flex flex-col gap-3">
          <li
            v-for="(item, index) in items"
            :key="index"
            class="grid items-end gap-3 rounded-xl border border-line p-3 sm:grid-cols-[1fr_auto_auto]"
          >
            <SelectField
              v-model="item.id"
              :label="`Equipamento ${index + 1}`"
              placeholder="Selecione"
              :options="equipmentOptions"
              :error="error?.firstError(`equipment.${index}.id`)"
            />
            <div class="flex flex-col gap-1.5">
              <span class="text-sm font-medium" :id="`qty-${index}`">Quantidade</span>
              <div class="flex h-10 items-center rounded-lg border border-line" role="group" :aria-labelledby="`qty-${index}`">
                <button type="button" class="flex h-full w-9 items-center justify-center hover:bg-canvas" @click="changeQuantity(index, -1)">
                  <Minus class="size-4" aria-hidden="true" />
                  <span class="sr-only">Diminuir</span>
                </button>
                <input
                  v-model="item.quantity"
                  type="number"
                  min="1"
                  inputmode="numeric"
                  class="h-full w-16 border-x border-line bg-transparent text-center text-sm outline-none"
                  :aria-labelledby="`qty-${index}`"
                />
                <button type="button" class="flex h-full w-9 items-center justify-center hover:bg-canvas" @click="changeQuantity(index, 1)">
                  <Plus class="size-4" aria-hidden="true" />
                  <span class="sr-only">Aumentar</span>
                </button>
              </div>
              <p v-if="error?.firstError(`equipment.${index}.quantity`)" class="text-xs text-danger">
                {{ error?.firstError(`equipment.${index}.quantity`) }}
              </p>
            </div>
            <BaseButton variant="ghost" @click="items.splice(index, 1)">
              <Trash2 class="size-4" aria-hidden="true" />
              <span class="sr-only">Remover equipamento {{ index + 1 }}</span>
            </BaseButton>
          </li>
        </ul>

        <dl v-if="moduleTotalKwp || inverterTotalKw" class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted">
          <div v-if="moduleTotalKwp" class="flex gap-1.5">
            <dt>Módulos:</dt>
            <dd class="font-semibold text-ink">{{ formatNumber(moduleTotalKwp, 'kWp') }}</dd>
          </div>
          <div v-if="inverterTotalKw" class="flex gap-1.5">
            <dt>Inversores:</dt>
            <dd class="font-semibold text-ink">{{ formatNumber(inverterTotalKw, 'kW') }}</dd>
          </div>
        </dl>
      </section>

      <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-tech">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-3">
            <span class="flex size-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-white" aria-hidden="true">3</span>
            <h2 id="sec-tech" class="font-semibold">Dados técnicos</h2>
          </div>
          <p v-if="generationType" class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary" aria-live="polite">
            {{ generationType }} · potência de acesso {{ formatNumber(accessPower, 'kW') }}
          </p>
        </div>
        <p class="text-sm text-muted">As potências são preenchidas automaticamente pela soma dos equipamentos. Você pode ajustar se precisar.</p>
        <SelectField
          v-model="form.modality"
          label="Modalidade de compensação"
          :options="[
            { value: 'autoconsumo_local', label: 'Autoconsumo local' },
            { value: 'autoconsumo_remoto', label: 'Autoconsumo remoto' },
            { value: 'geracao_compartilhada', label: 'Geração compartilhada' },
            { value: 'multiplas_uc', label: 'Múltiplas unidades consumidoras' },
          ]"
          :error="error?.firstError('modality')"
        />
        <div class="grid gap-4 sm:grid-cols-3">
          <FormField
            v-model="form.installed_power_kwp"
            label="Potência dos módulos (kWp)"
            type="number"
            required
            :error="error?.firstError('installed_power_kwp')"
          />
          <FormField
            v-model="form.inverter_power_kw"
            label="Potência dos inversores (kW)"
            type="number"
            required
            :error="error?.firstError('inverter_power_kw')"
          />
          <FormField
            v-model="form.estimated_generation_kwh_month"
            label="Geração estimada (kWh/mês)"
            type="number"
            :error="error?.firstError('estimated_generation_kwh_month')"
          />
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.has_battery" type="checkbox" class="size-4 accent-primary" />
          Sistema com armazenamento (bateria)
        </label>
        <TextareaField v-model="form.notes" label="Observações técnicas" :rows="3" :error="error?.firstError('notes')" />
      </section>

      <div class="sticky bottom-0 -mx-2 flex justify-end gap-2 border-t border-line bg-canvas/95 px-2 py-4 backdrop-blur">
        <RouterLink to="/projetos" class="inline-flex h-10 items-center rounded-lg border border-line px-4 text-sm font-medium hover:bg-surface">
          Cancelar
        </RouterLink>
        <BaseButton type="submit" :loading="submitting">{{ projectId ? 'Salvar alterações' : 'Criar projeto e abrir processo' }}</BaseButton>
      </div>
    </form>

    <ClientFormDialog v-if="clientDialog" :client="null" @close="clientDialog = false" @saved="onClientSaved" />
    <ConsumerUnitDialog
      v-if="unitDialog && form.client_id"
      :client-id="form.client_id"
      :unit="null"
      :distributors="distributors ?? []"
      @close="unitDialog = false"
      @saved="onUnitSaved"
    />
    <ResponsibleDialog v-if="responsibleDialog" :item="null" @close="responsibleDialog = false" @saved="onResponsibleSaved" />
    <EquipmentDialog
      v-if="equipmentDialog"
      :item="null"
      :default-type="equipmentDialog.type"
      @close="equipmentDialog = null"
      @saved="onEquipmentSaved"
    />
  </div>
</template>
