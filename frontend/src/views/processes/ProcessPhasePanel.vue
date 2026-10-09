<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import SelectField from '@/components/ui/SelectField.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ProcessActionDialog from './ProcessActionDialog.vue'
import DocumentUploadDialog from '@/views/documents/DocumentUploadDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import { formatDate } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { HomologationProcess, ProcessActionKey, StageCatalog, ChecklistItem } from '@/types/api'

const props = defineProps<{ process: HomologationProcess; mode: 'requirements' | 'actions' }>()
const emit = defineEmits<{ saved: [] }>()
const auth = useAuthStore()
const action = ref<ProcessActionKey | null>(null)
const uploading = shallowRef<{ type: string; owner: { type: string; id: string } } | null>(null)
const equipment = ref('')
const { data: catalog } = useApiQuery(() => 'stage-catalog', async () => (await api<{ data: StageCatalog }>('/process-stages')).data)
const labels: Partial<Record<ProcessActionKey, string>> = {
  register_correction: 'Registrar exigências', approve_access: 'Aprovar parecer de acesso', update_network_work: 'Atualizar obra de rede',
  report_execution: 'Informar execução', request_inspection: 'Registrar vistoria solicitada', record_inspection: 'Resultado da vistoria',
  record_connection_event: 'Evento de conexão', complete: 'Concluir processo',
}
const actions = computed(() => Object.entries(labels).map(([key, label]) => ({ key: key as ProcessActionKey, label, gate: props.process.actions?.[key as ProcessActionKey] })).filter(a => a.gate?.available))
const equipmentOptions = computed(() => (props.process.project?.equipment ?? []).map(e => ({ value: e.id, label: `${e.manufacturer} ${e.model}` })))
function ownerFor(item: ChecklistItem): { type: string; id: string } | null {
  switch (item.document_owner) {
    case 'equipment': return equipment.value ? { type: 'equipment', id: equipment.value } : null
    case 'execution': return props.process.execution ? { type: 'execution', id: props.process.execution.id } : null
    case 'inspection': return { type: 'process', id: props.process.id }
    case 'connection_event': return props.process.connection_events?.[0] ? { type: 'connection_event', id: props.process.connection_events[0].id } : null
    default: return { type: 'process', id: props.process.id }
  }
}
function upload(item: ChecklistItem) {
  const owner = ownerFor(item)
  if (owner && item.document_type) uploading.value = { type: item.document_type, owner }
}
function saved() { action.value = null; uploading.value = null; emit('saved') }
</script>

<template>
  <section class="mt-6 rounded-2xl border border-line bg-surface p-6" aria-labelledby="sec-phase">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 id="sec-phase" class="font-semibold">{{ mode === 'requirements' ? 'Requisitos da fase' : 'Execução, vistoria e conexão' }}</h2>
      <StatusBadge>{{ process.stage_label }}</StatusBadge>
    </div>
    <p v-if="mode === 'actions' && !['PREPARATION', 'CORRECTION'].includes(process.stage)" class="mt-2 text-sm text-muted">Obra de rede: {{ process.network_work_label }}</p>
    <p v-if="mode === 'actions' && process.open_deadline" class="mt-1 text-sm" :class="process.open_deadline.overdue ? 'text-danger' : 'text-muted'">
      {{ process.open_deadline.label }} · prazo {{ formatDate(process.open_deadline.due_at) }}
    </p>
    <p v-if="mode === 'actions' && process.execution" class="mt-1 text-sm text-muted">Instalação concluída em {{ formatDate(process.execution.completed_at) }}</p>
    <div v-if="mode === 'actions' && auth.can('homologations.manage')" class="mt-4 flex flex-wrap gap-2">
      <BaseButton v-for="item in actions" :key="item.key" variant="secondary" @click="action = item.key">{{ item.label }}</BaseButton>
    </div>
    <template v-if="mode === 'requirements' && process.phase_checklist?.items.length">
      <p class="mt-5 text-sm font-medium">{{ process.phase_checklist.label }} · {{ process.phase_checklist.satisfied }}/{{ process.phase_checklist.total }} requisitos atendidos</p>
      <SelectField v-if="process.phase_checklist.items.some(i => i.document_owner === 'equipment')" v-model="equipment" class="mt-3 max-w-sm" label="Equipamento para os anexos do catálogo" :options="equipmentOptions" />
      <ul class="mt-3 divide-y divide-line">
        <li v-for="item in process.phase_checklist.items" :key="item.code" class="flex flex-wrap items-center gap-3 py-3 text-sm">
          <div class="min-w-0 flex-1">
            <p class="font-medium">{{ item.label }}</p>
            <p v-if="item.detail" class="text-xs text-muted">{{ item.detail }}</p>
          </div>
          <StatusBadge :tone="item.status === 'PENDING' ? 'warning' : 'success'">{{ item.status === 'SATISFIED' ? 'Atendido' : item.status === 'WAIVED' ? 'Dispensado' : 'Pendente' }}</StatusBadge>
          <BaseButton v-if="item.document_type && item.status === 'PENDING' && ownerFor(item) && auth.can('documents.manage')" variant="ghost" @click="upload(item)">Anexar</BaseButton>
        </li>
      </ul>
      <RouterLink to="/documentos" class="mt-2 inline-block text-sm text-primary">Revisar os documentos enviados</RouterLink>
    </template>
    <ProcessActionDialog v-if="action" :process="process" :action="action" :catalog="catalog" @close="action = null" @saved="saved" />
    <DocumentUploadDialog v-if="uploading" endpoint="/documents" :initial-type="uploading.type" :owner="uploading.owner" @close="uploading = null" @saved="saved" />
  </section>
</template>
