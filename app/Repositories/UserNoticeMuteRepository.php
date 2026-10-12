<?php

namespace App\Repositories;

use App\Models\UserNoticeMute;
use Illuminate\Support\Facades\DB;

/**
 * 「這個人關掉了哪些私訊」Repository
 *
 * 有列＝關掉，沒列＝要收。
 */
class UserNoticeMuteRepository
{
    /**
     * 哪些人關掉了這一種通知
     *
     * ⚠ 一次撈整批。發送端是「每位同仁跑一輪」的迴圈，
     * 逐人問一次就是 N+1，而那幾支排程每天都跑。
     *
     * @param string $noticeKey
     * @return array user_id 陣列
     */
    public function getMutedUserIds($noticeKey)
    {
        return UserNoticeMute::query()
            ->where('notice_key', $noticeKey)
            ->pluck('user_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    /**
     * 這個人關掉了哪些
     *
     * @param int $userId
     * @return array notice_key 陣列
     */
    public function getMutedKeysByUser($userId)
    {
        return UserNoticeMute::query()
            ->where('user_id', $userId)
            ->pluck('notice_key')
            ->all();
    }

    /**
     * 整組覆寫某個人的設定
     *
     * ⚠ 包在 transaction 裡：先刪再寫，中間失敗的話那個人會變成「全部都收」，
     * 而畫面上顯示的卻是他剛才取消的樣子。
     *
     * ⚠ 收的是「要關掉的」清單，不是「要收的」—— 這張表是黑名單。
     * 畫面上使用者勾的是「要收」，由 Service 反轉後傳進來。
     *
     * @param int   $userId
     * @param array $mutedKeys 要關掉的通知
     * @return void
     */
    public function replaceForUser($userId, array $mutedKeys)
    {
        DB::transaction(function () use ($userId, $mutedKeys) {
            UserNoticeMute::query()->where('user_id', $userId)->delete();

            if (blank($mutedKeys)) {
                return;
            }

            $now = now()->toDateTimeString();
            $rows = [];

            foreach ($mutedKeys as $key) {
                $rows[] = [
                    'user_id'    => $userId,
                    'notice_key' => $key,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            UserNoticeMute::query()->insert($rows);
        });
    }
}
