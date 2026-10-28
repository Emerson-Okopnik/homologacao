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
    submitDisabled?: boolean
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
    class="m-auto w-[calc(100%_-_2rem)] rounded-[14px] border border-line bg-surface p-0 text-ink shadow-[0_20px_70px_-20px_#0A1F5C45] backdrop:bg-backdrop/60 backdrop:backdrop-blur-[2px]"
    :class="sizes[size]"
    @close="emit('close')"
  >
    <form class="flex max-h-[90dvh] flex-col" novalidate @submit.prevent="emit('submit')">
      <header class="flex items-start justify-between gap-4 px-6 pt-6 pb-2">
        <h2 :id="titleId" class="min-w-0 pt-1 text-xl font-semibold leading-7 tracking-tight">{{ title }}</h2>
        <button type="button" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-canvas text-muted hover:bg-blue-soft hover:text-ink" @click="dialog?.close()">
          <X class="size-5" aria-hidden="true" />
          <span class="sr-only">Fechar</span>
        </button>
      </header>

      <div class="flex flex-col gap-5 overflow-y-auto px-6 py-5">
        <InlineAlert v-if="error && error.status !== 422" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
        <InlineAlert v-else-if="error && Object.keys(error.errors).length === 0">{{ error.message }}</InlineAlert>
        <slot />
      </div>

      <footer class="flex flex-wrap justify-end gap-3 border-t border-line px-6 py-5">
        <BaseButton variant="secondary" @click="dialog?.close()">Cancelar</BaseButton>
        <BaseButton type="submit" :loading="submitting" :disabled="submitDisabled">{{ submitLabel }}</BaseButton>
      </footer>
    </form>
  </dialog>
</template>
