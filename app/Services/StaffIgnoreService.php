<?php

namespace App\Services;

use App\Presenters\TelegramUsernamePresenter;
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
 * 與 TelegramGroupMemberService 的忽略名單是**兩份並存**的機制：
 *
 * | | 這一支 | TelegramGroupMemberService |
 * |---|---|---|
 * | 範圍 | **全域**，所有對話 | 每個對話各自一份 |
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

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * 這個發話者是不是我方後台帳號
     *
     * ID 先比：那是終生不變的。username 是備援 —— 同事還沒被回填過 ID 時
     * 只能靠它，但它隨時可以被改掉。
     *
     * @param array $from Telegram 的 from 物件
     * @return bool
     */
    public function isStaff(array $from)
    {
        $keys = $this->keys();

        // 一個同事都沒填 Telegram 身分時直接結束，不要往下比
        if (blank($keys['ids']) && blank($keys['usernames'])) {
            return false;
        }

        $telegramUserId = (int) Arr::get($from, 'id', 0);

        if ($telegramUserId > 0 && in_array($telegramUserId, $keys['ids'], true)) {
            return true;
        }

        $username = TelegramUsernamePresenter::normalize(Arr::get($from, 'username'));

        if (blank($username) || !in_array($username, $keys['usernames'], true)) {
            return false;
        }

        /*
         * username 命中、但這個人的 ID 還沒記過 —— 補上去。
         *
         * 這是這支服務唯一的寫入動作，而且只會發生一次（補完之後就走上面
         * 那條 ID 比對）。補了之後他改 username 也還認得。
         */
        if ($telegramUserId > 0) {
            $this->backfillUserId($username, $telegramUserId);
        }

        return true;
    }

    /**
     * 名單裡的 username（訊息灰標籤用）
     *
     * 名冊（`telegram_group_member`）存的是 username，拿這批去比就能知道
     * 哪些發言者是我方同事，進而標出「不自動回覆」。
     *
     * @return array
     */
    public function usernames()
    {
        return $this->keys()['usernames'];
    }

    /**
     * 面板資料：名單本身，以及認不出身分的帳號
     *
     * 不走快取 —— 面板開啟的頻率遠低於收訊，而這裡要顯示暱稱，
     * 跟比對用的那兩組鍵不是同一種資料。
     *
     * @return array{staff: \Illuminate\Database\Eloquent\Collection,
     *     missing: \Illuminate\Database\Eloquent\Collection}
     */
    public function getPanel()
    {
        return [
            'staff'   => $this->userRepository->getTelegramIdentitiesForAutoReply(),
            'missing' => $this->userRepository->getMissingTelegramIdentity(),
        ];
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
     * 兩組比對鍵（快取）
     *
     * 名單極少變動，卻**每則 inbound 訊息都要讀** —— 不快取的話每一則
     * 客人訊息都會多一次 `user` 表查詢。
     *
     * 快取存的是純陣列而不是 Model 集合：序列化 Model 會把整個物件
     * 寫進快取，而這裡只需要兩組值。
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
                $users = $repository->getTelegramIdentitiesForAutoReply();

                return [
                    'ids' => $users->pluck('telegram_user_id')
                        ->filter()
                        ->map(function ($id) {
                            return (int) $id;
                        })
                        ->unique()
                        ->values()
                        ->all(),
                    'usernames' => TelegramUsernamePresenter::normalizeAll(
                        $users->pluck('telegram_username')->all()
                    ),
                ];
            }
        );
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
     * @param string $username 已正規化
     * @param int    $telegramUserId
     * @return void
     */
    private function backfillUserId($username, $telegramUserId)
    {
        try {
            $user = $this->userRepository->findByTelegramUsername($username);

            if (blank($user) || filled($user->telegram_user_id)) {
                return;
            }

            $this->userRepository->update($user, ['telegram_user_id' => $telegramUserId]);

            // 補完要讓名單重讀，否則快取裡的 ids 還是舊的，下一則訊息又會再補一次
            $this->forgetCache();

            Log::info('已回填後台帳號的 Telegram 使用者 ID', [
                'user_id'          => $user->id,
                'username'         => $username,
                'telegram_user_id' => $telegramUserId,
            ]);
        } catch (\Exception $e) {
            Log::warning('回填 Telegram 使用者 ID 失敗', [
                'username'         => $username,
                'telegram_user_id' => $telegramUserId,
                'error'            => $e->getMessage(),
            ]);
        }
    }
}
