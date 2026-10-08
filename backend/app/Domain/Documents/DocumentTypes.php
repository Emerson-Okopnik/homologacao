<?php

namespace App\Domain\Documents;

/**
 * Catálogo de tipos de documento e a qual entidade cada tipo se vincula.
 * A obrigatoriedade NÃO mora aqui: vem de requirement_rules.
 */
final class DocumentTypes
{
    /**
     * @var array<string, array{label: string, owner: string}>
     */
    public const TYPES = [
        'ACCESS_REQUEST_FORM' => ['label' => 'Formulário de solicitação de acesso', 'owner' => 'project'],
        'PROJECT_ART' => ['label' => 'ART/TRT de projeto', 'owner' => 'project'],
        'EXECUTION_ART' => ['label' => 'ART/TRT de execução', 'owner' => 'execution'],
        'SINGLE_LINE_DIAGRAM' => ['label' => 'Diagrama unifilar', 'owner' => 'project'],
        'DESCRIPTIVE_MEMORIAL' => ['label' => 'Memorial descritivo', 'owner' => 'project'],
        'HOLDER_ID' => ['label' => 'Documento de identificação do titular', 'owner' => 'project'],
        'POWER_OF_ATTORNEY' => ['label' => 'Procuração', 'owner' => 'project'],
        'CREDIT_ALLOCATION_LIST' => ['label' => 'Lista de rateio de créditos', 'owner' => 'project'],
        'SHARED_GENERATION_AGREEMENT' => ['label' => 'Instrumento de geração compartilhada', 'owner' => 'project'],
        'PROTECTION_STUDY' => ['label' => 'Estudo de proteção / coordenação', 'owner' => 'project'],
        'ISLANDING_STUDY_81R' => ['label' => 'Estudo de ilhamento (função 81R)', 'owner' => 'project'],
        'STORAGE_DATASHEET' => ['label' => 'Datasheet do sistema de armazenamento', 'owner' => 'project'],
        'DISPATCH_CONTROL_MEMORIAL' => ['label' => 'Memorial do controle de despacho', 'owner' => 'project'],
        'ENVIRONMENTAL_LICENSE' => ['label' => 'Licença ambiental', 'owner' => 'project'],
        'ENVIRONMENTAL_EXEMPTION' => ['label' => 'Declaração de dispensa ambiental', 'owner' => 'project'],
        'ENVIRONMENTAL_AUTHORIZATION' => ['label' => 'Autorização ambiental', 'owner' => 'project'],
        'FAST_TRACK_TERM' => ['label' => 'Termo de aceite Fast Track', 'owner' => 'project'],
        'MODULE_DATASHEET' => ['label' => 'Datasheet do módulo', 'owner' => 'equipment'],
        'INVERTER_DATASHEET' => ['label' => 'Datasheet do inversor', 'owner' => 'equipment'],
        'INMETRO_CERTIFICATE' => ['label' => 'Certificado/registro Inmetro', 'owner' => 'equipment'],
        'INVERTER_TEST_REPORT' => ['label' => 'Relatório de ensaio do inversor', 'owner' => 'equipment'],
        'EXECUTION_PHOTOS' => ['label' => 'Fotos da execução', 'owner' => 'execution'],
        'INSPECTION_REPORT' => ['label' => 'Relatório de vistoria', 'owner' => 'inspection'],
        'METERING_EVIDENCE' => ['label' => 'Evidência de medição', 'owner' => 'connection_event'],
        'DISTRIBUTOR_LETTER' => ['label' => 'Parecer / ofício da distribuidora', 'owner' => 'process'],
        'OTHER' => ['label' => 'Outro documento', 'owner' => 'process'],
    ];

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    public static function owner(string $type): string
    {
        return self::TYPES[$type]['owner'] ?? 'process';
    }

    /**
     * @return list<array{value: string, label: string, owner: string}>
     */
    public static function options(): array
    {
        return collect(self::TYPES)->map(fn ($t, $k) => ['value' => $k, ...$t])->values()->all();
    }
}
