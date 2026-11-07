<?php

namespace App\Repositories;

use App\Models\AutoReplyResume;
use Illuminate\Database\Eloquent\Collection;

/**
 * 自動回覆關閉紀錄 Repository
 *
 * 寫入是「關一次 → 一列」；讀取只有三種：
 *   - 排程要恢復哪幾筆
 *   - 群組被封存了、該作廢哪幾筆
 *   - 這些群組目前各自掛著哪一筆（聊天視窗顯示倒數、勾回來時結案）
 */
class AutoReplyResumeRepository
{
    /** @var array 欄位（都要帶表名前綴：撈待恢復時有 join） */
    private const COLUMNS = [
        'auto_reply_resume.id',
        'auto_reply_resume.telegram_group_id',
        'auto_reply_resume.user_id',
        'auto_reply_resume.operator_name',
        'auto_reply_resume.off_at',
        'auto_reply_resume.quiet_minutes',
        'auto_reply_resume.mode',
        'auto_reply_resume.status',
        'auto_reply_resume.resumed_at',
    ];

    /**
     * 記一筆關閉
     *
     * @param array $attributes
     * @return AutoReplyResume
     */
    public function create(array $attributes)
    {
        return AutoReplyResume::query()->create($attributes);
    }

    /**
     * 更新一筆
     *
     * @param AutoReplyResume $resume
     * @param array           $attributes
     * @return AutoReplyResume
     */
    public function update(AutoReplyResume $resume, array $attributes)
    {
        $resume->update($attributes);

        return $resume;
    }

    /**
     * 整批改狀態
     *
     * ⚠ 用 `whereIn` 一次更新而不是逐筆 `save()`：排程一輪可能恢復十幾筆，
     * 逐筆就是十幾趟來回。
     *
     * @param array $ids
     * @param int   $status 見 constants.AUTO_REPLY_RESUME.STATUS
     * @return int 影響列數
     */
    public function markStatus(array $ids, $status)
    {
        if (blank($ids)) {
            return 0;
        }

        return AutoReplyResume::query()
            ->whereIn('id', $ids)
            ->update([
                'status'     => $status,
                'resumed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * 該恢復的那幾筆
     *
     * 條件是「待恢復」且「暫時關閉」且對話靜置夠久，而且群組現在仍然是關著、
     * 仍然啟用。
     *
     * ⚠ **靜置的起算點是 `max(off_at, last_message_at)`**，不是只看後者 ——
     * 客服常是在客人安靜一陣子之後才關的，那時 `last_message_at` 比 `off_at`
     * 還早，只看它會讓這筆一關掉就立刻達標、下一分鐘就被恢復。
     *
     * ⚠ **`auto_reply = 0` 要擺進 SQL**：客服自己勾回來時 `toggle()` 會把這筆
     * 結案，但萬一沒結到（例外、舊資料），這道條件保證不會把人家剛開好的
     * 開關再動一次。
     *
     * ⚠ 這段 `whereRaw` 是 **MySQL 語法**（`GREATEST` / `DATE_ADD`）。放在 SQL
     * 而不是撈回來用 PHP 篩，是因為「待恢復」會隨群組數成長，而真正到期的
     * 通常只有零到幾筆 —— 用 PHP 篩等於每分鐘把全部撈一遍。
     *
     * @return Collection<AutoReplyResume>
     */
    public function getDueForResume()
    {
        $modes = (array) config('constants.AUTO_REPLY_RESUME.MODE');
        $statuses = (array) config('constants.AUTO_REPLY_RESUME.STATUS');

        return AutoReplyResume::query()
            ->select(self::COLUMNS)
            ->with(['group', 'user'])
            ->join('telegram_group', 'telegram_group.id', '=', 'auto_reply_resume.telegram_group_id')
            ->where('auto_reply_resume.status', $statuses['PENDING'])
            ->where('auto_reply_resume.mode', $modes['TEMPORARY'])
            ->whereNotNull('auto_reply_resume.quiet_minutes')
            ->where('telegram_group.auto_reply', 0)
            ->where('telegram_group.status', config('constants.TELEGRAM.GROUP_STATUS.ACTIVE'))
            ->whereRaw(
                'DATE_ADD('
                . 'GREATEST(auto_reply_resume.off_at, COALESCE(telegram_group.last_message_at, auto_reply_resume.off_at)),'
                . ' INTERVAL auto_reply_resume.quiet_minutes MINUTE'
                . ') <= ?',
                [now()]
            )
            ->orderBy('auto_reply_resume.id')
            ->get();
    }

    /**
     * 群組已經被封存、卻還掛著「待恢復」的那幾筆
     *
     * 恢復一個封存的對話沒有意義，但留著 `PENDING` 會讓它永遠撈得到、
     * 聊天視窗也會顯示一個不會到來的倒數 —— 所以要作廢（不通知）。
     *
     * @return Collection<AutoReplyResume>
     */
    public function getPendingForArchivedGroups()
    {
        $statuses = (array) config('constants.AUTO_REPLY_RESUME.STATUS');

        return AutoReplyResume::query()
            ->select(self::COLUMNS)
            ->join('telegram_group', 'telegram_group.id', '=', 'auto_reply_resume.telegram_group_id')
            ->where('auto_reply_resume.status', $statuses['PENDING'])
            ->where('telegram_group.status', '!=', config('constants.TELEGRAM.GROUP_STATUS.ACTIVE'))
            ->orderBy('auto_reply_resume.id')
            ->get();
    }

    /**
     * 某個群組目前掛著的那一筆待恢復
     *
     * @param int $groupId
     * @return AutoReplyResume|null
     */
    public function findPendingByGroup($groupId)
    {
        return AutoReplyResume::query()
            ->select(self::COLUMNS)
            ->where('auto_reply_resume.telegram_group_id', $groupId)
            ->where('auto_reply_resume.status', config('constants.AUTO_REPLY_RESUME.STATUS.PENDING'))
            ->orderByDesc('auto_reply_resume.id')
            ->first();
    }

    /**
     * 這些群組目前各自掛著的那一筆待恢復
     *
     * 給聊天視窗的群組清單用 —— 逐群組呼叫 `findPendingByGroup()` 就是 N+1。
     *
     * ⚠ 回傳以 `telegram_group_id` 為 key，**同一個群組只留 id 最大的那筆**。
     * 理論上一個群組同時只會有一筆 `PENDING`（關的時候會把舊的結案），
     * 但真的撞到兩筆時要取最新的，不是隨機一筆。
     *
     * @param array $groupIds
     * @return Collection<int, AutoReplyResume> key = telegram_group_id
     */
    public function getPendingByGroupIds(array $groupIds)
    {
        if (blank($groupIds)) {
            return new Collection();
        }

        return AutoReplyResume::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->whereIn('auto_reply_resume.telegram_group_id', $groupIds)
            ->where('auto_reply_resume.status', config('constants.AUTO_REPLY_RESUME.STATUS.PENDING'))
            ->orderBy('auto_reply_resume.id')
            ->get()
            ->keyBy('telegram_group_id');
    }
}
