<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use App\Services\PermissionMapService;
use App\Services\PermissionService;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::before(function (User $user) {
            return app(PermissionService::class)->userHasRole($user, Role::ADMIN_ROLE_NAME) ? true : null;
        });

        foreach (app(PermissionMapService::class)->getAllKeywords() as $keyword) {
            Gate::define($keyword, function (User $user) use ($keyword) {
                return app(PermissionService::class)->check($user, $keyword);
            });
        }
    }
}
