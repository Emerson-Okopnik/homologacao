<?php

namespace App\Domain\Homologations;

use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\TimelineEvent;

final class TimelineRecorder
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function record(HomologationProcess $process, string $type, string $title, ?string $description = null, ?array $payload = null, ?int $userId = null): TimelineEvent
    {
        return $process->timeline()->create([
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'user_id' => $userId ?? auth()->id(),
            'occurred_at' => now(),
        ]);
    }
}
