<?php

namespace App\Services;

use HeadlessChromium\BrowserFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
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
     * @param array  $options width / height / selector / crop / user_agent / prefix
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

        /*
         * 記耗時 —— 「截圖好像有點久」這種回報，沒有數字就只能猜。
         * 最常見的慢法是選擇器選不到元素而白等滿 WAIT_TIMEOUT（30 秒），
         * 那時這個數字會貼著 30000，一看就知道。
         */
        $startedAt = microtime(true);

        $browser = null;
        $work = $this->makeWorkDir();

        try {
            $browser = $this->createBrowser($binary, $options, $work);
            $this->shoot($browser, $url, $target, $options);
        } catch (\Throwable $e) {
            /*
             * 這裡攔 Throwable 不只是 Exception —— 套件在連線中斷時可能丟
             * Error。截圖失敗絕對不能讓報價整則送不出去。
             */
            Log::error('截圖失敗', [
                'url'      => $url,
                'selector' => Arr::get($options, 'selector'),
                'ms'       => (int) round((microtime(true) - $startedAt) * 1000),
                'error'    => $e->getMessage(),
            ]);

            return null;
        } finally {
            if (filled($browser)) {
                // 不關的話 Chrome 行程會留著，跑久了會把機器塞滿
                $browser->close();
            }

            // 要等 Chrome 關掉才能刪，不然它還握著裡面的檔案
            $this->removeWorkDir($work);
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
            // 貼著 30000 就是選擇器等不到元素、白等滿 WAIT_TIMEOUT
            'ms'       => (int) round((microtime(true) - $startedAt) * 1000),
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
     * 這次截圖的工作目錄
     *
     * ⚠ **用 apache / php-fpm 帳號跑時，光給 `--user-data-dir` 不夠。**
     * 正式機實際噴的是：
     *
     * ```
     * mkdir: cannot create directory '/usr/share/httpd/.local': Permission denied
     * touch: cannot touch '/usr/share/httpd/.local/share/applications/mimeapps.list': No such file...
     * chrome_crashpad_handler: --database is required
     * ```
     *
     * 三件不同的事，`--user-data-dir` 一件都管不到：
     *
     * 1. `/usr/share/httpd` 是 apache 的家目錄，**不可寫**。`mkdir`／`touch`
     *    這種錯誤格式是 **shell 腳本**噴的 —— Linux 的 `google-chrome-stable`
     *    是個 wrapper script，啟動前會做 desktop integration。它碰的是
     *    `$HOME`，不是 profile 目錄。只有換掉 `HOME` 才有用。
     * 2. 那個 `touch` 失敗是因為 script 只 `mkdir $HOME/.local`，沒有
     *    `mkdir -p` 中間層 —— 所以我們**預先把 `.local/share/applications`
     *    建好**，讓它 touch 得成。
     * 3. crashpad handler 被叫起來卻沒給 `--database`。用
     *    `--disable-crash-reporter` 不讓它啟動（見 `createBrowser()`）。
     *
     * 所以每次截圖開一個這樣的工作目錄：
     *
     * ```
     * <base>/<unique>/
     * ├── profile/                          → --user-data-dir
     * └── home/                             → HOME 與 XDG_*
     *     └── .local/share/applications/    → 讓 wrapper script 寫得進去
     * ```
     *
     * 每次都開新的而不是共用：Chrome 會對 profile 上鎖，共用的話兩張圖
     * 同時截就會卡住。
     *
     * 建不起來時回 null（讓套件走它的預設），不讓截圖死在這 ——
     * 本來能跑的環境不該因為多了這段而壞掉。
     *
     * @return array{root: string, profile: string, home: string}|null
     */
    private function makeWorkDir()
    {
        $base = (string) config('constants.DAILY_RATE.SCREENSHOT.USER_DATA_BASE');

        if (blank($base)) {
            return null;
        }

        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            Log::warning('建不出 Chrome 工作目錄的基底，改用套件預設', ['base' => $base]);

            return null;
        }

        $root = rtrim($base, '/') . '/' . uniqid('w', true);
        $profile = "{$root}/profile";
        $home = "{$root}/home";

        // wrapper script 的 touch 需要這一層先存在（理由見上面第 2 點）
        $needed = [$root, $profile, $home, "{$home}/.local", "{$home}/.local/share", "{$home}/.local/share/applications"];

        foreach ($needed as $dir) {
            if (!@mkdir($dir, 0700) && !is_dir($dir)) {
                Log::warning('建不出 Chrome 工作目錄，改用套件預設', ['dir' => $dir]);
                $this->removeWorkDir(['root' => $root]);

                return null;
            }
        }

        return ['root' => $root, 'profile' => $profile, 'home' => $home];
    }

    /**
     * 刪掉這次的工作目錄
     *
     * 不刪的話每截一次就留一份 profile 與 home，`storage/` 會一直長大。
     *
     * ⚠ 這是遞迴刪除，所以**先確認它真的在設定的基底底下**才動手 ——
     * 設定被改壞時不該變成刪掉別的東西。
     *
     * @param array|null $work makeWorkDir() 的回傳
     * @return void
     */
    private function removeWorkDir($work)
    {
        $root = Arr::get((array) $work, 'root');

        if (blank($root)) {
            return;
        }

        $base = rtrim((string) config('constants.DAILY_RATE.SCREENSHOT.USER_DATA_BASE'), '/');

        // 必須是「基底底下的子目錄」，不能是基底本身，也不能跑到外面去
        if (blank($base) || strpos($root, "{$base}/") !== 0) {
            Log::warning('Chrome 工作目錄不在設定的基底底下，不刪', [
                'root' => $root,
                'base' => $base,
            ]);

            return;
        }

        File::deleteDirectory($root);
    }

    /**
     * 開一個瀏覽器
     *
     * @param string     $binary
     * @param array      $options
     * @param array|null $work null 就讓套件自己挑目錄（見 makeWorkDir）
     * @return \HeadlessChromium\Browser\ProcessAwareBrowser
     */
    private function createBrowser($binary, $options, $work = null)
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
                /*
                 * crashpad handler 被叫起來卻沒給 `--database` 時，正式機會以
                 * `chrome_crashpad_handler: --database is required` 起不來。
                 * 我們不需要 crash 回報，直接不讓它啟動。
                 *
                 * 套件另有 `userCrashDumpsDir` 選項，但它的註解寫明
                 * 「crash reporter will be enabled automatically」—— 那是
                 * 反方向（給它一個可寫的 database），會多一個沒用的行程。
                 */
                '--disable-crash-reporter',
                '--disable-breakpad',
                // 首次啟動的精靈與預設瀏覽器檢查都會去碰 $HOME，一併關掉
                '--no-first-run',
                '--no-default-browser-check',
            ],
        ];

        /*
         * 指定工作目錄 —— php-fpm 帳號下少了這些會起不來，三個原因見
         * makeWorkDir() 的註解。
         *
         * `userDataDir` 套件會轉成 `--user-data-dir=`（BrowserProcess 第 405
         * 行），`envVariables` 會進 Symfony Process 的 $env（第 127 行）。
         *
         * ⚠ Symfony 的 env 是**我們設的優先、系統環境補上**
         * （`Process.php` 第 310-314 行用 `+=`），所以這裡只覆寫這幾個，
         * `PATH` 之類的照樣繼承 —— 不會把環境清空。
         */
        if (filled($work)) {
            $home = Arr::get($work, 'home');

            $browserOptions['userDataDir'] = Arr::get($work, 'profile');
            $browserOptions['envVariables'] = [
                // wrapper script 的 desktop integration 碰的是這個
                'HOME'            => $home,
                // 有些程式讀 XDG 而不是 HOME，一併指過來
                'XDG_CONFIG_HOME' => "{$home}/.config",
                'XDG_CACHE_HOME'  => "{$home}/.cache",
                'XDG_DATA_HOME'   => "{$home}/.local/share",
                'XDG_RUNTIME_DIR' => "{$home}/run",
            ];
        }

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
     * 等頁面準備好再截
     *
     * 兩段：先等 `wait_selector` 那個元素出現（頁面骨架好了），再固定等
     * `settle_ms` 讓它裡面的內容畫完。
     *
     * ⚠ **第二段是必要的，不是保險。** MAX 的 K 線圖在 iframe 裡，
     * 跨 frame 等不到「圖畫好了」這個事件 —— iframe 元素出現只代表容器在，
     * 不代表圖畫完了。沒有這段會截到空白的圖表框。
     *
     * （設定裡原本有個 `WAIT_MS`，但程式從來沒讀過它，2026-10-03 移除；
     * 這裡的 `SETTLE_MS` 是真的接上線的那一個。截到空白圖表就調大它。）
     *
     * @param \HeadlessChromium\Page $page
     * @param array                  $options
     * @return void
     */
    private function waitForReady($page, $options)
    {
        $waitSelector = Arr::get($options, 'wait_selector');

        if (filled($waitSelector)) {
            // 等不到就算了 —— findNode() 自己會記 warning，截整頁仍有機會是對的
            $this->findNode($page, $waitSelector);
        }

        $settleMs = (int) Arr::get($options, 'settle_ms');

        if ($settleMs > 0) {
            usleep($settleMs * 1000);
        }
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

        /*
         * 「等頁面準備好」與「要截哪裡」是兩件事，所以分成兩個設定。
         *
         * MAX 的 K 線圖在 iframe 裡，截的範圍又橫跨 iframe 內外（報價列在
         * 外、圖在內）—— 截圖只能走整頁，但還是得等圖表掛載好才截得到東西。
         * 這時 `wait_selector` 等外層那個 `<iframe>`，`selector` 留 null。
         */
        $this->waitForReady($page, $options);

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
