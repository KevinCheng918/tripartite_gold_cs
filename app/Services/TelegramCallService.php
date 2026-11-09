<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * 用 Telegram userbot 讓某個人的手機響
 *
 * 包 `tools/tg_call.py` 那支 Python sidecar —— PHP 做不到 MTProto 語音，
 * 而 Bot API 根本沒有通話能力。呼叫外部程序的做法比照這個專案既有的兩處
 * （`ClaudeCodeMatcher` 的 Claude CLI、`ScreenshotService` 的 headless Chrome）。
 *
 * ⚠ **這支只負責「讓它響」**，不負責判斷人有沒有被叫醒 ——
 * 那通電話接起來就會斷線（沒有音訊層），所以通話狀態不可信。
 * 「事情處理了沒」一律看求助單自己的 status。
 *
 * 詳見 .claude/knowledge/features/ticket-overdue-escalation.md
 */
class TelegramCallService
{
    /**
     * 設定是否齊全（憑證 ＋ session ＋ 腳本）
     *
     * ⚠ 短路用：排程每分鐘跑，設定沒齊就不該去開一個必然失敗的 process。
     *
     * @return bool
     */
    public function isConfigured()
    {
        $session = (string) config('telegram_call.session');

        return filled(config('telegram_call.api_id'))
            && filled(config('telegram_call.api_hash'))
            && file_exists((string) config('telegram_call.script'))
            && file_exists("{$session}.session");
    }

    /**
     * 撥一通
     *
     * @param string $target  `@username`、`+886…`、或 Telegram user id
     * @param int    $seconds 響幾秒後掛斷
     * @return array{ok:bool, answered:bool, error:string|null, retryable:bool, detail:string|null}
     */
    public function ring($target, $seconds)
    {
        if (!$this->isConfigured()) {
            return $this->failure('NOT_CONFIGURED', false);
        }

        if (blank($target)) {
            // 沒有 username／手機就撥不出去 —— 跟「tag 不到他」同一個根源
            return $this->failure('NO_TARGET', false);
        }

        $process = new Process(
            [
                (string) config('telegram_call.python'),
                (string) config('telegram_call.script'),
                (string) $target,
                '--seconds',
                (string) (int) $seconds,
            ],
            base_path(),
            $this->buildEnv()
        );

        $process->setTimeout((int) config('telegram_call.timeout'));

        try {
            $process->run();
        } catch (\Exception $e) {
            Log::error('撥號 sidecar 執行失敗', ['target' => $target, 'error' => $e->getMessage()]);

            return $this->failure('PROCESS_FAILED', true, $e->getMessage());
        }

        return $this->parse($process->getOutput(), $process->getErrorOutput(), $target);
    }

    /**
     * sidecar 的環境變數
     *
     * ⚠ 憑證走 env 不走命令列參數 —— 參數會出現在 `ps` 的輸出裡。
     *
     * @return array<string, string>
     */
    private function buildEnv()
    {
        return [
            'TG_API_ID'   => (string) config('telegram_call.api_id'),
            'TG_API_HASH' => (string) config('telegram_call.api_hash'),
            'TG_SESSION'  => (string) config('telegram_call.session'),
        ];
    }

    /**
     * 解析 sidecar 的輸出
     *
     * ⚠ sidecar 保證**一行 JSON**，而且失敗路徑也會印（不是只有成功才印）。
     * 解析不出來代表它整個掛了（python 不存在、腳本語法壞掉），
     * 那種情況 stderr 才有線索，所以要一起記。
     *
     * @param string $stdout
     * @param string $stderr
     * @param string $target
     * @return array
     */
    private function parse($stdout, $stderr, $target)
    {
        $decoded = json_decode(trim((string) $stdout), true);

        if (!is_array($decoded)) {
            Log::error('撥號 sidecar 的輸出不是 JSON', [
                'target' => $target,
                'stdout' => mb_substr((string) $stdout, 0, 500),
                'stderr' => mb_substr((string) $stderr, 0, 500),
            ]);

            return $this->failure('BAD_OUTPUT', true, $stderr);
        }

        if (!Arr::get($decoded, 'ok')) {
            $error = (string) Arr::get($decoded, 'error', 'UNKNOWN');

            Log::warning('撥號失敗', [
                'target'    => $target,
                'error'     => $error,
                'retryable' => (bool) Arr::get($decoded, 'retryable'),
                'detail'    => Arr::get($decoded, 'detail'),
                /*
                 * ⚠ **直譯器一定要記。** `TELETHON_NOT_INSTALLED` 幾乎都不是
                 * 真的沒裝，而是**跑到了另一支 Python** —— telethon 裝在 venv，
                 * 而排程的 PATH 解析到系統那支。不記下來的話，log 會指著
                 * 「沒安裝」而實際上裝好好的（2026-11-09 實際踩過）。
                 */
                'python' => (string) config('telegram_call.python'),
            ]);

            return $this->failure($error, (bool) Arr::get($decoded, 'retryable'), Arr::get($decoded, 'detail'));
        }

        Log::info('已撥號', [
            'target'   => $target,
            'call_id'  => Arr::get($decoded, 'call_id'),
            // ⚠ 僅供記錄：接起來會立刻斷線，分不出「沒接」與「接了就斷」
            'answered' => (bool) Arr::get($decoded, 'answered'),
        ]);

        return [
            'ok'        => true,
            'answered'  => (bool) Arr::get($decoded, 'answered'),
            'error'     => null,
            'retryable' => false,
            'detail'    => null,
        ];
    }

    /**
     * @param string      $error
     * @param bool        $retryable
     * @param string|null $detail
     * @return array
     */
    private function failure($error, $retryable, $detail = null)
    {
        return [
            'ok'        => false,
            'answered'  => false,
            'error'     => $error,
            'retryable' => $retryable,
            'detail'    => filled($detail) ? mb_substr((string) $detail, 0, 200) : null,
        ];
    }
}
