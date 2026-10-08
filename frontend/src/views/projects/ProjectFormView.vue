<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Plus, Trash2 } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, toApiError, type ApiError } from '@/lib/http'
import { formatNumber } from '@/lib/format'
import type { Client, ConsumerUnit, Equipment, Paginated, Project, TechnicalResponsible } from '@/types/api'

const MICRO_LIMIT_KW = 75

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

watch(
  () => lookups.value?.project,
  (project) => {
    if (!project) return
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
    units.value = clientId
      ? (await api<Paginated<ConsumerUnit>>('/consumer-units', { query: { client: clientId, active: true, per_page: 100 } })).data
      : []
    if (!form.consumer_unit_id && units.value.length === 1) form.consumer_unit_id = units.value[0]!.id
  },
  { immediate: true },
)

const clientOptions = computed(() => (lookups.value?.clients ?? []).map((c) => ({ value: c.id, label: c.name })))
const unitOptions = computed(() =>
  units.value.map((u) => ({ value: u.id, label: `UC ${u.number} · ${u.distributor?.name ?? ''} · ${u.address.city}/${u.address.state}` })),
)
const responsibleOptions = computed(() =>
  (lookups.value?.responsibles ?? []).map((r) => ({ value: r.id, label: `${r.name} (${r.council} ${r.registration})` })),
)
const equipmentOptions = computed(() =>
  (lookups.value?.equipment ?? []).map((e) => ({
    value: e.id,
    label: `${e.type === 'module' ? 'Módulo' : e.type === 'inverter' ? 'Inversor' : 'Bateria'} · ${e.manufacturer} ${e.model}`,
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
const moduleTotalKwp = computed(() =>
  items.value.reduce((sum, item) => {
    const eq = lookups.value?.equipment.find((e) => e.id === item.id)
    return eq?.type === 'module' && eq.power_w ? sum + (eq.power_w * Number(item.quantity || 0)) / 1000 : sum
  }, 0),
)

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
      description="Ao salvar um novo projeto, o processo de homologação é aberto automaticamente com o checklist de documentos."
    />

    <InlineAlert v-if="lookupError" :correlation-id="lookupError.correlationId">{{ lookupError.message }}</InlineAlert>

    <form v-else class="flex flex-col gap-6" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error && Object.keys(error.errors).length === 0" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
      <InlineAlert v-else-if="error">Revise os campos destacados.</InlineAlert>

      <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-holder">
        <h2 id="sec-holder" class="font-semibold">Titular e unidade consumidora</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <SelectField
            v-model="form.client_id"
            label="Cliente"
            placeholder="Selecione o cliente"
            :options="clientOptions"
            required
            :error="error?.firstError('client_id')"
          />
          <SelectField
            v-model="form.consumer_unit_id"
            label="Unidade consumidora"
            :placeholder="form.client_id && unitOptions.length === 0 ? 'Cliente sem UCs ativas' : 'Selecione a UC'"
            :options="unitOptions"
            required
            :error="error?.firstError('consumer_unit_id')"
          />
        </div>
        <SelectField
          v-model="form.technical_responsible_id"
          label="Responsável técnico"
          placeholder="Definir depois"
          :options="responsibleOptions"
          hint="Obrigatório antes do envio à distribuidora."
          :error="error?.firstError('technical_responsible_id')"
        />
      </section>

      <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-tech">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="sec-tech" class="font-semibold">Dados técnicos</h2>
          <p v-if="generationType" class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary" aria-live="polite">
            {{ generationType }} · potência de acesso {{ formatNumber(accessPower, 'kW') }}
          </p>
        </div>
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
            :hint="moduleTotalKwp ? `Equipamentos somam ${formatNumber(moduleTotalKwp, 'kWp')}` : undefined"
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
      </section>

      <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-eq">
        <div class="flex items-center justify-between">
          <h2 id="sec-eq" class="font-semibold">Equipamentos</h2>
          <BaseButton variant="secondary" :disabled="items.length >= 30" @click="items.push({ id: '', quantity: '1' })">
            <Plus class="size-4" aria-hidden="true" />
            Adicionar
          </BaseButton>
        </div>
        <p v-if="items.length === 0" class="text-sm text-muted">Nenhum equipamento vinculado. Cadastre itens em Cadastros técnicos.</p>
        <div v-for="(item, index) in items" :key="index" class="grid items-end gap-3 sm:grid-cols-[1fr_8rem_auto]">
          <SelectField v-model="item.id" :label="`Equipamento ${index + 1}`" placeholder="Selecione" :options="equipmentOptions" :error="error?.firstError(`equipment.${index}.id`)" />
          <FormField v-model="item.quantity" label="Quantidade" type="number" :error="error?.firstError(`equipment.${index}.quantity`)" />
          <BaseButton variant="ghost" @click="items.splice(index, 1)">
            <Trash2 class="size-4" aria-hidden="true" />
            <span class="sr-only">Remover equipamento {{ index + 1 }}</span>
          </BaseButton>
        </div>
      </section>

      <section class="rounded-2xl border border-line bg-surface p-6">
        <TextareaField v-model="form.notes" label="Observações técnicas" :rows="4" :error="error?.firstError('notes')" />
      </section>

      <div class="flex justify-end gap-2">
        <RouterLink to="/projetos" class="inline-flex h-10 items-center rounded-lg border border-line px-4 text-sm font-medium hover:bg-canvas">
          Cancelar
        </RouterLink>
        <BaseButton type="submit" :loading="submitting">{{ projectId ? 'Salvar alterações' : 'Criar projeto e abrir processo' }}</BaseButton>
      </div>
    </form>
  </div>
</template>
