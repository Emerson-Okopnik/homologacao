<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ArrowLeft, Mail, MapPin, Pencil, Phone, Plus, Trash2, UserRound, Zap } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ClientFormDialog from './ClientFormDialog.vue'
import ConsumerUnitDialog from './ConsumerUnitDialog.vue'
import ContactDialog from './ContactDialog.vue'
import PortalAccessPanel from './PortalAccessPanel.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api, toApiError, type ApiError } from '@/lib/http'
import { formatDocument, formatNumber } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import type { Client, ClientContact, ConsumerUnit, Distributor } from '@/types/api'

const route = useRoute()
const auth = useAuthStore()
const canManage = computed(() => auth.can('clients.manage'))

const version = ref(0)
const source = computed(() => ({ id: String(route.params.id), version: version.value }))
const { data, error, loading } = useApiQuery(source, (s) => api<{ data: Client }>(`/clients/${s.id}`))
const client = computed(() => data.value?.data ?? null)
const clientType = computed(() => client.value?.type.toUpperCase() === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física')
const documentLabel = computed(() => client.value?.type.toUpperCase() === 'PJ' ? 'CNPJ' : 'CPF')
const initialsOf = (name: string) => name.split(' ').filter(Boolean).slice(0, 2).map(part => part[0]?.toUpperCase()).join('')


const editingClient = ref(false)
const contactDialog = shallowRef<{ contact: ClientContact | null } | null>(null)
const unitDialog = shallowRef<{ unit: ConsumerUnit | null } | null>(null)
const distributors = shallowRef<Distributor[]>([])
const actionError = ref<ApiError | null>(null)

async function openUnit(unit: ConsumerUnit | null) {
  actionError.value = null
  try {
    if (distributors.value.length === 0) {
      distributors.value = (await api<{ data: Distributor[] }>('/distributors', { query: { active: true } })).data
    }
    unitDialog.value = { unit }
  } catch (e) {
    actionError.value = toApiError(e)
  }
}

async function removeContact(contact: ClientContact) {
  if (!confirm(`Remover o contato ${contact.name}?`)) return
  actionError.value = null
  try {
    await api(`/contacts/${contact.id}`, { method: 'DELETE' })
    version.value++
  } catch (e) {
    actionError.value = toApiError(e)
  }
}

function refresh() {
  editingClient.value = false
  contactDialog.value = null
  unitDialog.value = null
  version.value++
}
</script>

<template>
  <div class="mx-auto max-w-[1240px]">
    <RouterLink to="/clientes" class="mb-4 inline-flex items-center gap-2 text-[11px] text-muted hover:text-primary">
      <ArrowLeft class="size-3.5" aria-hidden="true" />
      Clientes
    </RouterLink>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading && !client" class="vg-empty-state" role="status">Carregando cliente…</p>

    <template v-if="client">
      <PageHeader :title="client.name" description="Cadastro, unidades consumidoras e acesso ao portal.">
        <template #actions>
          <BaseButton v-if="canManage" variant="secondary" @click="editingClient = true">
            <Pencil class="size-3.5" aria-hidden="true" />
            Editar cadastro
          </BaseButton>
          <RouterLink
            v-if="auth.can('projects.manage')"
            :to="{ path: '/projetos/novo', query: { client: client.id } }"
            class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-primary px-4 text-xs font-medium text-on-primary transition-colors hover:bg-primary-hover"
          >
            <Plus class="size-4" aria-hidden="true" />
            Novo projeto
          </RouterLink>
        </template>
      </PageHeader>

      <InlineAlert v-if="actionError" class="mb-5" :correlation-id="actionError.correlationId">{{ actionError.message }}</InlineAlert>

      <section id="client-summary" class="vg-panel grid gap-6 p-6 md:grid-cols-2 xl:grid-cols-[1.5fr_1fr_1.3fr_1fr] xl:items-center xl:gap-7" aria-label="Perfil do cliente">
        <div class="flex min-w-0 items-center gap-4">
          <span class="flex size-16 shrink-0 items-center justify-center rounded-full bg-primary-soft text-lg font-medium text-primary" aria-hidden="true">{{ initialsOf(client.name) }}</span>
          <div class="min-w-0">
            <h2 class="text-lg font-semibold leading-7 tracking-tight">{{ client.name }}</h2>
            <p v-if="client.trade_name" class="mt-1 break-words text-[11px] text-muted">{{ client.trade_name }}</p>
            <div class="mt-2.5 flex flex-wrap gap-2">
              <StatusBadge>{{ clientType }}</StatusBadge>
              <StatusBadge :tone="client.status === 'active' ? 'success' : 'neutral'">{{ client.status === 'active' ? 'Ativo' : 'Inativo' }}</StatusBadge>
            </div>
          </div>
        </div>
        <dl class="border-t border-line pt-5 md:border-t-0 md:border-l md:pt-0 md:pl-6">
          <dt class="vg-eyebrow">{{ documentLabel }}</dt>
          <dd class="mt-3 text-xs font-medium tabular-nums">{{ formatDocument(client.document) }}</dd>
        </dl>
        <dl class="min-w-0">
          <dt class="vg-eyebrow">E-mail</dt>
          <dd class="mt-3 break-words text-xs font-medium">{{ client.email ?? '—' }}</dd>
        </dl>
        <dl>
          <dt class="vg-eyebrow">Telefone</dt>
          <dd class="mt-3 text-xs font-medium">{{ client.phone ?? '—' }}</dd>
        </dl>
      </section>

      <nav class="my-6 flex flex-wrap gap-x-7 gap-y-3 border-b border-line text-[11px] text-muted" aria-label="Seções do cliente">
        <a href="#client-units" class="border-b-2 border-primary pb-3 font-medium text-primary">Unidades consumidoras · {{ client.consumer_units_count ?? client.consumer_units?.length ?? 0 }}</a>
        <a href="#client-contacts" class="pb-3 hover:text-primary">Contatos · {{ client.contacts?.length ?? 0 }}</a>
        <a v-if="auth.can('clients.view')" href="#portal-access" class="pb-3 hover:text-primary">Acesso ao portal</a>
        <span v-if="client.projects_count !== undefined" class="pb-3">Projetos vinculados · {{ client.projects_count }}</span>
      </nav>

      <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.9fr)_minmax(0,1fr)]">
        <div class="min-w-0 space-y-6">
          <section class="vg-panel" aria-labelledby="client-units">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-4">
              <div>
                <h2 id="client-units" class="scroll-mt-28 text-sm font-semibold">Unidades consumidoras</h2>
                <p class="mt-1 text-[11px] text-muted">Instalações vinculadas ao titular.</p>
              </div>
              <BaseButton v-if="canManage" variant="secondary" @click="openUnit(null)">
                <Plus class="size-3.5" aria-hidden="true" />
                Adicionar unidade
              </BaseButton>
            </header>
            <ul class="space-y-4 px-6 pb-6">
              <li v-for="unit in client.consumer_units ?? []" :key="unit.id" class="rounded-lg bg-canvas p-4">
                <div class="flex items-start gap-3.5">
                  <Zap class="mt-1 size-6 shrink-0 text-primary" aria-hidden="true" />
                  <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold">UC {{ unit.number }}</h3>
                    <p class="mt-1 text-[11px] text-muted">{{ unit.distributor?.name ?? 'Distribuidora não informada' }} · {{ unit.voltage_class }} · {{ unit.supply_type }}</p>
                  </div>
                  <StatusBadge :tone="unit.active ? 'success' : 'neutral'">{{ unit.active ? 'Ativa' : 'Inativa' }}</StatusBadge>
                </div>
                <div class="mt-4 flex flex-wrap items-end justify-between gap-3 border-t border-line pt-3.5">
                  <div class="min-w-0 flex-1">
                    <p class="flex items-start gap-2 text-[11px] leading-5">
                      <MapPin class="mt-0.5 size-3.5 shrink-0 text-muted" aria-hidden="true" />
                      {{ unit.full_address }}
                    </p>
                    <p class="mt-1 text-[10px] leading-5 text-muted">
                      Carga instalada {{ formatNumber(unit.installed_load_kw, 'kW') }}
                      <template v-if="unit.breaker_a"> · Disjuntor {{ unit.breaker_a }} A</template>
                    </p>
                  </div>
                  <BaseButton v-if="canManage" variant="secondary" @click="openUnit(unit)">
                    Editar unidade<span class="sr-only"> UC {{ unit.number }}</span>
                  </BaseButton>
                </div>
              </li>
              <li v-if="!client.consumer_units?.length" class="vg-empty-state rounded-lg bg-canvas">
                <Zap class="size-7 text-primary" aria-hidden="true" />
                Cadastre a primeira unidade consumidora para iniciar um projeto.
              </li>
            </ul>
          </section>

          <section class="vg-panel p-6" aria-labelledby="client-contacts">
            <header class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 id="client-contacts" class="scroll-mt-28 text-sm font-semibold">Contatos</h2>
                <p class="mt-1 text-[11px] text-muted">Pessoas de referência para esta homologação.</p>
              </div>
              <BaseButton v-if="canManage" variant="secondary" @click="contactDialog = { contact: null }">
                <Plus class="size-3.5" aria-hidden="true" />
                Adicionar contato
              </BaseButton>
            </header>
            <ul class="mt-5 divide-y divide-line">
              <li v-for="contact in client.contacts ?? []" :key="contact.id" class="flex items-start gap-3 py-4 first:pt-0 last:pb-0">
                <span class="vg-avatar" aria-hidden="true">{{ initialsOf(contact.name) }}</span>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="text-xs font-medium">{{ contact.name }}</p>
                    <StatusBadge v-if="contact.is_legal_representative" tone="info">Representante</StatusBadge>
                  </div>
                  <p v-if="contact.role" class="mt-1 text-[10px] text-muted">{{ contact.role }}</p>
                  <p v-if="contact.email" class="mt-2 flex items-start gap-2 break-all text-[11px] text-muted"><Mail class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ contact.email }}</p>
                  <p v-if="contact.phone" class="mt-1 flex items-center gap-2 text-[11px] text-muted"><Phone class="size-3.5 shrink-0" aria-hidden="true" />{{ contact.phone }}</p>
                </div>
                <div v-if="canManage" class="flex shrink-0 gap-1">
                  <button type="button" class="flex size-8 items-center justify-center rounded-md text-muted hover:bg-canvas hover:text-ink" @click="contactDialog = { contact }">
                    <Pencil class="size-3.5" aria-hidden="true" /><span class="sr-only">Editar {{ contact.name }}</span>
                  </button>
                  <button type="button" class="flex size-8 items-center justify-center rounded-md text-muted hover:bg-danger-soft hover:text-danger" @click="removeContact(contact)">
                    <Trash2 class="size-3.5" aria-hidden="true" /><span class="sr-only">Remover {{ contact.name }}</span>
                  </button>
                </div>
              </li>
              <li v-if="!client.contacts?.length" class="vg-empty-state rounded-lg bg-canvas">
                <UserRound class="size-7 text-primary" aria-hidden="true" />
                Nenhum contato cadastrado.
              </li>
            </ul>
          </section>
        </div>

        <div class="min-w-0 space-y-6">
          <PortalAccessPanel
            v-if="auth.can('clients.view')"
            :client-id="client.id"
            :client-name="client.name"
            :client-email="client.email ?? null"
            :can-manage="canManage"
          />
          <section v-if="client.notes" class="vg-panel p-6" aria-labelledby="client-notes">
            <h2 id="client-notes" class="text-sm font-semibold">Observações do cadastro</h2>
            <p class="mt-4 whitespace-pre-line text-xs leading-6 text-muted">{{ client.notes }}</p>
          </section>
        </div>
      </div>

      <ClientFormDialog v-if="editingClient" :client="client" @close="editingClient = false" @saved="refresh" />
      <ContactDialog
        v-if="contactDialog"
        :client-id="client.id"
        :contact="contactDialog.contact"
        @close="contactDialog = null"
        @saved="refresh"
      />
      <ConsumerUnitDialog
        v-if="unitDialog"
        :client-id="client.id"
        :unit="unitDialog.unit"
        :distributors="distributors"
        @close="unitDialog = null"
        @saved="refresh"
      />
    </template>
  </div>
</template>
