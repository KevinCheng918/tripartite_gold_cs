<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 站台 Model
 *
 * 每個站台代表一個租用主系統的客人（商戶）。
 *
 * @property int         $id
 * @property string      $name              站台名稱
 * @property string|null $domain            站台域名
 * @property string|null $api_url           主系統 API 網址
 * @property string|null $api_key           主系統 API Key
 * @property float       $credits           點數餘額（從 API 同步）
 * @property float|null  $credit_alert_threshold 餘點告警門檻，null 表示沿用全域設定
 * @property array|null  $settings          站台設定 JSON（費率、存款類型開關等）
 * @property int|null    $telegram_group_id 對應的 Telegram 群組 ID
 * @property int         $status            1=啟用, 2=凍結, 0=停用
 * @property string|null $note              備註
 * @property \Illuminate\Support\Carbon|null $synced_at         最後一次同步站台資訊的時間
 * @property \Illuminate\Support\Carbon|null $credit_alerted_at 最後一次送出餘點告警的時間
 *
 * @property-read array $topup_notice_blockers 發不了補點通知所缺的欄位名
 */
class Station extends Model
{
    protected $table = 'station';
    protected $guarded = ['id'];

    protected $casts = [
        'credits'  => 'decimal:2',
        'settings' => 'array',
        'status'    => 'integer',
        'synced_at' => 'datetime',
        // 門檻不轉 decimal：cast 成 decimal 會變字串，
        // 而這個值要拿去跟 credits 做數值比較，也要能判斷「有沒有設」
        'credit_alert_threshold' => 'float',
        'credit_alerted_at'      => 'datetime',
    ];

    /**
     * 發不了補點通知的原因：缺哪些設定
     *
     * 手動發送會先同步點數（要 `api_url` + `api_key`），再發到站台的
     * Telegram 群組（要 `telegram_group_id`）—— 三個缺任何一個都走不完，
     * 列表上的按鈕就該直接 disable，而不是讓人按下去才收到錯誤。
     *
     * 回傳的是**欄位名**而不是中文，文案由語系檔
     * （`station.topup_notice_missing.*`）負責。
     *
     * @return array
     */
    public function getTopupNoticeBlockersAttribute()
    {
        $required = ['api_url', 'api_key', 'telegram_group_id'];
        $missing = [];

        foreach ($required as $field) {
            if (blank($this->{$field})) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * 所屬系統
     *
     * @return BelongsTo
     */
    public function system()
    {
        return $this->belongsTo(System::class, 'system_id')->select(['id', 'name', 'bot_token']);
    }

    /**
     * 對應的 Telegram 群組
     *
     * @return BelongsTo
     */
    public function telegramGroup()
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }
}
