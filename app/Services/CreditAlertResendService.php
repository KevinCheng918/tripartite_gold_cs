<?php

namespace App\Services;

use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 匯率改動後，用新匯率把餘點告警重發一次
 *
 * 問句由 `DailyRateService::askResendCreditAlert()` 在匯率被改掉時送出，
 * 這一支只負責按鈕按下去之後的事。
 *
 * ⚠ **拆成獨立一支是為了避開循環相依**：
 * 重發要用 `StationCreditAlertService`，而那一支的建構子又吃
 * `DailyRateService`（它要今天的匯率）。把重發塞回 `DailyRateService`
 * 就會 A→B→A，DI 直接解不開。
 *
 * ⚠ 這個動作**會發訊息給客戶**（每個低於門檻的站台一輪），所以：
 * 要權限、而且按的人要再驗一次 —— 問句會留在群組裡，按的人不一定是
 * 改匯率的那個人。
 */
class CreditAlertResendService
{
    /** @var string 跟「用指令發餘點告警」同一個權限 —— 做的是同一件事 */
    const PERMISSION = CreditAlertCommandService::PERMISSION;

    private $alertService;
    private $userRepository;
    private $supportGroup;

    public function __construct(
        StationCreditAlertService $alertService,
        UserRepository $userRepository,
        SupportGroupService $supportGroup
    ) {
        // 重發走排程那支同一個入口，不另外寫一份判斷門檻與組訊息的邏輯
        $this->alertService = $alertService;
        $this->userRepository = $userRepository;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 按鈕按下去
     *
     * @param array    $parts     [action]
     * @param int|null $messageId 問句那則訊息
     * @param array    $from      按按鈕的人
     * @return void
     */
    public function handleCallback(array $parts, $messageId, array $from = [])
    {
        $config = (array) config('constants.CREDIT_ALERT_RESEND');
        $action = (string) Arr::get($parts, 0);

        // 「不用」不需要權限 —— 把一則問句收掉，誰都可以，而且那是安全的方向
        if ($action === (string) Arr::get($config, 'ACTION_CANCEL')) {
            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'CANCELLED_TEXT'));

            return;
        }

        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            /*
             * ⚠ 另發一則，不要 `editMessage` —— 編輯的話，沒權限的人按一下
             * 就把問句連同按鈕一起蓋掉，有權限的人就沒得按了。
             */
            $this->supportGroup->send($denied, null, null, DailyRateService::NOTICE_TYPE);

            return;
        }

        $this->resend($messageId, $config);
    }

    /**
     * 真的重發
     *
     * ⚠ **先把問句改成「正在重發」**：逐站打主系統 API ＋ 發兩則訊息，
     * 站台多的時候要好幾秒。不講的話按的人會以為沒反應又按一次 ——
     * 而這一按是發訊息給客戶。
     *
     * @param int|null $messageId
     * @param array    $config
     * @return void
     */
    private function resend($messageId, array $config)
    {
        $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'WORKING_TEXT'));

        try {
            $results = (array) $this->alertService->run([]);
        } catch (\Exception $e) {
            Log::error('重發餘點告警失敗', ['error' => $e->getMessage()]);

            $this->supportGroup->editMessage($messageId, (string) Arr::get($config, 'FAILED_TEXT'));

            return;
        }

        $alerted = 0;

        foreach ($results as $result) {
            /*
             * ⚠ `alerted` 是布林，用 `(bool)` 不要用 `filled()` ——
             * `filled(false)` 回的是 true（見 bugfix/2026-10-12-filled-on-boolean）。
             */
            if ((bool) Arr::get($result, 'alerted')) {
                $alerted++;
            }
        }

        $text = $alerted > 0
            ? strtr((string) Arr::get($config, 'DONE_TEXT'), ['{alerted}' => $alerted])
            : (string) Arr::get($config, 'NONE_TEXT');

        $this->supportGroup->editMessage($messageId, $text);

        Log::info('餘點告警已用新匯率重發', [
            'checked' => count($results),
            'alerted' => $alerted,
        ]);
    }

    /**
     * 這個人能不能按 —— 不能的話回要講的那句話
     *
     * @param array $from
     * @param array $config
     * @return string 空字串 ＝ 可以
     */
    private function permissionError(array $from, array $config)
    {
        $telegramId = Arr::get($from, 'id');

        // 短路：連 Telegram id 都沒有就不必查資料庫
        if (blank($telegramId)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        $user = $this->userRepository->findByTelegramUserId((int) $telegramId);

        if (blank($user)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        if (!$user->hasPermission(self::PERMISSION)) {
            Log::info('重發餘點告警被權限擋下', ['user_id' => $user->id]);

            return (string) Arr::get($config, 'NO_PERMISSION_TEXT');
        }

        return '';
    }
}
