import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { onUnauthorized } from './lib/http'
import { useAuthStore } from './stores/auth'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

onUnauthorized(() => {
  const auth = useAuthStore(pinia)
  if (auth.status !== 'authenticated') return
  auth.setSession(null)
  const current = router.currentRoute.value
  if (!current.meta.public) {
    void router.replace({ name: 'login', query: { redirect: current.fullPath } })
  }
})

app.mount('#app')
