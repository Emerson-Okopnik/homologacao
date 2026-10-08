<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { Distributor, Option } from '@/types/api'
import type { WorkflowStage, Requirement } from '@/types/homologation'
const props = defineProps<{
  kind: 'stage' | 'requirement' | 'credential'
  stage?: WorkflowStage
  requirement?: Requirement
  stages: WorkflowStage[]
  distributors: Distributor[]
  documentTypes: Option[]
}>()
const emit = defineEmits<{ close: []; saved: [] }>()
const r = props.requirement,
  s = props.stage
const form = reactive({
  code: s?.code ?? r?.code ?? '',
  name: s?.name ?? r?.name ?? '',
  order: String(s?.order ?? props.stages.length),
  stage_type: s?.stage_type ?? 'em_preparacao',
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
const macros = [
  { value: 'rascunho', label: 'Rascunho' },
  { value: 'em_preparacao', label: 'Preparação' },
  { value: 'pronto_para_envio', label: 'Pronto para envio' },
  { value: 'enviado', label: 'Enviado' },
  { value: 'em_analise', label: 'Em análise' },
  { value: 'pendencia_distribuidora', label: 'Pendência da distribuidora' },
  { value: 'aprovado', label: 'Parecer aprovado' },
  { value: 'vistoria_solicitada', label: 'Vistoria solicitada' },
  { value: 'conectado', label: 'Conectado' },
  { value: 'reprovado', label: 'Reprovado' },
  { value: 'cancelado', label: 'Cancelado' },
]
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
        order: Number(form.order),
        stage_type: form.stage_type,
        next: form.next,
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
    :title="kind === 'stage' ? 'Configurar etapa' : kind === 'requirement' ? 'Configurar requisito' : 'Referência de credencial'"
    size="lg"
    :submitting="saving"
    :error="error"
    @close="emit('close')"
    @submit="submit"
  >
    <ul v-if="error && Object.keys(error.errors).length" class="list-disc pl-5 text-sm text-danger">
      <li v-for="(messages, field) in error.errors" :key="field">{{ messages[0] }}</li>
    </ul>
    <template v-if="kind !== 'credential'"
      ><FormField v-model="form.name" label="Nome" required /><FormField
        v-model="form.code"
        label="Código"
        required
        :readonly="!!stage"
        hint="Use letras minúsculas, números e sublinhado."
    /></template>
    <template v-if="kind === 'stage'">
      <FormField v-model="form.order" label="Ordem no fluxo" type="number" /><SelectField
        v-model="form.stage_type"
        label="Natureza da etapa"
        :options="macros"
        hint="As validações de envio e conexão seguem a natureza selecionada."
      />
      <fieldset>
        <legend class="mb-2 text-sm font-medium">Etapas seguintes permitidas</legend>
        <div class="grid gap-2 sm:grid-cols-2">
          <label
            v-for="target in stages.filter((t) => t.active && t.id !== stage?.id)"
            :key="target.id"
            class="flex items-center gap-2 text-sm"
            ><input v-model="form.next" type="checkbox" :value="target.code" />{{ target.name }}</label
          >
        </div>
      </fieldset>
    </template>
    <template v-if="kind === 'requirement'">
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
    <label class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" />Ativo</label>
  </BaseDialog>
</template>
