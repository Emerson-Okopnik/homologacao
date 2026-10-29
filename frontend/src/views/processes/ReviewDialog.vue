<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ExternalLink, FileText, LoaderCircle } from '@lucide/vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, buildUrl, toApiError, type ApiError } from '@/lib/http'
import { formatBytes } from '@/lib/format'
import type { ProcessDocument } from '@/types/api'

const props = defineProps<{ document: ProcessDocument }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const status = ref<'aprovado' | 'reprovado'>('aprovado')
const notes = ref('')
const submitting = ref(false)
const error = ref<ApiError | null>(null)
const previewUrl = ref<string | null>(null)
const previewKind = ref<'pdf' | 'image' | null>(null)
const previewLoading = ref(false)
const previewError = ref('')
let previewRequest: AbortController | null = null
let requestVersion = 0
let disposed = false

function clearPreview() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = null
}

async function loadPreview() {
  const version = ++requestVersion
  previewRequest?.abort()
  clearPreview()
  previewError.value = ''
  const declaredMime = props.document.mime_type?.split(';')[0]?.trim().toLowerCase()
  previewKind.value = declaredMime === 'application/pdf' ? 'pdf' : ['image/jpeg', 'image/png'].includes(declaredMime ?? '') ? 'image' : null
  if (!previewKind.value) {
    previewLoading.value = false
    previewError.value = 'Use “Abrir arquivo” para consultar este documento.'
    return
  }

  const controller = new AbortController()
  previewRequest = controller
  previewLoading.value = true
  try {
    const response = await fetch(buildUrl(`/documents/${props.document.id}/download`), {
      credentials: 'include',
      headers: { Accept: declaredMime! },
      signal: controller.signal,
    })
    if (!response.ok) throw new Error('Prévia indisponível')
    const blob = await response.blob()
    const mime = blob.type.split(';')[0]?.toLowerCase()
    if (!blob.size || (mime && mime !== 'application/octet-stream' && mime !== declaredMime)) throw new Error('Formato de prévia não suportado')
    if (disposed || version !== requestVersion) return
    previewUrl.value = URL.createObjectURL(blob.type === declaredMime ? blob : new Blob([blob], { type: declaredMime }))
  } catch {
    if (!disposed && version === requestVersion && !controller.signal.aborted) {
      previewError.value = 'Não foi possível carregar a prévia. Você pode abrir o arquivo e continuar a revisão.'
    }
  } finally {
    if (!disposed && version === requestVersion) previewLoading.value = false
  }
}

onMounted(loadPreview)
watch(() => props.document.id, loadPreview)
onBeforeUnmount(() => {
  disposed = true
  previewRequest?.abort()
  clearPreview()
})

async function submit() {
  submitting.value = true
  error.value = null
  try {
    await api(`/documents/${props.document.id}/review`, {
      method: 'POST',
      body: { review_status: status.value, review_notes: notes.value || null },
    })
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog title="Revisar documento" size="xl" submit-label="Registrar revisão" :submitting="submitting" :error="error" @close="emit('close')" @submit="submit">
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <section class="min-w-0 overflow-hidden rounded-xl border border-line" aria-label="Prévia do documento">
        <header class="flex items-start gap-3 border-b border-line bg-canvas px-4 py-3">
          <FileText class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
          <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold">{{ document.type_label }} · v{{ document.version }}</p>
            <p class="mt-1 break-all text-[10px] leading-5 text-muted">{{ document.original_name }} · {{ formatBytes(document.size_bytes) }}</p>
          </div>
        </header>
        <div class="flex min-h-64 items-center justify-center bg-canvas p-3 lg:min-h-[26rem]" :aria-busy="previewLoading">
          <p v-if="previewLoading" class="flex items-center gap-2 text-xs text-muted" role="status"><LoaderCircle class="size-4 animate-spin" aria-hidden="true" />Carregando prévia…</p>
          <iframe v-else-if="previewUrl && previewKind === 'pdf'" :src="previewUrl" :title="`Prévia de ${document.original_name}`" class="h-[26rem] w-full rounded-md border-0 bg-surface" referrerpolicy="no-referrer" />
          <img v-else-if="previewUrl && previewKind === 'image'" :src="previewUrl" :alt="`Prévia de ${document.original_name}`" class="max-h-[32rem] max-w-full rounded-md object-contain" />
          <div v-else class="max-w-xs px-4 py-6 text-center">
            <FileText class="mx-auto size-9 text-muted/50" aria-hidden="true" />
            <p class="mt-4 text-xs leading-6 text-muted" role="status">{{ previewError }}</p>
            <button v-if="previewKind" type="button" class="mt-3 text-xs font-medium text-primary hover:underline" @click="loadPreview">Tentar novamente</button>
          </div>
        </div>
      </section>
      <div class="flex min-w-0 flex-col gap-5">
        <div>
          <p class="text-sm font-semibold">Decisão da revisão</p>
          <p class="mt-2 text-xs leading-6 text-muted">Confira o arquivo e registre a decisão para esta versão do documento.</p>
        </div>
        <a :href="buildUrl(`/documents/${document.id}/download`)" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-line bg-surface px-3 text-xs font-medium text-ink hover:bg-canvas"><ExternalLink class="size-4" aria-hidden="true" />Abrir arquivo</a>
        <SelectField v-model="status" label="Resultado" :options="[{ value: 'aprovado', label: 'Aprovar' }, { value: 'reprovado', label: 'Reprovar' }]" />
        <TextareaField v-model="notes" :label="status === 'reprovado' ? 'Motivo da reprovação' : 'Observações (opcional)'" :required="status === 'reprovado'" :rows="5" :error="error?.firstError('review_notes')" />
        <p class="rounded-lg bg-primary-soft px-3 py-3 text-[11px] leading-5 text-primary">A decisão e a nota ficam registradas no histórico desta versão.</p>
      </div>
    </div>
  </BaseDialog>
</template>
