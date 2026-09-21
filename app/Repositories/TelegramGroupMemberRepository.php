<?php

namespace App\Repositories;

use App\Models\TelegramGroupMember;
use Illuminate\Database\Eloquent\Collection;

/**
 * Telegram 群組成員 Repository
 *
 * 負責 telegram_group_member 表的所有 DB 操作。
 * 快取不在這裡 —— 依專案慣例（AppSettingService、QuickReplyService）由 Service 層處理。
 */
class TelegramGroupMemberRepository
{
    /** @var array 成員欄位 */
    private const COLUMNS = [
        'id', 'telegram_group_id', 'telegram_user_id', 'username', 'display_name',
        'last_seen_at', 'ignored', 'ignored_by', 'ignored_at',
    ];

    /** @var array 比對用的精簡欄位 —— 每則訊息都要讀，只取判斷需要的兩個鍵 */
    private const MATCH_COLUMNS = ['telegram_user_id', 'username'];

    /**
     * 取得這個對話的忽略名單（比對用）
     *
     * @param int $groupId
     * @return Collection
     */
    public function getIgnoredByGroup($groupId)
    {
        return TelegramGroupMember::query()
            ->select(self::MATCH_COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->where('ignored', true)
            ->get();
    }

    /**
     * 取得這個對話已忽略成員的顯示名稱
     *
     * 訊息上的灰色標籤用的，只要名字，不必帶出設定者。
     *
     * @param int $groupId
     * @return Collection
     */
    public function getIgnoredNames($groupId)
    {
        return TelegramGroupMember::query()
            ->select(['display_name'])
            ->where('telegram_group_id', $groupId)
            ->where('ignored', true)
            ->whereNotNull('display_name')
            ->get();
    }

    /**
     * 取得這個對話已忽略的成員（Modal 用，含設定者）
     *
     * @param int $groupId
     * @return Collection
     */
    public function getIgnoredWithCreator($groupId)
    {
        return TelegramGroupMember::query()
            ->select(self::COLUMNS)
            ->with('ignoredBy')
            ->where('telegram_group_id', $groupId)
            ->where('ignored', true)
            ->orderByDesc('ignored_at')
            ->get();
    }

    /**
     * 取得這個對話近期發言過、且未被忽略的成員
     *
     * @param int             $groupId
     * @param \Carbon\Carbon  $since   起始時間
     * @param int             $limit
     * @return Collection
     */
    public function getRecentByGroup($groupId, $since, $limit)
    {
        return TelegramGroupMember::query()
            ->select(self::COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->where('ignored', false)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 依 Telegram 使用者 ID 查詢
     *
     * @param int $groupId
     * @param int $telegramUserId
     * @return TelegramGroupMember|null
     */
    public function findByUserId($groupId, $telegramUserId)
    {
        return TelegramGroupMember::query()
            ->select(self::COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->where('telegram_user_id', $telegramUserId)
            ->first();
    }

    /**
     * 依 username 查詢
     *
     * @param int    $groupId
     * @param string $username 已正規化（小寫、不含 @）
     * @return TelegramGroupMember|null
     */
    public function findByUsername($groupId, $username)
    {
        return TelegramGroupMember::query()
            ->select(self::COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->where('username', $username)
            ->first();
    }

    /**
     * 依 username 查詢「還沒對上 Telegram 使用者 ID」的那筆
     *
     * 手動加入的成員只有 username。等這個人真的發言時要把 id 補回同一筆，
     * 否則以 id 為鍵新增會讓同一個人在名單裡出現兩次。
     *
     * @param int    $groupId
     * @param string $username 已正規化（小寫、不含 @）
     * @return TelegramGroupMember|null
     */
    public function findManualByUsername($groupId, $username)
    {
        return TelegramGroupMember::query()
            ->select(self::COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->where('username', $username)
            ->whereNull('telegram_user_id')
            ->first();
    }

    /**
     * 依 ID 查詢（限定對話，避免改到別的對話的成員）
     *
     * @param int $groupId
     * @param int $id
     * @return TelegramGroupMember|null
     */
    public function findInGroup($groupId, $id)
    {
        return TelegramGroupMember::query()
            ->select(self::COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->find($id);
    }

    /**
     * 新增成員
     *
     * @param array $attributes
     * @return TelegramGroupMember
     */
    public function create($attributes)
    {
        return TelegramGroupMember::query()->create($attributes);
    }

    /**
     * 更新成員
     *
     * @param TelegramGroupMember $member
     * @param array               $attributes
     * @return TelegramGroupMember
     */
    public function update(TelegramGroupMember $member, $attributes)
    {
        $member->update($attributes);

        return $member;
    }

    /**
     * 刪除成員
     *
     * @param TelegramGroupMember $member
     * @return void
     */
    public function delete(TelegramGroupMember $member)
    {
        $member->delete();
    }
}
