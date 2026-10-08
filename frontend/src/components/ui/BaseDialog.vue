<script setup lang="ts">
import { onMounted, useId, useTemplateRef } from 'vue'
import { X } from '@lucide/vue'
import BaseButton from './BaseButton.vue'
import InlineAlert from './InlineAlert.vue'
import type { ApiError } from '@/lib/http'

withDefaults(
  defineProps<{
    title: string
    submitLabel?: string
    submitting?: boolean
    error?: ApiError | null
    size?: 'md' | 'lg' | 'xl'
  }>(),
  { submitLabel: 'Salvar', submitting: false, error: null, size: 'md' },
)
const emit = defineEmits<{ close: []; submit: [] }>()

const dialog = useTemplateRef<HTMLDialogElement>('dialog')
const titleId = useId()
const sizes = { md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }

onMounted(() => dialog.value?.showModal())

defineExpose({ close: () => dialog.value?.close() })
</script>

<template>
  <dialog
    ref="dialog"
    :aria-labelledby="titleId"
    class="m-auto w-full rounded-2xl border border-line bg-surface p-0 text-ink shadow-xl backdrop:bg-ink/50"
    :class="sizes[size]"
    @close="emit('close')"
  >
    <form class="flex max-h-[90dvh] flex-col" novalidate @submit.prevent="emit('submit')">
      <header class="flex items-center justify-between border-b border-line px-6 py-4">
        <h2 :id="titleId" class="text-lg font-semibold">{{ title }}</h2>
        <button type="button" class="rounded-md p-1 text-muted hover:bg-canvas hover:text-ink" @click="dialog?.close()">
          <X class="size-5" aria-hidden="true" />
          <span class="sr-only">Fechar</span>
        </button>
      </header>

      <div class="flex flex-col gap-4 overflow-y-auto px-6 py-5">
        <InlineAlert v-if="error && error.status !== 422" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
        <InlineAlert v-else-if="error && Object.keys(error.errors).length === 0">{{ error.message }}</InlineAlert>
        <slot />
      </div>

      <footer class="flex justify-end gap-2 border-t border-line px-6 py-4">
        <BaseButton variant="secondary" @click="dialog?.close()">Cancelar</BaseButton>
        <BaseButton type="submit" :loading="submitting">{{ submitLabel }}</BaseButton>
      </footer>
    </form>
  </dialog>
</template>
