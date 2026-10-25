<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 站台餘點的同步紀錄
 *
 * @property int         $id
 * @property int         $station_id
 * @property string|null $credits    同步當下的餘點；null ＝ 這次同步失敗
 * @property string      $source     怎麼觸發的（見 SOURCE_*）
 * @property \Illuminate\Support\Carbon $created_at
 */
class StationCreditLog extends Model
{
    protected $table = 'station_credit_log';

    protected $guarded = ['id'];

    /** @var string 每日排程 */
    public const SOURCE_SCHEDULE = 'schedule';

    /** @var string 站台列表的同步按鈕 */
    public const SOURCE_MANUAL = 'manual';

    /** @var string 小幫手的「查餘點」指令 */
    public const SOURCE_COMMAND = 'command';

    /** @var string 餘點告警 */
    public const SOURCE_ALERT = 'alert';

    /** @var string 虛擬機費用試算 */
    public const SOURCE_AFFORD = 'afford';

    /*
     * ⚠ `credits` **不要 cast 成 float**：decimal(20,2) 轉 float 會在大數字
     * 上丟精度，而這是客戶的點數。保持字串，要運算時由呼叫端自己決定。
     */
    protected $casts = [
        'station_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class)->select(['id', 'name']);
    }
}
