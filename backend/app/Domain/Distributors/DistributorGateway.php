<?php

namespace App\Domain\Distributors;

use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\ProjectVersion;

interface DistributorGateway
{
    /** @return array<string, mixed> */
    public function buildPayload(HomologationProcess $process, ProjectVersion $version, string $kind): array;
}
