<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import DocumentUploadDialog from './DocumentUploadDialog.vue'
import ReviewDialog from '@/views/processes/ReviewDialog.vue'
import { buildUrl } from '@/lib/http'
import { useAuthStore } from '@/stores/auth'
import type { ProcessDocument } from '@/types/api'
const props = defineProps<{ endpoint: string; documents: ProcessDocument[]; editable?: boolean }>()
const emit = defineEmits<{ saved: [] }>()
const uploading = ref(false),
  history = ref(false),
  reviewing = ref<ProcessDocument | null>(null)
const auth = useAuthStore()
const visible = computed(() => props.documents.filter((d) => history.value || d.is_current))
function saved() {
  uploading.value = false
  reviewing.value = null
  emit('saved')
}
</script>
<template>
  <section class="rounded-2xl border border-line bg-surface p-5">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <h2 class="font-semibold">Documentos e evidências</h2>
      <div class="flex gap-2">
        <BaseButton variant="ghost" @click="history = !history">{{ history ? 'Versões atuais' : 'Todas as versões' }}</BaseButton
        ><BaseButton v-if="editable && auth.can('documents.manage')" variant="secondary" @click="uploading = true"
          >Adicionar documento</BaseButton
        >
      </div>
    </header>
    <p v-if="!visible.length" class="text-sm text-muted">Nenhum documento cadastrado.</p>
    <ul class="divide-y divide-line text-sm">
      <li v-for="doc in visible" :key="doc.id" class="flex flex-wrap items-center gap-3 py-3">
        <div class="min-w-0 flex-1">
          <p class="font-medium">{{ doc.type_label }} · v{{ doc.version }}</p>
          <p class="break-all text-xs text-muted">
            {{ doc.original_name }} · {{ doc.review_status }}<template v-if="doc.expires_at"> · válido até {{ doc.expires_at }}</template>
          </p>
        </div>
        <a :href="buildUrl(`/documents/${doc.id}/download`)" target="_blank" rel="noopener" class="text-primary hover:underline">Baixar</a>
        <BaseButton v-if="doc.is_current && auth.can('documents.manage')" variant="ghost" @click="reviewing = doc">Revisar</BaseButton>
      </li>
    </ul>
    <DocumentUploadDialog v-if="uploading" :endpoint="endpoint" @close="uploading = false" @saved="saved" />
    <ReviewDialog v-if="reviewing" :document="reviewing" @close="reviewing = null" @saved="saved" />
  </section>
</template>
