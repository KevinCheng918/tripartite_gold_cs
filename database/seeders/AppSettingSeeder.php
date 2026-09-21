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

            AppSettingService::KEY_TPL_ANSWER_FULL => implode("\n", [
                '您好，感謝您的詢問 😊',
                '',
                '{答案}',
                '',
                '若還有任何不清楚的地方，都歡迎再告訴我們，很高興為您服務！',
            ]),

            AppSettingService::KEY_TPL_ANSWER_SHORT => implode("\n", [
                '{答案}',
                '',
                '還有其他問題都歡迎再告訴我們！',
            ]),

            AppSettingService::KEY_TPL_CLARIFY_FULL => implode("\n", [
                '您好，為了提供給您最準確的說明，想先跟您確認一下是哪一項呢？',
                '',
                '{選項}',
                '',
                '麻煩您回覆編號，或直接描述一下您遇到的狀況，我們立刻為您處理 🙏',
            ]),

            AppSettingService::KEY_TPL_CLARIFY_SHORT => implode("\n", [
                '想再跟您確認一下是哪一項呢？',
                '',
                '{選項}',
                '',
                '麻煩您回覆編號，或直接描述您的狀況就可以囉 🙏',
            ]),

            AppSettingService::KEY_TPL_WAIT_FULL => implode("\n", [
                '您好，感謝您的詢問 🙏',
                '',
                '這部分我幫您向相關同仁確認一下，稍後馬上回覆您，',
                '感謝您的耐心等候，造成您的等待不好意思！',
            ]),

            AppSettingService::KEY_TPL_WAIT_SHORT => implode("\n", [
                '這部分我再幫您確認一下，稍後馬上回覆您，感謝您的耐心等候 🙏',
            ]),

            AppSettingService::KEY_TPL_SUPPORT_FULL => implode("\n", [
                '您好，久等了，已為您確認完畢 😊',
                '',
                '{答案}',
                '',
                '還有任何問題都歡迎再告訴我們，很樂意為您服務！',
            ]),

            AppSettingService::KEY_TPL_SUPPORT_SHORT => implode("\n", [
                '久等了，已為您確認完畢 😊',
                '',
                '{答案}',
                '',
                '還有任何問題都歡迎再告訴我們！',
            ]),
        ];
    }
}
