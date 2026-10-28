<?php

namespace App\Http\Controllers\Api\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $validated = $request->validate([
            'event' => ['sometimes', 'nullable', 'string', 'max:120'],
            'event_match' => ['sometimes', Rule::in(['exact', 'prefix'])],
            'user' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with('user')
            ->when($validated['event'] ?? null, fn ($q, string $event) => ($validated['event_match'] ?? 'prefix') === 'exact'
                ? $q->where('event', $event)
                : $q->where('event', 'like', addcslashes($event, '%_\\').'%'))
            ->when($validated['user'] ?? null, function ($q, string $uuid): void {
                // User também é tenant-scoped: UUID de outro tenant resulta em lista vazia.
                $q->where('user_id', User::query()->where('uuid', $uuid)->value('id') ?? 0);
            })
            ->when($validated['from'] ?? null, fn ($q, string $from) => $q->where('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, string $to) => $q->where('created_at', '<', Carbon::parse($to)->addDay()->startOfDay()))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }
}
