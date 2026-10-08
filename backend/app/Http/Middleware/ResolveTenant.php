<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * O tenant vem exclusivamente do usuário autenticado. Não existe header, subdomínio ou
 * parâmetro que permita escolher o tenant pela request, então um ID enviado manualmente
 * nunca alcança dados de outro tenant.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $tenant = $user->tenant()->first();

        if ($tenant === null || ! $tenant->active || ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            abort(403, 'Acesso desativado.');
        }

        $this->context->set($tenant);
        Log::withContext(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        return $next($request);
    }
}
