<?php

namespace App\Repositories;

use App\Criteria\CriteriaInterface;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 使用者 Repository
 *
 * 負責 user 表及 user_permissions 表的所有 DB 操作。
 */
class UserRepository
{
    /** @var array 列表查詢欄位 */
    private const LIST_COLUMNS = ['id', 'account', 'nickname', 'status', 'level', 'created_at'];

    /**
     * 依條件分頁查詢使用者
     *
     * @param array $filters 篩選條件（account, nickname, status, level）
     * @param int   $perPage
     * @return LengthAwarePaginator
     */
    public function paginate($filters = [], $perPage = 20)
    {
        $query = User::query()->select(self::LIST_COLUMNS)->with('permissions');

        if (filled($filters['account'] ?? null)) {
            $query->where('account', 'like', "%{$filters['account']}%");
        }

        if (filled($filters['nickname'] ?? null)) {
            $query->where('nickname', 'like', "%{$filters['nickname']}%");
        }

        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', (int) $filters['status']);
        }

        if (isset($filters['level']) && $filters['level'] !== '' && $filters['level'] !== null) {
            $query->where('level', (int) $filters['level']);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * 統計客服帳號各狀態數量（排除管理者）
     *
     * @return array
     */
    public function countByStatus()
    {
        $rows = User::query()
            ->selectRaw('status, COUNT(*) as count')
            ->where('level', config('constants.USER.LEVEL.CS'))
            ->groupBy('status')
            ->get();

        $normal = 0;
        $lock = 0;
        $deactivate = 0;

        foreach ($rows as $row) {
            if ((int) $row->status === config('constants.USER.STATUS.NORMAL')) {
                $normal = $row->count;
            } elseif ((int) $row->status === config('constants.USER.STATUS.LOCK')) {
                $lock = $row->count;
            } elseif ((int) $row->status === config('constants.USER.STATUS.DEACTIVATE')) {
                $deactivate = $row->count;
            }
        }

        return [
            'normal'     => $normal,
            'lock'       => $lock,
            'deactivate' => $deactivate,
            'total'      => $normal + $lock + $deactivate,
        ];
    }

    /**
     * 依 ID 查詢使用者
     *
     * @param int $id
     * @return User|null
     */
    public function find($id)
    {
        return User::query()->select(self::LIST_COLUMNS)->with('permissions')->find($id);
    }

    /**
     * 依帳號查詢使用者
     *
     * @param string $account
     * @return User|null
     */
    public function findByAccount($account)
    {
        return User::query()->where('account', $account)->first();
    }

    /**
     * 依帳號查詢使用者（含密碼，登入驗證用）
     *
     * @param string $account
     * @return User|null
     */
    public function findByAccountForLogin($account)
    {
        return User::query()
            ->select(['id', 'account', 'password', 'status'])
            ->where('account', $account)
            ->first();
    }

    /**
     * 新增使用者
     *
     * @param array $attributes
     * @return User
     */
    public function create($attributes)
    {
        return User::query()->create($attributes);
    }

    /**
     * 更新使用者
     *
     * @param User  $user
     * @param array $attributes
     * @return User
     */
    public function update(User $user, $attributes)
    {
        $user->update($attributes);

        return $user;
    }

    /**
     * 軟刪除使用者
     *
     * @param User $user
     * @return bool
     */
    public function softDelete(User $user)
    {
        return (bool) $user->delete();
    }

    /**
     * 同步帳號權限（全部取代）
     *
     * @param User  $user
     * @param array $keywords 權限 keyword 陣列
     * @return void
     */
    public function syncPermissions(User $user, $keywords)
    {
        DB::transaction(function () use ($user, $keywords) {
            UserPermission::query()->where('user_id', $user->id)->delete();

            if (empty($keywords)) {
                return;
            }

            $rows = array_map(function ($keyword) use ($user) {
                return [
                    'user_id' => $user->id,
                    'permission_keyword' => $keyword,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $keywords);

            UserPermission::query()->insert($rows);
        });
    }

    /**
     * 取得所有客服帳號（含停用，用於統計）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllCsUsers()
    {
        return User::query()
            ->select(['id', 'account', 'nickname', 'status'])
            ->where('level', config('constants.USER.LEVEL.CS'))
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 取得所有客服帳號（排除停用）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCsUsers()
    {
        return User::query()
            ->select(['id', 'account', 'nickname', 'status'])
            ->where('level', config('constants.USER.LEVEL.CS'))
            ->where('status', '!=', config('constants.USER.STATUS.DEACTIVATE'))
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 取得所有正常狀態帳號（下拉選單用）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveForDropdown()
    {
        return User::query()
            ->select(['id', 'nickname'])
            ->where('status', config('constants.USER.STATUS.NORMAL'))
            ->orderBy('nickname')
            ->get();
    }

    /**
     * 取得帳號的權限 keyword 清單
     *
     * @param User $user
     * @return array
     */
    public function getPermissionKeywords(User $user)
    {
        return $user->permissions()->pluck('permission_keyword')->all();
    }

    /**
     * 儲存 Web Push 訂閱資訊
     *
     * @param User   $user
     * @param string $endpoint
     * @param string $p256dhKey
     * @param string $authToken
     * @return User
     */
    public function savePushSubscription(User $user, $endpoint, $p256dhKey, $authToken)
    {
        $user->update([
            'push_endpoint'    => $endpoint,
            'push_p256dh_key'  => $p256dhKey,
            'push_auth_token'  => $authToken,
        ]);

        return $user;
    }

    /**
     * 清除 Web Push 訂閱資訊
     *
     * @param User $user
     * @return User
     */
    public function clearPushSubscription(User $user)
    {
        $user->update([
            'push_endpoint'    => null,
            'push_p256dh_key'  => null,
            'push_auth_token'  => null,
        ]);

        return $user;
    }

    /**
     * 取得所有已訂閱 Web Push 的使用者
     *
     * @param int|null $excludeId 排除的使用者 ID
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSubscribedUsers($excludeId = null)
    {
        $query = User::query()
            ->select(['id', 'push_endpoint', 'push_p256dh_key', 'push_auth_token'])
            ->whereNotNull('push_endpoint');

        if (filled($excludeId)) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->get();
    }
}
