<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 站台餘點的同步紀錄
 *
 * @property int         $id
 * @property int         $station_id
 * @property string|null $credits       同步當下的餘點；null ＝ 這次同步失敗
 * @property string      $credit_type    哪一種點數（見 CREDIT_TYPE_*）
 * @property string|null $change_amount  這一筆的異動金額（帶正負號）；只有補點有值
 * @property string      $source         怎麼觸發的（見 SOURCE_*）
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

    /**
     * 補點／扣點審核通過
     *
     * ⚠ 這一種**不是同步來的** —— 餘額取自主系統補點 API 回的 `data.credit`，
     * 而且是唯一會有 `change_amount` 的來源（同步只是取樣，不知道中間變動多少）。
     *
     * @var string
     */
    public const SOURCE_TOPUP = 'topup';

    /**
     * 系統點數（主系統 `User::$credit`）
     *
     * 同步寫的紀錄一律是這一種 —— `siteInfo()` 只回 `admin_credit`。
     *
     * @var string
     */
    public const CREDIT_TYPE_CREDIT = 'credit';

    /**
     * 商城點數（主系統 `User::$shop_credit`）
     *
     * ⚠ **一定要跟系統點數分成兩條線**（需求方 2026-10-30）：主系統補點 API
     * 回的 `data.credit` 是「被補的那一種」的餘額 —— 商城補點回的是商城餘額。
     * 混進系統點數那條曲線會變成一個憑空的大跳。
     *
     * ⚠ 目前**只有補點那幾筆**會是這一種：主系統 `siteInfo()` 只回
     * `admin_credit`，沒有商城餘額 —— 同步取樣不到。所以商城那條線是
     * 「補點事件」而不是「每小時取樣」，要變成完整曲線得先請主系統
     * 在 `siteInfo()` 多回一個商城餘額欄位。
     *
     * @var string
     */
    public const CREDIT_TYPE_SHOP = 'shop_credit';

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
