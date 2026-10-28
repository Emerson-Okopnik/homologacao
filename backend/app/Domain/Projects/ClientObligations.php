<?php

namespace App\Domain\Projects;

use App\Domain\Documents\DocumentTypes;

/**
 * Documentos que o cliente deve providenciar já na abertura da solicitação,
 * a partir do que ele declarou. Depois da conversão em projeto, o checklist
 * oficial vem das regras versionadas (RequirementEngine), filtrado por parte.
 */
final class ClientObligations
{
    /**
     * @param  array<string, mixed>  $system
     * @return list<array{type: string, label: string, required: bool, hint: string}>
     */
    public static function documentsFor(array $system): array
    {
        $mode = $system['compensation_mode'] ?? 'LOCAL_SELF_CONSUMPTION';
        $hasBeneficiaries = ! empty($system['beneficiaries']);

        $items = [
            ['HOLDER_ID', true, 'RG/CNH do titular ou contrato social, se pessoa jurídica.'],
            ['ENERGY_BILL', true, 'Fatura dos últimos 3 meses da unidade consumidora.'],
            ['POWER_OF_ATTORNEY', true, 'Autoriza o responsável técnico a protocolar em seu nome na distribuidora.'],
            ['ENTRY_STANDARD_PHOTO', true, 'Foto nítida do padrão de entrada com o medidor e o disjuntor.'],
            ['INSTALLATION_SITE_PHOTO', false, 'Telhado, laje ou área onde os módulos serão instalados.'],
        ];

        if (($system['is_property_owner'] ?? true) === false) {
            $items[] = ['PROPERTY_AUTHORIZATION', true, 'O imóvel não é seu: anexe a autorização assinada pelo proprietário.'];
        }
        if ($mode === 'SHARED_GENERATION') {
            $items[] = ['SHARED_GENERATION_AGREEMENT', true, 'Contrato do consórcio ou cooperativa que comprova a geração compartilhada.'];
        }
        if ($mode !== 'LOCAL_SELF_CONSUMPTION' && $hasBeneficiaries) {
            $items[] = ['CREDIT_ALLOCATION_LIST', true, 'Lista das UCs que vão receber créditos, com o percentual de cada uma.'];
        }

        return array_map(fn ($i) => [
            'type' => $i[0],
            'label' => DocumentTypes::label($i[0]),
            'required' => $i[1],
            'hint' => $i[2],
        ], $items);
    }
}
