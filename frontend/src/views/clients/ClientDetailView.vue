<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ArrowLeft, MapPin, Pencil, Plus, Trash2, UserRound } from '@lucide/vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ClientFormDialog from './ClientFormDialog.vue'
import ConsumerUnitDialog from './ConsumerUnitDialog.vue'
import ContactDialog from './ContactDialog.vue'
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

const editingClient = ref(false)
const contactDialog = shallowRef<{ contact: ClientContact | null } | null>(null)
const unitDialog = shallowRef<{ unit: ConsumerUnit | null } | null>(null)
const distributors = shallowRef<Distributor[]>([])
const actionError = ref<ApiError | null>(null)

async function openUnit(unit: ConsumerUnit | null) {
  if (distributors.value.length === 0) {
    distributors.value = (await api<{ data: Distributor[] }>('/distributors', { query: { active: true } })).data
  }
  unitDialog.value = { unit }
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
  <div class="mx-auto max-w-6xl">
    <RouterLink to="/clientes" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink">
      <ArrowLeft class="size-4" aria-hidden="true" />
      Clientes
    </RouterLink>

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading && !client" class="py-12 text-center text-sm text-muted">Carregando…</p>

    <template v-if="client">
      <PageHeader :title="client.name" :description="`${client.type} · ${formatDocument(client.document)}`">
        <template #actions>
          <StatusBadge :tone="client.status === 'active' ? 'success' : 'neutral'">
            {{ client.status === 'active' ? 'Ativo' : 'Inativo' }}
          </StatusBadge>
          <BaseButton v-if="canManage" variant="secondary" @click="editingClient = true">
            <Pencil class="size-4" aria-hidden="true" />
            Editar
          </BaseButton>
          <RouterLink
            v-if="auth.can('projects.manage')"
            :to="{ path: '/projetos/novo', query: { client: client.id } }"
            class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover"
          >
            <Plus class="size-4" aria-hidden="true" />
            Novo projeto
          </RouterLink>
        </template>
      </PageHeader>

      <InlineAlert v-if="actionError" class="mb-4" :correlation-id="actionError.correlationId">{{ actionError.message }}</InlineAlert>

      <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-2xl border border-line bg-surface p-6 lg:col-span-1" aria-labelledby="client-data">
          <h2 id="client-data" class="font-semibold">Dados cadastrais</h2>
          <dl class="mt-4 flex flex-col gap-3 text-sm">
            <div v-if="client.trade_name">
              <dt class="text-xs text-muted">Nome fantasia</dt>
              <dd>{{ client.trade_name }}</dd>
            </div>
            <div>
              <dt class="text-xs text-muted">E-mail</dt>
              <dd class="break-all">{{ client.email ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-muted">Telefone</dt>
              <dd>{{ client.phone ?? '—' }}</dd>
            </div>
            <div v-if="client.notes">
              <dt class="text-xs text-muted">Observações</dt>
              <dd class="whitespace-pre-line text-pretty">{{ client.notes }}</dd>
            </div>
          </dl>

          <div class="mt-6 flex items-center justify-between border-t border-line pt-4">
            <h3 class="text-sm font-semibold">Contatos</h3>
            <BaseButton v-if="canManage" variant="ghost" @click="contactDialog = { contact: null }">
              <Plus class="size-4" aria-hidden="true" />
              Adicionar
            </BaseButton>
          </div>
          <ul class="mt-2 flex flex-col gap-2">
            <li v-for="contact in client.contacts ?? []" :key="contact.id" class="flex items-start gap-3 rounded-lg bg-canvas p-3 text-sm">
              <UserRound class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />
              <div class="min-w-0 flex-1">
                <p class="font-medium">
                  {{ contact.name }}
                  <StatusBadge v-if="contact.is_legal_representative" tone="info" class="ml-1">Representante</StatusBadge>
                </p>
                <p class="truncate text-xs text-muted">{{ [contact.role, contact.email, contact.phone].filter(Boolean).join(' · ') }}</p>
              </div>
              <div v-if="canManage" class="flex gap-1">
                <button type="button" class="rounded p-1 text-muted hover:text-ink" @click="contactDialog = { contact }">
                  <Pencil class="size-3.5" aria-hidden="true" /><span class="sr-only">Editar {{ contact.name }}</span>
                </button>
                <button type="button" class="rounded p-1 text-muted hover:text-danger" @click="removeContact(contact)">
                  <Trash2 class="size-3.5" aria-hidden="true" /><span class="sr-only">Remover {{ contact.name }}</span>
                </button>
              </div>
            </li>
            <li v-if="!client.contacts?.length" class="text-sm text-muted">Nenhum contato cadastrado.</li>
          </ul>
        </section>

        <section class="rounded-2xl border border-line bg-surface lg:col-span-2" aria-labelledby="client-units">
          <div class="flex items-center justify-between border-b border-line px-6 py-4">
            <h2 id="client-units" class="font-semibold">Unidades consumidoras</h2>
            <BaseButton v-if="canManage" variant="secondary" @click="openUnit(null)">
              <Plus class="size-4" aria-hidden="true" />
              Nova UC
            </BaseButton>
          </div>
          <ul class="divide-y divide-line">
            <li v-for="unit in client.consumer_units ?? []" :key="unit.id" class="flex items-start gap-4 px-6 py-4">
              <MapPin class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
              <div class="min-w-0 flex-1">
                <p class="font-medium">
                  UC {{ unit.number }}
                  <span class="font-normal text-muted">· {{ unit.distributor?.name }}</span>
                </p>
                <p class="text-sm text-muted text-pretty">{{ unit.full_address }}</p>
                <p class="mt-1 text-xs text-muted">
                  {{ unit.voltage_class }} · {{ unit.supply_type }} · Carga {{ formatNumber(unit.installed_load_kw, 'kW') }}
                  <template v-if="unit.breaker_a"> · Disjuntor {{ unit.breaker_a }} A</template>
                </p>
              </div>
              <StatusBadge v-if="!unit.active">Inativa</StatusBadge>
              <BaseButton v-if="canManage" variant="ghost" @click="openUnit(unit)">
                Editar<span class="sr-only"> UC {{ unit.number }}</span>
              </BaseButton>
            </li>
            <li v-if="!client.consumer_units?.length" class="px-6 py-12 text-center text-sm text-muted">
              Cadastre a primeira unidade consumidora para iniciar um projeto.
            </li>
          </ul>
        </section>
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
