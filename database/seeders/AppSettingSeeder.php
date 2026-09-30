<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Services\AppSettingService;
use Illuminate\Database\Seeder;

/**
 * 全域設定的預設值
 *
 * 主要是對客話術模板 —— 沒有模板的話自動回覆送不出任何訊息。
 * 模板之後可以在後台「全域設定」頁隨時修改。
 *
 * ⚠️ **已存在的設定一律不覆蓋**，重跑 seeder 不會蓋掉調整過的話術。
 * 憑證（token、API key）不在這裡，那些只能從後台填。
 *
 * @example php artisan db:seed --class=AppSettingSeeder
 */
class AppSettingSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        $created = 0;

        foreach ($this->defaults() as $key => $value) {
            $exists = AppSetting::query()->select(['id'])->where('key', $key)->exists();

            if ($exists) {
                continue;
            }

            AppSetting::query()->create([
                'key'       => $key,
                'value'     => $value,
                'is_secret' => false,
            ]);

            $created++;
        }

        $this->command->info("已建立 {$created} 筆預設設定（既有設定未變動）");
    }

    /**
     * 預設值
     *
     * 話術刻意寫得客氣 —— 客人看到的每一則都要像有禮貌的專員在招呼他。
     * 送出時系統會自動在結尾加上署名，所以模板本身不要寫。
     *
     * @return array key => value
     */
    private function defaults()
    {
        return [
            AppSettingService::KEY_CLAUDE_MODEL          => 'opus',
            AppSettingService::KEY_FALLBACK_ENABLED      => '0',
            AppSettingService::KEY_FALLBACK_MODEL        => 'haiku',
            AppSettingService::KEY_FALLBACK_DAILY_LIMIT  => '200',
            AppSettingService::KEY_REMIND_FIRST_MINUTES  => '10',
            AppSettingService::KEY_REMIND_SECOND_MINUTES => '10',

            /*
             * 對客話術（tpl_*）2026-09-30 移到 config/auto_reply.php 的 templates，
             * 後台那一頁也一起移除了。
             *
             * 當初這裡把開場白與結尾語刻意留空（只有 '{答案}'），理由是那些該由
             * AI 的承接句負責；但後來有人在後台把問候語加了回去，於是客人第一次
             * 對話會連著看到兩個「您好」—— 一個來自承接句、一個來自模板。
             * 移除之後就沒有這個問題了。
             */
        ];
    }
}
