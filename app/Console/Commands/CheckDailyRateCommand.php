<?php

namespace App\Console\Commands;

use App\Services\DailyRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 檢查每日匯率的上線前置條件
 *
 * 在要部署的那台機器上跑一次，缺什麼一目了然。
 * 純檢查、不改任何東西、不發任何訊息，所以隨時可以跑。
 *
 * 狀態由 DailyRateService::readiness() 判斷，這裡只負責排版與「該怎麼修」
 * 的文案 —— 同一份狀態之後要搬到後台頁面也不用動 Service。
 */
class CheckDailyRateCommand extends Command
{
    protected $signature = 'rate:check';

    protected $description = '檢查每日匯率報價的前置條件（Chrome、字型、群組、題庫、排程）';

    private $rateService;

    public function __construct(DailyRateService $rateService)
    {
        parent::__construct();

        $this->rateService = $rateService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $state = $this->rateService->readiness();

        $this->line('');
        $this->line('  主機：' . (gethostname() ?: '-') . '（' . php_uname('m') . '）');
        $this->line('');

        $rows = $this->buildRows($state);
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
     * 把狀態排成表格
     *
     * @param array $state
     * @return array
     */
    private function buildRows($state)
    {
        $chrome = Arr::get($state, 'chrome');
        $fonts = (int) Arr::get($state, 'fonts');

        return [
            $this->required('daily_rate 資料表', Arr::get($state, 'table'), 'php artisan migrate'),
            $this->required(
                '內部支援群組',
                Arr::get($state, 'group'),
                '到通訊管理 → 通知設定填 chat_id，沒有這個報價送不出去'
            ),
            $this->required(
                '題庫的匯率題',
                Arr::get($state, 'item'),
                'php artisan db:seed --class=DailyRateQuickReplySeeder'
            ),
            $this->required('chrome-php 套件', Arr::get($state, 'package'), 'composer install'),

            $this->optional(
                '報價公版',
                Arr::get($state, 'template'),
                '已自訂',
                '用預設（匯率頁可以貼上自己的公版）'
            ),
            $this->optional(
                'Chrome 執行檔',
                filled($chrome),
                (string) $chrome,
                $this->installHint('chrome') . '（沒有就只發文字）'
            ),
            $this->optional(
                '中文字型',
                $fonts > 0,
                "{$fonts} 個",
                $fonts === -1
                    ? '沒有 fc-list 指令，無法判斷'
                    : $this->installHint('font') . '（沒有的話圖上中文是方框）'
            ),

            // 這項沒辦法從 PHP 內部確認，只能提醒
            ['排程有在跑', '？', 'crontab 要有 schedule:run（所有排程功能共用，不只匯率）'],
        ];
    }

    /**
     * 依這台機器的套件管理器給安裝指令
     *
     * 原本一律寫 apt-get，但正式機是 RHEL 系（沒有 apt-get），
     * 照著打會跑不動。偵測一次就不用每台機器問一遍。
     *
     * Chrome 在 RHEL 系沒有官方 repo，直接裝 rpm 比較省事。
     *
     * @param string $what chrome 或 font
     * @return string
     */
    private function installHint($what)
    {
        $redhat = file_exists('/etc/redhat-release');
        $installer = $redhat
            ? (filled($this->which('dnf')) ? 'dnf' : 'yum')
            : 'apt-get';

        if ($what === 'chrome') {
            return $redhat
                ? "sudo {$installer} install -y https://dl.google.com/linux/direct/google-chrome-stable_current_x86_64.rpm"
                : "sudo {$installer} install -y google-chrome-stable";
        }

        return $redhat
            ? "sudo {$installer} install -y google-noto-sans-cjk-ttc-fonts"
            : "sudo {$installer} install -y fonts-noto-cjk";
    }

    /**
     * 這個指令存在嗎
     *
     * @param string $command
     * @return string|null
     */
    private function which($command)
    {
        foreach (['/usr/bin/', '/usr/local/bin/', '/bin/'] as $dir) {
            if (is_executable($dir . $command)) {
                return $dir . $command;
            }
        }

        return null;
    }

    /**
     * 必要條件：缺了功能完全不會動
     *
     * @param string $label
     * @param bool   $ok
     * @param string $hint 沒完成時該怎麼做
     * @return array
     */
    private function required($label, $ok, $hint)
    {
        return [$label, $ok ? '✓' : '✗ 必要', $ok ? '' : $hint];
    }

    /**
     * 選用條件：缺了只是沒有圖／用預設值，報價照常
     *
     * @param string $label
     * @param bool   $ok
     * @param string $okHint   完成時顯示什麼
     * @param string $missHint 沒完成時顯示什麼
     * @return array
     */
    private function optional($label, $ok, $okHint, $missHint)
    {
        return [$label, $ok ? '✓' : '— 選用', $ok ? $okHint : $missHint];
    }
}
