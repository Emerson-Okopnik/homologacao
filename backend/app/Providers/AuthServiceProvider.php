<?php

namespace App\Providers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Infrastructure\Auth\TenantAwareUserProvider;
use App\Policies\AuditLogPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::provider('tenant-eloquent', fn (Application $app, array $config) => new TenantAwareUserProvider(
            $app['hash'],
            $config['model'],
        ));

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        // Permissões granulares disponíveis como abilities: Gate::allows('clients.manage').
        foreach (PermissionKey::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $query = http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]);

            return rtrim((string) config('homologa.frontend_url'), '/')."/redefinir-senha?{$query}";
        });
    }
}
