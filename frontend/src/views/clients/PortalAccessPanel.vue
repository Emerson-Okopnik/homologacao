<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Eye, KeyRound, Plus } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, toApiError, type ApiError } from '@/lib/http'
import { formatDateTime } from '@/lib/format'

const props = defineProps<{ clientId: string; clientName: string; clientEmail: string | null; canManage: boolean }>()

interface PortalUser {
  id: string | null
  name: string
  email: string
  active: boolean
  last_login_at: string | null
}

const version = ref(0)
const { data, error, loading } = useApiQuery(
  () => [props.clientId, version.value] as const,
  ([id]) => api<{ data: PortalUser[] }>(`/clients/${id}/portal-users`),
)
const users = computed(() => data.value?.data ?? [])

const open = ref(false)
const form = reactive({ name: props.clientName, email: props.clientEmail ?? '', password: '' })
const saving = ref(false)
const saveError = ref<ApiError | null>(null)
const created = ref('')

async function submit() {
  saving.value = true
  saveError.value = null
  try {
    await api(`/clients/${props.clientId}/portal-users`, { method: 'POST', body: { ...form } })
    created.value = `Acesso criado. Envie ao cliente o e-mail ${form.email} e a senha definida; ele entra pela mesma tela de login.`
    open.value = false
    form.password = ''
    version.value++
  } catch (e) {
    saveError.value = toApiError(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="vg-panel space-y-5 p-6" aria-labelledby="portal-access">
    <header class="flex items-start justify-between gap-3">
      <div>
        <h2 id="portal-access" class="scroll-mt-28 text-sm font-semibold">Acesso ao portal</h2>
        <p class="mt-2 text-[11px] leading-5 text-muted">Solicitações e documentos do titular em um só lugar.</p>
      </div>
      <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-soft"><KeyRound class="size-4 text-primary" aria-hidden="true" /></span>
    </header>

    <InlineAlert v-if="created" tone="success">{{ created }}</InlineAlert>
    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-if="loading && !data" class="text-[11px] text-muted" role="status">Carregando acessos…</p>

    <ul v-if="users.length" class="divide-y divide-line">
      <li v-for="u in users" :key="u.email" class="py-4 first:pt-0 last:pb-0">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-xs font-medium">{{ u.name }}</p>
          <StatusBadge :tone="u.active ? 'success' : 'neutral'">{{ u.active ? 'Ativo' : 'Inativo' }}</StatusBadge>
        </div>
        <p class="mt-2 break-all text-[11px] text-ink">{{ u.email }}</p>
        <p class="mt-2 text-[10px] leading-5 text-muted">{{ u.last_login_at ? `Último acesso: ${formatDateTime(u.last_login_at)}` : 'Ainda não acessou o portal' }}</p>
      </li>
    </ul>
    <p v-else-if="!open && !error && !loading" class="rounded-lg bg-canvas px-4 py-5 text-[11px] leading-6 text-muted">Nenhum acesso criado para este cliente.</p>

    <div class="flex items-start gap-2.5 rounded-lg bg-primary-soft p-3.5">
      <Eye class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
      <p class="text-[10px] leading-5 text-success">O cliente pode abrir solicitações, informar UC e equipamentos e enviar os documentos dele.</p>
    </div>

    <BaseButton v-if="canManage && !open" variant="secondary" block @click="open = true">
      <Plus class="size-3.5" aria-hidden="true" />
      Criar acesso
    </BaseButton>

    <form v-if="open" class="space-y-4 border-t border-line pt-5" novalidate @submit.prevent="submit">
      <h3 class="text-xs font-semibold">Novo acesso ao portal</h3>
      <FormField v-model="form.name" label="Nome" :error="saveError?.firstError('name')" required />
      <FormField v-model="form.email" label="E-mail de login" type="email" :error="saveError?.firstError('email')" required />
      <FormField v-model="form.password" label="Senha inicial" type="password" hint="Mín. 8, letras e números" :error="saveError?.firstError('password')" required />
      <InlineAlert v-if="saveError && Object.keys(saveError.errors).length === 0">{{ saveError.message }}</InlineAlert>
      <div class="flex flex-wrap gap-2">
        <BaseButton type="submit" :loading="saving">Criar acesso</BaseButton>
        <BaseButton variant="ghost" @click="open = false">Cancelar</BaseButton>
      </div>
    </form>
  </section>
</template>
