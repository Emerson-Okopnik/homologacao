<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { FileText, House, LogOut, Plus, Rows3 } from '@lucide/vue'
import BrandLogo from '@/components/layout/BrandLogo.vue'
import ThemeToggle from '@/components/ui/ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const leaving = ref(false)
const initials = computed(() => (auth.user?.name ?? '').split(' ').filter(Boolean).slice(0, 2).map(n => n[0]).join(''))
const currentRequestPath = computed(() => /^\/portal\/solicitacoes\/[^/]+$/.test(route.path) && route.name === 'portal-request' ? route.path : null)
const documentsTarget = computed(() => currentRequestPath.value ? `${currentRequestPath.value}#documentos` : '/portal#documentos')
const navigation = computed(() => [
  { label: 'Início', icon: House, to: '/portal', active: route.path === '/portal' && !route.hash },
  { label: 'Minhas solicitações', mobileLabel: 'Solicitações', icon: Rows3, to: '/portal#minhas-solicitacoes', active: (route.path !== '/portal' || !!route.hash) && route.hash !== '#documentos' },
  { label: 'Meus documentos', mobileLabel: 'Documentos', icon: FileText, to: documentsTarget.value, active: route.hash === '#documentos' },
])

watch(() => route.fullPath, async () => {
  if (!route.hash) return
  await nextTick()
  document.getElementById(route.hash.slice(1))?.scrollIntoView({ behavior: 'smooth', block: 'start' })
})

async function logout() {
  leaving.value = true
  try {
    await auth.logout()
  } finally {
    await router.replace({ name: 'login' })
  }
}
</script>

<template>
  <div class="portal-shell">
    <a href="#conteudo" class="portal-skip">Ir para o conteúdo</a>
    <header class="portal-header">
      <div class="portal-header-inner">
        <RouterLink to="/portal" class="portal-brand" aria-label="VERIGRID — Início do portal">
          <BrandLogo class="portal-logo" />
        </RouterLink>
        <span class="portal-label">Portal do cliente</span>
        <nav aria-label="Portal" class="portal-desktop-nav">
          <RouterLink v-for="item in navigation" :key="item.label" :to="item.to" :class="{ active: item.active }" :aria-current="item.active ? 'page' : undefined">
            {{ item.label }}
          </RouterLink>
        </nav>
        <div class="portal-account">
          <ThemeToggle />
          <span class="portal-avatar" aria-hidden="true">{{ initials }}</span>
          <div class="portal-account-name">
            <p>{{ auth.user?.name }}</p>
            <span>{{ auth.portalClient?.name ?? 'Minha conta' }}</span>
          </div>
          <button type="button" class="portal-logout" :disabled="leaving" @click="logout">
            <LogOut class="size-4" aria-hidden="true" />
            <span class="sr-only">Sair</span>
          </button>
        </div>
      </div>
    </header>

    <main id="conteudo" class="portal-main">
      <RouterView />
    </main>

    <footer class="portal-footer">
      <span>© {{ new Date().getFullYear() }} VERIGRID · Portal do cliente</span>
      <RouterLink to="/portal/solicitacoes/nova"><Plus class="size-3.5" aria-hidden="true" />Nova solicitação</RouterLink>
    </footer>

    <nav aria-label="Navegação do portal no celular" class="portal-mobile-nav">
      <RouterLink v-for="item in navigation" :key="item.label" :to="item.to" :class="{ active: item.active }" :aria-current="item.active ? 'page' : undefined">
        <component :is="item.icon" class="size-5" aria-hidden="true" />
        <span>{{ item.mobileLabel ?? item.label }}</span>
      </RouterLink>
    </nav>
  </div>
</template>

<style scoped>
.portal-shell {
  display: flex;
  min-height: 100dvh;
  flex-direction: column;
  background: var(--color-canvas);
}

.portal-skip {
  position: absolute;
  left: 1rem;
  top: -5rem;
  z-index: 80;
  padding: .8rem 1rem;
  border-radius: .5rem;
  background: var(--color-navy);
  color: white;
}

.portal-skip:focus {
  top: 1rem;
}

.portal-header {
  border-bottom: 1px solid var(--color-line);
  background: var(--color-surface);
}

.portal-header-inner {
  display: flex;
  min-height: 88px;
  max-width: 1376px;
  margin: 0 auto;
  align-items: center;
  gap: 24px;
  padding: 12px 28px;
}

.portal-brand {
  display: block;
  flex-shrink: 0;
  width: 216px;
}

.portal-logo {
  width: 100%;
  height: auto;
}

.portal-label {
  border-left: 1px solid var(--color-line);
  padding-left: 24px;
  font-size: 9px;
  font-weight: 500;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--color-muted);
  white-space: nowrap;
}

.portal-desktop-nav {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.portal-desktop-nav a {
  display: flex;
  min-height: 40px;
  align-items: center;
  justify-content: center;
  border-radius: 7px;
  padding: 8px 15px;
  font-size: 11px;
  color: var(--color-muted);
  white-space: nowrap;
  transition: background .15s,color .15s;
}

.portal-desktop-nav a:hover,
.portal-desktop-nav a.active {
  background: var(--color-primary-soft);
  color: var(--color-primary);
}

.portal-desktop-nav a.active {
  font-weight: 600;
}

.portal-account {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-left: auto;
}

.portal-avatar {
  display: flex;
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--color-primary-soft);
  font-size: 11px;
  font-weight: 600;
  color: var(--color-primary);
}

.portal-account-name {
  max-width: 160px;
}

.portal-account-name p {
  overflow: hidden;
  font-size: 11px;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.portal-account-name span {
  display: block;
  overflow: hidden;
  margin-top: 3px;
  font-size: 9px;
  color: var(--color-muted);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.portal-logout {
  display: flex;
  min-height: 40px;
  width: 36px;
  align-items: center;
  justify-content: center;
  border-radius: 7px;
  color: var(--color-muted);
  cursor: pointer;
}

.portal-logout:hover {
  background: var(--color-canvas);
  color: var(--color-ink);
}

.portal-main {
  flex: 1;
  width: 100%;
  max-width: 1296px;
  margin: 0 auto;
  padding: 40px 28px 32px;
}

.portal-footer {
  display: flex;
  width: 100%;
  max-width: 1296px;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin: 0 auto;
  padding: 20px 28px 28px;
  font-size: 10px;
  color: var(--color-muted);
}

.portal-footer a {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--color-primary);
}

.portal-mobile-nav {
  display: none;
}

@media (max-width:1199px) {
  .portal-header-inner {
    gap: 16px;
  }

  .portal-label {
    display: none;
  }

  .portal-account-name {
    display: none;
  }

  .portal-desktop-nav a {
    padding-right: 12px;
    padding-left: 12px;
  }
}

@media (max-width:767px) {
  .portal-header-inner {
    min-height: 80px;
    padding: 10px 16px;
  }

  .portal-brand {
    width: clamp(140px, 42vw, 200px);
  }

  .portal-desktop-nav {
    display: none;
  }

  .portal-account {
    gap: 3px;
  }

  .portal-avatar {
    display: none;
    height: 32px;
    width: 32px;
    font-size: 10px;
  }

  .portal-logout {
    width: 32px;
  }

  .portal-main {
    padding: 24px 24px 32px;
  }

  .portal-footer {
    padding: 16px 24px 100px;
    font-size: 9px;
    align-items: flex-start;
  }

  .portal-footer a {
    display: none;
  }

  .portal-mobile-nav {
    position: fixed;
    z-index: 30;
    bottom: 0;
    left: 0;
    right: 0;
    display: grid;
    grid-template-columns: repeat(3,minmax(0,1fr));
    gap: 8px;
    border-top: 1px solid var(--color-line);
    padding: 7px 16px calc(10px + env(safe-area-inset-bottom));
    background: var(--color-surface);
  }

  .portal-mobile-nav a {
    display: flex;
    min-height: 52px;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 5px;
    border-radius: 8px;
    font-size: 9px;
    color: var(--color-muted);
  }

  .portal-mobile-nav a.active {
    background: var(--color-primary-soft);
    color: var(--color-primary);
    font-weight: 600;
  }
}

@media (prefers-reduced-motion:reduce) {
  .portal-desktop-nav a {
    transition: none;
  }
}
</style>
