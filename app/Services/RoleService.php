<?php

namespace App\Services;

use App\Criteria\Role\RoleActiveCriteria;
use App\Criteria\Role\RoleKeywordSearchCriteria;
use App\Exceptions\RoleInUseException;
use App\Models\Role;
use App\Repositories\RoleRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoleService
{
    private RoleRepository $roleRepository;

    private PermissionMapService $permissionMapService;

    private PermissionService $permissionService;

    public function __construct(
        RoleRepository $roleRepository,
        PermissionMapService $permissionMapService,
        PermissionService $permissionService
    ) {
        $this->roleRepository = $roleRepository;
        $this->permissionMapService = $permissionMapService;
        $this->permissionService = $permissionService;
    }

    public function list(array $params): LengthAwarePaginator
    {
        $criteria = [];

        if (filled($params['keyword'] ?? null)) {
            $criteria[] = new RoleKeywordSearchCriteria($params['keyword']);
        }

        if (filled($params['active_only'] ?? null)) {
            $criteria[] = new RoleActiveCriteria();
        }

        return $this->roleRepository->paginate($criteria, (int) ($params['per_page'] ?? 20));
    }

    public function create(array $params): Role
    {
        return $this->roleRepository->create([
            'name' => $params['name'],
            'display_name' => $params['display_name'],
            'description' => $params['description'] ?? null,
            'is_active' => $params['is_active'] ?? true,
            'sort' => $params['sort'] ?? 0,
        ]);
    }

    public function update(Role $role, array $params): Role
    {
        $attributes = array_filter([
            'display_name' => $params['display_name'] ?? null,
            'description' => array_key_exists('description', $params) ? $params['description'] : null,
            'is_active' => array_key_exists('is_active', $params) ? $params['is_active'] : null,
            'sort' => array_key_exists('sort', $params) ? $params['sort'] : null,
        ], function ($value) {
            return $value !== null;
        });

        return $this->roleRepository->update($role, $attributes);
    }

    public function delete(Role $role): bool
    {
        if ($this->roleRepository->userCount($role) > 0) {
            throw new RoleInUseException("Role [{$role->name}] still has assigned users and cannot be deleted.");
        }

        $deleted = $this->roleRepository->softDelete($role);
        $this->permissionService->forgetRoleCache($role);

        return $deleted;
    }

    /**
     * @param array<int, string> $keywords
     */
    public function assignPermissions(Role $role, array $keywords): void
    {
        $invalid = array_filter($keywords, function ($keyword) {
            return !$this->permissionMapService->isValidKeyword($keyword);
        });

        if (!empty($invalid)) {
            Log::warning('Attempted to assign unknown permission keywords', [
                'role_id' => $role->id,
                'invalid_keywords' => array_values($invalid),
            ]);

            throw new \InvalidArgumentException('Unknown permission keyword(s): ' . implode(', ', $invalid));
        }

        DB::transaction(function () use ($role, $keywords) {
            $this->roleRepository->syncPermissions($role, $keywords);
        });

        $this->permissionService->forgetRoleCache($role);
    }
}
