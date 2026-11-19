<?php

namespace App\Services;

use App\Models\TelegramGroupMember;
use App\Repositories\TelegramGroupMemberRepository;
use Illuminate\Support\Arr;

/**
 * 對話視窗打 `@` 時的候選名單
 *
 * > 需求方 2026-11-19：「我後台的 Telegram 對話要能夠 @ 人，無論是自己人
 * > 還是站台客人，但要留意**有些人是沒有 id 的**。」
 * >
 * > 「只要**有在群組內**的都要列，不在群組內的不用列，因為列了也沒用。」
 *
 * ⚠⚠ **沒有 username 的人要用 `tg://user?id=` 叫。** 那種人打 `@名字`
 * 是沒有用的 —— Telegram 不會把它變成 mention，對方收不到任何通知。
 * 正確的做法是 text_mention：
 *
 * ```html
 * <a href="tg://user?id=123456789">權志龍</a>
 * ```
 *
 * 而 `TelegramBotService::escapeHtml()` 會把 Telegram 允許的標籤（含 `a`）
 * 還原回真的 HTML，`parse_mode` 也已經是 HTML —— 所以送得出去，
 * 不必改送訊息那一層。
 *
 * ⚠⚠ **Telegram 不給我們完整的群組成員名單**（Bot API 沒有「列出所有成員」
 * 這種方法），所以「在群組內的人」只能用**我們看過的人**去近似 ——
 * 也就是這個對話的名冊（發言過的 ＋ 客服手動加的）。
 * **潛水的人叫不出來，那是功能的邊界，不是壞掉。**
 *
 * 詳見 .claude/knowledge/features/chat-mention.md
 */
class TelegramMentionService
{
    private $memberRepository;
    private $staffIgnoreService;

    public function __construct(
        TelegramGroupMemberRepository $memberRepository,
        StaffIgnoreService $staffIgnoreService
    ) {
        $this->memberRepository = $memberRepository;
        // 用來標「這是我們的人」，讓客服一眼分得出同仁與客人
        $this->staffIgnoreService = $staffIgnoreService;
    }

    /**
     * 打 `@` 之後可以挑誰
     *
     * @param array $params group_id／keyword
     * @return \Illuminate\Support\Collection<int, array>
     */
    public function candidates(array $params)
    {
        $members = $this->memberRepository->searchForMention(
            (int) Arr::get($params, 'group_id'),
            (string) Arr::get($params, 'keyword', ''),
            (int) config('constants.TELEGRAM.MENTION.MAX_CANDIDATES')
        );

        return $members->map(function (TelegramGroupMember $member) {
            return $this->toCandidate($member);
        })->values();
    }

    /**
     * 一位成員 → 一個候選
     *
     * @param TelegramGroupMember $member
     * @return array{label: string, username: string|null, user_id: int|null, is_staff: bool}
     */
    private function toCandidate(TelegramGroupMember $member)
    {
        $username = (string) $member->username;

        return [
            // 選單上顯示的名字：有顯示名稱就用，否則退回 username
            'label'    => $this->labelOf($member),
            'username' => filled($username) ? $username : null,
            /*
             * ⚠ **只有「沒有 username」的人才需要帶 id 回前端。**
             * 有 username 的人送 `@xxx` 就夠了，多送一個 id 只是把成員的
             * Telegram id 散到前端去。
             */
            'user_id'  => filled($username) ? null : (int) $member->telegram_user_id,
            'is_staff' => $this->staffIgnoreService->isOurSide(
                (int) $member->telegram_user_id,
                $username
            ),
        ];
    }

    /**
     * 選單上要顯示的名字
     *
     * ⚠ 不能回空字串：那會變成一個點得到、但看不出是誰的空白列。
     * 顯示名稱 → username → 一句泛稱。
     *
     * @param TelegramGroupMember $member
     * @return string
     */
    private function labelOf(TelegramGroupMember $member)
    {
        if (filled($member->display_name)) {
            return (string) $member->display_name;
        }

        if (filled($member->username)) {
            return (string) $member->username;
        }

        return (string) trans('telegram_chat.mention_unknown');
    }
}
