<?php

namespace App\Services;

use App\Repositories\UserRepository;
use App\Services\AutoReply\GmailCredentialCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 用 Telegram 指令驗 Gmail 爬蟲憑證並產出 spider_setting
 *
 * ```
 * @機器人 驗一下兆豐的 Gmail 憑證
 * 帳號 1234-abc.apps.googleusercontent.com
 * 密碼 GOCSPX-xxxx
 * refresh token 1//0eXXXX
 *   ↓
 * ① 三組憑證都有填　✅
 * ② 換 access_token　✅（有效期 3599 秒）
 * ③ 連得上 Gmail　✅ 信箱：lv.payment@gmail.com（共 1,248 封）
 *    兆豐銀行入帳通知：最近 2 天有 3 封
 *
 * 🧩 spider_setting
 * { "index": "cashier", "cashier_url": "", … }
 * ```
 *
 * ⚠ **驗不過就不給 spider_setting** —— 跟主專案後台同一個規則
 * （`PaymentController::ajaxVerifyGmail()` 也是 `success` 才組設定），
 * 免得把錯的憑證套進去。
 *
 * ⚠ 驗證本身在 `GmailCredentialService`，這一支只管 Telegram 這一側
 * （解析、驗權限、把結果排版）。分開的理由：驗證那套錯誤對照表是照主專案抄的，
 * 跟「群組裡怎麼回話」無關，混在一起日後兩邊都不好對。
 */
class GmailCredentialCommandService
{
    /** @var string 做這件事需要的權限 */
    const PERMISSION = 'telegram_chat.gmail_credential';

    private $parser;
    private $gmailCredentialService;
    private $userRepository;
    private $supportGroup;

    public function __construct(
        GmailCredentialCommandParser $parser,
        GmailCredentialService $gmailCredentialService,
        UserRepository $userRepository,
        SupportGroupService $supportGroup
    ) {
        $this->parser = $parser;
        // 三步驗證與 spider_setting 的基底都在它身上
        $this->gmailCredentialService = $gmailCredentialService;
        $this->userRepository = $userRepository;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 處理一句指令
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from（對到系統帳號驗權限用）
     * @param int|null $threadId
     * @return bool 有處理就回 true；false 代表「這不是在講 Gmail 憑證」
     */
    public function handle($text, array $from, $threadId)
    {
        $config = (array) config('constants.GMAIL_CREDENTIAL_COMMAND');
        $parsed = $this->parser->parse($text);

        if (blank($parsed)) {
            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        // 不是在講這件事 —— 讓分派器換下一個
        if ((string) Arr::get($parsed, 'intent') !== GmailCredentialCommandParser::INTENT_GMAIL) {
            return false;
        }

        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            $this->reply($denied, $threadId);

            return true;
        }

        $this->replyVerification($parsed, $threadId, $config);

        return true;
    }

    /**
     * 驗證並回報
     *
     * @param array    $parsed
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replyVerification(array $parsed, $threadId, array $config)
    {
        $credentials = [
            'client_id'     => (string) Arr::get($parsed, 'client_id'),
            'client_secret' => (string) Arr::get($parsed, 'client_secret'),
            'refresh_token' => (string) Arr::get($parsed, 'refresh_token'),
        ];

        $bankCode = (string) Arr::get($parsed, 'bank');

        /*
         * ⚠ 這裡會打外部 API（Google），所以整段包 try/catch ——
         * Guzzle 的連線錯誤在 Service 裡只有「請求階段」有擋，
         * 建 client 或非預期的例外漏上來會讓使用者 tag 了完全沒反應。
         */
        try {
            $result = $this->gmailCredentialService->verify($credentials, filled($bankCode) ? $bankCode : null);
        } catch (\Exception $e) {
            Log::warning('Gmail 憑證驗證整段失敗', ['error' => $e->getMessage()]);

            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return;
        }

        $success = (bool) Arr::get($result, 'success');

        $text = (string) Arr::get($config, 'TITLE')
            . $this->renderSteps((array) Arr::get($result, 'steps', []), $config);

        $text .= $success
            ? $this->renderSetting($credentials, $config)
            : (string) Arr::get($config, 'FAILED_HINT');

        $this->reply($text, $threadId);

        /*
         * ⚠ **不記憑證內容**，只記過了沒、哪一步卡住。
         * 這是金流信箱的憑證，寫進 log 等於多一個外洩面。
         */
        Log::info('小幫手驗 Gmail 憑證', [
            'success'   => $success,
            'bank_code' => $bankCode,
            'failed_at' => $this->failedStepKey((array) Arr::get($result, 'steps', [])),
        ]);
    }

    /**
     * 三個步驟的那幾行
     *
     * @param array $steps
     * @param array $config
     * @return string
     */
    private function renderSteps(array $steps, array $config)
    {
        $text = '';

        foreach ($steps as $step) {
            $key = (string) Arr::get($step, 'key');
            $status = (string) Arr::get($step, 'status');

            $text .= strtr((string) Arr::get($config, 'STEP_LINE'), [
                '{icon}'  => (string) Arr::get($config, "STATUS_ICON.{$status}"),
                '{label}' => (string) Arr::get($config, "STEP_LABEL.{$key}"),
            ]);

            foreach ($this->detailLines($step, $config) as $line) {
                $text .= strtr((string) Arr::get($config, 'DETAIL_LINE'), ['{detail}' => $line]);
            }
        }

        return $text;
    }

    /**
     * 某一個步驟底下要補講的話
     *
     * ⚠ 回陣列而不是一個字串：第三步成功時會有兩行（信箱 ＋ 銀行入帳通知）。
     *
     * @param array $step
     * @param array $config
     * @return array<string>
     */
    private function detailLines(array $step, array $config)
    {
        $key = (string) Arr::get($step, 'key');
        $status = (string) Arr::get($step, 'status');
        $detail = (array) Arr::get($step, 'detail', []);

        if ($status === GmailCredentialService::STATUS_SKIP) {
            // 「未檢查」的原因只有一種，不必再分
            return [(string) Arr::get($config, 'UNREACHED')];
        }

        if ($key === GmailCredentialService::STEP_PARAMS) {
            return $this->paramsLines($status, $detail, $config);
        }

        if ($key === GmailCredentialService::STEP_TOKEN) {
            return [$this->tokenLine($status, $detail, $config)];
        }

        return $this->mailboxLines($status, $detail, $config);
    }

    /**
     * ① 三組憑證都有填
     *
     * @param string $status
     * @param array  $detail
     * @param array  $config
     * @return array<string>
     */
    private function paramsLines($status, array $detail, array $config)
    {
        // 過了就不必多講一句「都有填」，標題已經說了
        if ($status === GmailCredentialService::STATUS_PASS) {
            return [];
        }

        return [strtr((string) Arr::get($config, 'PARAMS_MISSING'), [
            '{fields}' => implode('、', (array) Arr::get($detail, 'fields', [])),
        ])];
    }

    /**
     * ② 換 access_token
     *
     * @param string $status
     * @param array  $detail
     * @param array  $config
     * @return string
     */
    private function tokenLine($status, array $detail, array $config)
    {
        if ($status === GmailCredentialService::STATUS_PASS) {
            return strtr((string) Arr::get($config, 'TOKEN_OK'), [
                '{expires}' => (string) Arr::get($detail, 'expires_in', 0),
            ]);
        }

        $reason = (string) Arr::get($detail, 'reason');

        if ($reason === 'field') {
            return strtr((string) Arr::get($config, 'TOKEN_FIELD'), [
                '{field}' => (string) Arr::get($detail, 'field'),
            ]);
        }

        if ($reason === 'client') {
            return strtr((string) Arr::get($config, 'TOKEN_CLIENT'), [
                '{error}' => (string) Arr::get($detail, 'error'),
            ]);
        }

        if ($reason === 'empty') {
            return (string) Arr::get($config, 'TOKEN_EMPTY');
        }

        return strtr((string) Arr::get($config, 'TOKEN_UNKNOWN'), [
            '{error}' => (string) Arr::get($detail, 'error'),
        ]);
    }

    /**
     * ③ 連得上 Gmail（成功時還會多一行銀行入帳通知）
     *
     * @param string $status
     * @param array  $detail
     * @param array  $config
     * @return array<string>
     */
    private function mailboxLines($status, array $detail, array $config)
    {
        if ($status !== GmailCredentialService::STATUS_PASS) {
            $reason = (string) Arr::get($detail, 'reason');

            if ($reason === 'not_gmail') {
                return [(string) Arr::get($config, 'MAILBOX_NOT_GMAIL')];
            }

            return [strtr((string) Arr::get($config, 'MAILBOX_UNKNOWN'), [
                '{error}' => (string) Arr::get($detail, 'error'),
            ])];
        }

        $email = (string) Arr::get($detail, 'email');

        $lines = [filled($email)
            ? strtr((string) Arr::get($config, 'MAILBOX_OK'), [
                '{email}' => $email,
                '{total}' => number_format((int) Arr::get($detail, 'messages_total', 0)),
            ])
            : (string) Arr::get($config, 'MAILBOX_NO_EMAIL'),
        ];

        $bankLine = $this->bankLine((array) Arr::get($detail, 'bank', []), $config);

        if (filled($bankLine)) {
            $lines[] = $bankLine;
        }

        return $lines;
    }

    /**
     * 順手搜的銀行入帳通知那一行
     *
     * @param array $bank
     * @param array $config
     * @return string 空字串 ＝ 沒講銀行，不印這一行
     */
    private function bankLine(array $bank, array $config)
    {
        if (blank($bank)) {
            return '';
        }

        /*
         * ⚠ **`supported` 是布林，不能用 `filled()` 判**：`filled(false)`
         * 回的是 true（見 bugfix/2026-10-12-filled-on-boolean）。
         */
        if (!(bool) Arr::get($bank, 'supported')) {
            return strtr((string) Arr::get($config, 'BANK_UNSUPPORTED'), [
                '{code}' => (string) Arr::get($bank, 'code'),
            ]);
        }

        if ((bool) Arr::get($bank, 'failed')) {
            return strtr((string) Arr::get($config, 'BANK_FAILED'), [
                '{name}' => (string) Arr::get($bank, 'name'),
            ]);
        }

        $count = (int) Arr::get($bank, 'count', 0);

        return strtr((string) Arr::get($config, $count > 0 ? 'BANK_FOUND' : 'BANK_EMPTY'), [
            '{name}'  => (string) Arr::get($bank, 'name'),
            '{days}'  => (string) Arr::get($bank, 'days'),
            '{count}' => (string) $count,
        ]);
    }

    /**
     * 驗過之後的 spider_setting
     *
     * @param array $credentials
     * @param array $config
     * @return string
     */
    private function renderSetting(array $credentials, array $config)
    {
        $setting = $this->gmailCredentialService->buildSpiderSetting($credentials);

        /*
         * ⚠ **不自己 `e()`**：`sendToThread()` 底下的 `escapeHtml()` 已經會
         * 轉義，再轉一次會讓 note 裡的 `<請勿保存帳號>` 顯示成 `&lt;…&gt;`，
         * 複製出去就是錯的文案。
         */
        return (string) Arr::get($config, 'SETTING_TITLE')
            . strtr((string) Arr::get($config, 'SETTING_BLOCK'), [
                '{json}' => json_encode($setting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ])
            . (string) Arr::get($config, 'SETTING_HINT');
    }

    /**
     * 卡在哪一步（寫 log 用）
     *
     * @param array $steps
     * @return string 空字串 ＝ 沒有失敗
     */
    private function failedStepKey(array $steps)
    {
        foreach ($steps as $step) {
            if ((string) Arr::get($step, 'status') === GmailCredentialService::STATUS_FAIL) {
                return (string) Arr::get($step, 'key');
            }
        }

        return '';
    }

    /**
     * 這個人能不能用 —— 不能的話回要講的那句話
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
            Log::info('指令驗 Gmail 憑證被權限擋下', ['user_id' => $user->id]);

            return (string) Arr::get($config, 'NO_PERMISSION_TEXT');
        }

        return '';
    }

    /**
     * @param string   $text
     * @param int|null $threadId
     * @return void
     */
    private function reply($text, $threadId)
    {
        $this->supportGroup->sendToThread($text, $threadId);
    }
}
