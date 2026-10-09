<?php

namespace App\Domain\Documents;

/**
 * Catálogo de tipos de documento: a qual entidade cada tipo se vincula (owner)
 * e QUEM tem a obrigação de providenciá-lo (party).
 * A obrigatoriedade NÃO mora aqui: vem de requirement_rules.
 */
final class DocumentTypes
{
    public const PARTY_CLIENT = 'CLIENT';

    public const PARTY_TECHNICAL = 'TECHNICAL';

    public const PARTY_DISTRIBUTOR = 'DISTRIBUTOR';

    public const PARTIES = [
        self::PARTY_CLIENT => 'Cliente (titular)',
        self::PARTY_TECHNICAL => 'Responsável técnico',
        self::PARTY_DISTRIBUTOR => 'Distribuidora',
    ];

    /**
     * @var array<string, array{label: string, owner: string, party: string}>
     */
    public const TYPES = [
        // Obrigações do cliente (titular da UC)
        'HOLDER_ID' => ['label' => 'Documento de identificação do titular', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'ENERGY_BILL' => ['label' => 'Conta de energia recente', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'POWER_OF_ATTORNEY' => ['label' => 'Procuração para o responsável técnico', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'PROPERTY_AUTHORIZATION' => ['label' => 'Autorização do proprietário do imóvel', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'ENTRY_STANDARD_PHOTO' => ['label' => 'Foto do padrão de entrada', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'INSTALLATION_SITE_PHOTO' => ['label' => 'Foto do local de instalação', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'CREDIT_ALLOCATION_LIST' => ['label' => 'Lista de rateio de créditos', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'SHARED_GENERATION_AGREEMENT' => ['label' => 'Instrumento de geração compartilhada', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'ENVIRONMENTAL_LICENSE' => ['label' => 'Licença ambiental', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'ENVIRONMENTAL_EXEMPTION' => ['label' => 'Declaração de dispensa ambiental', 'owner' => 'project', 'party' => self::PARTY_CLIENT],
        'ENVIRONMENTAL_AUTHORIZATION' => ['label' => 'Autorização ambiental', 'owner' => 'project', 'party' => self::PARTY_CLIENT],

        // Obrigações do responsável técnico
        'ACCESS_REQUEST_FORM' => ['label' => 'Formulário de solicitação de acesso', 'owner' => 'project', 'party' => self::PARTY_TECHNICAL],
        'PROJECT_ART' => ['label' => 'ART/TRT de projeto', 'owner' => 'project', 'party' => self::PARTY_TECHNICAL],
        'EXECUTION_ART' => ['label' => 'ART/TRT de execução', 'owner' => 'execution', 'party' => self::PARTY_TECHNICAL],
        'SINGLE_LINE_DIAGRAM' => ['label' => 'Diagrama unifilar', 'owner' => 'project', 'party' => self::PARTY_TECHNICAL],
        'DESCRIPTIVE_MEMORIAL' => ['label' => 'Memorial descritivo', 'owner' => 'project', 'party' => self::PARTY_TECHNICAL],
        'PROTECTION_STUDY' => ['label' => 'Estudo de proteção / coordenação', 'owner' => 'project', 'party' => self::PARTY_TECHNICAL],
        'ISLANDING_STUDY_81R' => ['label' => 'Estudo de ilhamento (função 81R)', 'owner' => 'project', 'party' => self::PARTY_TECHNICAL],
        'STORAGE_DATASHEET' => ['label' => 'Datasheet do sistema de armazenamento', 'owner' => 'project', 'party' => self::PARTY_TECHNICAL],
        'DISPATCH_CONTROL_MEMORIAL' => ['label' => 'Memorial do controle de despacho', 'owner' => 'project', 'party' => self::PARTY_TECHNICAL],
        'FAST_TRACK_TERM' => ['label' => 'Termo de aceite Fast Track', 'owner' => 'project', 'party' => self::PARTY_TECHNICAL],
        'MODULE_DATASHEET' => ['label' => 'Datasheet do módulo', 'owner' => 'equipment', 'party' => self::PARTY_TECHNICAL],
        'INVERTER_DATASHEET' => ['label' => 'Datasheet do inversor', 'owner' => 'equipment', 'party' => self::PARTY_TECHNICAL],
        'INMETRO_CERTIFICATE' => ['label' => 'Certificado/registro Inmetro', 'owner' => 'equipment', 'party' => self::PARTY_TECHNICAL],
        'INVERTER_TEST_REPORT' => ['label' => 'Relatório de ensaio do inversor', 'owner' => 'equipment', 'party' => self::PARTY_TECHNICAL],
        'EXECUTION_PHOTOS' => ['label' => 'Fotos da execução', 'owner' => 'execution', 'party' => self::PARTY_TECHNICAL],
        'OTHER' => ['label' => 'Outro documento', 'owner' => 'process', 'party' => self::PARTY_TECHNICAL],

        // Emitidos pela distribuidora
        'INSPECTION_REPORT' => ['label' => 'Relatório de vistoria', 'owner' => 'inspection', 'party' => self::PARTY_DISTRIBUTOR],
        'METERING_EVIDENCE' => ['label' => 'Evidência de medição', 'owner' => 'connection_event', 'party' => self::PARTY_DISTRIBUTOR],
        'DISTRIBUTOR_LETTER' => ['label' => 'Parecer / ofício da distribuidora', 'owner' => 'process', 'party' => self::PARTY_DISTRIBUTOR],
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

    public static function party(?string $type): string
    {
        return $type !== null ? (self::TYPES[$type]['party'] ?? self::PARTY_TECHNICAL) : self::PARTY_TECHNICAL;
    }

    /**
     * @return list<string>
     */
    public static function ofParty(string $party): array
    {
        return array_keys(array_filter(self::TYPES, fn ($t) => $t['party'] === $party));
    }

    /**
     * @return list<array{value: string, label: string, owner: string, party: string}>
     */
    public static function options(): array
    {
        return collect(self::TYPES)->map(fn ($t, $k) => ['value' => $k, ...$t])->values()->all();
    }
}
