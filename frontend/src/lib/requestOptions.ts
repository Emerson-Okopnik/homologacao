import type { ClientRequestStatus, RequestSystem } from '@/types/api'

type Tone = 'success' | 'warning' | 'danger' | 'neutral' | 'info'

export const compensationOptions = [
  { value: 'LOCAL_SELF_CONSUMPTION', label: 'Só nesta unidade (autoconsumo local)' },
  { value: 'REMOTE_SELF_CONSUMPTION', label: 'Também em outras unidades minhas (autoconsumo remoto)' },
  { value: 'SHARED_GENERATION', label: 'Dividir com outras pessoas (geração compartilhada)' },
  { value: 'MULTIPLE_UNITS', label: 'Condomínio / múltiplas unidades' },
]

export const installationOptions = [
  { value: 'TELHADO', label: 'Telhado' },
  { value: 'LAJE', label: 'Laje' },
  { value: 'SOLO', label: 'Solo' },
  { value: 'CARPORT', label: 'Garagem / carport' },
  { value: 'FACHADA', label: 'Fachada' },
]

export const supplyOptions = [
  { value: 'monofasico', label: 'Monofásico' },
  { value: 'bifasico', label: 'Bifásico' },
  { value: 'trifasico', label: 'Trifásico' },
]

export const voltageOptions = [
  { value: 'BT', label: 'Baixa tensão (residências e comércios)' },
  { value: 'MT', label: 'Média tensão (indústrias, grandes consumidores)' },
]

export const requestStatusTabs: Array<{ value: ClientRequestStatus | ''; label: string }> = [
  { value: '', label: 'Todas' },
  { value: 'SUBMITTED', label: 'Novas' },
  { value: 'IN_REVIEW', label: 'Em análise' },
  { value: 'NEEDS_INFO', label: 'Aguardando cliente' },
  { value: 'CONVERTED', label: 'Viraram projeto' },
  { value: 'CANCELLED', label: 'Canceladas' },
]

export function requestTone(status: ClientRequestStatus): Tone {
  switch (status) {
    case 'SUBMITTED':
      return 'info'
    case 'IN_REVIEW':
      return 'warning'
    case 'NEEDS_INFO':
      return 'danger'
    case 'CONVERTED':
      return 'success'
    default:
      return 'neutral'
  }
}

export function labelOf(options: Array<{ value: string; label: string }>, value: string | null | undefined): string {
  return options.find((o) => o.value === value)?.label ?? value ?? '—'
}

export function emptySystem(): RequestSystem {
  return {
    compensation_mode: 'LOCAL_SELF_CONSUMPTION',
    average_consumption_kwh: null,
    is_property_owner: true,
    installation_type: 'TELHADO',
    roof_material: null,
    installation_area_m2: null,
    integrator: null,
    modules: [{ brand: '', model: '', power_w: null, quantity: null }],
    inverters: [{ brand: '', model: '', power_kw: null, quantity: 1 }],
    has_battery: false,
    storage_energy_kwh: null,
    beneficiaries: [],
    notes: null,
  }
}

export function systemPowers(system: RequestSystem): { modules: number; inverters: number } {
  const modules = system.modules.reduce((s, m) => s + (Number(m.power_w) || 0) * (Number(m.quantity) || 0), 0) / 1000
  const inverters = system.inverters.reduce((s, i) => s + (Number(i.power_kw) || 0) * (Number(i.quantity) || 0), 0)
  return { modules: Math.round(modules * 1000) / 1000, inverters: Math.round(inverters * 1000) / 1000 }
}
