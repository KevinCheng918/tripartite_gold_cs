<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccountService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function list(array $params): LengthAwarePaginator
    {
        $criteria = [];

        if (filled($params['keyword'] ?? null)) {
            $criteria[] = new \App\Criteria\User\UserKeywordSearchCriteria($params['keyword']);
        }

        if (filled($params['role_id'] ?? null)) {
            $criteria[] = new \App\Criteria\User\UserRoleFilterCriteria((int) $params['role_id']);
        }

        return $this->userRepository->paginate($criteria, (int) ($params['per_page'] ?? 20));
    }

    public function create(array $params): User
    {
        return DB::transaction(function () use ($params) {
            $user = $this->userRepository->create([
                'name' => $params['name'],
                'email' => $params['email'],
                'password' => Hash::make($params['password']),
                'status' => User::STATUS_ACTIVE,
            ]);

            if (filled($params['role_ids'] ?? null)) {
                $this->userRepository->syncRoles($user, $params['role_ids']);
            }

            return $user;
        });
    }

    public function update(User $user, array $params): User
    {
        $attributes = array_filter([
            'name' => $params['name'] ?? null,
            'email' => $params['email'] ?? null,
            'status' => array_key_exists('status', $params) ? $params['status'] : null,
        ], function ($value) {
            return $value !== null;
        });

        if (filled($params['password'] ?? null)) {
            $attributes['password'] = Hash::make($params['password']);
        }

        return $this->userRepository->update($user, $attributes);
    }

    public function delete(User $user): bool
    {
        return $this->userRepository->softDelete($user);
    }

    public function assignRoles(User $user, array $roleIds): void
    {
        $this->userRepository->syncRoles($user, $roleIds);
    }
}
