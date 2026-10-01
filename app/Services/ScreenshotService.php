<?php

namespace App\Services;

use HeadlessChromium\BrowserFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * 網頁截圖
 *
 * 用 chrome-php/chrome（DevTools protocol）控制 headless Chrome，
 * 把網頁截成圖存進 public disk，回傳相對路徑 —— 給 `asset('storage/...')` 用，
 * `TelegramBotService::sendPhoto()` 認得這種網址，會自己換回本地檔案走 multipart。
 *
 * ⚠ **沒有 Chrome 就回 null，呼叫端要能接受沒有圖。**
 * 報價訊息不能因為截圖失敗就整則發不出去。
 *
 * 為什麼用套件而不是自己呼叫 CLI：
 *
 * - **可以直接截某個元素**（CSS selector）。CLI 只能截整個視窗再用座標裁，
 *   對方改版就裁到錯的地方，而且不會報錯
 * - 可以「等元素真的出現」，比固定等幾秒可靠
 *
 * 環境需求沒有變，仍然要有 Chrome 執行檔：x86_64 Linux 裝
 * google-chrome-stable 即可。arm64（Apple Silicon 上的 Docker）沒有官方
 * Chrome，開發機通常截不了圖，走的就是「沒有圖、只發文字」那條路。
 */
class ScreenshotService
{
    /**
     * 可能的 Chrome 執行檔
     *
     * 套件自己也會猜路徑，但猜不到時會丟例外。先自己找一輪，
     * 找不到就乾脆不要開始 —— 這樣才能安靜降級而不是噴錯。
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

    /** @var int 等頁面與元素的逾時（毫秒） */
    private const WAIT_TIMEOUT = 30000;

    /** @var string|null 找到的執行檔 */
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
     * 目前用的是哪個執行檔
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
     * 系統裝了幾個中文字型
     *
     * 純 CLI 的伺服器通常一個都沒有 —— 要截的網站是中文的話，
     * 沒字型截出來會是一排方框，**而且不會有任何錯誤訊息**，
     * 只會得到一張看起來很正常但全是豆腐塊的圖。
     *
     * @return int 沒有 fc-list 指令時回 -1（無法判斷，不要謊報成「有」）
     */
    public function chineseFontCount()
    {
        try {
            $process = new Process(['fc-list', ':lang=zh']);
            $process->setTimeout(10);
            $process->run();
        } catch (\Exception $e) {
            return -1;
        }

        if (!$process->isSuccessful()) {
            return -1;
        }

        return count(array_filter(explode("\n", trim($process->getOutput()))));
    }

    /**
     * 截一張圖
     *
     * 有給 selector 就只截那個元素，沒給就截整個視窗。
     *
     * @param string $url
     * @param array  $options width / height / wait_ms / selector / prefix
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

        Storage::disk('public')->makeDirectory(self::DIRECTORY);
        $target = Storage::disk('public')->path($relative);

        $browser = null;

        try {
            $browser = $this->createBrowser($binary, $options);
            $this->shoot($browser, $url, $target, $options);
        } catch (\Throwable $e) {
            /*
             * 這裡攔 Throwable 不只是 Exception —— 套件在連線中斷時可能丟
             * Error。截圖失敗絕對不能讓報價整則送不出去。
             */
            Log::error('截圖失敗', [
                'url'      => $url,
                'selector' => Arr::get($options, 'selector'),
                'error'    => $e->getMessage(),
            ]);

            return null;
        } finally {
            if (filled($browser)) {
                // 不關的話 Chrome 行程會留著，跑久了會把機器塞滿
                $browser->close();
            }
        }

        if (!file_exists($target) || filesize($target) === 0) {
            Log::error('截圖檔案是空的', ['url' => $url]);

            return null;
        }

        Log::info('截圖完成', [
            'url'      => $url,
            'path'     => $relative,
            'bytes'    => filesize($target),
            'selector' => Arr::get($options, 'selector'),
        ]);

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

    // ---------------------------------------------------------------
    //  內部
    // ---------------------------------------------------------------

    /**
     * 開一個瀏覽器
     *
     * @param string $binary
     * @param array  $options
     * @return \HeadlessChromium\Browser\ProcessAwareBrowser
     */
    private function createBrowser($binary, $options)
    {
        $browserOptions = [
            'headless'       => true,
            // 容器裡沒有 sandbox 需要的權限，不關掉會直接起不來
            'noSandbox'      => true,
            'windowSize'     => [
                (int) Arr::get($options, 'width', 1920),
                (int) Arr::get($options, 'height', 1080),
            ],
            'startupTimeout' => 30,
            'customFlags'    => [
                // /dev/shm 在容器裡預設只有 64MB，Chrome 會因此崩潰
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--hide-scrollbars',
                // headless 的 navigator.webdriver 是 true，一併關掉
                '--disable-blink-features=AutomationControlled',
            ],
        ];

        /*
         * 偽裝成一般瀏覽器。
         *
         * headless Chrome 預設的 User-Agent 帶有「HeadlessChrome」字樣，
         * 有 bot 防護的網站會直接擋掉 —— MAX 對一般的 HTTP 請求就已經回 403，
         * 不設這個很可能連頁面都載不到。
         */
        $userAgent = Arr::get($options, 'user_agent');

        if (filled($userAgent)) {
            $browserOptions['userAgent'] = $userAgent;
        }

        return (new BrowserFactory($binary))->createBrowser($browserOptions);
    }

    /**
     * 導頁、等畫面、截圖存檔
     *
     * @param \HeadlessChromium\Browser\ProcessAwareBrowser $browser
     * @param string                                        $url
     * @param string                                        $target
     * @param array                                         $options
     * @return void
     */
    private function shoot($browser, $url, $target, $options)
    {
        $page = $browser->createPage();
        $page->navigate($url)->waitForNavigation();

        $selector = Arr::get($options, 'selector');
        $node = filled($selector) ? $this->findNode($page, $selector) : null;

        if (filled($node)) {
            $page->screenshotElement($node)->saveToFile($target, self::WAIT_TIMEOUT);

            return;
        }

        /*
         * 沒給 selector、或選不到元素時退回整頁。
         *
         * 這時才用座標裁切 —— 它是備案不是主力：座標寫死，對方改版就裁錯，
         * 而且不會報錯。能用 selector 就別用它。
         */
        $page->screenshot()->saveToFile($target, self::WAIT_TIMEOUT);
        $this->crop($target, Arr::get($options, 'crop'));
    }

    /**
     * 等元素出現並取得它
     *
     * K 線圖是 JS 畫的，頁面「載入完成」不等於圖已經畫好。等元素比固定睡幾秒
     * 可靠：慢的時候不會截到空白，快的時候也不用白等。
     *
     * 等不到就回 null 讓呼叫端退回整頁 —— 選擇器失效不該讓整張圖沒了。
     *
     * @param \HeadlessChromium\Page $page
     * @param string                 $selector
     * @return \HeadlessChromium\Dom\Node|null
     */
    private function findNode($page, $selector)
    {
        try {
            $page->waitUntilContainsElement($selector, self::WAIT_TIMEOUT);
            $nodes = $page->dom()->search($selector);
        } catch (\Throwable $e) {
            Log::warning('截圖選擇器等不到元素，改截整頁', [
                'selector' => $selector,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }

        if (blank($nodes)) {
            Log::warning('截圖選擇器找不到元素，改截整頁', ['selector' => $selector]);

            return null;
        }

        return $nodes[0];
    }

    /**
     * 裁出想要的那一塊（只在退回整頁時才用）
     *
     * 座標是對著特定視窗寬度量出來的，**換了尺寸或對方改版就要重新校正**。
     * 裁切失敗一律保留原圖：送一張沒裁好的，總比整個截圖作廢好。
     *
     * @param string     $path 圖檔絕對路徑，會就地覆寫
     * @param array|null $crop X / Y / WIDTH / HEIGHT，null 表示不裁
     * @return void
     */
    private function crop($path, $crop)
    {
        if (blank($crop) || !extension_loaded('gd')) {
            return;
        }

        $source = @imagecreatefrompng($path);

        if ($source === false) {
            Log::warning('截圖裁切失敗：讀不到圖檔', ['path' => $path]);

            return;
        }

        $rect = [
            'x'      => (int) Arr::get($crop, 'X', 0),
            'y'      => (int) Arr::get($crop, 'Y', 0),
            'width'  => (int) Arr::get($crop, 'WIDTH', 0),
            'height' => (int) Arr::get($crop, 'HEIGHT', 0),
        ];

        // 超出原圖範圍的話 imagecrop 會回出乎意料的結果，先夾在邊界內
        $rect['width'] = min($rect['width'], imagesx($source) - $rect['x']);
        $rect['height'] = min($rect['height'], imagesy($source) - $rect['y']);

        if ($rect['width'] <= 0 || $rect['height'] <= 0) {
            Log::warning('截圖裁切範圍無效，保留原圖', ['crop' => $crop]);
            imagedestroy($source);

            return;
        }

        $cropped = imagecrop($source, $rect);

        if ($cropped === false) {
            Log::warning('截圖裁切失敗，保留原圖', ['crop' => $rect]);
            imagedestroy($source);

            return;
        }

        imagepng($cropped, $path);
        imagedestroy($cropped);
        imagedestroy($source);
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
