<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { Equipment } from '@/types/api'

const props = defineProps<{ item: Equipment | null; defaultType?: Equipment['type'] }>()
const emit = defineEmits<{ close: []; saved: [item: Equipment] }>()

const toText = (value: number | null | undefined) => (value === null || value === undefined ? '' : String(value))
const toNumber = (value: string) => (value.trim() === '' ? null : Number(value.replace(',', '.')))

const form = reactive({
  type: props.item?.type ?? props.defaultType ?? 'module',
  manufacturer: props.item?.manufacturer ?? '',
  model: props.item?.model ?? '',
  power_w: toText(props.item?.power_w),
  energy_kwh: toText(props.item?.energy_kwh),
  efficiency: toText(props.item?.efficiency),
  certification: props.item?.certification ?? '',
  active: props.item?.active ?? true,
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  const body = {
    ...form,
    power_w: toNumber(form.power_w),
    energy_kwh: toNumber(form.energy_kwh),
    efficiency: toNumber(form.efficiency),
    certification: form.certification || null,
  }
  try {
    const result = props.item
      ? await api<{ data: Equipment }>(`/equipment/${props.item.id}`, { method: 'PUT', body })
      : await api<{ data: Equipment }>('/equipment', { method: 'POST', body })
    emit('saved', result.data)
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog
    :title="item ? 'Editar equipamento' : 'Novo equipamento'"
    :submitting="submitting"
    :error="error"
    @close="emit('close')"
    @submit="submit"
  >
    <SelectField
      v-model="form.type"
      label="Tipo"
      :options="[
        { value: 'module', label: 'Módulo fotovoltaico' },
        { value: 'inverter', label: 'Inversor' },
        { value: 'battery', label: 'Bateria' },
      ]"
      :error="error?.firstError('type')"
    />
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField v-model="form.manufacturer" label="Fabricante" required :error="error?.firstError('manufacturer')" />
      <FormField v-model="form.model" label="Modelo" required :error="error?.firstError('model')" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField v-if="form.type !== 'battery'" v-model="form.power_w" label="Potência (W)" type="number" :error="error?.firstError('power_w')" />
      <FormField v-else v-model="form.energy_kwh" label="Capacidade (kWh)" type="number" :error="error?.firstError('energy_kwh')" />
      <FormField v-model="form.efficiency" label="Eficiência (%)" type="number" :error="error?.firstError('efficiency')" />
    </div>
    <FormField
      v-model="form.certification"
      label="Certificação / registro"
      hint="Ex.: número do registro Inmetro"
      :error="error?.firstError('certification')"
    />
    <label v-if="item" class="flex items-center gap-2 text-sm">
      <input v-model="form.active" type="checkbox" class="size-4 accent-primary" />
      Ativo no catálogo
    </label>
  </BaseDialog>
</template>
