<?php

namespace App\Services;

use App\Presenters\TelegramUsernamePresenter;
use App\Repositories\TelegramGroupStaffAllowRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 後台帳號一律不自動回覆
 *
 * 我方同事（客服、工程、主管）用**自己的 Telegram** 在客戶群組講話時，
 * 系統不該自動回覆他 —— 那是同事在跟客人溝通，不是客人在問問題。
 *
 * **預設屏蔽全部，除非某個對話把他特別打開。** 那份例外存在
 * `telegram_group_staff_allow`（有列 = 打開），只對那一個對話生效。
 *
 * 與 TelegramGroupMemberService 的忽略名單是**兩份並存**的機制：
 *
 * | | 這一支 | TelegramGroupMemberService |
 * |---|---|---|
 * | 範圍 | **全域**（可逐對話開例外） | 每個對話各自一份 |
 * | 名單 | 跟著 `user` 表自動走 | 客服手動挑／手動輸入 |
 * | 典型對象 | 我方同事 | 客戶方的工程師、PM |
 *
 * 任一份命中就不自動回覆。
 *
 * 只擋自動回覆這一段 —— 訊息照常存、照常推播、照常算未讀。
 */
class StaffIgnoreService
{
    private $userRepository;
    private $allowRepository;

    public function __construct(
        UserRepository $userRepository,
        TelegramGroupStaffAllowRepository $allowRepository
    ) {
        $this->userRepository = $userRepository;
        $this->allowRepository = $allowRepository;
    }

    /**
     * 這個發話者是不是「要屏蔽的同事」
     *
     * 兩步：先認人，再看這個對話有沒有把他特別打開。
     *
     * 認人時 ID 先比 —— 那是終生不變的。username 是備援，同事還沒被回填過
     * ID 時只能靠它，但它隨時可以被改掉。
     *
     * @param array $from    Telegram 的 from 物件
     * @param int   $groupId 哪個對話（例外是逐對話的）
     * @return bool
     */
    public function isStaff(array $from, $groupId)
    {
        $userId = $this->matchUserId($from);

        if (blank($userId)) {
            return false;
        }

        // 這個對話特別打開了他 → 不屏蔽，照常自動回覆
        return !in_array($userId, $this->allowedUserIds($groupId), true);
    }

    /**
     * 要標「不自動回覆」的同事 username（訊息灰標籤用）
     *
     * 名冊（`telegram_group_member`）存的是 username，拿這批去比就能知道
     * 哪些發言者是我方同事。
     *
     * ⚠ **要扣掉這個對話特別打開的人** —— 他現在會被自動回覆，訊息上再標
     * 「不自動回覆」就是標錯。扣除只是兩份已快取清單的差集，不會多打 DB。
     *
     * @param int $groupId
     * @return array
     */
    public function ignoredUsernames($groupId)
    {
        $map = $this->keys()['usernames'];
        $allowed = $this->allowedUserIds($groupId);

        if (blank($allowed)) {
            return array_keys($map);
        }

        $usernames = [];

        foreach ($map as $username => $userId) {
            if (!in_array((int) $userId, $allowed, true)) {
                $usernames[] = $username;
            }
        }

        return $usernames;
    }

    /**
     * 面板資料：名單（含這個對話的放行狀態）、以及認不出身分的帳號
     *
     * 不走名單快取 —— 面板開啟的頻率遠低於收訊，而這裡要顯示暱稱與
     * 「誰打開的」，跟比對用的那兩組映射不是同一種資料。
     *
     * @param int $groupId
     * @return array{staff: \Illuminate\Database\Eloquent\Collection,
     *     missing: \Illuminate\Database\Eloquent\Collection, allows: array}
     */
    public function getPanel($groupId)
    {
        $allows = [];

        foreach ($this->allowRepository->getByGroupWithCreator($groupId) as $allow) {
            $allows[(int) $allow->user_id] = [
                'by' => filled($allow->allowedBy) ? $allow->allowedBy->nickname : null,
                'at' => filled($allow->allowed_at) ? $allow->allowed_at->toDateTimeString() : null,
            ];
        }

        return [
            'staff'   => $this->userRepository->getTelegramIdentitiesForAutoReply(),
            'missing' => $this->userRepository->getMissingTelegramIdentity(),
            // Resource 要靠它決定每一列的勾選狀態與「誰打開的」
            'allows'  => $allows,
        ];
    }

    /**
     * 在這個對話特別打開某個同事（他會開始被自動回覆）
     *
     * @param int      $groupId
     * @param int      $userId
     * @param int|null $operatorId
     * @return void
     */
    public function allow($groupId, $userId, $operatorId)
    {
        $this->allowRepository->allow($groupId, $userId, $operatorId);
        $this->forgetAllowCache($groupId);

        Log::info('對話特別放行內部員工', [
            'group_id' => $groupId,
            'user_id'  => $userId,
            'operator' => $operatorId,
        ]);
    }

    /**
     * 收回放行，回到預設的「不自動回覆」
     *
     * @param int      $groupId
     * @param int      $userId
     * @param int|null $operatorId
     * @return void
     */
    public function block($groupId, $userId, $operatorId)
    {
        $this->allowRepository->block($groupId, $userId);
        $this->forgetAllowCache($groupId);

        Log::info('對話收回內部員工的放行', [
            'group_id' => $groupId,
            'user_id'  => $userId,
            'operator' => $operatorId,
        ]);
    }

    /**
     * 清掉名單快取
     *
     * 帳號的 `telegram_username` 或 `status` 一變動就要呼叫 ——
     * 不清的話，新同事最久要等快取過期才會被屏蔽。
     *
     * @return void
     */
    public function forgetCache()
    {
        Cache::forget((string) config('constants.TELEGRAM.STAFF_IGNORE.CACHE_KEY'));
    }

    /**
     * 認出這個發話者是哪一個後台帳號
     *
     * @param array $from Telegram 的 from 物件
     * @return int|null 不是同事就回 null
     */
    private function matchUserId(array $from)
    {
        $keys = $this->keys();

        // 一個同事都沒填 Telegram 身分時直接結束，不要往下比
        if (blank($keys['ids']) && blank($keys['usernames'])) {
            return null;
        }

        $telegramUserId = (int) Arr::get($from, 'id', 0);

        if ($telegramUserId > 0 && array_key_exists($telegramUserId, $keys['ids'])) {
            return (int) $keys['ids'][$telegramUserId];
        }

        $username = TelegramUsernamePresenter::normalize(Arr::get($from, 'username'));

        if (blank($username) || !array_key_exists($username, $keys['usernames'])) {
            return null;
        }

        $userId = (int) $keys['usernames'][$username];

        /*
         * username 命中、但這個人的 ID 還沒記過 —— 補上去。
         *
         * 這是這支服務在收訊流程裡唯一的寫入動作，而且只會發生一次
         * （補完之後就走上面那條 ID 比對）。補了之後他改 username 也還認得。
         */
        if ($telegramUserId > 0) {
            $this->backfillUserId($userId, $username, $telegramUserId);
        }

        return $userId;
    }

    /**
     * 兩組比對映射（快取）
     *
     * 回的是 `telegram_user_id => user_id` 與 `username => user_id` ——
     * 命中時直接拿到「是哪個同事」，不必再回頭查 `user` 表就能判斷例外。
     *
     * 名單極少變動，卻**每則 inbound 訊息都要讀** —— 不快取的話每一則
     * 客人訊息都會多一次 `user` 表查詢。
     *
     * 快取存的是純陣列而不是 Model 集合：序列化 Model 會把整個物件
     * 寫進快取，而這裡只需要兩組對應關係。
     *
     * @return array{ids: array, usernames: array}
     */
    private function keys()
    {
        $config = config('constants.TELEGRAM.STAFF_IGNORE');
        $repository = $this->userRepository;

        return Cache::remember(
            (string) $config['CACHE_KEY'],
            (int) $config['CACHE_SECONDS'],
            function () use ($repository) {
                $ids = [];
                $usernames = [];

                foreach ($repository->getTelegramIdentitiesForAutoReply() as $user) {
                    if (filled($user->telegram_user_id)) {
                        $ids[(int) $user->telegram_user_id] = (int) $user->id;
                    }

                    $username = TelegramUsernamePresenter::normalize($user->telegram_username);

                    if (filled($username)) {
                        $usernames[$username] = (int) $user->id;
                    }
                }

                return ['ids' => $ids, 'usernames' => $usernames];
            }
        );
    }

    /**
     * 這個對話特別打開了哪些同事（快取）
     *
     * 只在「已經確定發話者是同事」之後才會被呼叫 —— 一般客人的訊息
     * 完全不會碰到它。
     *
     * @param int $groupId
     * @return array user_id 陣列
     */
    private function allowedUserIds($groupId)
    {
        $config = config('constants.TELEGRAM.STAFF_IGNORE');
        $repository = $this->allowRepository;

        return Cache::remember(
            $config['ALLOW_CACHE_PREFIX'] . $groupId,
            (int) $config['CACHE_SECONDS'],
            function () use ($repository, $groupId) {
                return $repository->getUserIdsByGroup($groupId)
                    ->pluck('user_id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * 清掉某個對話的放行清單快取
     *
     * @param int $groupId
     * @return void
     */
    private function forgetAllowCache($groupId)
    {
        Cache::forget(config('constants.TELEGRAM.STAFF_IGNORE.ALLOW_CACHE_PREFIX') . $groupId);
    }

    /**
     * 把 Telegram 使用者 ID 補到帳號上
     *
     * ⚠ 這段跑在收訊流程裡，**失敗絕不能影響收訊** —— 訊息本身已經存好了，
     * 回填只是為了讓下一次比對更可靠。所以整段包 try/catch，只記 log。
     *
     * 只補「還沒有 ID」的那一筆：兩個帳號填到同一個 username（人為填錯）時，
     * 這樣至多只會補到其中一個，不會兩筆都被蓋成同一個人。
     *
     * @param int    $userId         已經比對出來的帳號 id
     * @param string $username       已正規化，記 log 用
     * @param int    $telegramUserId
     * @return void
     */
    private function backfillUserId($userId, $username, $telegramUserId)
    {
        try {
            $user = $this->userRepository->findForTelegramBackfill($userId);

            if (blank($user) || filled($user->telegram_user_id)) {
                return;
            }

            $this->userRepository->update($user, ['telegram_user_id' => $telegramUserId]);

            // 補完要讓名單重讀，否則快取裡的 ids 還是舊的，下一則訊息又會再補一次
            $this->forgetCache();

            Log::info('已回填後台帳號的 Telegram 使用者 ID', [
                'user_id'          => $userId,
                'username'         => $username,
                'telegram_user_id' => $telegramUserId,
            ]);
        } catch (\Exception $e) {
            Log::warning('回填 Telegram 使用者 ID 失敗', [
                'user_id'          => $userId,
                'telegram_user_id' => $telegramUserId,
                'error'            => $e->getMessage(),
            ]);
        }
    }
}
