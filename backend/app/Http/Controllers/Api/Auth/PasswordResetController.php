<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\Users\PasswordResetCredentials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Jobs\RequestPasswordReset;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        // Até e-mails desconhecidos seguem a fila: a resposta não revela se existe conta.
        RequestPasswordReset::dispatch($request->validated('email'));

        // Resposta idêntica para e-mail existente ou não (evita enumeração de usuários).
        return response()->json([
            'message' => 'Solicitação recebida. Se houver uma conta ativa para este e-mail, você receberá um link para redefinir a senha.',
        ], 202);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = DB::transaction(function () use ($request): string {
            // Serializa o consumo do mesmo token em pedidos simultâneos.
            DB::table(config('auth.passwords.users.table'))
                ->where('email', $request->validated('email'))
                ->lockForUpdate()->first();

            return Password::broker()->reset(
                [...$request->only('password', 'password_confirmation', 'token'), ...PasswordResetCredentials::forEmail($request->validated('email'))],
                function (User $user, string $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->saveQuietly();

                    $tenant = Tenant::query()->find($user->tenant_id);
                    $this->audit->log('auth.password_reset', $user, actor: $user, tenant: $tenant);

                    event(new PasswordReset($user));
                },
            );
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'Link de redefinição inválido ou expirado. Solicite um novo link.']);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso. Acesse o sistema com a nova senha.']);
    }
}
