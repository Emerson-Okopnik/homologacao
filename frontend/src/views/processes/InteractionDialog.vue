<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import TextareaField from '@/components/ui/TextareaField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'

const props = defineProps<{ processId: string }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const form = reactive({ type: 'nota', channel: 'portal', description: '', occurred_at: '' })
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  try {
    await api(`/processes/${props.processId}/interactions`, {
      method: 'POST',
      body: { ...form, occurred_at: form.occurred_at || null },
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
  <BaseDialog title="Registrar interação" :submitting="submitting" :error="error" @close="emit('close')" @submit="submit">
    <div class="grid gap-4 sm:grid-cols-2">
      <SelectField
        v-model="form.type"
        label="Tipo"
        :options="[
          { value: 'nota', label: 'Nota interna' },
          { value: 'envio', label: 'Envio à distribuidora' },
          { value: 'resposta_distribuidora', label: 'Resposta da distribuidora' },
          { value: 'contato', label: 'Contato com cliente' },
        ]"
      />
      <SelectField
        v-model="form.channel"
        label="Canal"
        :options="[
          { value: 'portal', label: 'Portal' },
          { value: 'email', label: 'E-mail' },
          { value: 'telefone', label: 'Telefone' },
          { value: 'presencial', label: 'Presencial' },
          { value: 'api', label: 'API' },
        ]"
      />
    </div>
    <TextareaField v-model="form.description" label="Descrição" required :rows="5" :error="error?.firstError('description')" />
    <FormField v-model="form.occurred_at" label="Data da interação" type="datetime-local" hint="Deixe em branco para agora." :error="error?.firstError('occurred_at')" />
  </BaseDialog>
</template>
