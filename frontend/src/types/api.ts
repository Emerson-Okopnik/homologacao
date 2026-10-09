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
  client?: { id: string; name: string } | null
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
  state: string | null
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
    utm_zone?: string | null
    utm_x?: string | null
    utm_y?: string | null
  }
  full_address: string
  voltage_class: string
  voltage?: number | null
  neutral_voltage?: number | null
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
  cpf?: string | null
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
  nominal_ac_power_kw?: number | null
  has_inmetro_registration?: boolean
  inmetro_registration_number?: string | null
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

export type ProcessStatus =
  | 'rascunho'
  | 'em_preparacao'
  | 'pronto_para_envio'
  | 'enviado'
  | 'em_analise'
  | 'pendencia_distribuidora'
  | 'aprovado'
  | 'vistoria_solicitada'
  | 'conectado'
  | 'reprovado'
  | 'cancelado'

export interface ProcessStatusMeta {
  value: string
  stage_type?: ProcessStatus
  label: string
  on_board: boolean
  terminal: boolean
  transitions: string[]
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

export interface Project {
  id: string
  code: string
  name?: string | null
  installation_type?: string | null
  status?: string
  processes?: ProjectSummaryProcess[]
  generation_type: 'micro' | 'mini'
  modality: string
  modality_label: string
  installed_power_kwp: number
  inverter_power_kw: number
  access_power_kw: number
  has_battery: boolean
  estimated_generation_kwh_month: number | null
  notes: string | null
  client?: { id: string; name: string; document: string }
  consumer_unit?: ConsumerUnit
  technical_responsible?: TechnicalResponsible | null
  equipment?: Array<Pick<Equipment, 'id' | 'type' | 'manufacturer' | 'model' | 'power_w'> & { quantity: number }>
  process?: ProjectSummaryProcess | null
  created_at: string | null

  source_type: string
  modules_power_kwp: number
  inverters_power_kw: number
  considered_power_kw: number
  classification: GenerationClassification | null
  classification_label: string
  fast_track_eligible: boolean
  storage_energy_kwh: number | null
  has_dispatch_controller: boolean
  declared_dispatchable: boolean
  has_coupling_transformer: boolean
  compensation_mode: CompensationMode
  compensation_mode_label: string
  compensation_method: 'PERCENTAGE' | 'PRIORITY' | null
  initial_protocol?: string | null
  responsibilities?: ProjectResponsibility[]
  compensation_units?: Array<{ consumer_unit_id: string; number: string; percentage: number | null; priority: number | null }>
  fast_track_acceptances?: Array<{ party: FastTrackParty; party_label: string; signer_name: string; statement_version: string; accepted_at: string }>
  waivers?: Array<{ requirement_code: string; reason: string }>
}

export interface ProcessDocument {
  id: string
  process_id?: string | null
  project?: { id: string; code: string; client?: string | null } | null
  document_type: string
  type_label: string
  version: number
  is_current: boolean
  original_name: string
  mime_type: string
  size_bytes: number
  sha256: string
  issued_at?: string | null
  expires_at?: string | null
  review_status: 'pendente' | 'aprovado' | 'reprovado'
  review_notes: string | null
  uploaded_by: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  created_at: string | null
  process?: { id: string; code: string; client?: string | null }

  owner: string | null
  links?: Array<{ type: string; is_current: boolean; label: string }>

}

export interface Pendency {
  id: string
  origin: 'interna' | 'distribuidora' | 'vistoria'
  external?: boolean
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

export interface HomologationProcess {
  id: string
  code: string
  status: ProcessStatus
  status_label: string
  stage_code?: string
  priority?: string
  allowed_transitions: Array<Option<string> & { stage_type?: ProcessStatus }>
  editable: boolean
  protocol_number: string | null
  due_date: string | null
  status_changed_at: string | null
  submitted_at: string | null
  approved_at: string | null
  connected_at: string | null
  created_at: string | null
  distributor?: Distributor
  assignee?: { id: string; name: string } | null
  project?: Project
  open_pendencies_count?: number
  history?: Array<{ from_status: string | null; to_status: string; reason: string | null; user: string | null; created_at: string }>
  pendencies?: Pendency[]
  interactions?: Array<{ id: string; type: string; channel: string; description: string; user: string | null; occurred_at: string }>
  checklist?: Array<{ id?: string; type: string | null; label: string; required: boolean; status?: string; notes?: string | null; document: ProcessDocument | null }>
  readiness_issues?: string[]
  phase_checklist?: Checklist

  stage: WorkflowStage
  stage_label: string
  network_work_status: NetworkWorkStatus
  network_work_label: string
  stage_changed_at: string | null
  completed_at: string | null
  open_deadline: ProcessDeadline | null
  current_version?: { version: number; reason: string; sha256: string; created_at: string } | null
  deadlines?: ProcessDeadline[]
  execution?: { id: string; started_at: string | null; completed_at: string; notes: string | null } | null
  inspections?: Inspection[]
  connection_events?: Array<{ id: string; type: string; type_label: string; occurred_at: string; meter_number: string | null; notes: string | null }>
  timeline?: Array<{ type: string; title: string; description: string | null; user: string | null; occurred_at: string }>
  actions?: Record<ProcessActionKey, ActionGate>
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


export interface ChecklistItem {
  status: 'SATISFIED' | 'PENDING' | 'WAIVED'
  detail: string | null
  waivable: boolean
  waiver_reason: string | null
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

export type ClientRequestStatus = 'SUBMITTED' | 'IN_REVIEW' | 'NEEDS_INFO' | 'CONVERTED' | 'CANCELLED'

export interface RequestModule {
  brand: string
  model: string
  power_w: number | null
  quantity: number | null
}

export interface RequestInverter {
  brand: string
  model: string
  power_kw: number | null
  quantity: number | null
}

export interface RequestBeneficiary {
  uc_number: string
  holder_name?: string | null
  percentage: number | null
}

export interface RequestSystem {
  compensation_mode: string
  average_consumption_kwh: number | null
  is_property_owner: boolean
  installation_type: string
  roof_material: string | null
  installation_area_m2: number | null
  integrator: string | null
  modules: RequestModule[]
  inverters: RequestInverter[]
  has_battery: boolean
  storage_energy_kwh: number | null
  beneficiaries: RequestBeneficiary[]
  notes: string | null
}

export interface RequestDocument {
  id: string
  document_type: string
  label: string
  party: string
  original_name: string
  version: number
  review_status: string
  review_notes: string | null
  size_bytes: number
  created_at: string
}

export interface ClientObligation {
  type: string
  label: string
  required: boolean
  reason: string
  document: RequestDocument | null
}

export interface RequestMessage {
  from: 'client' | 'team' | 'system'
  author: string
  text: string
  at: string
}

export interface ClientRequest {
  id: string
  code: string
  status: { value: ClientRequestStatus; label: string }
  editable: boolean
  client?: { id: string; name: string; document: string; email: string | null; phone: string | null }
  consumer_unit?: ConsumerUnit
  system: RequestSystem
  declared_powers: { modules_kwp: number; inverters_kw: number }
  messages: RequestMessage[]
  technical_responsible: {
    id: string
    name: string
    council: string
    registration: string
    email: string | null
    phone: string | null
  } | null
  project: {
    id: string
    code: string
    process: { id: string; code: string; stage: { value: string; label: string }; status: { value: string; label: string } } | null
  } | null
  client_obligations: ClientObligation[]
  client_progress: { required: number; sent: number }
  technical_obligations?: Array<{ type: string; label: string }>
  documents: RequestDocument[]
  assigned_at: string | null
  converted_at: string | null
  submitted_at: string | null
  created_at: string
}

export interface PortalSummary {
  client: { uuid: string; name: string; email: string | null; phone: string | null } | null
  requests_total: number
  requests_open: number
  needs_info: number
  converted: number
  units: number
}
