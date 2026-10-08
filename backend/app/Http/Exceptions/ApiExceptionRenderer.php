<?php

namespace App\Http\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Formato único de erro da API:
 * { "message": string, "code": string, "errors"?: {campo: [mensagens]}, "correlation_id": string }
 *
 * Erros inesperados nunca expõem stack trace, SQL ou mensagens internas fora do modo debug.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! ($request->is('api/*') || $request->expectsJson())) {
            return null;
        }

        [$status, $code, $message, $errors] = match (true) {
            $e instanceof ValidationException => [$e->status, 'validation_failed', 'Os dados informados são inválidos.', $e->errors()],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Sessão expirada ou não autenticada.', null],
            $e instanceof AuthorizationException => [403, 'forbidden', 'Você não tem permissão para esta ação.', null],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'not_found', 'Registro não encontrado.', null],
            $e instanceof DomainException => [$e->status(), $e->errorCode(), $e->getMessage(), null],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $this->httpCode($e->getStatusCode()), $this->httpMessage($e), null],
            default => [500, 'server_error', 'Erro interno. Informe o código de correlação ao suporte.', null],
        };

        $payload = array_filter([
            'message' => $message,
            'code' => $code,
            'errors' => $errors,
            'correlation_id' => $request->attributes->get('correlation_id'),
        ], fn ($v) => $v !== null);

        if ($status === 500 && config('app.debug')) {
            $payload['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse($payload, $status, $headers);
    }

    private function httpCode(int $status): string
    {
        return match ($status) {
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            419 => 'csrf_mismatch',
            429 => 'too_many_requests',
            default => 'http_error',
        };
    }

    private function httpMessage(HttpExceptionInterface $e): string
    {
        return match ($e->getStatusCode()) {
            419 => 'Sessão expirada. Recarregue a página.',
            429 => 'Muitas tentativas. Aguarde e tente novamente.',
            default => $e->getMessage() !== '' ? $e->getMessage() : 'Erro na requisição.',
        };
    }
}
