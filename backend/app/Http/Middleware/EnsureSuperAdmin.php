<?php

namespace App\Http\Middleware;

use App\Domain\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            abort(403, 'Acesso restrito ao super administrador.');
        }

        return $next($request);
    }
}
