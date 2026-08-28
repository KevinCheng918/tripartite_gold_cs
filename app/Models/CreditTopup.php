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
 * @property float       $usdt_amount
 * @property float       $exchange_rate
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
        'usdt_amount'   => 'decimal:4',
        'exchange_rate' => 'decimal:4',
        'credit_amount' => 'decimal:2',
        'status'        => 'integer',
        'reviewed_at'   => 'datetime',
        'images'        => 'array',
    ];

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
