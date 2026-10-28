<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import type { ProjectVersion, TechnicalData } from '@/types/homologation'
const props = defineProps<{ projectId: string; version: ProjectVersion; versions: ProjectVersion[] }>()
const emit = defineEmits<{ close: [] }>()
const compare = ref('')
interface Snapshot {
  project: { code: string; name: string; installed_power_kwp: string; inverter_power_kw: string }
  technical_data: TechnicalData
  documents: Array<{ id: string; document_type: string; original_name: string; version: number; sha256: string }>
}
const source = computed(() => ({ id: props.version.id, compare: compare.value }))
const { data, error } = useApiQuery(
  source,
  async (s) =>
    (
      await api<{ data: { snapshot: Snapshot; changes?: Array<{ field: string; before: unknown; after: unknown }> } }>(
        `/projects/${props.projectId}/versions/${s.id}`,
        { query: { compare_to: s.compare || undefined } },
      )
    ).data,
)
</script>
<template>
  <BaseDialog
    :title="`Versão ${version.version} · ${version.change_reason}`"
    size="xl"
    submit-label="Fechar"
    :error="error"
    @submit="emit('close')"
    @close="emit('close')"
  >
    <p class="break-all text-xs text-muted">SHA-256: {{ version.sha256 }}</p>
    <template v-if="data">
      <p class="text-sm">
        {{ data.snapshot.project.name || data.snapshot.project.code }} · {{ data.snapshot.project.installed_power_kwp }} kWp ·
        {{ data.snapshot.project.inverter_power_kw }} kW de inversores
      </p>
      <h3 class="font-medium">Documentos congelados</h3>
      <ul class="space-y-2 text-sm">
        <li v-for="doc in data.snapshot.documents" :key="doc.id">
          <p>{{ doc.original_name }} · v{{ doc.version }}</p>
          <p class="break-all text-xs text-muted">{{ doc.sha256 }}</p>
        </li>
      </ul>
      <SelectField
        v-model="compare"
        label="Comparar com outra versão"
        placeholder="Selecione"
        :options="versions.filter((v) => v.id !== version.id).map((v) => ({ value: v.id, label: `v${v.version} · ${v.change_reason}` }))"
      />
      <p v-if="compare && !data.changes?.length" class="text-sm text-muted">Nenhuma diferença encontrada.</p>
      <div v-if="data.changes?.length" class="overflow-x-auto">
        <table class="w-full text-left text-xs">
          <thead>
            <tr>
              <th class="p-2">Campo</th>
              <th class="p-2">Versão comparada</th>
              <th class="p-2">Esta versão</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="change in data.changes" :key="change.field" class="border-t border-line">
              <td class="p-2">{{ change.field }}</td>
              <td class="p-2">{{ change.before ?? '—' }}</td>
              <td class="p-2">{{ change.after ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </BaseDialog>
</template>
