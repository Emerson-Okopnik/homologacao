<?php

namespace App\Domain\Rules;

/**
 * Whitelist de fatos que regras podem consultar. Regras nunca executam código:
 * só comparam estes fatos com valores literais usando operadores permitidos.
 */
final class FactCatalog
{
    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in'];

    /**
     * @return array<string, array{type: string, label: string, values?: list<string>}>
     */
    public static function all(): array
    {
        return [
            'considered_power_kw' => ['type' => 'number', 'label' => 'Potência considerada (kW)'],
            'modules_power_kwp' => ['type' => 'number', 'label' => 'Potência dos módulos (kWp)'],
            'inverters_power_kw' => ['type' => 'number', 'label' => 'Potência dos inversores (kW)'],
            'source_type' => ['type' => 'enum', 'label' => 'Fonte', 'values' => ['SOLAR']],
            'classification' => ['type' => 'enum', 'label' => 'Classificação', 'values' => ['MICRO', 'MINI_NON_DISPATCHABLE', 'MINI_DISPATCHABLE']],
            'classification_group' => ['type' => 'enum', 'label' => 'Grupo da classificação', 'values' => ['MICRO', 'MINI']],
            'connection_voltage' => ['type' => 'enum', 'label' => 'Tensão de conexão', 'values' => ['LOW', 'MEDIUM']],
            'distributor_code' => ['type' => 'string', 'label' => 'Código da distribuidora'],
            'has_storage' => ['type' => 'bool', 'label' => 'Possui armazenamento'],
            'storage_ratio_monthly' => ['type' => 'number', 'label' => 'Armazenamento / geração mensal estimada'],
            'has_dispatch_controller' => ['type' => 'bool', 'label' => 'Possui controle de despacho'],
            'declared_dispatchable' => ['type' => 'bool', 'label' => 'Declarado despachável'],
            'has_coupling_transformer' => ['type' => 'bool', 'label' => 'Possui transformador de acoplamento'],
            'inverters_only' => ['type' => 'bool', 'label' => 'Conexão somente via inversores'],
            'has_credit_allocation' => ['type' => 'bool', 'label' => 'Aloca créditos para outras UCs'],
            'compensation_mode' => ['type' => 'enum', 'label' => 'Modalidade de compensação', 'values' => ['LOCAL_SELF_CONSUMPTION', 'REMOTE_SELF_CONSUMPTION', 'SHARED_GENERATION', 'MULTIPLE_UNITS']],
            'inverters_without_inmetro' => ['type' => 'number', 'label' => 'Inversores sem registro Inmetro'],
            'fast_track_eligible' => ['type' => 'bool', 'label' => 'Elegível ao Fast Track'],
            'has_initial_protocol' => ['type' => 'bool', 'label' => 'Possui protocolo inicial'],
            'project_rt_defined' => ['type' => 'bool', 'label' => 'RT de projeto definido'],
            'project_rt_regular' => ['type' => 'bool', 'label' => 'RT de projeto com registro regular'],
            'project_art_number_informed' => ['type' => 'bool', 'label' => 'Número da ART de projeto informado'],
            'network_work_status' => ['type' => 'enum', 'label' => 'Situação da obra de rede', 'values' => ['NOT_REQUIRED', 'UNDER_ANALYSIS', 'REQUIRED', 'WAITING_EXECUTION', 'COMPLETED', 'RELEASED']],
            'network_work_required' => ['type' => 'bool', 'label' => 'Obra de rede necessária'],
            'network_work_cleared' => ['type' => 'bool', 'label' => 'Rede liberada para vistoria'],
            'execution_reported' => ['type' => 'bool', 'label' => 'Execução informada'],
            'execution_rt_defined' => ['type' => 'bool', 'label' => 'RT de execução definido'],
            'open_pendencies' => ['type' => 'number', 'label' => 'Pendências abertas'],
            'last_inspection_approved' => ['type' => 'bool', 'label' => 'Última vistoria aprovada'],
            'meter_installed' => ['type' => 'bool', 'label' => 'Medidor instalado'],
            'system_connected' => ['type' => 'bool', 'label' => 'Sistema conectado'],
        ];
    }

    public static function has(string $fact): bool
    {
        return array_key_exists($fact, self::all());
    }
}
