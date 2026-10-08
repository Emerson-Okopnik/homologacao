<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { Distributor } from '@/types/api'

const props = defineProps<{ item: Distributor }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const form = reactive({
  integration_mode: props.item.integration_mode,
  portal_url: props.item.portal_url ?? '',
  secret_ref: '',
  active: props.item.active,
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  const body: Record<string, unknown> = {
    integration_mode: form.integration_mode,
    portal_url: form.portal_url || null,
    active: form.active,
  }
  if (form.secret_ref) body.secret_ref = form.secret_ref
  try {
    await api(`/distributors/${props.item.id}`, { method: 'PUT', body })
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog :title="`Configurar ${item.name}`" :submitting="submitting" :error="error" @close="emit('close')" @submit="submit">
    <SelectField
      v-model="form.integration_mode"
      label="Modo de integração"
      :options="[
        { value: 'assisted', label: 'Fluxo assistido (manual)' },
        { value: 'api', label: 'API oficial' },
        { value: 'automation', label: 'Automação autorizada' },
      ]"
      hint="Sem API oficial, mantenha o fluxo assistido: o pacote é montado aqui e o protocolo registrado manualmente."
      :error="error?.firstError('integration_mode')"
    />
    <FormField v-model="form.portal_url" label="URL do portal" type="url" :error="error?.firstError('portal_url')" />
    <FormField
      v-model="form.secret_ref"
      label="Referência da credencial"
      :hint="item.has_credential ? 'Já existe uma credencial configurada. Preencha apenas para substituí-la.' : 'Nome da variável no cofre de segredos (ex.: CEMIG_API_TOKEN). A credencial nunca é armazenada no banco.'"
      autocomplete="off"
      :error="error?.firstError('secret_ref')"
    />
    <label class="flex items-center gap-2 text-sm">
      <input v-model="form.active" type="checkbox" class="size-4 accent-primary" />
      Distribuidora ativa
    </label>
  </BaseDialog>
</template>
