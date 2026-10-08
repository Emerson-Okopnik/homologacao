<script setup lang="ts">
import { computed, ref, reactive, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProjectDocumentsPanel from '@/views/documents/ProjectDocumentsPanel.vue'
import ProjectVersionDialog from './ProjectVersionDialog.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import { useApiQuery } from '@/composables/useApiQuery'
import { useAuthStore } from '@/stores/auth'
import type { Project, Equipment, ConsumerUnit } from '@/types/api'
import type { ProjectTechnicalResponse, ProjectVersion } from '@/types/homologation'
const route = useRoute(),
  router = useRouter(),
  auth = useAuthStore()
const revision = ref(0),
  projectId = computed(() => String(route.params.id))
const source = computed(() => ({ id: projectId.value, revision: revision.value }))
const { data, error: queryError } = useApiQuery(source, async (s) => {
  const [project, technical, equipment, units] = await Promise.all([
    api<{ data: Project }>(`/projects/${s.id}`),
    api<{ data: ProjectTechnicalResponse }>(`/projects/${s.id}/technical-data`),
    api<{ data: Equipment[] }>('/equipment', { query: { per_page: 100, active: true } }),
    api<{ data: ConsumerUnit[] }>('/consumer-units', { query: { per_page: 100, active: true } }),
  ])
  return { project: project.data, ...technical.data, equipment: equipment.data, units: units.data }
})
const text = (v: unknown) => (v === null || v === undefined ? '' : String(v))
const number = (v: string) => (v.trim() ? Number(v.replace(',', '.')) : null)
const form = reactive({
  name: '',
  installation_type: '',
  connection_point: '',
  supply_voltage: '',
  phase_configuration: '',
  main_breaker_a: '',
  installed_load_kw: '',
  contracted_demand_kw: '',
  existing_generation_kw: '',
  emergency_generator: false,
  mode: '',
  allocation_rule: 'percentage',
})
const arrays = ref<
  Array<{
    module_model_id: string
    module_quantity: string
    strings_quantity: string
    modules_per_string: string
    azimuth: string
    tilt: string
  }>
>([])
const inverters = ref<
  Array<{
    inverter_model_id: string
    quantity: string
    nominal_ac_kw: string
    connection_voltage: string
    protection: string
    previousProtection: Record<string, unknown>
  }>
>([])
const storage = ref<
  Array<{
    battery_model_id: string
    quantity: string
    energy_kwh: string
    power_kw: string
    operating_strategy: string
    dispatchable: boolean
  }>
>([])
const units = ref<Array<{ consumer_unit_id: string; percentage: string; priority: string }>>([])
const term = reactive({ type: 'ART', number: '', issued_at: '', valid_until: '', file_id: '' })
const saving = ref(false),
  error = ref<ApiError | null>(null),
  notice = ref(''),
  viewing = ref<ProjectVersion | null>(null)
const canEdit = computed(() => auth.can('projects.manage') && data.value?.editable)
watch(
  () => data.value,
  (value) => {
    if (!value) return
    const technical = value.technical_data,
      connection = technical.connection
    Object.assign(form, {
      name: technical.name || value.project.code,
      installation_type: technical.installation_type ?? '',
      connection_point: text(connection.connection_point),
      supply_voltage: text(connection.supply_voltage),
      phase_configuration: text(connection.phase_configuration),
      main_breaker_a: text(connection.main_breaker_a),
      installed_load_kw: text(connection.installed_load_kw),
      contracted_demand_kw: text(connection.contracted_demand_kw),
      existing_generation_kw: text(connection.existing_generation_kw),
      emergency_generator: connection.emergency_generator ?? false,
      mode: technical.compensation.mode,
      allocation_rule: technical.compensation.allocation_rule,
    })
    arrays.value = technical.arrays.map((r) => ({
      module_model_id: r.module_model_id,
      module_quantity: text(r.module_quantity),
      strings_quantity: text(r.strings_quantity),
      modules_per_string: text(r.modules_per_string),
      azimuth: text(r.azimuth),
      tilt: text(r.tilt),
    }))
    inverters.value = technical.inverters.map((r) => ({
      inverter_model_id: r.inverter_model_id,
      quantity: text(r.quantity),
      nominal_ac_kw: text(r.nominal_ac_kw),
      connection_voltage: text(r.connection_voltage),
      protection: text(r.protection_config_json?.description),
      previousProtection: r.protection_config_json ?? {},
    }))
    storage.value = technical.storage.map((r) => ({
      battery_model_id: r.battery_model_id,
      quantity: text(r.quantity),
      energy_kwh: text(r.energy_kwh),
      power_kw: text(r.power_kw),
      operating_strategy: text(r.operating_strategy),
      dispatchable: r.dispatchable,
    }))
    units.value = technical.compensation.units.map((r) => ({
      consumer_unit_id: r.consumer_unit_id,
      percentage: text(r.percentage),
      priority: text(r.priority),
    }))
    term.type = value.project.technical_responsible?.council === 'CFT' ? 'TRT' : 'ART'
  },
)
const equipmentOptions = (type: Equipment['type']) =>
  (data.value?.equipment ?? []).filter((e) => e.type === type).map((e) => ({ value: e.id, label: `${e.manufacturer} ${e.model}` }))
const unitOptions = computed(() =>
  (data.value?.units ?? []).map((u) => ({ value: u.id, label: `UC ${u.number} · ${u.client?.name ?? ''}` })),
)
const modalities = [
  { value: 'autoconsumo_local', label: 'Autoconsumo local' },
  { value: 'autoconsumo_remoto', label: 'Autoconsumo remoto' },
  { value: 'geracao_compartilhada', label: 'Geração compartilhada' },
  { value: 'multiplas_uc', label: 'Múltiplas UCs' },
]
function add(type: string) {
  if (type === 'array')
    arrays.value.push({ module_model_id: '', module_quantity: '1', strings_quantity: '', modules_per_string: '', azimuth: '', tilt: '' })
  if (type === 'inverter')
    inverters.value.push({
      inverter_model_id: '',
      quantity: '1',
      nominal_ac_kw: '',
      connection_voltage: '',
      protection: '',
      previousProtection: {},
    })
  if (type === 'storage')
    storage.value.push({ battery_model_id: '', quantity: '1', energy_kwh: '', power_kw: '', operating_strategy: '', dispatchable: false })
  if (type === 'unit') units.value.push({ consumer_unit_id: '', percentage: '', priority: '' })
}
async function perform(action: () => Promise<unknown>, message: string) {
  saving.value = true
  error.value = null
  notice.value = ''
  try {
    await action()
    notice.value = message
    revision.value++
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    saving.value = false
  }
}
function save() {
  return perform(
    () =>
      api(`/projects/${projectId.value}/technical-data`, {
        method: 'PUT',
        body: {
          name: form.name,
          installation_type: form.installation_type || null,
          connection: {
            connection_point: form.connection_point || null,
            supply_voltage: number(form.supply_voltage),
            phase_configuration: form.phase_configuration || null,
            main_breaker_a: number(form.main_breaker_a),
            installed_load_kw: number(form.installed_load_kw),
            contracted_demand_kw: number(form.contracted_demand_kw),
            existing_generation_kw: number(form.existing_generation_kw),
            emergency_generator: form.emergency_generator,
          },
          arrays: arrays.value.map((r) => ({
            module_model_id: r.module_model_id,
            module_quantity: number(r.module_quantity),
            strings_quantity: number(r.strings_quantity),
            modules_per_string: number(r.modules_per_string),
            azimuth: number(r.azimuth),
            tilt: number(r.tilt),
          })),
          inverters: inverters.value.map((r) => ({
            inverter_model_id: r.inverter_model_id,
            quantity: number(r.quantity),
            nominal_ac_kw: number(r.nominal_ac_kw),
            connection_voltage: number(r.connection_voltage),
            protection_config_json: r.protection
              ? { ...r.previousProtection, description: r.protection }
              : Object.keys(r.previousProtection).length
                ? r.previousProtection
                : null,
          })),
          storage: storage.value.map((r) => ({
            ...r,
            quantity: number(r.quantity),
            energy_kwh: number(r.energy_kwh),
            power_kw: number(r.power_kw),
            operating_strategy: r.operating_strategy || null,
          })),
          compensation: {
            mode: form.mode,
            allocation_rule: form.allocation_rule,
            units: units.value.map((r) => ({
              consumer_unit_id: r.consumer_unit_id,
              percentage: number(r.percentage),
              priority: number(r.priority),
            })),
          },
        },
      }),
    'Dados técnicos salvos. As potências foram calculadas a partir dos equipamentos.',
  )
}
function saveTerm() {
  return perform(
    () =>
      api(`/projects/${projectId.value}/responsibility-terms`, {
        method: 'POST',
        body: { ...term, valid_until: term.valid_until || null, technical_responsible_id: data.value?.project.technical_responsible?.id },
      }),
    'Termo vinculado ao projeto.',
  )
}
async function openProcess() {
  await perform(async () => {
    const result = await api<{ data: { id: string } }>(`/projects/${projectId.value}/processes`, { method: 'POST' })
    await router.push(`/processos/${result.data.id}`)
  }, 'Processo aberto.')
}
</script>
<template>
  <div class="mx-auto max-w-6xl space-y-6">
    <RouterLink to="/projetos" class="text-sm text-primary">Voltar aos projetos</RouterLink>
    <PageHeader
      :title="`Dados técnicos · ${data?.project.code ?? ''}`"
      description="Arranjos, conexão, armazenamento, rateio e responsabilidade técnica do projeto."
    />
    <InlineAlert v-if="queryError">{{ queryError.message }}</InlineAlert>
    <InlineAlert v-if="error"
      ><p>{{ error.message }}</p>
      <ul class="list-disc pl-5">
        <li v-for="(messages, field) in error.errors" :key="field">{{ messages[0] }}</li>
      </ul></InlineAlert
    >
    <p v-if="notice" role="status" class="rounded-lg bg-success-soft p-3 text-sm text-success">{{ notice }}</p>
    <template v-if="data">
      <div class="flex flex-wrap gap-3 text-sm">
        <RouterLink v-if="auth.can('projects.manage')" :to="`/projetos/${projectId}`" class="text-primary"
          >Cliente, UC e responsável técnico</RouterLink
        ><RouterLink v-for="p in data.processes" :key="p.id" :to="`/processos/${p.id}`" class="text-primary"
          >{{ p.code }} · {{ p.stage ?? p.status }}</RouterLink
        ><BaseButton v-if="auth.can('homologations.manage')" variant="secondary" :loading="saving" @click="openProcess"
          >Abrir outro processo</BaseButton
        >
      </div>
      <p v-if="!data.editable" class="text-sm text-muted">
        Os dados técnicos ficam bloqueados durante a análise. As versões enviadas permanecem disponíveis abaixo.
      </p>
      <form class="space-y-6" novalidate @submit.prevent="save">
        <fieldset :disabled="!canEdit || saving" class="space-y-6">
          <section class="rounded-2xl border border-line bg-surface p-5">
            <h2 class="mb-4 font-semibold">Identificação e conexão</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <FormField v-model="form.name" label="Nome do projeto" required />
              <SelectField
                v-model="form.installation_type"
                label="Instalação"
                placeholder="Selecione"
                :options="[
                  { value: 'rooftop', label: 'Telhado' },
                  { value: 'ground', label: 'Solo' },
                  { value: 'other', label: 'Outra' },
                ]"
              />
              <FormField v-model="form.connection_point" label="Ponto de conexão" />
              <FormField v-model="form.supply_voltage" label="Tensão de fornecimento (V)" type="number" step="any" />
              <SelectField
                v-model="form.phase_configuration"
                label="Fases"
                placeholder="Selecione"
                :options="[
                  { value: 'monofasico', label: 'Monofásico' },
                  { value: 'bifasico', label: 'Bifásico' },
                  { value: 'trifasico', label: 'Trifásico' },
                ]"
              />
              <FormField v-model="form.main_breaker_a" label="Disjuntor geral (A)" type="number" />
              <FormField v-model="form.installed_load_kw" label="Carga instalada (kW)" type="number" step="any" />
              <FormField v-model="form.contracted_demand_kw" label="Demanda contratada (kW)" type="number" step="any" />
              <FormField v-model="form.existing_generation_kw" label="Geração existente (kW)" type="number" step="any" />
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm"
              ><input v-model="form.emergency_generator" type="checkbox" />Possui gerador de emergência</label
            >
          </section>
          <section class="rounded-2xl border border-line bg-surface p-5">
            <header class="mb-4 flex justify-between">
              <h2 class="font-semibold">Arranjos fotovoltaicos</h2>
              <BaseButton variant="secondary" @click="add('array')">Adicionar arranjo</BaseButton>
            </header>
            <div v-for="(row, index) in arrays" :key="index" class="mb-4 grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-3">
              <SelectField
                v-model="row.module_model_id"
                label="Modelo do módulo"
                placeholder="Selecione"
                :options="equipmentOptions('module')"
              />
              <FormField v-model="row.module_quantity" label="Quantidade de módulos" type="number" />
              <FormField v-model="row.strings_quantity" label="Quantidade de strings" type="number" />
              <FormField v-model="row.modules_per_string" label="Módulos por string" type="number" />
              <FormField v-model="row.azimuth" label="Azimute (°)" type="number" step="any" />
              <FormField v-model="row.tilt" label="Inclinação (°)" type="number" step="any" />
              <BaseButton variant="ghost" @click="arrays.splice(index, 1)">Remover arranjo</BaseButton>
            </div>
          </section>
          <section class="rounded-2xl border border-line bg-surface p-5">
            <header class="mb-4 flex justify-between">
              <h2 class="font-semibold">Inversores</h2>
              <BaseButton variant="secondary" @click="add('inverter')">Adicionar inversor</BaseButton>
            </header>
            <div v-for="(row, index) in inverters" :key="index" class="mb-4 grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-2">
              <SelectField
                v-model="row.inverter_model_id"
                label="Modelo"
                placeholder="Selecione"
                :options="equipmentOptions('inverter')"
                @update:model-value="
                  row.nominal_ac_kw = text((data.equipment.find((e) => e.id === row.inverter_model_id)?.power_w ?? 0) / 1000)
                "
              />
              <FormField v-model="row.quantity" label="Quantidade" type="number" />
              <FormField v-model="row.nominal_ac_kw" label="Potência nominal por inversor (kW)" type="number" step="any" />
              <FormField v-model="row.connection_voltage" label="Tensão de conexão (V)" type="number" step="any" />
              <TextareaField v-model="row.protection" label="Configuração de proteção" class="sm:col-span-2" />
              <BaseButton variant="ghost" @click="inverters.splice(index, 1)">Remover inversor</BaseButton>
            </div>
          </section>
          <section class="rounded-2xl border border-line bg-surface p-5">
            <header class="mb-4 flex justify-between">
              <h2 class="font-semibold">Armazenamento (opcional)</h2>
              <BaseButton variant="secondary" @click="add('storage')">Adicionar bateria</BaseButton>
            </header>
            <div v-for="(row, index) in storage" :key="index" class="mb-4 grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-2">
              <SelectField v-model="row.battery_model_id" label="Modelo" placeholder="Selecione" :options="equipmentOptions('battery')" />
              <FormField v-model="row.quantity" label="Quantidade" type="number" />
              <FormField v-model="row.energy_kwh" label="Energia por bateria (kWh)" type="number" step="any" />
              <FormField v-model="row.power_kw" label="Potência por bateria (kW)" type="number" step="any" />
              <TextareaField v-model="row.operating_strategy" label="Estratégia de operação" class="sm:col-span-2" />
              <label class="flex items-center gap-2 text-sm"><input v-model="row.dispatchable" type="checkbox" />Despachável</label>
              <BaseButton variant="ghost" @click="storage.splice(index, 1)">Remover bateria</BaseButton>
            </div>
          </section>
          <section class="rounded-2xl border border-line bg-surface p-5">
            <h2 class="mb-4 font-semibold">Compensação e rateio</h2>
            <div class="mb-4 grid gap-4 sm:grid-cols-2">
              <SelectField v-model="form.mode" label="Modalidade" :options="modalities" /><SelectField
                v-model="form.allocation_rule"
                label="Regra de distribuição"
                :options="[
                  { value: 'percentage', label: 'Percentual (total de 100%)' },
                  { value: 'priority', label: 'Ordem de prioridade' },
                ]"
              />
            </div>
            <div v-for="(row, index) in units" :key="index" class="mb-3 grid items-end gap-3 sm:grid-cols-[1fr_10rem_auto]">
              <SelectField
                v-model="row.consumer_unit_id"
                label="UC beneficiária"
                placeholder="Selecione"
                :options="unitOptions"
              /><FormField
                v-if="form.allocation_rule === 'percentage'"
                v-model="row.percentage"
                label="Percentual (%)"
                type="number"
                step="any"
              /><FormField v-else v-model="row.priority" label="Prioridade" type="number" /><BaseButton
                variant="ghost"
                @click="units.splice(index, 1)"
                >Remover</BaseButton
              >
            </div>
            <BaseButton variant="secondary" @click="add('unit')">Adicionar beneficiária</BaseButton>
          </section>
          <div class="flex items-center justify-between gap-3">
            <p class="text-sm text-muted">
              Potência atual: {{ data.project.installed_power_kwp }} kWp · inversores {{ data.project.inverter_power_kw }} kW
            </p>
            <BaseButton v-if="canEdit" type="submit" :loading="saving">Salvar dados técnicos</BaseButton>
          </div>
        </fieldset>
      </form>
      <ProjectDocumentsPanel
        :endpoint="`/projects/${projectId}/documents`"
        :documents="data.documents"
        :editable="!!canEdit"
        @saved="revision++"
      />
      <section class="rounded-2xl border border-line bg-surface p-5">
        <h2 class="mb-3 font-semibold">
          Responsabilidade técnica · {{ data.project.technical_responsible?.name ?? 'Defina o responsável no cadastro do projeto' }}
        </h2>
        <ul class="mb-4 text-sm">
          <li v-for="t in data.technical_data.responsibility_terms" :key="t.id">
            {{ t.type }} {{ t.number }} · emissão {{ t.issued_at }}<template v-if="t.valid_until"> · validade {{ t.valid_until }}</template>
          </li>
        </ul>
        <form
          v-if="canEdit && data.project.technical_responsible"
          class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-3"
          novalidate
          @submit.prevent="saveTerm"
        >
          <FormField v-model="term.number" :label="`Número da ${term.type}`" required /><FormField
            v-model="term.issued_at"
            type="date"
            label="Emissão"
            required
          /><FormField v-model="term.valid_until" type="date" label="Validade (opcional)" />
          <SelectField
            v-model="term.file_id"
            label="Arquivo de ART/TRT"
            placeholder="Selecione"
            :options="
              data.documents
                .filter((d) => d.is_current && d.document_type === 'art_trt')
                .map((d) => ({ value: d.id, label: `${d.original_name} · v${d.version}` }))
            "
          /><BaseButton type="submit" :loading="saving">Vincular termo</BaseButton>
        </form>
      </section>
      <section class="rounded-2xl border border-line bg-surface p-5">
        <h2 class="mb-3 font-semibold">Versões congeladas</h2>
        <p v-if="!data.versions.length" class="text-sm text-muted">Uma versão será congelada ao preparar o envio.</p>
        <ul class="divide-y divide-line">
          <li v-for="v in data.versions" :key="v.id" class="flex justify-between gap-3 py-3 text-sm">
            <span>v{{ v.version }} · {{ v.change_reason }}</span
            ><BaseButton variant="ghost" @click="viewing = v">Ver e comparar</BaseButton>
          </li>
        </ul>
      </section>
      <InlineAlert v-if="data.issues.length"
        ><p class="font-medium">Dados que precisam ser completados antes do envio</p>
        <ul class="list-disc pl-5">
          <li v-for="issue in data.issues" :key="issue">{{ issue }}</li>
        </ul></InlineAlert
      >
      <ProjectVersionDialog v-if="viewing" :project-id="projectId" :version="viewing" :versions="data.versions" @close="viewing = null" />
    </template>
  </div>
</template>
