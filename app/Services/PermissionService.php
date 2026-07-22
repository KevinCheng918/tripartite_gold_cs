<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Repositories\RoleRepository;
use Illuminate\Support\Facades\Cache;

class PermissionService
{
    private const CACHE_TTL_SECONDS = 3600;

    private RoleRepository $roleRepository;

    public function __construct(RoleRepository $roleRepository)
    {
        $this->roleRepository = $roleRepository;
    }

    public function check(User $user, string $keyword): bool
    {
        if ($this->userHasRole($user, Role::ADMIN_ROLE_NAME)) {
            return true;
        }

        foreach ($user->roles as $role) {
            if (in_array($keyword, $this->getRolePermissionKeywords($role->id), true)) {
                return true;
            }
        }

        return false;
    }

    public function userHasRole(User $user, string $roleName): bool
    {
        return $user->roles->contains(function (Role $role) use ($roleName) {
            return $role->name === $roleName;
        });
    }

    /**
     * @return array<int, string>
     */
    public function getRolePermissionKeywords(int $roleId): array
    {
        return Cache::remember(
            "role_permissions:{$roleId}",
            self::CACHE_TTL_SECONDS,
            function () use ($roleId) {
                return $this->roleRepository->getPermissionKeywords($roleId);
            }
        );
    }

    public function forgetRoleCache(Role $role): void
    {
        Cache::forget("role_permissions:{$role->id}");
    }
}
