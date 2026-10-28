<?php

namespace App\Domain\Distributors\Enums;

/**
 * Modos de operação do canal externo. Não existe API pública documentada da CELESC;
 * "api" e "automation" só podem ser ativados quando houver canal oficialmente autorizado (RN-20).
 */
enum IntegrationMode: string
{
    case Assisted = 'assisted';
    case Api = 'api';
    case Automation = 'automation';

    public function label(): string
    {
        return match ($this) {
            self::Assisted => 'Fluxo assistido (manual)',
            self::Api => 'API oficial',
            self::Automation => 'Automação autorizada',
        };
    }
}
