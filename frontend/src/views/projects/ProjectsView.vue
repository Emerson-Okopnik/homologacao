<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { Plus } from '@lucide/vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { useDebouncedRef } from '@/composables/useDebouncedRef'
import { api } from '@/lib/http'
import { formatDate, formatNumber, stageTone } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { Paginated, Project } from '@/types/api'

const auth = useAuthStore()
const search = ref('')
const debounced = useDebouncedRef(search, 300)
const filters = reactive({ page: 1 })
watch(debounced, () => (filters.page = 1))

const source = computed(() => ({ search: debounced.value, page: filters.page }))
const { data, error, loading } = useApiQuery(source, (s) => api<Paginated<Project>>('/projects', { query: s }))
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <PageHeader title="Projetos" description="Sistemas fotovoltaicos dimensionados e vinculados a uma unidade consumidora.">
      <template v-if="auth.can('projects.manage')" #actions>
        <RouterLink
          to="/projetos/novo"
          class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover"
        >
          <Plus class="size-4" aria-hidden="true" />
          Novo projeto
        </RouterLink>
      </template>
    </PageHeader>

    <div class="overflow-hidden rounded-2xl border border-line bg-surface">
      <div class="border-b border-line p-4">
        <SearchInput v-model="search" label="Buscar projetos" placeholder="Código, cliente ou UC" />
      </div>

      <div v-if="error" class="p-4">
        <InlineAlert :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm" :aria-busy="loading">
          <thead class="bg-canvas text-xs font-semibold uppercase tracking-wider text-muted">
            <tr>
              <th scope="col" class="px-4 py-3">Projeto</th>
              <th scope="col" class="px-4 py-3">Cliente / UC</th>
              <th scope="col" class="px-4 py-3 text-right">Potência</th>
              <th scope="col" class="px-4 py-3">Modalidade</th>
              <th scope="col" class="px-4 py-3">Processo</th>
              <th scope="col" class="px-4 py-3">Criado em</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="project in data?.data ?? []" :key="project.id" :class="loading && 'opacity-60'">
              <td class="px-4 py-3">
                <RouterLink :to="`/projetos/${project.id}`" class="font-mono font-medium hover:text-primary hover:underline">
                  {{ project.code }}
                </RouterLink>
                <p class="text-xs text-muted">{{ project.classification_label }}</p>
              </td>
              <td class="px-4 py-3">
                <p class="font-medium">{{ project.client?.name }}</p>
                <p class="text-xs text-muted">UC {{ project.consumer_unit?.number }} · {{ project.consumer_unit?.distributor?.name }}</p>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">
                {{ formatNumber(project.considered_power_kw, 'kW') }}
                <p class="text-xs text-muted">módulos {{ formatNumber(project.modules_power_kwp, 'kWp') }}</p>
              </td>
              <td class="px-4 py-3">{{ project.compensation_mode_label }}</td>
              <td class="px-4 py-3">
                <RouterLink v-if="project.process" :to="`/processos/${project.process.id}`" class="hover:opacity-80">
                  <StatusBadge :tone="stageTone(project.process.stage, project.process.status)">
                    {{ !['conectado', 'cancelado'].includes(project.process.status) ? project.process.stage_label : project.process.status_label }}
                  </StatusBadge>
                </RouterLink>
              </td>
              <td class="px-4 py-3 text-muted tabular-nums">{{ formatDate(project.created_at) }}</td>
            </tr>
            <tr v-if="!loading && data && data.data.length === 0">
              <td colspan="6" class="px-4 py-12 text-center text-muted">Nenhum projeto encontrado.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="data" :meta="data.meta" @change="filters.page = $event" />
    </div>
  </div>
</template>
