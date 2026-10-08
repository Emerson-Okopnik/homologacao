<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { api, toApiError, type ApiError } from '@/lib/http'
import type { ConsumerUnit, Distributor } from '@/types/api'

const props = defineProps<{ clientId: string; unit: ConsumerUnit | null; distributors: Distributor[] }>()
const emit = defineEmits<{ close: []; saved: [unit: ConsumerUnit] }>()

const toText = (value: number | null | undefined) => (value === null || value === undefined ? '' : String(value))
const toNumber = (value: string) => (value.trim() === '' ? null : Number(value.replace(',', '.')))

const form = reactive({
  distributor_id: props.unit?.distributor?.id ?? '',
  number: props.unit?.number ?? '',
  street: props.unit?.address.street ?? '',
  address_number: props.unit?.address.number ?? '',
  complement: props.unit?.address.complement ?? '',
  district: props.unit?.address.district ?? '',
  city: props.unit?.address.city ?? '',
  state: props.unit?.address.state ?? '',
  zip: props.unit?.address.zip ?? '',
  voltage_class: props.unit?.voltage_class ?? 'BT',
  voltage: toText(props.unit?.voltage), neutral_voltage: toText(props.unit?.neutral_voltage),
  utm_zone: props.unit?.address.utm_zone ?? '', utm_x: props.unit?.address.utm_x ?? '', utm_y: props.unit?.address.utm_y ?? '',
  supply_type: props.unit?.supply_type ?? 'monofasico',
  installed_load_kw: toText(props.unit?.installed_load_kw),
  contracted_demand_kw: toText(props.unit?.contracted_demand_kw),
  breaker_a: toText(props.unit?.breaker_a),
  active: props.unit?.active ?? true,
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)

const distributorOptions = computed(() =>
  props.distributors.map((d) => ({ value: d.id, label: `${d.name} (${d.state})` })),
)

async function submit() {
  submitting.value = true
  error.value = null
  const body = {
    ...form,
    client_id: props.clientId,
    state: form.state.toUpperCase(),
    zip: form.zip.replace(/\D/g, '') || null,
    installed_load_kw: toNumber(form.installed_load_kw),
    contracted_demand_kw: toNumber(form.contracted_demand_kw),
    breaker_a: toNumber(form.breaker_a),
    voltage: toNumber(form.voltage), neutral_voltage: toNumber(form.neutral_voltage), utm_zone: form.utm_zone.toUpperCase() || null, utm_x: toNumber(form.utm_x), utm_y: toNumber(form.utm_y),
  }
  try {
    const result = props.unit
      ? await api<{ data: ConsumerUnit }>(`/consumer-units/${props.unit.id}`, { method: 'PUT', body })
      : await api<{ data: ConsumerUnit }>('/consumer-units', { method: 'POST', body })
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
    :title="unit ? 'Editar unidade consumidora' : 'Nova unidade consumidora'"
    :submitting="submitting"
    :error="error"
    size="lg"
    @close="emit('close')"
    @submit="submit"
  >
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField v-model="form.number" label="Número da UC" required :error="error?.firstError('number')" />
      <SelectField
        v-model="form.distributor_id"
        label="Distribuidora"
        placeholder="Selecione"
        :options="distributorOptions"
        :error="error?.firstError('distributor_id')"
      />
    </div>

    <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
      <FormField v-model="form.street" label="Logradouro" required :error="error?.firstError('street')" />
      <FormField v-model="form.address_number" label="Número" :error="error?.firstError('address_number')" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField v-model="form.complement" label="Complemento" />
      <FormField v-model="form.district" label="Bairro" />
    </div>
    <div class="grid gap-4 sm:grid-cols-[1fr_5rem_9rem]">
      <FormField v-model="form.city" label="Cidade" required :error="error?.firstError('city')" />
      <FormField v-model="form.state" label="UF" required :error="error?.firstError('state')" />
      <FormField v-model="form.zip" label="CEP" :error="error?.firstError('zip')" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <SelectField
        v-model="form.voltage_class"
        label="Classe de tensão"
        :options="[
          { value: 'BT', label: 'Baixa tensão (BT)' },
          { value: 'MT', label: 'Média tensão (MT)' },
        ]"
      />
      <SelectField
        v-model="form.supply_type"
        label="Tipo de fornecimento"
        :options="[
          { value: 'monofasico', label: 'Monofásico' },
          { value: 'bifasico', label: 'Bifásico' },
          { value: 'trifasico', label: 'Trifásico' },
        ]"
      />
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
      <FormField v-model="form.installed_load_kw" label="Carga instalada (kW)" type="number" :error="error?.firstError('installed_load_kw')" />
      <FormField v-model="form.contracted_demand_kw" label="Demanda contratada (kW)" type="number" :error="error?.firstError('contracted_demand_kw')" />
      <FormField v-model="form.breaker_a" label="Disjuntor (A)" type="number" :error="error?.firstError('breaker_a')" />
    </div>
    <label v-if="unit" class="flex items-center gap-2 text-sm">
      <input v-model="form.active" type="checkbox" class="size-4 accent-primary" />
      Unidade ativa
    </label>
    <div class="grid gap-4 sm:grid-cols-2"><FormField v-model="form.voltage" label="Tensão entre fases (V)" type="number" step="any" :error="error?.firstError('voltage')" /><FormField v-model="form.neutral_voltage" label="Tensão fase-neutro (V)" type="number" step="any" :error="error?.firstError('neutral_voltage')" /></div>
    <div class="grid gap-4 sm:grid-cols-3"><FormField v-model="form.utm_zone" label="Zona UTM" hint="Ex.: 22J" :error="error?.firstError('utm_zone')" /><FormField v-model="form.utm_x" label="UTM E (m)" type="number" step="any" :error="error?.firstError('utm_x')" /><FormField v-model="form.utm_y" label="UTM N (m)" type="number" step="any" :error="error?.firstError('utm_y')" /></div>
  </BaseDialog>
</template>
