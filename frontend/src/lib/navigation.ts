import type { Component } from 'vue'
import {
  ClipboardCheck,
  FileText,
  FolderKanban,
  LayoutDashboard,
  ScrollText,
  Settings,
  SquareKanban,
  Users,
  UsersRound,
} from '@lucide/vue'
import type { PermissionKey } from '@/types/api'

export interface NavItem {
  label: string
  icon: Component
  permission: PermissionKey
  to?: string
}

export interface NavSection {
  title: string
  items: NavItem[]
}

/** Itens sem `to` pertencem a Waves futuras e aparecem como "Em breve". */
export const navigation: NavSection[] = [
  {
    title: 'Operação',
    items: [
      { label: 'Dashboard', icon: LayoutDashboard, permission: 'dashboard.view', to: '/' },
      { label: 'Clientes', icon: UsersRound, permission: 'clients.view' },
      { label: 'Projetos', icon: FolderKanban, permission: 'projects.view' },
      { label: 'Kanban', icon: SquareKanban, permission: 'homologations.view' },
      { label: 'Homologações', icon: ClipboardCheck, permission: 'homologations.view' },
      { label: 'Documentos', icon: FileText, permission: 'documents.view' },
    ],
  },
  {
    title: 'Administração',
    items: [
      { label: 'Usuários', icon: Users, permission: 'users.view', to: '/usuarios' },
      { label: 'Auditoria', icon: ScrollText, permission: 'audit.view', to: '/auditoria' },
      { label: 'Configurações', icon: Settings, permission: 'workflow.configure' },
    ],
  },
]
