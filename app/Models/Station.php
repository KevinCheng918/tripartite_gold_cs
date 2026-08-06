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
 * @property array|null  $settings          站台設定 JSON（費率、存款類型開關等）
 * @property int|null    $telegram_group_id 對應的 Telegram 群組 ID
 * @property int         $status            1=啟用, 2=凍結, 0=停用
 * @property string|null $note              備註
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
    ];

    /**
     * 所屬系統
     *
     * @return BelongsTo
     */
    public function system()
    {
        return $this->belongsTo(System::class, 'system_id')->select(['id', 'name']);
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
