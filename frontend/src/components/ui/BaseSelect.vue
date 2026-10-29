<script setup lang="ts" generic="T extends string | number">
import { computed, nextTick, onScopeDispose, ref, useId, useTemplateRef, watch, type CSSProperties } from 'vue'
import { Check, ChevronDown } from '@lucide/vue'

const props = withDefaults(defineProps<{
  id?: string
  name?: string
  options: ReadonlyArray<{ value: T; label: string; disabled?: boolean }>
  placeholder?: string
  disabled?: boolean
  required?: boolean
  invalid?: boolean
  ariaLabel?: string
  ariaLabelledby?: string
  ariaDescribedby?: string
  size?: 'sm' | 'md'
}>(), { size: 'md' })
const model = defineModel<T>({ required: true })
const generatedId = useId()
const controlId = computed(() => props.id ?? generatedId)
const listId = `${generatedId}-options`
const root = useTemplateRef<HTMLElement>('root')
const trigger = useTemplateRef<HTMLButtonElement>('trigger')
const menu = useTemplateRef<HTMLElement>('menu')
const open = ref(false)
const activeIndex = ref(-1)
const menuStyle = ref<CSSProperties>({ inset: 'auto', margin: 0 })
const items = computed(() => props.placeholder === undefined ? props.options : [
  { value: '' as T, label: props.placeholder }, ...props.options,
])
const selected = computed(() => items.value.find(option => option.value === model.value))
const enabled = computed(() => items.value.map((option, index) => option.disabled ? -1 : index).filter(index => index >= 0))
let search = ''
let searchTimer: ReturnType<typeof setTimeout> | undefined

function positionMenu() {
  if (!trigger.value || !open.value) return
  const rect = trigger.value.getBoundingClientRect()
  const below = window.innerHeight - rect.bottom - 12
  const above = rect.top - 12
  const upwards = below < 200 && above > below
  const height = Math.max(0, Math.min(280, upwards ? above : below))
  const width = Math.min(rect.width, window.innerWidth - 16)
  menuStyle.value = {
    inset: 'auto', margin: 0, width: `${width}px`, maxHeight: `${height}px`,
    left: `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`,
    ...(upwards ? { bottom: `${window.innerHeight - rect.top + 6}px` } : { top: `${rect.bottom + 6}px` }),
  }
}

function scrollToActive() {
  void nextTick(() => document.getElementById(`${listId}-${activeIndex.value}`)?.scrollIntoView?.({ block: 'nearest' }))
}

function show(last = false) {
  if (props.disabled || open.value) return
  open.value = true
  const index = items.value.findIndex(option => option.value === model.value && !option.disabled)
  activeIndex.value = index >= 0 ? index : (last ? enabled.value.at(-1) : enabled.value[0]) ?? -1
}

function close(restoreFocus = false) {
  open.value = false
  search = ''
  clearTimeout(searchTimer)
  if (restoreFocus) trigger.value?.focus()
}

function choose(index: number) {
  const option = items.value[index]
  if (!option || option.disabled || props.disabled) return
  model.value = option.value
  close(true)
}

function move(direction: number) {
  const index = enabled.value.indexOf(activeIndex.value)
  activeIndex.value = enabled.value[Math.max(0, Math.min(enabled.value.length - 1, index + direction))] ?? -1
  scrollToActive()
}

function onKeydown(event: KeyboardEvent) {
  if (props.disabled || event.ctrlKey || event.metaKey || event.isComposing) return
  if (event.key === 'Tab') { close(); return }
  if (event.key === 'Escape') {
    if (open.value) { event.preventDefault(); event.stopPropagation(); close(true) }
    return
  }
  if (['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', ' '].includes(event.key)) {
    event.preventDefault()
    if (event.key === 'Enter' || event.key === ' ') {
      if (open.value) choose(activeIndex.value)
      else show()
    } else if (event.key === 'Home' || event.key === 'End') {
      show()
      activeIndex.value = (event.key === 'Home' ? enabled.value[0] : enabled.value.at(-1)) ?? -1
      scrollToActive()
    } else if (!open.value) show(event.key === 'ArrowUp')
    else move(event.key === 'ArrowDown' ? 1 : -1)
    return
  }
  if (event.key.length === 1 && !event.altKey) {
    event.preventDefault()
    show()
    clearTimeout(searchTimer)
    search += event.key
    const normalize = (value: string) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR')
    const term = normalize(search)
    const index = enabled.value.find(index => normalize(items.value[index]!.label).startsWith(term))
    if (index !== undefined) { activeIndex.value = index; scrollToActive() }
    searchTimer = setTimeout(() => { search = '' }, 600)
  }
}

function onNativeChange(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  const index = items.value.findIndex(option => String(option.value) === value)
  if (index >= 0 && !props.disabled) model.value = items.value[index]!.value
}

watch(open, (value, _previous, onCleanup) => {
  if (!value) return
  const outside = (event: Event) => {
    if (event.target instanceof Node && !root.value?.contains(event.target)) close()
  }
  document.addEventListener('pointerdown', outside, true)
  document.addEventListener('focusin', outside)
  window.addEventListener('resize', positionMenu)
  document.addEventListener('scroll', positionMenu, true)
  onCleanup(() => {
    document.removeEventListener('pointerdown', outside, true)
    document.removeEventListener('focusin', outside)
    window.removeEventListener('resize', positionMenu)
    document.removeEventListener('scroll', positionMenu, true)
  })
  void nextTick(() => {
    if (!open.value) return
    positionMenu()
    // A camada superior mantém o menu visível em painéis e diálogos com rolagem.
    menu.value?.showPopover?.()
    scrollToActive()
  })
})
watch(() => props.disabled, value => { if (value) close() })
watch(items, () => {
  if (open.value) {
    activeIndex.value = items.value.findIndex(option => option.value === model.value && !option.disabled)
    if (activeIndex.value < 0) activeIndex.value = enabled.value[0] ?? -1
  }
})
onScopeDispose(() => clearTimeout(searchTimer))
defineExpose({ focus: () => trigger.value?.focus() })
</script>

<template>
  <div ref="root" class="relative min-w-0">
    <button
      :id="controlId"
      ref="trigger"
      type="button"
      role="combobox"
      :disabled="disabled"
      :aria-label="ariaLabel"
      :aria-labelledby="ariaLabelledby"
      :aria-describedby="ariaDescribedby"
      :aria-invalid="invalid || undefined"
      :aria-required="required || undefined"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-controls="open ? listId : undefined"
      :aria-activedescendant="open && activeIndex >= 0 ? `${listId}-${activeIndex}` : undefined"
      class="flex w-full items-center justify-between gap-3 rounded-[7px] border bg-surface text-left text-xs text-ink transition-shadow focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15 disabled:cursor-not-allowed disabled:bg-canvas disabled:opacity-65"
      :class="[size === 'sm' ? 'h-9 px-2.5' : 'h-[46px] px-3.5', invalid ? 'border-danger' : open ? 'border-primary ring-2 ring-primary/15' : 'border-line']"
      @click="open ? close() : show()"
      @keydown="onKeydown"
    >
      <span class="truncate" :class="!selected && 'text-muted'">{{ selected?.label ?? placeholder ?? 'Selecione' }}</span>
      <ChevronDown class="size-3.5 shrink-0 text-muted transition-transform" :class="open && 'rotate-180'" aria-hidden="true" />
    </button>

    <!-- Preserva envio de formulários e validação nativa sem duplicar o controle acessível. -->
    <select :value="model" :name="name" :required="required" :disabled="disabled" tabindex="-1" aria-hidden="true" class="sr-only pointer-events-none" @change="onNativeChange" @invalid.prevent="trigger?.focus()">
      <option v-for="option in items" :key="option.value" :value="option.value" :disabled="option.disabled">{{ option.label }}</option>
    </select>

    <div
      v-if="open"
      :id="listId"
      ref="menu"
      role="listbox"
      :aria-label="ariaLabel"
      :aria-labelledby="ariaLabelledby ?? (!ariaLabel ? controlId : undefined)"
      popover="manual"
      class="fixed z-[100] overflow-y-auto overscroll-contain rounded-lg border border-line bg-surface p-1 text-xs text-ink shadow-[0_8px_28px_-6px_#0A1F5C30]"
      :style="menuStyle"
      @mousedown.prevent
    >
      <div
        v-for="(option, index) in items"
        :id="`${listId}-${index}`"
        :key="option.value"
        role="option"
        :aria-selected="option.value === model"
        :aria-disabled="option.disabled || undefined"
        class="flex min-h-9 items-center justify-between gap-3 rounded-md px-3 py-2 leading-5"
        :class="option.disabled ? 'cursor-not-allowed opacity-40' : [
          'cursor-pointer',
          option.value === model ? 'bg-primary-soft font-medium text-primary' : activeIndex === index ? 'bg-canvas' : '',
          activeIndex === index && 'outline-1 -outline-offset-1 outline-primary/30',
        ]"
        @pointermove="!option.disabled && (activeIndex = index)"
        @click="choose(index)"
      >
        <span class="min-w-0 break-words">{{ option.label }}</span>
        <Check v-if="option.value === model" class="size-3.5 shrink-0" aria-hidden="true" />
      </div>
      <p v-if="items.length === 0" class="px-3 py-3 text-muted">Nenhuma opção disponível</p>
    </div>
  </div>
</template>
