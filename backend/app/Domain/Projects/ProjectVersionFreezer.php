<?php

namespace App\Domain\Projects;

use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\ProjectVersion;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Users\Models\User;

/** Routes rule-based submissions through the immutable version service. */
final class ProjectVersionFreezer
{
    public function __construct(private readonly ProjectVersionService $versions) {}

    /** @param array<string, mixed> $checklist */
    public function freeze(SolarProject $project, HomologationProcess $process, string $reason, array $checklist, ?int $userId): ProjectVersion
    {
        $actor = User::query()->findOrFail($userId);

        return $this->versions->freeze($project, $actor, $reason, $process, $checklist);
    }
}
