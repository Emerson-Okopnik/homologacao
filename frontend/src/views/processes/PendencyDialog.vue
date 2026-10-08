<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { Pendency } from '@/types/api'

const props = defineProps<{ processId: string; resolving?: Pendency | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const form = reactive({ origin: 'distribuidora', title: '', description: '', due_date: '', resolution: '' })
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  try {
    if (props.resolving) {
      await api(`/pendencies/${props.resolving.id}/resolve`, { method: 'POST', body: { resolution: form.resolution } })
    } else {
      await api(`/processes/${props.processId}/pendencies`, {
        method: 'POST',
        body: { origin: form.origin, title: form.title, description: form.description || null, due_date: form.due_date || null },
      })
    }
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog
    :title="resolving ? 'Resolver pendência' : 'Registrar pendência'"
    :submit-label="resolving ? 'Marcar como resolvida' : 'Registrar'"
    :submitting="submitting"
    :error="error"
    @close="emit('close')"
    @submit="submit"
  >
    <template v-if="resolving">
      <div class="rounded-lg bg-canvas p-3 text-sm">
        <p class="font-medium">{{ resolving.title }}</p>
        <p v-if="resolving.description" class="mt-1 whitespace-pre-line text-muted">{{ resolving.description }}</p>
      </div>
      <TextareaField v-model="form.resolution" label="Como foi resolvida" required :rows="4" :error="error?.firstError('resolution')" />
    </template>
    <template v-else>
      <SelectField
        v-model="form.origin"
        label="Origem"
        :options="[
          { value: 'distribuidora', label: 'Exigência da distribuidora' },
          { value: 'interna', label: 'Pendência interna' },
        ]"
        :error="error?.firstError('origin')"
      />
      <FormField v-model="form.title" label="Título" required :error="error?.firstError('title')" />
      <TextareaField v-model="form.description" label="Descrição" :rows="4" :error="error?.firstError('description')" />
      <FormField v-model="form.due_date" label="Prazo" type="date" :error="error?.firstError('due_date')" />
    </template>
  </BaseDialog>
</template>
