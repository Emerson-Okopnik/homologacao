<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { Plus } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import DistributorDialog from './DistributorDialog.vue'
import EquipmentDialog from './EquipmentDialog.vue'
import ResponsibleDialog from './ResponsibleDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import { formatNumber } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { Distributor, Equipment, Paginated, TechnicalResponsible } from '@/types/api'

type Tab = 'responsibles' | 'equipment' | 'distributors'

const auth = useAuthStore()
const tabs = computed(() =>
  [
    { id: 'responsibles' as const, label: 'Responsáveis técnicos', show: auth.can('projects.view') },
    { id: 'equipment' as const, label: 'Equipamentos', show: auth.can('projects.view') },
    { id: 'distributors' as const, label: 'Distribuidoras', show: true },
  ].filter((t) => t.show),
)
const active = ref<Tab>(tabs.value[0]?.id ?? 'distributors')
const version = ref(0)

const source = computed(() => ({ tab: active.value, version: version.value }))
const { data, error, loading } = useApiQuery(source, async (s) => {
  if (s.tab === 'responsibles') return { kind: s.tab, items: (await api<{ data: TechnicalResponsible[] }>('/technical-responsibles')).data }
  if (s.tab === 'equipment')
    return { kind: s.tab, items: (await api<Paginated<Equipment>>('/equipment', { query: { per_page: 100 } })).data }
  return { kind: s.tab, items: (await api<{ data: Distributor[] }>('/distributors')).data }
})

const responsibles = computed(() => (data.value?.kind === 'responsibles' ? (data.value.items as TechnicalResponsible[]) : []))
const equipment = computed(() => (data.value?.kind === 'equipment' ? (data.value.items as Equipment[]) : []))
const distributors = computed(() => (data.value?.kind === 'distributors' ? (data.value.items as Distributor[]) : []))

const responsibleDialog = shallowRef<{ item: TechnicalResponsible | null } | null>(null)
const equipmentDialog = shallowRef<{ item: Equipment | null } | null>(null)
const distributorDialog = shallowRef<Distributor | null>(null)

const equipmentLabels = { module: 'Módulo', inverter: 'Inversor', battery: 'Bateria' }

function saved() {
  responsibleDialog.value = null
  equipmentDialog.value = null
  distributorDialog.value = null
  version.value++
}
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <PageHeader title="Cadastros técnicos" description="Responsáveis técnicos, catálogo de equipamentos e distribuidoras atendidas.">
      <template #actions>
        <BaseButton v-if="active === 'responsibles' && auth.can('technical_responsibles.manage')" @click="responsibleDialog = { item: null }">
          <Plus class="size-4" aria-hidden="true" /> Novo responsável
        </BaseButton>
        <BaseButton v-if="active === 'equipment' && auth.can('projects.manage')" @click="equipmentDialog = { item: null }">
          <Plus class="size-4" aria-hidden="true" /> Novo equipamento
        </BaseButton>
      </template>
    </PageHeader>

    <div role="tablist" aria-label="Tipo de cadastro" class="mb-4 flex gap-1 rounded-xl border border-line bg-surface p-1 sm:w-fit">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        role="tab"
        type="button"
        :aria-selected="active === tab.id"
        class="flex-1 rounded-lg px-4 py-2 text-sm font-medium whitespace-nowrap transition-colors"
        :class="active === tab.id ? 'bg-primary text-white' : 'text-muted hover:text-ink'"
        @click="active = tab.id"
      >
        {{ tab.label }}
      </button>
    </div>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>

    <div v-else class="overflow-x-auto rounded-2xl border border-line bg-surface" role="tabpanel">
      <table v-if="active === 'responsibles'" class="w-full text-left text-sm" :aria-busy="loading">
        <thead class="bg-canvas text-xs font-semibold uppercase tracking-wider text-muted">
          <tr>
            <th scope="col" class="px-4 py-3">Nome</th>
            <th scope="col" class="px-4 py-3">Registro</th>
            <th scope="col" class="px-4 py-3">Contato</th>
            <th scope="col" class="px-4 py-3 text-right">Projetos</th>
            <th scope="col" class="px-4 py-3">Situação</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="rt in responsibles" :key="rt.id">
            <td class="px-4 py-3 font-medium">{{ rt.name }}</td>
            <td class="px-4 py-3 tabular-nums">{{ rt.council }}-{{ rt.state }} {{ rt.registration }}</td>
            <td class="px-4 py-3 text-muted">{{ rt.email ?? rt.phone ?? '—' }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ rt.projects_count ?? 0 }}</td>
            <td class="px-4 py-3">
              <StatusBadge :tone="rt.active && rt.registration_status === 'regular' ? 'success' : 'warning'">
                {{ !rt.active ? 'Inativo' : rt.registration_status === 'regular' ? 'Regular' : 'Irregular' }}
              </StatusBadge>
            </td>
            <td class="px-4 py-3 text-right">
              <BaseButton v-if="auth.can('technical_responsibles.manage')" variant="ghost" @click="responsibleDialog = { item: rt }">
                Editar<span class="sr-only"> {{ rt.name }}</span>
              </BaseButton>
            </td>
          </tr>
          <tr v-if="!loading && responsibles.length === 0">
            <td colspan="6" class="px-4 py-12 text-center text-muted">Nenhum responsável técnico cadastrado.</td>
          </tr>
        </tbody>
      </table>

      <table v-else-if="active === 'equipment'" class="w-full text-left text-sm" :aria-busy="loading">
        <thead class="bg-canvas text-xs font-semibold uppercase tracking-wider text-muted">
          <tr>
            <th scope="col" class="px-4 py-3">Tipo</th>
            <th scope="col" class="px-4 py-3">Fabricante / modelo</th>
            <th scope="col" class="px-4 py-3 text-right">Potência</th>
            <th scope="col" class="px-4 py-3">Certificação</th>
            <th scope="col" class="px-4 py-3">Situação</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="item in equipment" :key="item.id">
            <td class="px-4 py-3">{{ equipmentLabels[item.type] }}</td>
            <td class="px-4 py-3">
              <span class="font-medium">{{ item.manufacturer }}</span>
              <span class="text-muted"> · {{ item.model }}</span>
            </td>
            <td class="px-4 py-3 text-right tabular-nums">
              {{ item.type === 'battery' ? formatNumber(item.energy_kwh, 'kWh') : formatNumber(item.power_w, 'W') }}
            </td>
            <td class="px-4 py-3 text-muted">{{ item.certification ?? '—' }}</td>
            <td class="px-4 py-3">
              <StatusBadge :tone="item.active ? 'success' : 'neutral'">{{ item.active ? 'Ativo' : 'Inativo' }}</StatusBadge>
            </td>
            <td class="px-4 py-3 text-right">
              <BaseButton v-if="auth.can('projects.manage')" variant="ghost" @click="equipmentDialog = { item }">
                Editar<span class="sr-only"> {{ item.model }}</span>
              </BaseButton>
            </td>
          </tr>
          <tr v-if="!loading && equipment.length === 0">
            <td colspan="6" class="px-4 py-12 text-center text-muted">Nenhum equipamento cadastrado.</td>
          </tr>
        </tbody>
      </table>

      <table v-else class="w-full text-left text-sm" :aria-busy="loading">
        <thead class="bg-canvas text-xs font-semibold uppercase tracking-wider text-muted">
          <tr>
            <th scope="col" class="px-4 py-3">Distribuidora</th>
            <th scope="col" class="px-4 py-3">UF</th>
            <th scope="col" class="px-4 py-3">Integração</th>
            <th scope="col" class="px-4 py-3">Credencial</th>
            <th scope="col" class="px-4 py-3">Situação</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="d in distributors" :key="d.id">
            <td class="px-4 py-3">
              <span class="font-medium">{{ d.name }}</span>
              <a v-if="d.portal_url" :href="d.portal_url" target="_blank" rel="noopener noreferrer" class="block text-xs text-primary hover:underline">
                Portal da distribuidora
              </a>
            </td>
            <td class="px-4 py-3">{{ d.state }}</td>
            <td class="px-4 py-3">{{ d.integration_mode_label }}</td>
            <td class="px-4 py-3">
              <StatusBadge :tone="d.has_credential ? 'success' : 'neutral'">{{ d.has_credential ? 'Configurada' : 'Não configurada' }}</StatusBadge>
            </td>
            <td class="px-4 py-3">
              <StatusBadge :tone="d.active ? 'success' : 'neutral'">{{ d.active ? 'Ativa' : 'Inativa' }}</StatusBadge>
            </td>
            <td class="px-4 py-3 text-right">
              <BaseButton v-if="auth.can('integrations.configure')" variant="ghost" @click="distributorDialog = d">
                Configurar<span class="sr-only"> {{ d.name }}</span>
              </BaseButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <ResponsibleDialog v-if="responsibleDialog" :item="responsibleDialog.item" @close="responsibleDialog = null" @saved="saved" />
    <EquipmentDialog v-if="equipmentDialog" :item="equipmentDialog.item" @close="equipmentDialog = null" @saved="saved" />
    <DistributorDialog v-if="distributorDialog" :item="distributorDialog" @close="distributorDialog = null" @saved="saved" />
  </div>
</template>
