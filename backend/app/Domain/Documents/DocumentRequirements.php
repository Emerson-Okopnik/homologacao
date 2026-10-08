<?php

namespace App\Domain\Documents;

use App\Domain\Projects\Models\SolarProject;

/**
 * Checklist documental do dossiê de acesso. A obrigatoriedade varia conforme
 * modalidade, porte (micro/mini) e presença de armazenamento.
 */
final class DocumentRequirements
{
    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'formulario_solicitacao' => 'Formulário de solicitação de acesso',
        'art_trt' => 'ART/TRT do responsável técnico',
        'diagrama_unifilar' => 'Diagrama unifilar',
        'memorial_descritivo' => 'Memorial descritivo',
        'datasheet_modulo' => 'Datasheet dos módulos',
        'datasheet_inversor' => 'Datasheet dos inversores',
        'certificado_inversor' => 'Certificado INMETRO do inversor',
        'documento_titular' => 'Documento de identificação do titular',
        'procuracao' => 'Procuração',
        'lista_rateio' => 'Lista de rateio de créditos',
        'datasheet_bateria' => 'Datasheet do sistema de armazenamento',
        'estudo_protecao' => 'Estudo de proteção / coordenação',
        'outro' => 'Outro documento',
    ];

    /**
     * @return list<string>
     */
    public static function requiredFor(SolarProject $project): array
    {
        $required = [
            'formulario_solicitacao', 'art_trt', 'diagrama_unifilar', 'memorial_descritivo',
            'datasheet_modulo', 'datasheet_inversor', 'certificado_inversor', 'documento_titular',
        ];

        if (in_array($project->modality, ['autoconsumo_remoto', 'geracao_compartilhada', 'multiplas_uc'], true)) {
            $required[] = 'lista_rateio';
        }

        if ($project->has_battery) {
            $required[] = 'datasheet_bateria';
        }

        if ($project->generation_type === 'mini') {
            $required[] = 'estudo_protecao';
        }

        return $required;
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }
}
