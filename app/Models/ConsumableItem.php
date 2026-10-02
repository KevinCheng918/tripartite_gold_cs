<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 消耗品品項 Model
 *
 * 內勤領用的消耗品種類（卡片、SIM 卡…）。
 *
 * @property int         $id
 * @property string      $name       品項名稱
 * @property string|null $unit       單位（張、個），純顯示用
 * @property int         $status     1=啟用, 0=停用
 * @property int         $sort_order 排序
 * @property string|null $note       備註
 */
class ConsumableItem extends Model
{
    protected $table = 'consumable_item';
    protected $guarded = ['id'];

    protected $casts = [
        'status'     => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * 這個品項的所有流水
     *
     * @return HasMany
     */
    public function records()
    {
        return $this->hasMany(ConsumableRecord::class);
    }

    /**
     * 還能不能登記新紀錄
     *
     * 停用的品項舊紀錄照常顯示與計算，只是不能再登記新的。
     *
     * @return bool
     */
    public function isActive()
    {
        return (int) $this->status === (int) config('constants.CONSUMABLE.STATUS.ACTIVE');
    }
}
