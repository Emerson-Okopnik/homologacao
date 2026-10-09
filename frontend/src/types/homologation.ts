import type { Option, ProcessDocument, ProcessStatus, WorkflowStage as ProcessPhase } from './api'
export interface ProjectVersion {
  id: string
  version: number
  status: string
  change_reason: string
  sha256: string
  frozen_at: string
}
export interface TechnicalData {
  name: string | null
  installation_type: string | null
  connection: {
    connection_point?: string | null
    supply_voltage?: string | null
    phase_configuration?: string | null
    main_breaker_a?: number | null
    installed_load_kw?: string | null
    contracted_demand_kw?: string | null
    existing_generation_kw?: string | null
    emergency_generator?: boolean
  }
  arrays: Array<{
    module_model_id: string
    module_quantity: number
    strings_quantity: number | null
    modules_per_string: number | null
    azimuth: string | null
    tilt: string | null
  }>
  inverters: Array<{
    inverter_model_id: string
    quantity: number
    nominal_ac_kw: string
    connection_voltage: string | null
    protection_config_json: Record<string, unknown> | null
  }>
  storage: Array<{
    battery_model_id: string
    quantity: number
    energy_kwh: string | null
    power_kw: string | null
    dispatchable: boolean
    operating_strategy: string | null
  }>
  compensation: {
    mode: string
    allocation_rule: string
    units: Array<{ consumer_unit_id: string; percentage: string | null; priority: number | null }>
  }
  responsibility_terms: Array<{
    id: string
    type: string
    number: string
    issued_at: string
    valid_until: string | null
    technical_responsible_id: string
    file_id: string
  }>
}
export interface ProjectTechnicalResponse {
  technical_data: TechnicalData
  editable: boolean
  documents: ProcessDocument[]
  versions: ProjectVersion[]
  processes: Array<{ id: string; code: string; status: string; stage: string | null }>
  issues: string[]
}
export interface Submission {
  id: string
  kind: string
  status: string
  version_id: string
  version: number
  change_reason: string
  request_hash: string
  external_receipt: string | null
  receipt_document_id: string | null
  submitted_at: string | null
}
export interface Tracking {
  external: {
    id: string
    protocol_number: string | null
    status: string | null
    portal_url: string | null
    last_synced_at: string | null
  } | null
  submissions: Submission[]
  events: Array<{
    id: string
    direction: string
    type: string
    success: boolean
    response: Record<string, unknown> | null
    request_hash: string | null
    occurred_at: string
  }>
  pending_items: Array<{
    id: string
    code: string | null
    description: string
    status: string
    due_at: string | null
    response_document_id: string | null
  }>
  budgets: Array<{ id: string; issued_at: string; expires_at: string | null; amount: number; works_required: boolean; document_id: string }>
  inspections: Array<{
    id: string
    status: string
    requested_at: string
    scheduled_at: string | null
    performed_at: string | null
    connection_approved_at: string | null
    report_document_id: string | null
  }>
  assignments: Array<{ id: string; name: string; role: string; active: boolean; assigned_at: string | null; revoked_at: string | null }>
  stage_history: Array<{ stage: string; entered_at: string; left_at: string | null; actor: string | null; notes: string | null }>
}
export interface WorkflowStage {
  id: string
  code: string
  name: string
  order: number
  stage_type: string
  phase: ProcessPhase | null
  terminal: boolean
  next: string[]
  active: boolean
}
export interface Requirement {
  id: string
  code: string
  name: string
  distributor_id: string | null
  required_document_type: string | null
  active: boolean
  conditions: {
    min_power_kw?: number | null
    max_power_kw?: number | null
    modality?: string[]
    generation_type?: string[]
    installation_type?: string[]
    has_battery?: boolean
  }
}
export interface WorkflowConfiguration {
  phases: Option<ProcessPhase>[]
  status_types: Array<Option<ProcessStatus> & { phase: ProcessPhase | null; terminal: boolean }>
  stages: WorkflowStage[]
  requirements: Requirement[]
  credentials: Array<{
    id: string
    distributor_id: string
    credential_ref: string
    auth_type: string
    expires_at: string | null
    active: boolean
  }>
}
