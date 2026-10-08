<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowRight, FileCheck2, GitBranch, PlugZap } from '@lucide/vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useApiQuery } from '@/composables/useApiQuery'
import { api } from '@/lib/http'
import type { Distributor, StageCatalog } from '@/types/api'

const { data, error, loading } = useApiQuery(
  () => 'settings',
  async () => {
    const [catalog, documentTypes, distributors] = await Promise.all([
      api<{ data: StageCatalog }>('/process-stages'),
      api<{ data: Array<{ value: string; label: string }> }>('/document-types'),
      api<{ data: Distributor[] }>('/distributors'),
    ])
    return { stages: catalog.data.stages, documentTypes: documentTypes.data, distributors: distributors.data }
  },
)

const integrated = computed(() => (data.value?.distributors ?? []).filter((d) => d.integration_mode !== 'manual').length)
</script>

<template>
  <div class="mx-auto max-w-5xl">
    <PageHeader title="Configurações" description="Visão geral do fluxo de homologação, documentos exigidos e integrações com distribuidoras." />

    <InlineAlert v-if="error" :correlation-id="error.correlationId">{{ error.message }}</InlineAlert>
    <p v-else-if="loading && !data" class="text-sm text-muted">Carregando configurações…</p>

    <div v-else-if="data" class="flex flex-col gap-6">
      <section class="rounded-2xl border border-line bg-surface p-6" aria-labelledby="cfg-workflow">
        <div class="mb-4 flex items-center gap-2">
          <GitBranch class="size-5 text-primary" aria-hidden="true" />
          <h2 id="cfg-workflow" class="font-semibold">Etapas do processo</h2>
        </div>
        <p class="mb-3 text-sm text-muted">
          As etapas são derivadas dos fatos do processo (parecer, vistoria, obra na rede, conexão). Não há transição manual de status.
        </p>
        <ol class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
          <li v-for="(stage, index) in data.stages" :key="stage.value" class="flex items-center gap-2">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-canvas text-xs font-semibold text-muted">{{ index + 1 }}</span>
            <span class="text-sm font-medium">{{ stage.label }}</span>
            <ArrowRight v-if="index < data.stages.length - 1" class="hidden size-3.5 text-muted sm:block" aria-hidden="true" />
          </li>
        </ol>
      </section>

      <div class="grid gap-6 md:grid-cols-2">
        <section class="rounded-2xl border border-line bg-surface p-6" aria-labelledby="cfg-docs">
          <div class="mb-4 flex items-center gap-2">
            <FileCheck2 class="size-5 text-primary" aria-hidden="true" />
            <h2 id="cfg-docs" class="font-semibold">Tipos de documento</h2>
          </div>
          <ul class="flex flex-col gap-2 text-sm">
            <li v-for="doc in data.documentTypes" :key="doc.value" class="flex items-center justify-between gap-2 rounded-lg bg-canvas px-3 py-2">
              <span>{{ doc.label }}</span>
              <code class="text-xs text-muted">{{ doc.value }}</code>
            </li>
          </ul>
        </section>

        <section class="flex flex-col rounded-2xl border border-line bg-surface p-6" aria-labelledby="cfg-int">
          <div class="mb-4 flex items-center gap-2">
            <PlugZap class="size-5 text-primary" aria-hidden="true" />
            <h2 id="cfg-int" class="font-semibold">Integrações com distribuidoras</h2>
          </div>
          <p class="text-sm text-muted">
            {{ integrated }} de {{ data.distributors.length }} distribuidoras com integração configurada.
          </p>
          <ul class="mt-4 flex flex-col gap-2 text-sm">
            <li v-for="d in data.distributors.slice(0, 6)" :key="d.id" class="flex items-center justify-between gap-2">
              <span>{{ d.name }} <span class="text-muted">({{ d.state }})</span></span>
              <span class="text-xs text-muted">{{ d.integration_mode_label }}</span>
            </li>
          </ul>
          <RouterLink to="/cadastros" class="mt-auto inline-flex items-center gap-1.5 pt-4 text-sm font-medium text-primary hover:underline">
            Gerenciar distribuidoras
            <ArrowRight class="size-4" aria-hidden="true" />
          </RouterLink>
        </section>
      </div>
    </div>
  </div>
</template>
