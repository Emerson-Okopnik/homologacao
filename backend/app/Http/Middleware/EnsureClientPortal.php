<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Exceptions\DomainException;
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
            $user && $user->hasPermission('portal.access'),
            403,
            'Acesso restrito ao portal do cliente.',
        );

        if (! $user->client()->exists()) {
            throw new DomainException('Seu acesso ao portal ainda não está vinculado ao cadastro do titular. Peça à equipe para vincular seu usuário ao cliente.', 'portal_client_not_linked', 403);
        }

        return $next($request);
    }
}
