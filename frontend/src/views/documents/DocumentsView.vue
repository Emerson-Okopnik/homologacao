<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { Download } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import SelectField from '@/components/ui/SelectField.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ReviewDialog from '@/views/processes/ReviewDialog.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { useDebouncedRef } from '@/composables/useDebouncedRef'
import { api, buildUrl } from '@/lib/http'
import { formatBytes, formatDateTime, statusTone } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { Paginated, ProcessDocument } from '@/types/api'

const auth = useAuthStore()
const search = ref('')
const debounced = useDebouncedRef(search, 300)
const filters = reactive({ review_status: 'pendente', page: 1, version: 0 })
watch([debounced, () => filters.review_status], () => (filters.page = 1))

const source = computed(() => ({ search: debounced.value || undefined, review_status: filters.review_status || undefined, page: filters.page, v: filters.version }))
const { data, error, loading } = useApiQuery(source, ({ v: _v, ...query }) => api<Paginated<ProcessDocument>>('/documents', { query }))

const reviewing = shallowRef<ProcessDocument | null>(null)
function onReviewed() {
  reviewing.value = null
  filters.version++
}
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <PageHeader title="Documentos" description="Versões vigentes de todos os processos. Revise o que chegou antes do envio à distribuidora." />

    <div class="overflow-hidden rounded-2xl border border-line bg-surface">
      <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-end">
        <div class="sm:w-80">
          <SearchInput v-model="search" label="Buscar documentos" placeholder="Arquivo, processo ou cliente" />
        </div>
        <div class="sm:w-52">
          <SelectField
            v-model="filters.review_status"
            label="Revisão"
            placeholder="Todos"
            :options="[
              { value: 'pendente', label: 'Aguardando revisão' },
              { value: 'aprovado', label: 'Aprovados' },
              { value: 'reprovado', label: 'Reprovados' },
            ]"
          />
        </div>
      </div>

      <div v-if="error" class="p-4">
        <InlineAlert :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm" :aria-busy="loading">
          <thead class="bg-canvas text-xs font-semibold uppercase tracking-wider text-muted">
            <tr>
              <th scope="col" class="px-4 py-3">Documento</th>
              <th scope="col" class="px-4 py-3">Processo</th>
              <th scope="col" class="px-4 py-3">Enviado</th>
              <th scope="col" class="px-4 py-3">Revisão</th>
              <th scope="col" class="px-4 py-3"><span class="sr-only">Ações</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="doc in data?.data ?? []" :key="doc.id" :class="loading && 'opacity-60'">
              <td class="px-4 py-3">
                <p class="font-medium">{{ doc.type_label }} <span class="text-xs text-muted">v{{ doc.version }}</span></p>
                <p class="max-w-xs truncate text-xs text-muted">{{ doc.original_name }} · {{ formatBytes(doc.size_bytes) }}</p>
              </td>
              <td class="px-4 py-3">
                <RouterLink v-if="doc.process" :to="`/processos/${doc.process.id}`" class="font-mono text-xs font-medium hover:text-primary hover:underline">
                  {{ doc.process.code }}
                </RouterLink>
                <RouterLink v-else-if="doc.project" :to="`/projetos/${doc.project.id}/dados-tecnicos`" class="text-xs text-primary">{{ doc.project.code }} · Documento do projeto</RouterLink>
                <p class="text-xs text-muted">{{ doc.process?.client ?? doc.project?.client }}</p>
              </td>
              <td class="px-4 py-3 text-xs text-muted">
                {{ formatDateTime(doc.created_at) }}
                <p>{{ doc.uploaded_by }}</p>
              </td>
              <td class="px-4 py-3">
                <StatusBadge :tone="statusTone(doc.review_status)" class="capitalize">{{ doc.review_status }}</StatusBadge>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-1">
                  <a
                    :href="buildUrl(`/documents/${doc.id}/download`)"
                    target="_blank"
                    rel="noopener"
                    class="rounded-lg p-2 text-muted hover:bg-canvas hover:text-ink"
                  >
                    <Download class="size-4" aria-hidden="true" />
                    <span class="sr-only">Baixar {{ doc.original_name }}</span>
                  </a>
                  <BaseButton v-if="doc.review_status === 'pendente' && auth.can('documents.manage')" variant="ghost" @click="reviewing = doc">
                    Revisar
                  </BaseButton>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && data && data.data.length === 0">
              <td colspan="5" class="px-4 py-12 text-center text-muted">Nenhum documento encontrado.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="data" :meta="data.meta" @change="filters.page = $event" />
    </div>

    <ReviewDialog v-if="reviewing" :document="reviewing" @close="reviewing = null" @saved="onReviewed" />
  </div>
</template>
