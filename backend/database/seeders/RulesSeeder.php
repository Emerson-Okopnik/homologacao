<?php

namespace Database\Seeders;

use App\Domain\Rules\Models\DeadlineRule;
use App\Domain\Rules\Models\FastTrackRule;
use App\Domain\Rules\Models\GenerationClassificationRule;
use App\Domain\Rules\Models\RequirementRule;
use App\Domain\Rules\Models\VersionedRule;
use App\Domain\Rules\RuleValidator;
use Illuminate\Database\Seeder;

/**
 * Regras padrão versionadas (v1). Baseadas na Lei 14.300/2022, REN ANEEL 1.000/2021 e
 * no guia CELESC "Conexão de Micro e Minigeradores" (out/2020). Valores devem ser
 * conferidos pelo responsável técnico antes de uso em produção; novas versões são
 * criadas pelo super admin sem alterar as anteriores.
 */
class RulesSeeder extends Seeder
{
    private const FROM = '2023-01-07';

    private const LAW = 'Lei 14.300/2022, art. 1º, XI e XIII';

    private const REN = 'REN ANEEL 1.000/2021';

    private const CELESC = 'CELESC — Conexão de Micro e Minigeradores (out/2020)';

    public function run(RuleValidator $validator): void
    {
        foreach ($this->classificationRules() as $rule) {
            $this->upsert(GenerationClassificationRule::class, $rule, $validator);
        }

        foreach ($this->fastTrackRules() as $rule) {
            $this->upsert(FastTrackRule::class, $rule, $validator);
        }

        foreach ($this->requirementRules() as $rule) {
            $this->upsert(RequirementRule::class, $rule, $validator);
        }

        foreach ($this->deadlineRules() as $rule) {
            $this->upsert(DeadlineRule::class, $rule, $validator);
        }
    }

    /**
     * @param  class-string<VersionedRule>  $model
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(string $model, array $attributes, RuleValidator $validator): void
    {
        foreach (['conditions', 'satisfied_when'] as $field) {
            if (! empty($attributes[$field])) {
                $errors = $validator->errors($attributes[$field]);
                if ($errors !== []) {
                    throw new \RuntimeException("Regra {$attributes['rule_code']} inválida ({$field}): ".implode('; ', $errors));
                }
            }
        }

        $model::query()->updateOrCreate(
            ['rule_code' => $attributes['rule_code'], 'version' => 1],
            [
                'distributor_code' => null,
                'priority' => 0,
                'effective_from' => self::FROM,
                'effective_to' => null,
                'active' => true,
                ...$attributes,
                'conditions' => $attributes['conditions'] ?? [],
                'version' => 1,
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function classificationRules(): array
    {
        return [
            [
                'rule_code' => 'CLASS_MICRO',
                'priority' => 30,
                'description' => 'Microgeração: potência instalada até 75 kW.',
                'conditions' => ['fact' => 'considered_power_kw', 'op' => 'lte', 'value' => 75],
                'classification' => 'MICRO',
                'source_reference' => self::LAW,
            ],
            [
                'rule_code' => 'CLASS_MINI_DISPATCHABLE',
                'priority' => 20,
                'description' => 'Minigeração despachável: acima de 75 kW, até 5 MW, com despacho declarado e controle.',
                'conditions' => ['all' => [
                    ['fact' => 'considered_power_kw', 'op' => 'gt', 'value' => 75],
                    ['fact' => 'considered_power_kw', 'op' => 'lte', 'value' => 5000],
                    ['fact' => 'declared_dispatchable', 'op' => 'eq', 'value' => true],
                    ['fact' => 'has_dispatch_controller', 'op' => 'eq', 'value' => true],
                ]],
                'classification' => 'MINI_DISPATCHABLE',
                'source_reference' => self::LAW.' (fontes despacháveis, incl. FV com armazenamento)',
            ],
            [
                'rule_code' => 'CLASS_MINI_NON_DISPATCHABLE',
                'priority' => 10,
                'description' => 'Minigeração não despachável: acima de 75 kW e até 3 MW.',
                'conditions' => ['all' => [
                    ['fact' => 'considered_power_kw', 'op' => 'gt', 'value' => 75],
                    ['fact' => 'considered_power_kw', 'op' => 'lte', 'value' => 3000],
                ]],
                'classification' => 'MINI_NON_DISPATCHABLE',
                'source_reference' => self::LAW,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fastTrackRules(): array
    {
        return [[
            'rule_code' => 'FAST_TRACK_MICRO_INVERTER',
            'description' => 'Microgeração conectada por inversores certificados, sem armazenamento, em autoconsumo local.',
            'conditions' => ['all' => [
                ['fact' => 'classification', 'op' => 'eq', 'value' => 'MICRO', 'message' => 'Fast Track restrito a microgeração.'],
                ['fact' => 'inverters_only', 'op' => 'eq', 'value' => true, 'message' => 'Conexão deve ser somente via inversores.'],
                ['fact' => 'inverters_without_inmetro', 'op' => 'eq', 'value' => 0, 'message' => 'Todos os inversores precisam de registro Inmetro.'],
                ['fact' => 'has_storage', 'op' => 'eq', 'value' => false, 'message' => 'Sistemas com armazenamento não são elegíveis.'],
                ['fact' => 'compensation_mode', 'op' => 'eq', 'value' => 'LOCAL_SELF_CONSUMPTION', 'message' => 'Apenas autoconsumo local.'],
            ]],
            'source_reference' => self::REN.', art. 72 e seguintes (procedimento simplificado)',
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function requirementRules(): array
    {
        $doc = fn (string $code, string $phase, string $type, string $label, string $reason, ?array $when = null, bool $waivable = false, string $source = self::CELESC) => [
            'rule_code' => $code,
            'description' => $label,
            'conditions' => $when,
            'phase' => $phase,
            'kind' => 'DOCUMENT',
            'document_type' => $type,
            'satisfied_when' => null,
            'outcome' => 'REQUIRED',
            'label' => $label,
            'reason' => $reason,
            'waivable' => $waivable,
            'source_reference' => $source,
        ];

        $fact = fn (string $code, string $phase, string $label, string $reason, array $satisfied, ?array $when = null) => [
            'rule_code' => $code,
            'description' => $label,
            'conditions' => $when,
            'phase' => $phase,
            'kind' => 'FACT',
            'document_type' => null,
            'satisfied_when' => $satisfied,
            'outcome' => 'REQUIRED',
            'label' => $label,
            'reason' => $reason,
            'waivable' => false,
            'source_reference' => self::REN,
        ];

        $mini = ['fact' => 'classification_group', 'op' => 'eq', 'value' => 'MINI'];

        return [
            $doc('REQ_ACCESS_FORM', 'SUBMISSION', 'ACCESS_REQUEST_FORM', 'Formulário de solicitação de acesso', 'Obrigatório para todo pedido de conexão.'),
            $doc('REQ_PROJECT_ART', 'SUBMISSION', 'PROJECT_ART', 'ART/TRT de projeto', 'Responsabilidade técnica pelo projeto.'),
            $doc('REQ_SINGLE_LINE', 'SUBMISSION', 'SINGLE_LINE_DIAGRAM', 'Diagrama unifilar', 'Representação da instalação e proteções.'),
            $doc('REQ_MEMORIAL', 'SUBMISSION', 'DESCRIPTIVE_MEMORIAL', 'Memorial descritivo', 'Exigido para minigeração.', $mini),
            $doc('REQ_HOLDER_ID', 'SUBMISSION', 'HOLDER_ID', 'Documento do titular', 'Identificação do titular da UC.', null, true),
            $doc('REQ_MODULE_DS', 'SUBMISSION', 'MODULE_DATASHEET', 'Datasheet dos módulos', 'Características dos módulos instalados.'),
            $doc('REQ_INVERTER_DS', 'SUBMISSION', 'INVERTER_DATASHEET', 'Datasheet dos inversores', 'Características dos inversores.'),
            $doc('REQ_INMETRO', 'SUBMISSION', 'INMETRO_CERTIFICATE', 'Registro Inmetro dos inversores', 'Inversores sem registro exigem comprovação.', ['fact' => 'inverters_without_inmetro', 'op' => 'eq', 'value' => 0]),
            $doc('REQ_INVERTER_TEST', 'SUBMISSION', 'INVERTER_TEST_REPORT', 'Relatório de ensaio do inversor', 'Inversor sem registro Inmetro.', ['fact' => 'inverters_without_inmetro', 'op' => 'gt', 'value' => 0]),
            $doc('REQ_CREDIT_LIST', 'SUBMISSION', 'CREDIT_ALLOCATION_LIST', 'Lista de rateio de créditos', 'Há alocação de créditos para outras UCs.', ['fact' => 'has_credit_allocation', 'op' => 'eq', 'value' => true]),
            $doc('REQ_SHARED_AGREEMENT', 'SUBMISSION', 'SHARED_GENERATION_AGREEMENT', 'Instrumento de geração compartilhada', 'Modalidade de geração compartilhada.', ['fact' => 'compensation_mode', 'op' => 'eq', 'value' => 'SHARED_GENERATION']),
            $doc('REQ_PROTECTION', 'SUBMISSION', 'PROTECTION_STUDY', 'Estudo de proteção', 'Exigido para minigeração.', $mini),
            $doc('REQ_STORAGE_DS', 'SUBMISSION', 'STORAGE_DATASHEET', 'Datasheet do armazenamento', 'Sistema possui armazenamento.', ['fact' => 'has_storage', 'op' => 'eq', 'value' => true]),
            $doc('REQ_DISPATCH_MEMO', 'SUBMISSION', 'DISPATCH_CONTROL_MEMORIAL', 'Memorial do controle de despacho', 'Minigeração despachável.', ['fact' => 'classification', 'op' => 'eq', 'value' => 'MINI_DISPATCHABLE']),
            $doc('REQ_FAST_TRACK_TERM', 'SUBMISSION', 'FAST_TRACK_TERM', 'Termo de aceite Fast Track', 'Projeto segue o procedimento simplificado.', ['fact' => 'fast_track_eligible', 'op' => 'eq', 'value' => true], false, self::REN),
            $fact('REQ_PROJECT_RT', 'SUBMISSION', 'RT de projeto com registro regular', 'Responsável técnico precisa estar definido e regular.', ['all' => [
                ['fact' => 'project_rt_defined', 'op' => 'eq', 'value' => true],
                ['fact' => 'project_rt_regular', 'op' => 'eq', 'value' => true],
            ]]),
            $fact('REQ_NETWORK_CLEARED', 'INSPECTION_REQUEST', 'Rede liberada para vistoria', 'Obras de rede precisam estar concluídas.', ['fact' => 'network_work_cleared', 'op' => 'eq', 'value' => true]),
            $fact('REQ_EXECUTION', 'INSPECTION_REQUEST', 'Execução informada com RT', 'Execução da obra deve estar registrada.', ['all' => [
                ['fact' => 'execution_reported', 'op' => 'eq', 'value' => true],
                ['fact' => 'execution_rt_defined', 'op' => 'eq', 'value' => true],
            ]]),
            $doc('REQ_EXECUTION_ART', 'INSPECTION_REQUEST', 'EXECUTION_ART', 'ART/TRT de execução', 'Responsabilidade técnica pela execução.'),
            $fact('REQ_NO_PENDENCIES', 'INSPECTION_REQUEST', 'Sem pendências abertas', 'Pendências devem ser resolvidas antes da vistoria.', ['fact' => 'open_pendencies', 'op' => 'eq', 'value' => 0]),
            $fact('REQ_INSPECTION_OK', 'COMPLETION', 'Vistoria aprovada', 'Conclusão exige vistoria aprovada.', ['fact' => 'last_inspection_approved', 'op' => 'eq', 'value' => true]),
            $fact('REQ_METER', 'COMPLETION', 'Medidor bidirecional instalado', 'Medição precisa estar instalada.', ['fact' => 'meter_installed', 'op' => 'eq', 'value' => true]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function deadlineRules(): array
    {
        return [
            [
                'rule_code' => 'DEADLINE_OPINION_MICRO',
                'priority' => 10,
                'description' => 'Parecer de acesso para microgeração.',
                'conditions' => ['fact' => 'classification_group', 'op' => 'eq', 'value' => 'MICRO'],
                'deadline_type' => 'ACCESS_OPINION',
                'days' => 15,
                'day_count' => 'BUSINESS',
                'source_reference' => self::REN,
            ],
            [
                'rule_code' => 'DEADLINE_OPINION_MINI',
                'priority' => 10,
                'description' => 'Parecer de acesso para minigeração.',
                'conditions' => ['fact' => 'classification_group', 'op' => 'eq', 'value' => 'MINI'],
                'deadline_type' => 'ACCESS_OPINION',
                'days' => 30,
                'day_count' => 'BUSINESS',
                'source_reference' => self::REN,
            ],
            [
                'rule_code' => 'DEADLINE_INSPECTION',
                'description' => 'Realização da vistoria após solicitação.',
                'conditions' => null,
                'deadline_type' => 'INSPECTION',
                'days' => 7,
                'day_count' => 'BUSINESS',
                'source_reference' => self::REN,
            ],
        ];
    }
}
