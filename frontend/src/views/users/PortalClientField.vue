<script setup lang="ts">
import { computed } from 'vue'
import SelectField from '@/components/ui/SelectField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'

const props = withDefaults(defineProps<{ url?: string; error?: string }>(), { url: '/users/client-options' })
const model = defineModel<string>({ required: true })
const { data, error: lookupError, loading } = useApiQuery(
  () => props.url,
  url => api<{ data: Array<{ id: string; name: string; document: string }> }>(url),
)
const options = computed(() => (data.value?.data ?? []).map(client => ({
  value: client.id, label: `${client.name} · ${client.document}`,
})))
</script>

<template>
  <InlineAlert v-if="lookupError" :correlation-id="lookupError.correlationId">{{ lookupError.message }}</InlineAlert>
  <SelectField
    v-model="model"
    label="Cliente titular"
    :options="options"
    placeholder="Selecione o cliente cadastrado"
    required
    :disabled="loading || Boolean(lookupError)"
    :error="error"
    hint="Este usuário terá acesso somente às UCs e solicitações deste titular, pelo portal do cliente."
  />
</template>
