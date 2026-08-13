<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 帳號管理 Service
 *
 * 處理帳號的 CRUD 及權限指派等核心商業邏輯。
 * DB Transaction 在此層處理，Controller 不碰 DB。
 */
class AccountService
{
    private $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * 查詢帳號列表（分頁）
     *
     * @param array $params 可含 keyword, per_page
     * @return LengthAwarePaginator
     */
    public function list($params)
    {
        $filters = [
            'account'  => $params['account'] ?? null,
            'nickname' => $params['nickname'] ?? null,
            'status'   => $params['status'] ?? null,
            'level'    => $params['level'] ?? null,
        ];

        return $this->userRepository->paginate(
            $filters,
            (int) ($params['per_page'] ?? config('constants.PAGINATION.USER', 20))
        );
    }

    /**
     * 取得客服帳號狀態統計（排除管理者）
     *
     * @return array ['normal' => N, 'lock' => N, 'deactivate' => N, 'total' => N]
     */
    public function getStatusStats()
    {
        return $this->userRepository->countByStatus();
    }

    /**
     * 新增帳號（客服帳號，level=CS）
     *
     * @param array $params 含 account, nickname, password
     * @return User
     */
    public function create($params)
    {
        return DB::transaction(function () use ($params) {
            return $this->userRepository->create([
                'account'  => $params['account'],
                'nickname' => $params['nickname'],
                'password' => $params['password'],
                'status'   => config('constants.USER.STATUS.NORMAL'),
                'level'    => config('constants.USER.LEVEL.CS'),
            ]);
        });
    }

    /**
     * 更新帳號
     *
     * @param User  $user
     * @param array $params
     * @return User
     */
    public function update(User $user, $params)
    {
        $attributes = array_filter([
            'nickname' => $params['nickname'] ?? null,
            'account'  => $params['account'] ?? null,
            'status'   => array_key_exists('status', $params) ? $params['status'] : null,
        ], function ($value) {
            return filled($value);
        });

        if (filled($params['password'] ?? null)) {
            $attributes['password'] = $params['password'];
        }

        return DB::transaction(function () use ($user, $attributes) {
            return $this->userRepository->update($user, $attributes);
        });
    }

    /**
     * 指派權限（全部取代）
     *
     * @param User  $user
     * @param array $keywords
     * @return void
     */
    public function assignPermissions(User $user, $keywords)
    {
        $this->userRepository->syncPermissions($user, $keywords);
    }
}
