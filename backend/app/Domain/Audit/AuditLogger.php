<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Redactor $redactor,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $event,
        ?Model $auditable = null,
        ?array $old = null,
        ?array $new = null,
        ?string $justification = null,
        array $metadata = [],
        ?User $actor = null,
        ?Tenant $tenant = null,
    ): AuditLog {
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;
        $tenant ??= $this->tenantContext->get() ?? $actor?->tenant()->first();

        $entry = new AuditLog([
            'event' => $event,
            'user_id' => $actor?->getKey(),
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $this->redactor->redact($old),
            'new_values' => $this->redactor->redact($new),
            'metadata' => $this->redactor->redact($metadata) ?: null,
            'justification' => $justification,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            'correlation_id' => $this->request->attributes->get('correlation_id'),
        ]);

        if ($tenant === null) {
            throw new Exceptions\AuditTenantMissingException($event);
        }

        $this->tenantContext->run($tenant, fn () => $entry->save());

        return $entry;
    }
}
