<?php

namespace App\Services;

use App\Repositories\UserRepository;
use App\Services\AutoReply\StrangerReplyWriter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 用驗證碼把後台帳號跟 Telegram 身份綁起來
 *
 * ⚠ **為什麼不沿用「填了 Telegram 帳號就自動綁」**（2026-10-09 移除）：
 * 那個做法只看一個條件 —— 私訊的人 username 等於後台欄位的值。於是
 * 「後台打錯一個字」「同仁改過 handle 被別人註冊走」「離職沒清資料」
 * 這三種不需要攻擊、只要剛好就成立的情況，都會讓陌生人收到班表與任務卡。
 *
 * ⚠ **驗證碼存 cache 不存 DB**：10 分鐘就過期的東西不該長住資料表，
 * 也不必為它跑一支 migration。代價是清快取時碼會失效 —— 重新產生即可。
 */
class TelegramBindService
{
    /** @var string 綁定結果：成功 */
    const RESULT_BOUND = 'bound';

    /** @var string 綁定結果：碼過期或用過了 */
    const RESULT_EXPIRED = 'expired';

    /** @var string 綁定結果：這個 Telegram 身份已經綁在別人身上 */
    const RESULT_TAKEN = 'taken';

    private $userRepository;
    private $strangerWriter;

    public function __construct(UserRepository $userRepository, StrangerReplyWriter $strangerWriter)
    {
        $this->userRepository = $userRepository;
        $this->strangerWriter = $strangerWriter;
    }

    /**
     * 產生一組綁定碼
     *
     * ⚠ **同一個人重複按只會拿到同一組**（效期內）—— 每按一次就換一組的話，
     * 他把碼念到一半又重新整理，手上那組就失效了。
     *
     * @param int $userId
     * @return array code / expires_in（分鐘）
     */
    public function issueCode($userId)
    {
        $config = (array) config('constants.TELEGRAM.BIND');
        $minutes = (int) Arr::get($config, 'CODE_MINUTES');
        $userKey = Arr::get($config, 'CACHE_USER_PREFIX') . (int) $userId;

        $existing = Cache::get($userKey);

        if (filled($existing)) {
            return ['code' => $existing, 'expires_in' => $minutes];
        }

        $code = $this->randomCode($config);

        /*
         * 兩個方向都要存：
         *   code → user_id   驗碼時要知道是誰
         *   user_id → code   重新整理設定頁時要看得到同一組
         */
        Cache::put(Arr::get($config, 'CACHE_PREFIX') . $code, (int) $userId, now()->addMinutes($minutes));
        Cache::put($userKey, $code, now()->addMinutes($minutes));

        return ['code' => $code, 'expires_in' => $minutes];
    }

    /**
     * 拿一串文字當綁定碼試試看
     *
     * @param string      $text           同仁私訊的內容
     * @param int|string  $telegramUserId
     * @param string|null $username       順便回填，之後群組 @ 他要用
     * @return array|null result / user / taken_by；不是綁定碼就回 null
     */
    public function redeem($text, $telegramUserId, $username = null)
    {
        $config = (array) config('constants.TELEGRAM.BIND');
        $code = strtoupper(trim((string) $text));

        // 短路：長度不對就不必去查 cache
        if (mb_strlen($code) !== (int) Arr::get($config, 'CODE_LENGTH')) {
            return null;
        }

        $cacheKey = Arr::get($config, 'CACHE_PREFIX') . $code;
        $userId = Cache::get($cacheKey);

        /*
         * ⚠ 長度對但查不到 → 當成「碼過期了」而不是陌生訊息。
         *
         * 他顯然是在試綁定（剛好打了六個字的人極少），回一句「過期了，
         * 請重新產生」比回「這裡沒有服務」有用得多。
         */
        if (blank($userId)) {
            return ['result' => self::RESULT_EXPIRED, 'user' => null, 'taken_by' => null];
        }

        $user = $this->userRepository->find((int) $userId);

        if (blank($user)) {
            $this->forgetCode($config, $code, (int) $userId);

            return ['result' => self::RESULT_EXPIRED, 'user' => null, 'taken_by' => null];
        }

        /*
         * ⚠ **這個 Telegram 身份已經綁在別人身上就拒絕**，不要靜默搬走 ——
         * 搬走的話原本那個人會默默收不到班表與任務卡，而且不會有任何跡象。
         *
         * 2026-10-09 之前完全沒有這道檢查，所以同一個人可能被綁在兩個帳號上、
         * 每天收到兩份通知。
         */
        $taken = $this->userRepository->findByTelegramUserId($telegramUserId, (int) $user->id);

        if (filled($taken)) {
            return ['result' => self::RESULT_TAKEN, 'user' => $user, 'taken_by' => $taken];
        }

        $this->userRepository->bindTelegram($user, $telegramUserId, $username);
        $this->forgetCode($config, $code, (int) $user->id);

        Log::info('Telegram 驗證碼綁定完成', [
            'user_id'          => $user->id,
            'telegram_user_id' => $telegramUserId,
        ]);

        return ['result' => self::RESULT_BOUND, 'user' => $user, 'taken_by' => null];
    }

    /**
     * 解除綁定
     *
     * ⚠ **不清 `telegram_username`**：那是在群組 `@` 他用的，
     * 跟「能不能私訊」是兩件事。
     *
     * @param \App\Models\User $user
     * @param int|null         $operatorId
     * @return void
     */
    public function unbind($user, $operatorId = null)
    {
        $this->userRepository->clearTelegramBinding($user);

        Log::info('Telegram 綁定已解除', [
            'user_id'     => $user->id,
            'operator_id' => $operatorId,
        ]);
    }

    /**
     * 陌生人私訊時該回什麼（回 null 代表這次不要回）
     *
     * ⚠ **只回第一次，之後靜默**（需求方 2026-10-09）。不加冷卻的話，
     * 陌生人連發 100 則 bot 就回 100 則 —— 可以拿來當回音牆騷擾，
     * 也會把 bot 的流量吃掉。
     *
     * ⚠ 記的是 **Telegram 的 user id** 而不是 chat id：兩者在私訊裡相同，
     * 但用 user id 語意才對（「這個人」回過了）。
     *
     * @param int|string $telegramUserId
     * @return string|null
     */
    public function strangerReply($telegramUserId)
    {
        $config = (array) config('constants.TELEGRAM.BIND');
        $key = Arr::get($config, 'STRANGER_CACHE_PREFIX') . $telegramUserId;

        if (filled(Cache::get($key))) {
            return null;
        }

        Cache::put($key, true, now()->addHours((int) Arr::get($config, 'STRANGER_HOURS')));

        return $this->strangerWriter->write();
    }

    /**
     * 產生一組不會看錯的碼
     *
     * @param array $config
     * @return string
     */
    private function randomCode(array $config)
    {
        $alphabet = (string) Arr::get($config, 'CODE_ALPHABET');
        $length = (int) Arr::get($config, 'CODE_LENGTH');
        $max = mb_strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            // random_int 而不是 rand：這串是驗證用的，可預測就沒意義了
            $code .= mb_substr($alphabet, random_int(0, $max), 1);
        }

        return $code;
    }

    /**
     * 碼用掉或失效時，兩個方向的 cache 都要清
     *
     * @param array  $config
     * @param string $code
     * @param int    $userId
     * @return void
     */
    private function forgetCode(array $config, $code, $userId)
    {
        Cache::forget(Arr::get($config, 'CACHE_PREFIX') . $code);
        Cache::forget(Arr::get($config, 'CACHE_USER_PREFIX') . $userId);
    }
}
