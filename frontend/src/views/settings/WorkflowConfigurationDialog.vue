<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { Distributor, Option, WorkflowStage as ProcessPhase } from '@/types/api'
import type { WorkflowConfiguration, WorkflowStage, Requirement } from '@/types/homologation'
const props = defineProps<{
  kind: 'stage' | 'requirement' | 'credential'
  stage?: WorkflowStage
  requirement?: Requirement
  stages: WorkflowStage[]
  phases: WorkflowConfiguration['phases']
  statusTypes: WorkflowConfiguration['status_types']
  phase?: ProcessPhase | null
  distributors: Distributor[]
  documentTypes: Option[]
}>()
const emit = defineEmits<{ close: []; saved: [] }>()
const r = props.requirement,
  s = props.stage
const initialPhase = s ? s.phase : props.phase === undefined ? 'PREPARATION' : props.phase
const initialType = s?.stage_type ?? props.statusTypes.find(type => type.phase === initialPhase && type.value !== 'rascunho')?.value ?? 'em_preparacao'
const form = reactive({
  code: s?.code ?? r?.code ?? '',
  name: s?.name ?? r?.name ?? '',
  order: String((s?.order ?? props.stages.length) + 1),
  phase: initialPhase ?? 'SHARED',
  stage_type: initialType,
  next: [...(s?.next ?? [])],
  active: s?.active ?? r?.active ?? true,
  distributor_id: r?.distributor_id ?? '',
  document: r?.required_document_type ?? '',
  min: r?.conditions.min_power_kw?.toString() ?? '',
  max: r?.conditions.max_power_kw?.toString() ?? '',
  modalities: [...(r?.conditions.modality ?? [])],
  generation: r?.conditions.generation_type?.[0] ?? '',
  installation: r?.conditions.installation_type?.[0] ?? '',
  battery: r?.conditions.has_battery === undefined ? '' : r.conditions.has_battery ? 'yes' : 'no',
  credential_ref: '',
  auth_type: 'portal',
  expires: '',
})
const saving = ref(false),
  error = ref<ApiError | null>(null)
const phaseOptions = computed(() => [...props.phases, { value: 'SHARED', label: 'Qualquer fase (cancelamento)' }])
const statusOptions = computed(() => props.statusTypes.filter(type => (type.phase ?? 'SHARED') === form.phase))
const terminal = computed(() => props.statusTypes.find(type => type.value === form.stage_type)?.terminal === true)
const initialStage = computed(() => s?.code === 'rascunho')
const transitionGroups = computed(() => [
  ...props.phases.map(phase => ({ ...phase, stages: props.stages.filter(target => target.phase === phase.value && (target.active || form.next.includes(target.code)) && (target.id !== s?.id || form.next.includes(target.code))) })),
  { value: 'SHARED', label: 'Cancelamento', stages: props.stages.filter(target => target.phase === null && (target.active || form.next.includes(target.code)) && (target.id !== s?.id || form.next.includes(target.code))) },
].filter(group => group.stages.length))
watch(() => form.phase, () => {
  if (!statusOptions.value.some(type => type.value === form.stage_type)) {
    form.stage_type = statusOptions.value.find(type => type.value !== 'rascunho')?.value ?? statusOptions.value[0]?.value ?? ''
  }
})
watch(terminal, value => { if (value) form.next = [] })
const modalityOptions = [
  { value: 'autoconsumo_local', label: 'Autoconsumo local' },
  { value: 'autoconsumo_remoto', label: 'Autoconsumo remoto' },
  { value: 'geracao_compartilhada', label: 'Geração compartilhada' },
  { value: 'multiplas_uc', label: 'Múltiplas UCs' },
]
async function submit() {
  saving.value = true
  error.value = null
  try {
    let endpoint = '',
      method: 'POST' | 'PUT' = 'POST',
      body: unknown
    if (props.kind === 'stage') {
      endpoint = `/workflow-stages${s ? `/${s.id}` : ''}`
      method = s ? 'PUT' : 'POST'
      body = {
        code: form.code,
        name: form.name,
        order: Number(form.order) - 1,
        stage_type: form.stage_type,
        next: terminal.value ? [] : form.next,
        active: form.active,
      }
    }
    if (props.kind === 'requirement') {
      endpoint = `/requirements${r ? `/${r.id}` : ''}`
      method = r ? 'PUT' : 'POST'
      const conditions = {
        ...(form.min ? { min_power_kw: Number(form.min.replace(',', '.')) } : {}),
        ...(form.max ? { max_power_kw: Number(form.max.replace(',', '.')) } : {}),
        ...(form.modalities.length ? { modality: form.modalities } : {}),
        ...(form.generation
          ? { generation_type: r?.conditions.generation_type?.includes(form.generation) ? r.conditions.generation_type : [form.generation] }
          : {}),
        ...(form.installation
          ? {
              installation_type: r?.conditions.installation_type?.includes(form.installation)
                ? r.conditions.installation_type
                : [form.installation],
            }
          : {}),
        ...(form.battery ? { has_battery: form.battery === 'yes' } : {}),
      }
      body = {
        code: form.code,
        name: form.name,
        distributor_id: form.distributor_id || null,
        required_document_type: form.document || null,
        conditions,
        active: form.active,
      }
    }
    if (props.kind === 'credential') {
      endpoint = '/external-credentials'
      body = {
        distributor_id: form.distributor_id,
        credential_ref: form.credential_ref,
        auth_type: form.auth_type,
        expires_at: form.expires ? new Date(form.expires).toISOString() : null,
        active: form.active,
      }
    }
    await api(endpoint, { method, body })
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    saving.value = false
  }
}
</script>
<template>
  <BaseDialog
    :title="kind === 'stage' ? stage ? 'Configurar situação' : 'Adicionar situação' : kind === 'requirement' ? 'Configurar requisito' : 'Referência de credencial'"
    size="lg"
    :submitting="saving"
    :error="error"
    @close="emit('close')"
    @submit="submit"
  >
    <ul v-if="error && Object.keys(error.errors).length" class="list-disc pl-5 text-sm text-danger">
      <li v-for="(messages, field) in error.errors" :key="field">{{ messages[0] }}</li>
    </ul>
    <FormField v-if="kind !== 'credential'" v-model="form.name" label="Nome" required />
    <template v-if="kind === 'stage'">
      <SelectField v-model="form.phase" label="Fase do processo" :options="phaseOptions" :disabled="initialStage"
        hint="Fase de entrada da situação. A execução, a vistoria e a conexão também avançam pelos registros do processo." />
      <SelectField
        v-model="form.stage_type"
        label="Situação base"
        :options="statusOptions"
        :disabled="initialStage"
        hint="Determina as validações de documentos, envio, vistoria e conexão."
      />
      <p v-if="terminal" class="rounded-lg bg-canvas p-3 text-sm text-muted">Esta situação encerra o processo e não permite novas transições.</p>
      <fieldset v-else>
        <legend class="mb-2 text-sm font-medium">Situações seguintes permitidas</legend>
        <div v-for="group in transitionGroups" :key="group.value" class="mb-3">
          <p class="mb-2 text-xs font-semibold text-muted">{{ group.label }}</p>
          <div class="grid gap-2 sm:grid-cols-2">
            <label v-for="target in group.stages" :key="target.id" class="flex items-center gap-2 text-sm">
              <input v-model="form.next" type="checkbox" :value="target.code" />
              {{ target.name }}
              <span v-if="!target.active" class="text-xs text-muted">(desabilitada; remova a seleção)</span>
            </label>
          </div>
        </div>
      </fieldset>
      <details class="rounded-lg border border-line p-3" :open="!stage">
        <summary class="cursor-pointer text-sm font-medium">Código e ordem da situação</summary>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <FormField v-model="form.code" label="Código" required :readonly="!!stage" :hint="stage ? 'O código de uma situação existente é permanente.' : 'Use letras minúsculas, números e sublinhado.'" />
          <FormField v-model="form.order" label="Ordem da situação" type="number" :min="1" :max="1001" hint="Posição das situações dentro da configuração." />
        </div>
      </details>
      <p v-if="initialStage" class="text-sm text-muted">Rascunho é a situação inicial e permanece habilitada para abrir novos processos.</p>
    </template>
    <template v-if="kind === 'requirement'">
      <FormField v-model="form.code" label="Código" required hint="Use letras minúsculas, números e sublinhado." />
      <SelectField
        v-model="form.distributor_id"
        label="Distribuidora"
        placeholder="Todas"
        :options="distributors.map((d) => ({ value: d.id, label: d.name }))"
      /><SelectField
        v-model="form.document"
        label="Documento exigido"
        placeholder="Requisito validado manualmente"
        :options="documentTypes"
      />
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField v-model="form.min" label="Potência de acesso mínima (kW)" type="number" step="any" /><FormField
          v-model="form.max"
          label="Potência de acesso máxima (kW)"
          type="number"
          step="any"
        />
      </div>
      <fieldset>
        <legend class="mb-2 text-sm font-medium">Modalidades (nenhuma marcada = todas)</legend>
        <label v-for="m in modalityOptions" :key="m.value" class="mb-2 flex items-center gap-2 text-sm"
          ><input v-model="form.modalities" type="checkbox" :value="m.value" />{{ m.label }}</label
        >
      </fieldset>
      <div class="grid gap-4 sm:grid-cols-2">
        <SelectField
          v-model="form.generation"
          label="Porte"
          placeholder="Todos"
          :options="[
            { value: 'micro', label: 'Microgeração' },
            { value: 'mini', label: 'Minigeração' },
          ]"
        /><SelectField
          v-model="form.installation"
          label="Instalação"
          placeholder="Todas"
          :options="[
            { value: 'rooftop', label: 'Telhado' },
            { value: 'ground', label: 'Solo' },
            { value: 'other', label: 'Outra' },
          ]"
        />
      </div>
      <SelectField
        v-model="form.battery"
        label="Armazenamento"
        placeholder="Com ou sem bateria"
        :options="[
          { value: 'yes', label: 'Somente com bateria' },
          { value: 'no', label: 'Somente sem bateria' },
        ]"
      />
    </template>
    <template v-if="kind === 'credential'">
      <SelectField
        v-model="form.distributor_id"
        label="Distribuidora"
        placeholder="Selecione"
        :options="distributors.map((d) => ({ value: d.id, label: d.name }))"
      /><FormField
        v-model="form.credential_ref"
        label="Referência da credencial"
        required
        hint="Identificador do cofre ou cadastro externo. Informe a referência, sem senha ou token."
      /><SelectField
        v-model="form.auth_type"
        label="Tipo de autenticação"
        :options="[
          { value: 'portal', label: 'Portal assistido' },
          { value: 'oauth2', label: 'OAuth 2' },
          { value: 'api_key', label: 'Chave de API' },
          { value: 'certificate', label: 'Certificado' },
        ]"
      /><FormField v-model="form.expires" label="Validade (opcional)" type="datetime-local" />
    </template>
    <label class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" :disabled="kind === 'stage' && initialStage" />{{ kind === 'stage' ? 'Situação habilitada' : 'Ativo' }}</label>
  </BaseDialog>
</template>
