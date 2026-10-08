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
  value: ProcessStatus
  label: string
  on_board: boolean
  terminal: boolean
  transitions: ProcessStatus[]
}

export interface ProjectSummaryProcess {
  id: string
  code: string
  status: ProcessStatus
  status_label: string
}

export interface Project {
  id: string
  code: string
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
}

export interface ProcessDocument {
  id: string
  document_type: string
  type_label: string
  version: number
  is_current: boolean
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
  process?: { id: string; code: string; client?: string | null }
}

export interface Pendency {
  id: string
  origin: 'interna' | 'distribuidora'
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
  allowed_transitions: Option<ProcessStatus>[]
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
  checklist?: Array<{ type: string; label: string; required: boolean; document: ProcessDocument | null }>
  readiness_issues?: string[]
}

export interface DashboardData {
  totals: {
    active: number
    waiting_distributor: number
    with_pendencies: number
    connected: number
    overdue: number
    open_pendencies: number
    documents_to_review: number
    connected_power_kwp: number
    avg_approval_days: number | null
  }
  by_status: Array<{ status: ProcessStatus; label: string; total: number }>
  recent: Array<{ id: string; code: string; client: string | null; status: ProcessStatus; status_label: string; status_changed_at: string | null }>
}
