<?php

namespace App\Presenters;

/**
 * Telegram username 正規化 Presenter
 *
 * 去掉開頭的 `@`、轉小寫：
 *   @Abc  → abc
 *   abc   → abc
 *   ' @A ' → a
 *
 * 存與比對都要走這一支，否則 `@Abc` 與 `abc` 會被當成兩個人。
 *
 * 會有兩個地方用它，所以抽出來共用而不是各寫一份：
 *   - TelegramGroupMemberService — 每個對話的忽略名單（名冊存的 username）
 *   - StaffIgnoreService         — 後台帳號名單（user.telegram_username）
 *
 * 後者尤其需要：`user.telegram_username` 是人工填的，有人填 `@name`、
 * 有人填 `name`，兩套規則只要分岔一次就會開始漏人。
 */
class TelegramUsernamePresenter
{
    /**
     * 正規化 username
     *
     * @param string|null $username 可含 @、前後空白、大小寫不拘
     * @return string|null 空值回 null（不是空字串）—— 讓呼叫端一律用 filled() 判斷
     */
    public static function normalize($username)
    {
        if (blank($username)) {
            return null;
        }

        $normalized = mb_strtolower(ltrim(trim($username), '@'));

        return filled($normalized) ? $normalized : null;
    }

    /**
     * 整批正規化，順便濾掉空的並去重
     *
     * 名單類的資料（後台帳號、忽略名單）都是整包取出來比對，
     * 每個呼叫端自己 map + filter + unique 會重複三行程式碼。
     *
     * @param iterable $usernames
     * @return array 重新索引過的陣列，可直接丟 in_array
     */
    public static function normalizeAll($usernames)
    {
        $normalized = [];

        foreach ($usernames as $username) {
            $clean = self::normalize($username);

            if (filled($clean)) {
                $normalized[$clean] = true;
            }
        }

        return array_keys($normalized);
    }
}
