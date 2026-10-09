<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas do portal: exige um usuário vinculado a um cliente e com a permissão do portal.
 */
final class EnsureClientPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user && $user->getAttribute('client_id') !== null && $user->hasPermission('portal.access'),
            403,
            'Acesso restrito ao portal do cliente.',
        );

        return $next($request);
    }
}
