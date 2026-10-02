<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 消耗品流水 Model
 *
 * 領用（進）與使用（出）共用一張表，方向由 `type` 決定 ——
 * `quantity` 永遠是正數。
 *
 * 品項直接存名稱（`item_name`），沒有品項表 —— 登記時自己打。
 *
 * @property int         $id
 * @property int         $user_id     哪個內勤
 * @property string      $item_name   品項名稱
 * @property int         $type        1=領用, 2=使用
 * @property int         $quantity    數量（正數）
 * @property string      $happened_at 領用／使用的日期
 * @property string|null $note        備註
 * @property int|null    $created_by  誰登記的
 */
class ConsumableRecord extends Model
{
    protected $table = 'consumable_record';
    protected $guarded = ['id'];

    protected $casts = [
        'user_id'     => 'integer',
        'type'        => 'integer',
        'quantity'    => 'integer',
        'created_by'  => 'integer',
        'happened_at' => 'date',
    ];

    /**
     * 這筆是領用還是使用
     *
     * @return bool
     */
    public function isIssue()
    {
        return $this->type === (int) config('constants.CONSUMABLE.TYPE.ISSUE');
    }

    /**
     * 消耗品的持有人
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class)->select(['id', 'account', 'nickname', 'level']);
    }

    /**
     * 登記的人
     *
     * 帳號被刪時會是 null（nullOnDelete），UI 顯示「已離職人員」——
     * 比照忽略名單的 ignoredBy()。
     *
     * @return BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'account', 'nickname']);
    }
}
