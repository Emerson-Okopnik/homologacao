<script setup lang="ts">
import { onMounted, reactive, ref, useTemplateRef } from 'vue'
import { X } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { api, ApiError } from '@/lib/http'
import type { Role, User } from '@/types/api'

const props = defineProps<{ user: User | null; roles: Role[] }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const dialog = useTemplateRef<HTMLDialogElement>('dialog')

const form = reactive({
  name: props.user?.name ?? '',
  email: props.user?.email ?? '',
  password: '',
  active: props.user?.active ?? true,
  roles: props.user?.roles?.map((role) => role.slug) ?? [],
})
const submitting = ref(false)
const error = ref<ApiError | null>(null)

onMounted(() => dialog.value?.showModal())

async function submit() {
  submitting.value = true
  error.value = null
  const body: Record<string, unknown> = { ...form }
  if (props.user && !form.password) delete body.password
  try {
    if (props.user) {
      await api(`/users/${props.user.id}`, { method: 'PATCH', body })
    } else {
      await api('/users', { method: 'POST', body })
    }
    emit('saved')
  } catch (e) {
    error.value = e instanceof ApiError ? e : new ApiError(0, 'Falha de conexão com o servidor.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <dialog
    ref="dialog"
    aria-labelledby="user-dialog-title"
    class="m-auto w-full max-w-lg rounded-2xl border border-line bg-surface p-0 text-ink shadow-xl backdrop:bg-ink/50"
    @close="emit('close')"
  >
    <form class="flex flex-col" novalidate @submit.prevent="submit">
      <header class="flex items-center justify-between border-b border-line px-6 py-4">
        <h2 id="user-dialog-title" class="text-lg font-semibold">{{ user ? 'Editar usuário' : 'Novo usuário' }}</h2>
        <button type="button" class="rounded-md p-1 text-muted hover:bg-canvas hover:text-ink" @click="dialog?.close()">
          <X class="size-5" aria-hidden="true" />
          <span class="sr-only">Fechar</span>
        </button>
      </header>

      <div class="flex flex-col gap-4 px-6 py-5">
        <InlineAlert v-if="error && error.status !== 422" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>

        <FormField v-model="form.name" label="Nome" autocomplete="off" required :error="error?.firstError('name')" />
        <FormField v-model="form.email" label="E-mail" type="email" autocomplete="off" required :error="error?.firstError('email')" />
        <FormField
          v-model="form.password"
          :label="user ? 'Nova senha (opcional)' : 'Senha inicial'"
          type="password"
          autocomplete="new-password"
          :required="!user"
          hint="Mínimo de 10 caracteres, com maiúsculas, minúsculas e números."
          :error="error?.firstError('password')"
        />

        <fieldset>
          <legend class="text-sm font-medium">Papéis</legend>
          <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <label
              v-for="role in roles"
              :key="role.slug"
              class="flex items-start gap-2 rounded-lg border border-line p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary-soft"
            >
              <input v-model="form.roles" type="checkbox" :value="role.slug" class="mt-0.5 size-4 accent-primary" />
              <span>
                <span class="block font-medium">{{ role.name }}</span>
                <span v-if="role.description" class="block text-xs text-muted">{{ role.description }}</span>
              </span>
            </label>
          </div>
          <p v-if="error?.firstError('roles')" class="mt-1.5 text-xs text-danger">{{ error.firstError('roles') }}</p>
        </fieldset>

        <label v-if="user" class="flex items-center gap-2 text-sm">
          <input v-model="form.active" type="checkbox" class="size-4 accent-primary" />
          Usuário ativo
        </label>
        <p v-if="error?.firstError('active')" class="-mt-2 text-xs text-danger">{{ error.firstError('active') }}</p>
      </div>

      <footer class="flex justify-end gap-2 border-t border-line px-6 py-4">
        <BaseButton variant="secondary" @click="dialog?.close()">Cancelar</BaseButton>
        <BaseButton type="submit" :loading="submitting">Salvar</BaseButton>
      </footer>
    </form>
  </dialog>
</template>
