<?php

namespace App\Domain\Distributors;

use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\ProjectVersion;
use App\Domain\Shared\Exceptions\DomainException;

/** Dossiê para operação assistida. Chamadas oficiais dependem de um contrato de API. */
final class CelescGatewayAdapter implements DistributorGateway
{
    /** @return array<string, mixed> */
    public function buildPayload(HomologationProcess $process, ProjectVersion $version, string $kind): array
    {
        if ($process->distributor->integration_mode->value !== 'assisted') {
            throw new DomainException('Este canal ainda não tem um adaptador de API autorizado. Configure o modo assistido para registrar o envio pelo portal.', 'channel_unavailable', 409);
        }

        return ['channel' => 'assisted', 'kind' => $kind, 'process' => $process->uuid, 'distributor' => $process->distributor->code,
            'project_version' => $version->version, 'version_hash' => $version->snapshot_sha256, 'dossier' => $version->snapshot_json];
    }
}
