<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        // O broker usa o provider "tenant-eloquent", que busca por e-mail sem TenantScope.
        Password::broker()->sendResetLink($request->only('email'));

        // Resposta idêntica para e-mail existente ou não (evita enumeração de usuários).
        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
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

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Link de redefinição inválido ou expirado.']);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso. Faça login com a nova senha.']);
    }
}
