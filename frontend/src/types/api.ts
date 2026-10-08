export type PermissionKey =
  | 'users.view'
  | 'users.manage'
  | 'roles.view'
  | 'audit.view'
  | 'clients.view'
  | 'clients.manage'
  | 'technical_responsibles.manage'
  | 'projects.view'
  | 'projects.manage'
  | 'documents.view'
  | 'documents.manage'
  | 'homologations.view'
  | 'homologations.manage'
  | 'process.cancel'
  | 'protocol.override'
  | 'workflow.configure'
  | 'requirements.configure'
  | 'integrations.configure'
  | 'dashboard.view'

export interface Role {
  slug: string
  name: string
  description: string | null
  is_system: boolean
  permissions?: PermissionKey[]
  users_count?: number
}

export interface User {
  id: string
  name: string
  email: string
  active: boolean
  last_login_at: string | null
  created_at: string | null
  roles?: Role[]
  is_super_admin?: boolean
}

export interface Me {
  user: User
  tenant: { id: string; name: string; slug: string } | null
  permissions: PermissionKey[]
  is_super_admin?: boolean
}

export interface Tenant {
  id: string
  name: string
  slug: string
  active: boolean
  users_count?: number
  roles_count?: number
  is_current: boolean
  created_at: string | null
}

export interface PermissionDefinition {
  key: PermissionKey
  label: string
  group: string
  group_label: string
}

export interface AuditLog {
  id: string
  event: string
  actor: { id: string; name: string } | null
  subject_type: string | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  metadata: Record<string, unknown> | null
  justification: string | null
  ip_address: string | null
  correlation_id: string | null
  created_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

export interface Option<T extends string = string> {
  value: T
  label: string
}

export interface Distributor {
  id: string
  code: string
  name: string
  state: string
  integration_mode: string
  integration_mode_label: string
  portal_url: string | null
  has_credential: boolean
  active: boolean
}

export interface ClientContact {
  id: string
  name: string
  email: string | null
  phone: string | null
  role: string | null
  is_legal_representative: boolean
}

export interface ConsumerUnit {
  id: string
  number: string
  client?: { id: string; name: string }
  distributor?: Distributor
  address: {
    street: string
    number: string | null
    complement: string | null
    district: string | null
    city: string
    state: string
    zip: string
  }
  full_address: string
  voltage_class: string
  supply_type: string
  installed_load_kw: number | null
  contracted_demand_kw: number | null
  breaker_a: number | null
  active: boolean
}

export interface Client {
  id: string
  type: 'pf' | 'pj'
  document: string
  name: string
  trade_name: string | null
  email: string | null
  phone: string | null
  status: string
  notes: string | null
  consumer_units_count?: number
  projects_count?: number
  contacts?: ClientContact[]
  consumer_units?: ConsumerUnit[]
  created_at: string | null
}

export interface TechnicalResponsible {
  id: string
  name: string
  council: string
  registration: string
  state: string
  email: string | null
  phone: string | null
  registration_status: string
  active: boolean
  projects_count?: number
}

export interface Equipment {
  id: string
  type: 'module' | 'inverter' | 'battery'
  manufacturer: string
  model: string
  power_w: number | null
  energy_kwh: number | null
  efficiency: number | null
  certification: string | null
  active: boolean
}

export type ProcessStatus = 'ACTIVE' | 'COMPLETED' | 'CANCELLED'

export type WorkflowStage = 'PREPARATION' | 'EXTERNAL_ANALYSIS' | 'CORRECTION' | 'EXECUTION' | 'INSPECTION' | 'CONNECTION'

export type NetworkWorkStatus = 'NOT_REQUIRED' | 'UNDER_ANALYSIS' | 'REQUIRED' | 'WAITING_EXECUTION' | 'COMPLETED' | 'RELEASED'

export type CompensationMode = 'LOCAL_SELF_CONSUMPTION' | 'REMOTE_SELF_CONSUMPTION' | 'SHARED_GENERATION' | 'MULTIPLE_UNITS'

export type GenerationClassification = 'MICRO' | 'MINI_NON_DISPATCHABLE' | 'MINI_DISPATCHABLE'

export type FastTrackParty = 'REQUESTER' | 'TECHNICAL_RESPONSIBLE'

export interface StageCatalog {
  stages: Option<WorkflowStage>[]
  network_work: Option<NetworkWorkStatus>[]
  connection_events: Option<string>[]
}

export interface ProjectSummaryProcess {
  id: string
  code: string
  status: ProcessStatus
  status_label: string
  stage: WorkflowStage
  stage_label: string
  editable: boolean
}

export interface ProjectEquipment {
  id: string
  type: Equipment['type']
  manufacturer: string
  model: string
  power_w: number | null
  nominal_ac_power_kw: number | null
  has_inmetro_registration: boolean
  quantity: number
}

export interface ProjectResponsibility {
  purpose: 'PROJECT' | 'EXECUTION'
  purpose_label: string
  art_number: string | null
  responsible: { id: string; name: string; council: string; registration: string; registration_status: string | null }
}

export interface Project {
  id: string
  code: string
  source_type: string
  modules_power_kwp: number
  inverters_power_kw: number
  considered_power_kw: number
  classification: GenerationClassification | null
  classification_label: string
  fast_track_eligible: boolean
  has_battery: boolean
  storage_energy_kwh: number | null
  has_dispatch_controller: boolean
  declared_dispatchable: boolean
  has_coupling_transformer: boolean
  estimated_generation_kwh_month: number | null
  compensation_mode: CompensationMode
  compensation_mode_label: string
  compensation_method: 'PERCENTAGE' | 'PRIORITY' | null
  notes: string | null
  initial_protocol?: string | null
  client?: { id: string; name: string; document: string }
  consumer_unit?: ConsumerUnit
  equipment?: ProjectEquipment[]
  responsibilities?: ProjectResponsibility[]
  compensation_units?: Array<{ consumer_unit_id: string; number: string; percentage: number | null; priority: number | null }>
  fast_track_acceptances?: Array<{ party: FastTrackParty; party_label: string; signer_name: string; statement_version: string; accepted_at: string }>
  waivers?: Array<{ requirement_code: string; reason: string }>
  process?: ProjectSummaryProcess | null
  created_at: string | null
}

export interface ChecklistItem {
  code: string
  version: number
  label: string
  kind: string
  document_type: string | null
  document_label: string | null
  document_owner: string | null
  outcome: 'REQUIRED' | 'OPTIONAL' | 'NOT_APPLICABLE' | 'WAIVED' | string
  reason: string | null
  source_reference: string | null
  satisfied?: boolean
  document?: ProcessDocument | null
  [key: string]: unknown
}

export interface Checklist {
  phase: 'SUBMISSION' | 'INSPECTION_REQUEST' | 'COMPLETION'
  label: string
  items: ChecklistItem[]
  total: number
  satisfied: number
  percent: number
  blocking: string[]
}

export interface ProjectEvaluation {
  powers: { modules_kwp: number; inverters_kw: number; considered_kw: number }
  classification: {
    value: GenerationClassification | null
    label: string | null
    rule_code: string | null
    rule_version: number | null
    reason: string | null
    decided_at: string | null
  }
  fast_track: {
    eligible: boolean
    rule_code: string | null
    rule_version: number | null
    reasons: string[]
    statement_version: string
    statement: string
  }
  checklist: Checklist
}

export interface ProcessDocument {
  id: string
  document_type: string
  type_label: string
  owner: string | null
  version: number
  is_current?: boolean
  original_name: string
  mime_type: string
  size_bytes: number
  sha256: string
  review_status: 'pendente' | 'aprovado' | 'reprovado'
  review_notes: string | null
  uploaded_by: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  created_at: string | null
  links?: Array<{ type: string; is_current: boolean; label: string }>
}

export interface Pendency {
  id: string
  origin: string
  title: string
  description: string | null
  status: 'aberta' | 'resolvida'
  due_date: string | null
  resolution: string | null
  created_by: string | null
  resolved_by: string | null
  resolved_at: string | null
  created_at: string | null
}

export interface ProcessDeadline {
  type: string
  label: string
  status: string
  starts_at: string
  due_at: string
  days: number
  day_count: string
  overdue: boolean
}

export interface ActionGate {
  available: boolean
  reasons: string[]
}

export type ProcessActionKey =
  | 'submit'
  | 'register_correction'
  | 'approve_access'
  | 'update_network_work'
  | 'report_execution'
  | 'request_inspection'
  | 'record_inspection'
  | 'record_connection_event'
  | 'complete'
  | 'cancel'

export interface Inspection {
  id: string
  sequence: number
  status: string
  status_label: string
  requested_at: string
  scheduled_for: string | null
  result_at: string | null
  result_notes: string | null
  is_open: boolean
}

export interface HomologationProcess {
  id: string
  code: string
  status: ProcessStatus
  status_label: string
  stage: WorkflowStage
  stage_label: string
  network_work_status: NetworkWorkStatus
  network_work_label: string
  protocol_number: string | null
  stage_changed_at: string | null
  submitted_at: string | null
  approved_at: string | null
  completed_at: string | null
  created_at: string | null
  open_deadline: ProcessDeadline | null
  distributor?: Distributor
  assignee?: { id: string; name: string } | null
  project?: Project
  open_pendencies_count?: number
  current_version?: { version: number; reason: string; sha256: string; created_at: string } | null
  deadlines?: ProcessDeadline[]
  execution?: { id: string; started_at: string | null; completed_at: string; notes: string | null } | null
  inspections?: Inspection[]
  connection_events?: Array<{ id: string; type: string; type_label: string; occurred_at: string; meter_number: string | null; notes: string | null }>
  timeline?: Array<{ type: string; title: string; description: string | null; user: string | null; occurred_at: string }>
  pendencies?: Pendency[]
  interactions?: Array<{ id: string; type: string; channel: string; description: string; user: string | null; occurred_at: string }>
  actions?: Record<ProcessActionKey, ActionGate>
  checklist?: Checklist
  documents?: ProcessDocument[]
}

export interface DashboardData {
  totals: {
    active: number
    waiting_distributor: number
    in_correction: number
    completed: number
    overdue: number
    open_pendencies: number
    documents_to_review: number
    completed_power_kw: number
    avg_approval_days: number | null
  }
  by_stage: Array<{ stage: WorkflowStage; label: string; total: number }>
  recent: Array<{ id: string; code: string; client: string | null; stage: WorkflowStage; stage_label: string; status: ProcessStatus; status_label: string; stage_changed_at: string | null }>
}

