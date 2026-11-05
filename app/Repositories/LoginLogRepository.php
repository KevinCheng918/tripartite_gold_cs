<?php

namespace App\Repositories;

use App\Models\LoginLog;
use Illuminate\Support\Arr;

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

        $account = Arr::get($filters, 'account');
        if (filled($account)) {
            $query->where('account', 'like', "%{$account}%");
        }

        $isSuccess = Arr::get($filters, 'is_success');
        if (filled($isSuccess)) {
            $query->where('is_success', (int) $isSuccess);
        }

        $ip = Arr::get($filters, 'ip');
        if (filled($ip)) {
            $query->where('ip', 'like', "%{$ip}%");
        }

        $startDate = Arr::get($filters, 'start_date');
        if (filled($startDate)) {
            $query->where('created_at', '>=', "{$startDate} 00:00:00");
        }

        $endDate = Arr::get($filters, 'end_date');
        if (filled($endDate)) {
            $query->where('created_at', '<=', "{$endDate} 23:59:59");
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

    /**
     * 還沒處理過登入通知的紀錄
     *
     * ⚠ **一定要帶 `$since` 下限。** 排程停掉一天再恢復的話，沒有下限會把
     * 一整天的登入全撈出來補發 —— 幾十則幾小時前的通知同時湧進來，
     * 既沒有價值（早就來不及反應）又會把人洗到以後都不看。
     * 窗口在 `constants.LOGIN_NOTICE.LOOKBACK_MINUTES`。
     *
     * ⚠ 照 `id` 遞增排，不是 `created_at` 遞減：失敗累積要按發生順序判斷，
     * 而同一秒內可能有多筆（連續試密碼），`created_at` 分不出先後。
     *
     * @param \Carbon\Carbon|string $since 只撈這個時間點之後的
     * @param int                   $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function pendingNotices($since, $limit = 100)
    {
        return LoginLog::query()
            ->select('id', 'user_id', 'account', 'ip', 'is_success', 'device', 'fail_reason', 'created_at')
            ->whereNull('notified_at')
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 回看窗口之外、還沒處理的紀錄 id
     *
     * ⚠ 這些**不發通知，但一定要標記掉**。留著 null 的話每分鐘都會被
     * `pendingNotices()` 的 `whereNull` 掃到（即使因為時間下限而撈不出來），
     * 而且永遠不會消失 —— 索引會越來越不好用。
     *
     * @param \Carbon\Carbon|string $before
     * @param int                   $limit
     * @return array<int, int>
     */
    public function staleNoticeIds($before, $limit = 500)
    {
        return LoginLog::query()
            ->select('id')
            ->whereNull('notified_at')
            ->where('created_at', '<', $before)
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * 標記這些紀錄已經處理過登入通知
     *
     * ⚠ 「處理過」包含「判斷之後決定不發」—— 不是只有真的發出去的才標。
     * 否則決定不發的那些會被每分鐘重新判斷一次，永遠累積。
     *
     * @param array<int, int> $ids
     * @return int 影響筆數
     */
    public function markNotified($ids)
    {
        $ids = array_filter((array) $ids);

        if (blank($ids)) {
            return 0;
        }

        return LoginLog::query()->whereIn('id', $ids)->update(['notified_at' => now()]);
    }

    /**
     * 這個帳號**成功登入過**的 IP 清單
     *
     * ⚠⚠ **只採計成功登入（`is_success = 1`）。** 把失敗也算進「見過」的話，
     * 攻擊者第一次失敗之後他的 IP 就變成「見過的 IP」，後續連續嘗試全部
     * 降級成要等累積門檻 —— 等於自己把警報關小聲。
     *
     * @param int $userId
     * @param int $limit
     * @return array<int, string>
     */
    public function knownIps($userId, $limit = 50)
    {
        return LoginLog::query()
            ->select('ip')
            ->where('user_id', (int) $userId)
            ->where('is_success', 1)
            ->distinct()
            ->orderByDesc('ip')
            ->limit($limit)
            ->pluck('ip')
            ->all();
    }

    /**
     * 這個帳號**成功登入過**的 User-Agent 原字串清單
     *
     * 回原字串而不是指紋：指紋怎麼算是 `UserAgentPresenter` 的事，
     * Repository 不該知道（也沒辦法在 SQL 裡算）。
     *
     * ⚠ `distinct` 是對原字串做的，所以同一台裝置換了 Chrome 版本會多一列。
     * 筆數因此比「裝置數」多，`$limit` 要給得寬一點。
     * 去重到「同一台裝置」是 Service 用指紋做的。
     *
     * @param int $userId
     * @param int $limit
     * @return array<int, string>
     */
    public function knownDevices($userId, $limit = 50)
    {
        return LoginLog::query()
            ->select('device')
            ->where('user_id', (int) $userId)
            ->where('is_success', 1)
            ->whereNotNull('device')
            ->distinct()
            ->orderByDesc('device')
            ->limit($limit)
            ->pluck('device')
            ->all();
    }

    /**
     * 這個帳號在某個時間點之後失敗了幾次
     *
     * ⚠ 用 `account` 而不是 `user_id`：帳號不存在時 `user_id` 是 null，
     * 而「有人在猜帳號名稱」也該被算進同一串嘗試。
     *
     * @param string                $account
     * @param \Carbon\Carbon|string $since
     * @return int
     */
    public function countFailuresSince($account, $since)
    {
        return LoginLog::query()
            ->where('account', (string) $account)
            ->where('is_success', 0)
            ->where('created_at', '>=', $since)
            ->count();
    }
}
