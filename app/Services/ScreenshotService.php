<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * 網頁截圖
 *
 * 用 headless Chrome 把網頁截成圖，存進 public disk 後回傳相對路徑
 * （給 `asset('storage/...')` 用，TelegramBotService::sendPhoto() 認得這種網址，
 * 會自己換回本地檔案走 multipart 上傳）。
 *
 * ⚠ **沒有 Chrome 就回 null，呼叫端要能接受沒有圖。**
 * 這是刻意的：報價訊息不能因為截圖失敗就整則發不出去。
 *
 * 環境需求：x86_64 Linux 裝 google-chrome-stable 即可。
 * arm64（Apple Silicon 上的 Docker）沒有官方 Chrome，Ubuntu 22.04 的
 * chromium 套件又是 snap 過渡包、容器裡裝不起來 —— 開發機通常截不了圖，
 * 這時走的就是「沒有圖、只發文字」那條路。
 */
class ScreenshotService
{
    /**
     * 可能的 Chrome 執行檔
     *
     * 依序找第一個存在的。不同發行版與安裝方式的命名不一樣，
     * 寫死單一路徑在換機器時就會失效。
     *
     * @var array
     */
    private const BINARIES = [
        'google-chrome-stable',
        'google-chrome',
        'chromium',
        'chromium-browser',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/opt/google/chrome/chrome',
    ];

    /** @var string 截圖放這個資料夾（public disk 底下） */
    private const DIRECTORY = 'screenshot';

    /** @var int 整支 Process 的逾時秒數。網站慢或卡住時不能讓排程一直等 */
    private const TIMEOUT = 60;

    /** @var string|null 找到的執行檔，null 表示還沒找過 */
    private $binary;

    /** @var bool 找過了沒 —— 找不到時 $binary 也是 null，要靠這個分辨 */
    private $resolved = false;

    /**
     * 這台機器能不能截圖
     *
     * @return bool
     */
    public function isAvailable()
    {
        return filled($this->binaryPath());
    }

    /**
     * 目前用的是哪個執行檔（給測試按鈕顯示用）
     *
     * @return string|null
     */
    public function binaryPath()
    {
        if ($this->resolved) {
            return $this->binary;
        }

        $this->resolved = true;

        foreach (self::BINARIES as $candidate) {
            if ($this->canRun($candidate)) {
                $this->binary = $candidate;

                return $this->binary;
            }
        }

        return null;
    }

    /**
     * 截一張圖
     *
     * @param string $url
     * @param array  $options width / height / wait_ms / prefix
     * @return string|null public disk 的相對路徑；截不成回 null
     */
    public function capture($url, $options = [])
    {
        $binary = $this->binaryPath();

        if (blank($binary)) {
            Log::warning('這台機器沒有可用的 Chrome，略過截圖', ['url' => $url]);

            return null;
        }

        $relative = self::DIRECTORY . '/' . Arr::get($options, 'prefix', 'shot')
            . '-' . now()->format('Ymd-His') . '-' . substr(md5($url . microtime()), 0, 6) . '.png';

        // Storage::path() 需要資料夾先存在，Chrome 不會幫忙建
        Storage::disk('public')->makeDirectory(self::DIRECTORY);
        $target = Storage::disk('public')->path($relative);

        $process = new Process($this->buildCommand($binary, $url, $target, $options));
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            Log::error('截圖逾時', ['url' => $url, 'timeout' => self::TIMEOUT]);

            return null;
        }

        /*
         * 不看 exit code —— headless Chrome 常常截圖成功卻回非 0
         * （GPU、字型、dbus 之類的警告都會影響），看檔案有沒有生出來才準。
         */
        if (!file_exists($target) || filesize($target) === 0) {
            Log::error('截圖失敗', [
                'url'    => $url,
                'exit'   => $process->getExitCode(),
                'stderr' => mb_substr((string) $process->getErrorOutput(), 0, 500),
            ]);

            return null;
        }

        Log::info('截圖完成', ['url' => $url, 'path' => $relative, 'bytes' => filesize($target)]);

        return $relative;
    }

    /**
     * 刪掉截圖檔
     *
     * 截圖只是為了送出去，送完就沒用了 —— 不刪的話 public 會一直長大。
     *
     * @param string|null $relative
     * @return void
     */
    public function forget($relative)
    {
        if (blank($relative)) {
            return;
        }

        Storage::disk('public')->delete($relative);
    }

    /**
     * 組 headless Chrome 的參數
     *
     * @param string $binary
     * @param string $url
     * @param string $target 輸出檔案的絕對路徑
     * @param array  $options
     * @return array
     */
    private function buildCommand($binary, $url, $target, $options)
    {
        $width = (int) Arr::get($options, 'width', 1280);
        $height = (int) Arr::get($options, 'height', 900);
        $waitMs = (int) Arr::get($options, 'wait_ms', 8000);

        return [
            $binary,
            '--headless=new',
            // 容器裡沒有 sandbox 需要的權限，不加這個會直接起不來
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--hide-scrollbars',
            '--force-device-scale-factor=1',
            "--window-size={$width},{$height}",
            // 圖表是 JS 畫的，要給它時間跑完才截得到東西
            "--virtual-time-budget={$waitMs}",
            "--screenshot={$target}",
            $url,
        ];
    }

    /**
     * 這個執行檔能不能跑
     *
     * ⚠ 不能只看檔案存不存在。Ubuntu 22.04 的 `chromium-browser` 是一個
     * **shell script**，內容只是印「requires the chromium snap to be installed」
     * 然後結束 —— 檔案在、`command -v` 也找得到，但完全不能用。
     * 所以實際跑一次 `--version` 來確認。
     *
     * @param string $candidate
     * @return bool
     */
    private function canRun($candidate)
    {
        try {
            $process = new Process([$candidate, '--version']);
            $process->setTimeout(10);
            $process->run();
        } catch (\Exception $e) {
            return false;
        }

        $output = trim($process->getOutput() . ' ' . $process->getErrorOutput());

        // 真的瀏覽器會印出「Google Chrome 120.x」「Chromium 119.x」這類字樣
        return (bool) preg_match('/(chrome|chromium)\s+\d+\./i', $output);
    }
}
