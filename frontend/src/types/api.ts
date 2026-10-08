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
}

export interface Me {
  user: User
  tenant: { id: string; name: string; slug: string } | null
  permissions: PermissionKey[]
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
