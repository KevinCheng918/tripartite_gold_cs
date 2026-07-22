<?php

namespace App\Repositories;

use App\Criteria\CriteriaInterface;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UserRepository
{
    private const LIST_COLUMNS = ['id', 'name', 'email', 'status', 'created_at'];

    /**
     * @param array<int, CriteriaInterface> $criteria
     */
    public function paginate(array $criteria = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = User::query()->select(self::LIST_COLUMNS)->with('roles');

        foreach ($criteria as $criterion) {
            $query = $criterion->apply($query);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function find(int $id): ?User
    {
        return User::query()->select(self::LIST_COLUMNS)->with('roles')->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }

    public function update(User $user, array $attributes): User
    {
        $user->update($attributes);

        return $user;
    }

    public function softDelete(User $user): bool
    {
        return (bool) $user->delete();
    }

    public function syncRoles(User $user, array $roleIds): void
    {
        DB::transaction(function () use ($user, $roleIds) {
            $user->roles()->sync($roleIds);
        });
    }
}
