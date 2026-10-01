<?php

namespace App\Console\Commands;

use App\Repositories\QuickReplyRepository;
use App\Services\AppSettingService;
use App\Services\DailyRateService;
use App\Services\ScreenshotService;
use App\Services\SupportGroupService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * 檢查每日匯率的上線前置條件
 *
 * 在要部署的那台機器上跑一次，缺什麼一目了然。
 * 純檢查、不改任何東西、不發任何訊息。
 */
class CheckDailyRateCommand extends Command
{
    protected $signature = 'rate:check';

    protected $description = '檢查每日匯率報價的前置條件（Chrome、字型、群組、題庫、排程）';

    private $rateService;
    private $screenshotService;
    private $supportGroup;
    private $appSettingService;
    private $quickReplyRepository;

    public function __construct(
        DailyRateService $rateService,
        ScreenshotService $screenshotService,
        SupportGroupService $supportGroup,
        AppSettingService $appSettingService,
        QuickReplyRepository $quickReplyRepository
    ) {
        parent::__construct();

        $this->rateService = $rateService;
        $this->screenshotService = $screenshotService;
        $this->supportGroup = $supportGroup;
        $this->appSettingService = $appSettingService;
        $this->quickReplyRepository = $quickReplyRepository;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $this->line('');
        $this->line('  主機：' . (gethostname() ?: '-') . '（' . php_uname('m') . '）');
        $this->line('');

        $rows = array_merge(
            $this->checkCore(),
            $this->checkScreenshot()
        );

        $this->table(['項目', '狀態', '說明'], $rows);

        $blocking = count(array_filter($rows, function ($row) {
            return $row[1] === '✗ 必要';
        }));

        if ($blocking > 0) {
            $this->warn("  還有 {$blocking} 項必要條件沒完成，排程跑起來也不會動。");

            return 0;
        }

        $this->info('  必要條件都齊了。可以到匯率頁按「立即報價」測整條迴路。');

        return 0;
    }

    /**
     * 功能本身能不能動
     *
     * @return array
     */
    private function checkCore()
    {
        $hasTable = \Schema::hasTable('daily_rate');
        $hasItem = filled($this->quickReplyRepository->findItemByImportKey(
            (string) config('constants.DAILY_RATE.QUICK_REPLY_KEY')
        ));
        $hasGroup = $this->supportGroup->isConfigured();
        $hasTemplate = filled($this->appSettingService->get(AppSettingService::KEY_DAILY_RATE_TEMPLATE));

        return [
            [
                'daily_rate 資料表',
                $hasTable ? '✓' : '✗ 必要',
                $hasTable ? '' : 'php artisan migrate',
            ],
            [
                '內部支援群組',
                $hasGroup ? '✓' : '✗ 必要',
                $hasGroup ? '' : '到全域設定頁填 chat_id，沒有這個報價送不出去',
            ],
            [
                '題庫的匯率題',
                $hasItem ? '✓' : '✗ 必要',
                $hasItem ? '' : 'php artisan db:seed --class=DailyRateQuickReplySeeder',
            ],
            [
                '報價公版',
                $hasTemplate ? '✓ 已自訂' : '— 用預設',
                $hasTemplate ? '' : '匯率頁可以貼上自己的公版',
            ],
            [
                '排程有在跑',
                '？',
                'crontab 要有 schedule:run（所有排程功能共用，不只匯率）',
            ],
        ];
    }

    /**
     * 截圖相關（缺了不影響報價，只是沒有圖）
     *
     * @return array
     */
    private function checkScreenshot()
    {
        $binary = $this->screenshotService->binaryPath();
        $fonts = $this->countChineseFonts();

        return [
            [
                'Chrome 執行檔',
                filled($binary) ? '✓' : '— 選用',
                filled($binary) ? $binary : 'apt-get install -y google-chrome-stable（沒有就只發文字）',
            ],
            [
                '中文字型',
                $fonts > 0 ? '✓' : '— 選用',
                $fonts > 0 ? "{$fonts} 個" : 'apt-get install -y fonts-noto-cjk（沒有的話圖上中文是方框）',
            ],
            [
                'chrome-php 套件',
                class_exists('HeadlessChromium\\BrowserFactory') ? '✓' : '✗ 必要',
                class_exists('HeadlessChromium\\BrowserFactory') ? '' : 'composer install',
            ],
        ];
    }

    /**
     * 系統裝了幾個中文字型
     *
     * 純 CLI 的伺服器通常一個都沒有 —— MAX 的介面是中文的，
     * 沒字型截出來會是一排方框，而且不會有任何錯誤訊息。
     *
     * @return int 沒有 fc-list 指令時回 -1（無法判斷）
     */
    private function countChineseFonts()
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
}
