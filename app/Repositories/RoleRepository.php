<?php

namespace App\Repositories;

use App\Criteria\CriteriaInterface;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RoleRepository
{
    private const LIST_COLUMNS = ['id', 'name', 'display_name', 'description', 'is_active', 'sort'];

    /**
     * @param array<int, CriteriaInterface> $criteria
     */
    public function paginate(array $criteria = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Role::query()->select(self::LIST_COLUMNS)->with('permissions');

        foreach ($criteria as $criterion) {
            $query = $criterion->apply($query);
        }

        return $query->orderBy('sort')->orderBy('id')->paginate($perPage);
    }

    public function find(int $id): ?Role
    {
        return Role::query()->select(self::LIST_COLUMNS)->with('permissions')->find($id);
    }

    public function findByName(string $name): ?Role
    {
        return Role::query()->where('name', $name)->first();
    }

    public function create(array $attributes): Role
    {
        return Role::query()->create($attributes);
    }

    public function update(Role $role, array $attributes): Role
    {
        $role->update($attributes);

        return $role;
    }

    public function softDelete(Role $role): bool
    {
        return (bool) $role->delete();
    }

    public function userCount(Role $role): int
    {
        return $role->users()->count();
    }

    /**
     * @return array<int, string>
     */
    public function getPermissionKeywords(int $roleId): array
    {
        return RolePermission::query()
            ->where('role_id', $roleId)
            ->pluck('permission_keyword')
            ->all();
    }

    /**
     * @param array<int, string> $keywords
     */
    public function syncPermissions(Role $role, array $keywords): void
    {
        DB::transaction(function () use ($role, $keywords) {
            RolePermission::query()->where('role_id', $role->id)->delete();

            if (empty($keywords)) {
                return;
            }

            $rows = array_map(function ($keyword) use ($role) {
                return [
                    'role_id' => $role->id,
                    'permission_keyword' => $keyword,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $keywords);

            RolePermission::query()->insert($rows);
        });
    }
}
