<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    public function login(LoginRequest $request): MeResource
    {
        $key = $request->throttleKey();
        $maxAttempts = (int) config('homologa.auth.max_login_attempts');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'email' => 'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($key).' segundos.',
            ])->status(429);
        }

        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, (int) config('homologa.auth.lockout_seconds'));
            $this->auditFailedLogin($request->string('email')->toString(), 'invalid_credentials');

            // Mensagem genérica: não revela se o e-mail existe.
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $tenant = Tenant::query()->find($user->tenant_id);

        if (! $user->active || ! $tenant?->active) {
            Auth::guard('web')->logout();
            RateLimiter::hit($key, (int) config('homologa.auth.lockout_seconds'));
            $this->auditFailedLogin($user->email, $user->active ? 'tenant_inactive' : 'user_inactive');

            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }

        $this->tenantContext->set($tenant);

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->audit->log('auth.login', $user, actor: $user, tenant: $tenant);

        return new MeResource($user);
    }

    public function me(Request $request): MeResource
    {
        return new MeResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $this->audit->log('auth.logout', $request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * Só é possível auditar quando o e-mail pertence a um usuário (o audit log é por tenant).
     * Tentativas com e-mails desconhecidos ficam apenas no log de aplicação, sem o e-mail.
     */
    private function auditFailedLogin(string $email, string $reason): void
    {
        $user = User::query()->withoutGlobalScope(TenantScope::class)->where('email', $email)->first();

        if ($user === null) {
            logger()->notice('auth.login_failed', ['reason' => 'unknown_email']);

            return;
        }

        $tenant = Tenant::query()->find($user->tenant_id);

        if ($tenant !== null) {
            $this->audit->log('auth.login_failed', $user, metadata: ['reason' => $reason], tenant: $tenant);
        }
    }
}
