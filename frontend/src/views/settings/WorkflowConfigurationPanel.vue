<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import WorkflowConfigurationDialog from './WorkflowConfigurationDialog.vue'
import { api } from '@/lib/http'
import { useApiQuery } from '@/composables/useApiQuery'
import { useAuthStore } from '@/stores/auth'
import type { Distributor, Option, WorkflowStage as ProcessPhase } from '@/types/api'
import type { WorkflowConfiguration, WorkflowStage, Requirement } from '@/types/homologation'
defineProps<{ distributors: Distributor[]; documentTypes: Option[] }>()
const emit = defineEmits<{ saved: [] }>()
const auth = useAuthStore(),
  revision = ref(0),
  dialog = ref<{ kind: 'stage' | 'requirement' | 'credential'; stage?: WorkflowStage; requirement?: Requirement; phase?: ProcessPhase | null } | null>(null)
const { data, error } = useApiQuery(
  computed(() => revision.value),
  async () => (await api<{ data: WorkflowConfiguration }>('/workflow-configuration')).data,
)
const phases = computed(() => (data.value?.phases ?? []).map(phase => ({
  ...phase,
  stages: (data.value?.stages ?? []).filter(stage => stage.phase === phase.value),
})))
const sharedStages = computed(() => (data.value?.stages ?? []).filter(stage => stage.phase === null))
const labelOf = computed(() => new Map((data.value?.stages ?? []).map(stage => [stage.code, stage.name])))
function saved() {
  dialog.value = null
  revision.value++
  emit('saved')
}
</script>
<template>
  <div class="space-y-6">
    <InlineAlert v-if="error">{{ error.message }}</InlineAlert>
    <template v-if="data">
      <section class="rounded-2xl border border-line bg-surface p-5">
        <h2 class="font-semibold">Configuração das etapas</h2>
        <p class="mt-2 text-sm text-muted">As seis fases seguem o Dashboard e o Kanban. Abra uma fase para configurar suas situações e transições.</p>
        <div class="mt-5 divide-y divide-line">
          <details v-for="(phase, index) in phases" :key="phase.value" class="py-4" :data-phase="phase.value">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-primary">
              <div class="flex min-w-0 items-center gap-3">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-semibold text-primary">{{ index + 1 }}</span>
                <div>
                  <h3 class="text-sm font-semibold">{{ phase.label }}</h3>
                  <p class="mt-1 text-xs text-muted">{{ phase.stages.filter(s => s.active).length }} de {{ phase.stages.length }} {{ phase.stages.length === 1 ? 'situação habilitada' : 'situações habilitadas' }}</p>
                </div>
              </div>
              <span class="text-sm font-medium text-primary">{{ auth.can('workflow.configure') ? 'Configurar' : 'Ver situações' }}</span>
            </summary>
            <div class="mt-4 rounded-xl border border-line bg-canvas p-4">
              <ul class="divide-y divide-line">
                <li v-for="s in phase.stages" :key="s.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0 text-sm">
                    <p class="font-medium">{{ s.name }} <span class="ml-2 text-xs font-normal text-muted">{{ s.active ? 'Habilitada' : 'Desabilitada' }}<template v-if="s.terminal"> · Encerra o processo</template></span></p>
                    <p class="mt-1 text-xs text-muted">{{ s.next.length ? 'Pode seguir para: ' + s.next.map(code => labelOf.get(code) ?? code).join(', ') : 'Sem transições configuradas' }}</p>
                  </div>
                  <BaseButton v-if="auth.can('workflow.configure')" variant="ghost" :aria-label="`Editar situação ${s.name}`" @click="dialog = { kind: 'stage', stage: s }">Editar</BaseButton>
                </li>
              </ul>
              <p v-if="!phase.stages.length" class="text-sm text-muted">Nenhuma situação configurada para esta fase.</p>
              <BaseButton v-if="auth.can('workflow.configure')" class="mt-3" variant="secondary" @click="dialog = { kind: 'stage', phase: phase.value }">Adicionar situação</BaseButton>
            </div>
          </details>
        </div>
        <details v-if="sharedStages.length" class="mt-2 border-t border-line pt-4">
          <summary class="cursor-pointer text-sm font-medium text-muted">Cancelamento em qualquer fase</summary>
          <ul class="mt-3 divide-y divide-line">
            <li v-for="s in sharedStages" :key="s.id" class="flex items-center justify-between gap-3 py-3 text-sm">
              <p>{{ s.name }} <span class="text-xs text-muted">· {{ s.active ? 'Habilitada' : 'Desabilitada' }} · Encerra o processo</span></p>
              <BaseButton v-if="auth.can('workflow.configure')" variant="ghost" :aria-label="`Editar situação ${s.name}`" @click="dialog = { kind: 'stage', stage: s }">Editar</BaseButton>
            </li>
          </ul>
        </details>
      </section>
      <section class="rounded-2xl border border-line bg-surface p-5">
        <header class="mb-4 flex justify-between">
          <h2 class="font-semibold">Requisitos do dossiê</h2>
          <BaseButton v-if="auth.can('requirements.configure')" variant="secondary" @click="dialog = { kind: 'requirement' }"
            >Novo requisito</BaseButton
          >
        </header>
        <ul class="divide-y divide-line">
          <li v-for="r in data.requirements" :key="r.id" class="flex items-center justify-between gap-3 py-3 text-sm">
            <div>
              <p>{{ r.name }} · {{ r.active ? 'Ativo' : 'Inativo' }}</p>
              <p class="text-xs text-muted">
                {{ r.distributor_id ? distributors.find((d) => d.id === r.distributor_id)?.name : 'Todas as distribuidoras' }} ·
                {{ r.required_document_type ? 'Revisão documental' : 'Validação manual' }}
              </p>
            </div>
            <BaseButton v-if="auth.can('requirements.configure')" variant="ghost" @click="dialog = { kind: 'requirement', requirement: r }"
              >Editar</BaseButton
            >
          </li>
        </ul>
      </section>
      <section class="rounded-2xl border border-line bg-surface p-5">
        <header class="mb-4 flex justify-between">
          <h2 class="font-semibold">Referências de credenciais</h2>
          <BaseButton v-if="auth.can('integrations.configure')" variant="secondary" @click="dialog = { kind: 'credential' }"
            >Cadastrar referência</BaseButton
          >
        </header>
        <p class="mb-3 text-sm text-muted">
          Os envios operam pelo portal no modo assistido. O uso de API depende da documentação e do acesso oficial da distribuidora.
        </p>
        <ul class="text-sm">
          <li v-for="c in data.credentials" :key="c.id" class="py-2">
            {{ distributors.find((d) => d.id === c.distributor_id)?.name }} · {{ c.credential_ref }} · {{ c.active ? 'Ativa' : 'Inativa' }}
          </li>
        </ul>
      </section>
      <WorkflowConfigurationDialog
        v-if="dialog"
        v-bind="dialog"
        :stages="data.stages"
        :phases="data.phases"
        :status-types="data.status_types"
        :distributors="distributors"
        :document-types="documentTypes"
        @close="dialog = null"
        @saved="saved"
      />
    </template>
  </div>
</template>
