<?php

namespace App\Console\Commands;

use App\Services\TelegramCallService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
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

        /*
         * ⚠ **先驗直譯器，再往下走。**
         *
         * 2026-11-09 實際踩到：telethon 裝在 venv，但 `TG_CALL_PYTHON` 沒生效，
         * 於是跑到系統的 python3 ——而腳本只會喊「請先安裝 telethon」，
         * 讓人以為是沒裝。**錯誤訊息怪錯了對象**，查了很久。
         *
         * 所以這裡先把「用的是哪一個 python」印出來，再確認那一個有 telethon。
         */
        if (!$this->checkPython()) {
            return 1;
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
     * 確認「要用的那個 python」存在而且裝了 telethon
     *
     * ⚠ 把直譯器路徑印出來是重點：telethon 裝在 venv、而程式跑到系統 python
     * 的時候，光看「沒有 telethon」完全看不出問題在哪。
     *
     * @return bool
     */
    private function checkPython()
    {
        $python = (string) config('telegram_call.python');

        $this->line("使用的 Python：<comment>{$python}</comment>");

        $probe = new Process([$python, '-c', 'import telethon, sys; print(telethon.__version__); print(sys.version.split()[0])']);
        $probe->setTimeout(30);

        try {
            $probe->run();
        } catch (\Exception $e) {
            $this->error("無法執行這個 Python：{$e->getMessage()}");
            $this->showPythonHint($python);

            return false;
        }

        if (!$probe->isSuccessful()) {
            $this->line('');
            $this->error("這個 Python 沒有 telethon（或根本不存在）。");
            $this->line('');
            $this->line('它回報的錯誤：');
            $this->line('    ' . trim(mb_substr($probe->getErrorOutput(), 0, 300)));
            $this->showPythonHint($python);

            return false;
        }

        $lines = preg_split('/\R/', trim($probe->getOutput()));
        $this->line('Telethon 版本：<comment>' . (string) Arr::get($lines, 0) . '</comment>'
            . '（Python ' . (string) Arr::get($lines, 1) . '）');

        $this->warnIfNotAbsolute($python);
        $this->line('');

        return true;
    }

    /**
     * 路徑不是絕對路徑時警告 —— 就算現在成功也要講
     *
     * ⚠⚠ **「我從 shell 跑得起來」不等於「排程跑得起來」。**
     *
     * 2026-11-09 實際遇到：`TG_CALL_PYTHON` 沒設，但從互動式 shell 執行時
     * 裸的 `python3` 解析到了 venv 裡那一支（因為那個使用者的 PATH 有它），
     * 於是登入成功、看起來一切正常。
     *
     * 但真正撥號的是 **cron 與 php-fpm**，它們的 PATH 跟登入的 shell 不一樣
     * —— 很可能解析到沒有 telethon 的系統 Python，然後**在客人已經等了兩小時
     * 的那一刻靜悄悄失敗**。
     *
     * 所以這個警告不是潔癖：它擋的是一個只在最需要功能的時候才會現形的坑。
     *
     * @param string $python
     * @return void
     */
    private function warnIfNotAbsolute($python)
    {
        if (strpos($python, '/') === 0) {
            return;
        }

        $this->line('');
        $this->warn("⚠ 現在用的是「{$python}」，**不是絕對路徑**。");
        $this->line('');
        $this->line('它在你這個 shell 裡找得到 telethon，但**排程與 php-fpm 的 PATH 不一樣** ——');
        $this->line('很可能解析到別的 Python，然後在真的要撥號的時候才失敗。');
        $this->line('');
        $this->line('請在 .env 指定絕對路徑（先確認是哪一支）：');
        $this->line('');
        $this->line('    which -a python3        # 或 readlink -f $(which python3)');
        $this->line('    TG_CALL_PYTHON=/path/to/venv/bin/python');
        $this->line('    php artisan config:clear && php artisan config:cache');
    }

    /**
     * 直譯器不對時要怎麼修
     *
     * @param string $python
     * @return void
     */
    private function showPythonHint($python)
    {
        $this->line('');

        if ($python === 'python3') {
            $this->warn('⚠ 現在用的是**預設值** `python3`（系統 Python），而 telethon 多半裝在 venv。');
        }

        $this->line('請在 .env 指定那個 venv 的直譯器**絕對路徑**：');
        $this->line('');
        $this->line('    TG_CALL_PYTHON=/path/to/venv/bin/python');
        $this->line('');
        $this->line('然後重建設定快取：');
        $this->line('');
        $this->line('    php artisan config:clear && php artisan config:cache');
        $this->line('');
        $this->warn('⚠ 順序很重要 —— 先加 .env 那一行，再 config:cache。');
        $this->warn('  反過來的話快取裡存的還是舊值，看起來就像沒生效。');
        $this->line('');
        $this->line('⚠ 一定要絕對路徑：php-fpm 與排程的 PATH 跟你的 shell 不一樣。');
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
