<script setup lang="ts">
import { computed, ref } from 'vue'
import { Download, FileText, History, Plus } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import DocumentUploadDialog from './DocumentUploadDialog.vue'
import ReviewDialog from '@/views/processes/ReviewDialog.vue'
import { buildUrl } from '@/lib/http'
import { formatDate, formatDateTime, formatBytes, statusTone } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { ProcessDocument } from '@/types/api'
const props = defineProps<{ endpoint: string; documents: ProcessDocument[]; editable?: boolean }>()
const emit = defineEmits<{ saved: [] }>()
const uploading = ref(false),
  history = ref(false),
  reviewing = ref<ProcessDocument | null>(null)
const auth = useAuthStore()
const visible = computed(() => props.documents.filter((d) => history.value || d.is_current))
const currentDocuments = computed(() => props.documents.filter((document) => document.is_current))
const approvedCount = computed(() => currentDocuments.value.filter((document) => document.review_status === 'aprovado').length)
const reviewLabels = { pendente: 'Aguardando revisão', aprovado: 'Aprovado', reprovado: 'Ajuste solicitado' }
function saved() {
  uploading.value = false
  reviewing.value = null
  emit('saved')
}
</script>
<template>
  <section class="vg-panel p-5 sm:p-6">
    <header class="mb-5 flex flex-wrap items-center justify-between gap-4">
      <div><h2 class="text-sm font-semibold">Documentos e evidências</h2><p class="mt-2 text-xs text-muted">{{ currentDocuments.length }} documentos vigentes · {{ approvedCount }} aprovados</p></div>
      <div class="flex gap-2">
        <BaseButton variant="ghost" @click="history = !history"><History class="size-4" aria-hidden="true" />{{ history ? 'Versões atuais' : 'Todas as versões' }}</BaseButton
        ><BaseButton v-if="editable && auth.can('documents.manage')" variant="secondary" @click="uploading = true"
          ><Plus class="size-4" aria-hidden="true" />Adicionar documento</BaseButton
        >
      </div>
    </header>
    <div v-if="!visible.length" class="vg-empty-state rounded-lg border border-dashed border-line bg-canvas"><FileText class="size-7 text-primary" aria-hidden="true" /><p>Nenhum documento cadastrado.</p></div>
    <ul class="divide-y divide-line text-sm">
      <li v-for="doc in visible" :key="doc.id" class="flex flex-wrap items-start gap-3 py-4">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-canvas text-primary"><FileText class="size-5" aria-hidden="true" /></span>
        <div class="min-w-0 flex-1">
          <p class="text-xs font-medium">{{ doc.type_label }} <span class="ml-1 text-[10px] text-muted">v{{ doc.version }}</span></p>
          <p class="mt-1 break-all text-[11px] text-muted">{{ doc.original_name }} · {{ formatBytes(doc.size_bytes) }}</p>
          <p class="mt-1 text-[10px] text-muted">{{ formatDateTime(doc.created_at) }}<template v-if="doc.expires_at"> · válido até {{ formatDate(doc.expires_at) }}</template></p>
          <p v-if="doc.review_notes" class="mt-2 rounded-md bg-blue-soft px-3 py-2 text-xs leading-5 text-ink">{{ doc.review_notes }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3"><StatusBadge :tone="statusTone(doc.review_status)">{{ reviewLabels[doc.review_status] }}</StatusBadge><a :href="buildUrl(`/documents/${doc.id}/download`)" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center gap-1.5 text-xs font-medium text-primary hover:underline"><Download class="size-4" aria-hidden="true" />Baixar</a><BaseButton v-if="doc.is_current && auth.can('documents.manage')" variant="ghost" @click="reviewing = doc">Revisar</BaseButton></div>
      </li>
    </ul>
    <DocumentUploadDialog v-if="uploading" :endpoint="endpoint" @close="uploading = false" @saved="saved" />
    <ReviewDialog v-if="reviewing" :document="reviewing" @close="reviewing = null" @saved="saved" />
  </section>
</template>
