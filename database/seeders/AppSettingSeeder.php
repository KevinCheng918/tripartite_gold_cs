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
             * 開場白與結尾語都不放在模板裡 —— 那些由 AI 的承接句負責，每次不同。
             * 在這裡寫固定句子，客人連著問就會看到一模一樣的開頭與結尾，
             * 那正是改版前被嫌罐頭的原因。
             */
            AppSettingService::KEY_TPL_ANSWER_FULL  => '{答案}',
            AppSettingService::KEY_TPL_ANSWER_SHORT => '{答案}',

            AppSettingService::KEY_TPL_WAIT_FULL => implode("\n", [
                '您好，感謝您的詢問 🙏',
                '',
                '這部分我幫您向相關同仁確認一下，稍後馬上回覆您，',
                '感謝您的耐心等候，造成您的等待不好意思！',
            ]),

            AppSettingService::KEY_TPL_WAIT_SHORT => implode("\n", [
                '這部分我再幫您確認一下，稍後馬上回覆您，感謝您的耐心等候 🙏',
            ]),

            /*
             * 同仁回答之後轉給客人的。這則前面沒有 AI 承接句，
             * 所以保留一句「久等了」承接，但不加固定結尾語。
             */
            AppSettingService::KEY_TPL_SUPPORT_FULL => implode("\n", [
                '久等了，已為您確認完畢 😊',
                '',
                '{答案}',
            ]),

            AppSettingService::KEY_TPL_SUPPORT_SHORT => implode("\n", [
                '久等了，已為您確認完畢 😊',
                '',
                '{答案}',
            ]),
        ];
    }
}
