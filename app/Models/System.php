<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 系統 Model
 *
 * 記錄可選擇的系統名稱，站台建立時需選擇所屬系統。
 *
 * @property int         $id
 * @property string      $name      系統名稱
 * @property string|null $bot_token Telegram Bot Token
 * @property int         $status    1=啟用, 0=停用
 */
class System extends Model
{
    protected $table = 'system';
    protected $guarded = ['id'];

    protected $casts = [
        'status' => 'integer',
    ];

    /**
     * 此系統下的站台
     *
     * @return HasMany
     */
    public function stations()
    {
        return $this->hasMany(Station::class, 'system_id');
    }
}
