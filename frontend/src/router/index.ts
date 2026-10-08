import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { PermissionKey } from '@/types/api'
import AppLayout from '@/layouts/AppLayout.vue'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    guestOnly?: boolean
    permission?: PermissionKey
    title?: string
  }
}

export { safeRedirect } from './redirect'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/auth/LoginView.vue'),
      meta: { public: true, guestOnly: true, title: 'Entrar' },
    },
    {
      path: '/esqueci-senha',
      name: 'forgot-password',
      component: () => import('@/views/auth/ForgotPasswordView.vue'),
      meta: { public: true, guestOnly: true, title: 'Recuperar senha' },
    },
    {
      path: '/redefinir-senha/:token',
      name: 'reset-password',
      component: () => import('@/views/auth/ResetPasswordView.vue'),
      meta: { public: true, guestOnly: true, title: 'Redefinir senha' },
    },
    {
      path: '/',
      component: AppLayout,
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
          meta: { title: 'Dashboard' },
        },
        {
          path: 'usuarios',
          name: 'users',
          component: () => import('@/views/users/UsersView.vue'),
          meta: { permission: 'users.view', title: 'Usuários' },
        },
        {
          path: 'auditoria',
          name: 'audit',
          component: () => import('@/views/audit/AuditView.vue'),
          meta: { permission: 'audit.view', title: 'Auditoria' },
        },
        {
          path: 'acesso-negado',
          name: 'forbidden',
          component: () => import('@/views/ForbiddenView.vue'),
          meta: { title: 'Acesso negado' },
        },
        {
          path: ':pathMatch(.*)*',
          name: 'not-found',
          component: () => import('@/views/NotFoundView.vue'),
          meta: { title: 'Página não encontrada' },
        },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.ensureLoaded()

  if (to.meta.public) {
    return to.meta.guestOnly && auth.status === 'authenticated' ? { name: 'dashboard' } : true
  }

  if (auth.status !== 'authenticated') {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }

  if (to.meta.permission && !auth.can(to.meta.permission)) {
    return { name: 'forbidden' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · Homologa Solar` : 'Homologa Solar'
})
