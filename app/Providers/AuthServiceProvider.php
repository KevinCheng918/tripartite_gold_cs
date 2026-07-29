<?php

namespace App\Providers;

use App\Models\User;
use App\Services\PermissionMapService;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * 權限認證服務提供者
 *
 * 管理者（level=ADMIN）自動 bypass 所有權限檢查，
 * 客服（level=CS）依 user_permissions 表的 keyword 判斷。
 */
class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [];

    /**
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // 管理者 bypass 所有權限
        Gate::before(function (User $user) {
            return $user->isAdmin() ? true : null;
        });

        // 為每個 keyword 註冊 Gate
        foreach (app(PermissionMapService::class)->getAllKeywords() as $keyword) {
            Gate::define($keyword, function (User $user) use ($keyword) {
                return $user->hasPermission($keyword);
            });
        }
    }
}
