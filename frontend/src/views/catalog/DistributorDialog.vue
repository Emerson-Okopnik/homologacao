<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { Distributor } from '@/types/api'

const props = defineProps<{ item: Distributor | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const form = reactive({
  code: '',
  name: '',
  state: '',
  integration_mode: props.item?.integration_mode ?? 'assisted',
  portal_url: props.item?.portal_url ?? '',
  secret_ref: '',
  active: props.item?.active ?? true,
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
  if (!props.item) Object.assign(body, { code: form.code, name: form.name, state: form.state || null })
  try {
    if (props.item) await api(`/distributors/${props.item.id}`, { method: 'PUT', body })
    else await api('/distributors', { method: 'POST', body })
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog :title="item ? `Configurar ${item.name}` : 'Nova distribuidora'" :submitting="submitting" :error="error" @close="emit('close')" @submit="submit">
    <template v-if="!item">
      <FormField v-model="form.name" label="Nome da distribuidora" required :error="error?.firstError('name')" />
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField v-model="form.code" label="Código" required hint="Identificador único. Ex.: CEMIG ou CPFL." :error="error?.firstError('code')" />
        <FormField v-model="form.state" label="UF" hint="Opcional. Ex.: SP." :error="error?.firstError('state')" />
      </div>
    </template>
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
      :hint="item?.has_credential ? 'Já existe uma credencial configurada. Preencha apenas para substituí-la.' : 'Nome da variável no cofre de segredos (ex.: CEMIG_API_TOKEN). A credencial nunca é armazenada no banco.'"
      autocomplete="off"
      :error="error?.firstError('secret_ref')"
    />
    <label class="flex items-center gap-2 text-sm">
      <input v-model="form.active" type="checkbox" class="size-4 accent-primary" />
      Distribuidora ativa
    </label>
  </BaseDialog>
</template>
