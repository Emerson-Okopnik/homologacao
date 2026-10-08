<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import WorkflowConfigurationDialog from './WorkflowConfigurationDialog.vue'
import { api } from '@/lib/http'
import { useApiQuery } from '@/composables/useApiQuery'
import { useAuthStore } from '@/stores/auth'
import type { Distributor, Option } from '@/types/api'
import type { WorkflowConfiguration, WorkflowStage, Requirement } from '@/types/homologation'
defineProps<{ distributors: Distributor[]; documentTypes: Option[] }>()
const emit = defineEmits<{ saved: [] }>()
const auth = useAuthStore(),
  revision = ref(0),
  dialog = ref<{ kind: 'stage' | 'requirement' | 'credential'; stage?: WorkflowStage; requirement?: Requirement } | null>(null)
const { data, error } = useApiQuery(
  computed(() => revision.value),
  async () => (await api<{ data: WorkflowConfiguration }>('/workflow-configuration')).data,
)
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
        <header class="mb-4 flex justify-between">
          <h2 class="font-semibold">Configuração das etapas</h2>
          <BaseButton v-if="auth.can('workflow.configure')" variant="secondary" @click="dialog = { kind: 'stage' }">Nova etapa</BaseButton>
        </header>
        <ul class="divide-y divide-line">
          <li v-for="s in data.stages" :key="s.id" class="flex items-center justify-between gap-3 py-3 text-sm">
            <span>{{ s.order + 1 }}. {{ s.name }} · {{ s.active ? 'Ativa' : 'Inativa' }}</span
            ><BaseButton v-if="auth.can('workflow.configure')" variant="ghost" @click="dialog = { kind: 'stage', stage: s }"
              >Editar</BaseButton
            >
          </li>
        </ul>
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
        :distributors="distributors"
        :document-types="documentTypes"
        @close="dialog = null"
        @saved="saved"
      />
    </template>
  </div>
</template>
