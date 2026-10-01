<?php

namespace App\Repositories;

use App\Models\TelegramGroupStaffAllow;
use Illuminate\Database\Eloquent\Collection;

/**
 * 「這個對話特別放行哪些同事」Repository
 *
 * 負責 telegram_group_staff_allow 表的所有 DB 操作。
 * 快取不在這裡 —— 依專案慣例由 Service 層處理。
 */
class TelegramGroupStaffAllowRepository
{
    /**
     * 這個對話放行了哪些同事（比對用，只要 id）
     *
     * 每則同事發言都要讀，所以只取 `user_id` 一欄。
     *
     * @param int $groupId
     * @return Collection
     */
    public function getUserIdsByGroup($groupId)
    {
        return TelegramGroupStaffAllow::query()
            ->select(['user_id'])
            ->where('telegram_group_id', $groupId)
            ->get();
    }

    /**
     * 這個對話的放行清單（面板用，含是誰打開的）
     *
     * @param int $groupId
     * @return Collection
     */
    public function getByGroupWithCreator($groupId)
    {
        return TelegramGroupStaffAllow::query()
            ->select(['id', 'telegram_group_id', 'user_id', 'allowed_by', 'allowed_at'])
            ->with('allowedBy')
            ->where('telegram_group_id', $groupId)
            ->get();
    }

    /**
     * 放行一個同事（已經放行過就不重複寫）
     *
     * 用 firstOrCreate 而不是先查再寫：unique 索引是
     * `(telegram_group_id, user_id)`，連點兩次按鈕也只會有一列。
     *
     * @param int      $groupId
     * @param int      $userId
     * @param int|null $operatorId
     * @return TelegramGroupStaffAllow
     */
    public function allow($groupId, $userId, $operatorId)
    {
        return TelegramGroupStaffAllow::query()->firstOrCreate(
            ['telegram_group_id' => $groupId, 'user_id' => $userId],
            ['allowed_by' => $operatorId, 'allowed_at' => now()]
        );
    }

    /**
     * 收回放行（回到預設的「不自動回覆」）
     *
     * 直接刪列 —— 這張表是「有列 = 放行」，收回就是沒有列。
     *
     * @param int $groupId
     * @param int $userId
     * @return int 刪掉幾列
     */
    public function block($groupId, $userId)
    {
        return TelegramGroupStaffAllow::query()
            ->where('telegram_group_id', $groupId)
            ->where('user_id', $userId)
            ->delete();
    }
}
