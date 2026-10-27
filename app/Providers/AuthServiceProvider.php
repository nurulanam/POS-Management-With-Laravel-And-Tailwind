<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::define('manage-business', fn (User $user) => $user->is_active && $user->isAdmin());
        Gate::define('view-reports', fn (User $user) => $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));
        Gate::define('manage-cash', fn (User $user) => $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));
        Gate::define('manage-adjustments', fn (User $user) => $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));
        Gate::define('manage-transfers', fn (User $user) => $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));
        Gate::define('manage-purchasing', fn (User $user) => $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));
        Gate::define('manage-inventory', fn (User $user) => $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));
        Gate::define('view-team', fn (User $user) => $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));
    }
}
