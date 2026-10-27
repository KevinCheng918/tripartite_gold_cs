<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 站台補點/扣點紀錄 Model
 *
 * @property int         $id
 * @property int         $station_id
 * @property int         $action_type     1=加點, 2=扣點
 * @property string      $credit_type     credit 或 shop_credit
 * @property int         $input_type      1=輸入 USDT 換算, 2=直接輸入點數
 * @property float       $usdt_amount     直接輸入點數時為 0
 * @property float       $exchange_rate   直接輸入點數時為 0
 * @property float       $credit_amount
 * @property int         $status          0=待審核, 1=已完成, 2=拒絕, 3=API失敗
 * @property string|null $api_response
 * @property int|null    $requested_by
 * @property int|null    $reviewed_by
 * @property string|null $reviewed_at
 * @property string|null $note
 */
class CreditTopup extends Model
{
    protected $table = 'credit_topup';

    protected $guarded = ['id'];

    /** @var int 加點 */
    public const ACTION_ADD = 1;
    /** @var int 扣點 */
    public const ACTION_DEDUCT = 2;

    /** @var int 輸入 USDT 依匯率換算點數 */
    public const TYPE_USDT = 1;
    /** @var int 直接輸入點數，無 USDT 與匯率，不列入均匯率計算 */
    public const TYPE_CREDIT = 2;

    /** @var string 系統點數（會呼叫主系統 API 真的加扣） */
    public const CREDIT_TYPE_CREDIT = 'credit';
    /** @var string 商城點數（會呼叫主系統 API 真的加扣） */
    public const CREDIT_TYPE_SHOP = 'shop_credit';
    /**
     * 其他
     *
     * ⚠ **不呼叫主系統 API**（需求方 2026-10-14）。這一類是「記一筆帳」——
     * 金額照填、照樣要人審核通過或拒絕，但主系統的點數不會動。
     * 所以備註必填：不寫的話，事後沒人知道這筆是什麼。
     *
     * @var string
     */
    public const CREDIT_TYPE_OTHER = 'other';

    /** @var string 站台沒綁 Telegram 群組，沒有地方可以發 */
    public const ISSUE_NO_GROUP = 'no_group';

    /** @var string 主站沒回訊息，沒有內容可以發 */
    public const ISSUE_NO_MESSAGE = 'no_message';

    /** @var string Telegram 拒收或丟例外 */
    public const ISSUE_SEND_FAILED = 'send_failed';

    /**
     * 主站回的是舊格式（沒有「原本點數」那三行）
     *
     * ⚠ 這一種**訊息有送出去**，只是格式舊 —— 所以 `notified_at` 會有值，
     * 跟上面三種「根本沒送出去」不一樣。
     *
     * 主系統 `AdminCreditService` 在 2026-08-28 才改成三行格式，
     * 停在那之前的站台會回舊的那一句。
     *
     * @var string
     */
    public const ISSUE_OLD_FORMAT = 'old_format';

    /** @var int 待審核 */
    public const STATUS_PENDING = 0;
    /** @var int 已完成 */
    public const STATUS_COMPLETED = 1;
    /** @var int 拒絕 */
    public const STATUS_REJECTED = 2;
    /** @var int API 失敗 */
    public const STATUS_FAILED = 3;

    protected $casts = [
        'action_type'   => 'integer',
        'input_type'    => 'integer',
        'usdt_amount'   => 'decimal:4',
        'exchange_rate' => 'decimal:4',
        'credit_amount' => 'decimal:2',
        'status'        => 'integer',
        'reviewed_at'   => 'datetime',
        'notified_at'   => 'datetime',
        'images'        => 'array',
    ];

    /**
     * 這一筆是不是「其他」
     *
     * ⚠ 「其他」**不呼叫主系統 API** —— 審核通過只是把這筆帳記成已完成，
     * 主系統的點數不會動，也不會發通知給客戶（沒有東西可以通知）。
     *
     * 寫成 predicate 而不是讓各處自己比字串：這個判斷會出現在審核流程、
     * 列表顯示、驗證規則幾個地方，各寫一份就會有人漏掉。
     *
     * @return bool
     */
    public function isOther()
    {
        return (string) $this->credit_type === self::CREDIT_TYPE_OTHER;
    }

    /**
     * @return BelongsTo
     */
    public function station(): BelongsTo
    {
        // telegram_group_id 供補點審核通過後發送 Telegram 通知使用，漏掉會讀成 null
        return $this->belongsTo(Station::class)
            ->select(['id', 'system_id', 'name', 'domain', 'api_url', 'api_key', 'telegram_group_id']);
    }

    /**
     * @return BelongsTo
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->select(['id', 'nickname']);
    }

    /**
     * @return BelongsTo
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->select(['id', 'nickname']);
    }
}
