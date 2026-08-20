<?php

namespace App\Repositories;

use App\Models\LoginLog;

/**
 * 登入紀錄 Repository
 */
class LoginLogRepository
{
    /**
     * 新增登入紀錄
     *
     * @param array $attributes [user_id, account, ip, is_success, device, fail_reason]
     * @return LoginLog
     */
    public function create(array $attributes)
    {
        return LoginLog::create($attributes);
    }

    /**
     * 分頁查詢登入紀錄
     *
     * @param array $filters [account, is_success, start_date, end_date]
     * @param int   $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(array $filters, $perPage = 20)
    {
        $query = LoginLog::query()
            ->select('id', 'user_id', 'account', 'ip', 'is_success', 'device', 'fail_reason', 'created_at');

        if (filled($filters['account'] ?? null)) {
            $query->where('account', 'like', "%{$filters['account']}%");
        }

        if (filled($filters['is_success'] ?? null)) {
            $query->where('is_success', (int) $filters['is_success']);
        }

        if (filled($filters['ip'] ?? null)) {
            $query->where('ip', 'like', "%{$filters['ip']}%");
        }

        if (filled($filters['start_date'] ?? null)) {
            $query->where('created_at', '>=', "{$filters['start_date']} 00:00:00");
        }

        if (filled($filters['end_date'] ?? null)) {
            $query->where('created_at', '<=', "{$filters['end_date']} 23:59:59");
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * 依 user_id 分頁查詢登入紀錄
     *
     * @param int $userId
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateByUserId($userId, $perPage = 10)
    {
        return LoginLog::query()
            ->select('id', 'account', 'ip', 'is_success', 'device', 'fail_reason', 'created_at')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
