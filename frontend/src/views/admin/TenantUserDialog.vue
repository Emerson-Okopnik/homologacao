<script setup lang="ts">
import { reactive, ref } from 'vue'
import BaseDialog from '@/components/ui/BaseDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import { api, ApiError, toApiError } from '@/lib/http'
import type { Role, User } from '@/types/api'

const props = defineProps<{ tenantId: string; user: User | null; roles: Role[]; selfId: string | undefined }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const form = reactive({
  name: '',
  email: '',
  password: '',
  roles: props.user?.roles?.map((r) => r.slug) ?? [],
  active: props.user?.active ?? true,
  is_super_admin: props.user?.is_super_admin ?? false,
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)
const isSelf = props.user?.id === props.selfId

async function submit() {
  submitting.value = true
  error.value = null
  try {
    if (props.user) {
      await api(`/admin/tenants/${props.tenantId}/users/${props.user.id}`, {
        method: 'PATCH',
        body: { roles: form.roles, active: form.active, is_super_admin: form.is_super_admin },
      })
    } else {
      await api(`/admin/tenants/${props.tenantId}/users`, {
        method: 'POST',
        body: { name: form.name, email: form.email, password: form.password, roles: form.roles },
      })
    }
    emit('saved')
  } catch (e) {
    error.value = toApiError(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseDialog
    :title="user ? `Acesso de ${user.name}` : 'Novo usuário'"
    :submitting="submitting"
    :error="error"
    @close="emit('close')"
    @submit="submit"
  >
    <template v-if="!user">
      <FormField v-model="form.name" label="Nome" autocomplete="off" required :error="error?.firstError('name')" />
      <FormField v-model="form.email" label="E-mail" type="email" autocomplete="off" required :error="error?.firstError('email')" />
      <FormField
        v-model="form.password"
        label="Senha inicial"
        type="password"
        autocomplete="new-password"
        required
        hint="Mínimo de 10 caracteres, com maiúsculas, minúsculas e números."
        :error="error?.firstError('password')"
      />
    </template>
    <p v-else class="text-sm text-muted">{{ user.email }}</p>

    <fieldset>
      <legend class="text-sm font-medium">Perfis</legend>
      <div class="mt-2 grid gap-2 sm:grid-cols-2">
        <label
          v-for="role in roles"
          :key="role.slug"
          class="flex items-start gap-2 rounded-lg border border-line p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary-soft"
        >
          <input v-model="form.roles" type="checkbox" :value="role.slug" class="mt-0.5 size-4 accent-primary" />
          <span>
            <span class="block font-medium">{{ role.name }}</span>
            <span class="block text-xs text-muted">{{ role.permissions?.length ?? 0 }} permissões</span>
          </span>
        </label>
      </div>
      <p v-if="error?.firstError('roles')" class="mt-1.5 text-xs text-danger">{{ error.firstError('roles') }}</p>
    </fieldset>

    <div v-if="user" class="flex flex-col gap-3 rounded-xl border border-line p-4">
      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.active" type="checkbox" class="size-4 accent-primary" :disabled="isSelf" />
        Usuário ativo
      </label>
      <p v-if="error?.firstError('active')" class="text-xs text-danger">{{ error.firstError('active') }}</p>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="form.is_super_admin" type="checkbox" class="mt-0.5 size-4 accent-primary" :disabled="isSelf" />
        <span>
          <span class="block font-medium">Super administrador da plataforma</span>
          <span class="block text-xs text-muted">Pode gerenciar todos os tenants, perfis e usuários.</span>
        </span>
      </label>
      <p v-if="error?.firstError('is_super_admin')" class="text-xs text-danger">{{ error.firstError('is_super_admin') }}</p>
    </div>
  </BaseDialog>
</template>
