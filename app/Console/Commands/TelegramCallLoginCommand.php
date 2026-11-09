<?php

namespace App\Console\Commands;

use App\Services\TelegramCallService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * 產生逾時升級撥號用的 Telegram session（人工跑一次）
 *
 * 逾時升級要用 userbot 撥電話，而 userbot 必須先登入一次拿到 session 檔。
 * 那一步要收驗證碼，所以**只能人工跑**，不能放進排程。
 *
 * ⚠ **為什麼不是 seeder**：seeder 是灌 DB 資料用的，而這件事沒有任何
 * 資料要寫進資料庫 —— 它要的是**互動輸入**（手機號碼、驗證碼）。
 * seeder 跑在非互動環境時會直接卡住或失敗。
 *
 *     php artisan telegram:call-login
 *
 * 詳見 .claude/knowledge/features/ticket-overdue-escalation.md
 */
class TelegramCallLoginCommand extends Command
{
    protected $signature = 'telegram:call-login';

    protected $description = '登入 Telegram userbot，產生逾時升級撥號用的 session（人工跑一次）';

    /**
     * @param TelegramCallService $callService
     * @return int
     */
    public function handle(TelegramCallService $callService)
    {
        $apiId = (string) config('telegram_call.api_id');
        $apiHash = (string) config('telegram_call.api_hash');
        $session = (string) config('telegram_call.session');

        $this->line('');
        $this->info('=== Telegram userbot 登入（逾時升級撥號用）===');
        $this->line('');

        if (blank($apiId) || blank($apiHash)) {
            return $this->missingCredentials();
        }

        if (file_exists("{$session}.session")) {
            $this->warn("session 已經存在：{$session}.session");

            if (!$this->confirm('要重新登入並覆蓋嗎？', false)) {
                $this->line('沒有變更。');

                return 0;
            }
        }

        $this->showInstructions($session);

        if (!$this->runLogin($apiId, $apiHash, $session)) {
            return 1;
        }

        $this->line('');

        // 用撥號服務自己的判斷確認，而不是只看檔案在不在 —— 兩邊要同一個標準
        if ($callService->isConfigured()) {
            $this->info('✓ 撥號設定已完成，逾時升級可以開始撥電話了。');
        } else {
            $this->error('session 建好了，但撥號設定仍不完整 —— 請確認 tools/tg_call.py 存在。');
        }

        $this->showNextSteps();

        return 0;
    }

    /**
     * 憑證沒設時，把「要填什麼」直接印出來
     *
     * ⚠ 這段比錯誤訊息重要：使用者卡在這裡時，需要的是**可以照抄的東西**，
     * 不是「請設定環境變數」。
     *
     * @return int
     */
    private function missingCredentials()
    {
        $this->error('還沒設定 Telegram 應用程式憑證。');
        $this->line('');
        $this->line('請到 https://my.telegram.org → API development tools 申請，');
        $this->line('然後把這兩行加進專案根目錄的 .env：');
        $this->line('');
        $this->line('    TG_CALL_API_ID=你的api_id');
        $this->line('    TG_CALL_API_HASH=你的api_hash');
        $this->line('');
        $this->warn('⚠ 申請那一頁擋的是「來源 IP」不是帳號 ——');
        $this->warn('  看到 Too many attempts 時，換手機 4G 熱點通常就過了。');
        $this->line('');
        $this->line('加完之後跑一次 php artisan config:clear，再執行這個指令。');

        return 1;
    }

    /**
     * 接下來會被問什麼
     *
     * @param string $session
     * @return void
     */
    private function showInstructions($session)
    {
        $this->line("session 會建在：{$session}.session");
        $this->line('');
        $this->line('接下來會問你三件事：');
        $this->line('');
        $this->line('  1. <comment>手機號碼</comment> —— 要當 userbot 的<options=bold>那個帳號</>的號碼，');
        $this->line('     國際格式例如 +8869xxxxxxxx');
        $this->line('     （如果你用別的帳號申請 api_id，這裡還是填 userbot 那個）');
        $this->line('');
        $this->line('  2. <comment>驗證碼</comment> —— 發到那個帳號的 <options=bold>Telegram App 裡</>，');
        $this->line('     不是簡訊。那支手機要先登入著那個帳號');
        $this->line('');
        $this->line('  3. <comment>兩步驗證密碼</comment> —— 只有開了才會問');
        $this->line('');
    }

    /**
     * 跑 Python 登入腳本
     *
     * ⚠ **一定要 `setTty(true)`**：Telethon 的提示要讀 stdin，沒有 TTY 的話
     * 使用者看得到問題卻打不進去，而且會卡住直到逾時。
     *
     * ⚠ `setTimeout(null)`：這是人在輸入，沒有合理的逾時可言。
     *
     * @param string $apiId
     * @param string $apiHash
     * @param string $session
     * @return bool
     */
    private function runLogin($apiId, $apiHash, $session)
    {
        $script = base_path('tools/tg_login.py');

        if (!file_exists($script)) {
            $this->error("找不到登入腳本：{$script}");

            return false;
        }

        $process = new Process(
            [(string) config('telegram_call.python'), $script],
            base_path(),
            [
                'TG_API_ID'   => $apiId,
                'TG_API_HASH' => $apiHash,
                'TG_SESSION'  => $session,
            ]
        );

        $process->setTimeout(null);

        try {
            $process->setTty(true);
            $process->run();
        } catch (\Exception $e) {
            $this->error('登入程序無法執行：' . $e->getMessage());
            $this->line('');
            $this->line('如果錯誤訊息提到 TTY，代表這個環境沒有終端機（例如從排程或 CI 跑）。');
            $this->line('請在互動式的 shell 裡執行，例如：');
            $this->line('');
            $this->line('    docker-compose exec workspace bash');
            $this->line('    cd /var/www/tripartite_gold_cs && php artisan telegram:call-login');

            return false;
        }

        if (!$process->isSuccessful()) {
            $this->error('登入失敗。');

            return false;
        }

        return true;
    }

    /**
     * @return void
     */
    private function showNextSteps()
    {
        $this->line('');
        $this->line('<options=bold>還要做這幾件事，逾時升級才會真的撥電話：</>');
        $this->line('');
        $this->line('  • 用這個 userbot 帳號把<options=bold>會被排班的同仁加成聯絡人</>');
        $this->line('    （沒設 @username 的人要靠這個才撥得到）');
        $this->line('  • 請同仁確認 Telegram 的「誰可以打電話給我」沒設成僅聯絡人，');
        $this->line('    或確認這個帳號已經在他們的聯絡人裡');
        $this->line('  • 到「通知設定 → 求助單提醒」把收件人勾好，<options=bold>再打開開關</>');
        $this->line('');
        $this->warn('⚠ session 檔等同那個帳號的密碼，不要外流、不要進版控。');
        $this->line('');
    }
}
