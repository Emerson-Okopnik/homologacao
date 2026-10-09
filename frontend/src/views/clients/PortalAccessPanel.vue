<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { KeyRound } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
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
const { data, error } = useApiQuery(
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
  <section class="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-6 lg:col-span-3" aria-labelledby="portal-access">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex flex-col gap-1">
        <h2 id="portal-access" class="flex items-center gap-2 font-semibold text-ink">
          <KeyRound class="size-4 text-primary" aria-hidden="true" />Acesso ao portal do cliente
        </h2>
        <p class="text-sm text-muted">Com o acesso, o próprio cliente abre solicitações, informa UC e equipamentos e envia os documentos dele.</p>
      </div>
      <BaseButton v-if="canManage && !open" variant="secondary" @click="open = true">Criar acesso</BaseButton>
    </div>

    <p v-if="created" class="rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-ink" role="status">{{ created }}</p>
    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>

    <form v-if="open" class="grid gap-4 md:grid-cols-3" novalidate @submit.prevent="submit">
      <FormField v-model="form.name" label="Nome" :error="saveError?.firstError('name')" required />
      <FormField v-model="form.email" label="E-mail de login" type="email" :error="saveError?.firstError('email')" required />
      <FormField v-model="form.password" label="Senha inicial" type="password" hint="Mín. 8, letras e números" :error="saveError?.firstError('password')" required />
      <InlineAlert v-if="saveError && Object.keys(saveError.errors).length === 0" class="md:col-span-3">{{ saveError.message }}</InlineAlert>
      <div class="flex gap-2 md:col-span-3">
        <BaseButton type="submit" :loading="saving">Criar acesso</BaseButton>
        <BaseButton variant="ghost" @click="open = false">Cancelar</BaseButton>
      </div>
    </form>

    <ul v-if="users.length" class="divide-y divide-line rounded-xl border border-line">
      <li v-for="u in users" :key="u.email" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
        <span class="text-ink">{{ u.name }} · <span class="text-muted">{{ u.email }}</span></span>
        <span class="text-xs text-muted">{{ u.last_login_at ? `Último acesso ${formatDateTime(u.last_login_at)}` : 'Nunca acessou' }}</span>
      </li>
    </ul>
    <p v-else-if="!open && !error" class="text-sm text-muted">Nenhum acesso criado para este cliente.</p>
  </section>
</template>
