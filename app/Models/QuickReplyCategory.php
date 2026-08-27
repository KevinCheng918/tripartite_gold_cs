<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 快速回覆類別 Model
 *
 * @property int    $id
 * @property string $label
 * @property int    $sort
 * @property int    $status
 */
class QuickReplyCategory extends Model
{
    protected $table = 'quick_reply_category';

    protected $guarded = ['id'];

    protected $casts = [
        'sort'   => 'integer',
        'status' => 'integer',
    ];

    /**
     * @return HasMany
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuickReplyItem::class, 'category_id');
    }

    /**
     * 只取啟用中的問題，依排序
     *
     * @return HasMany
     */
    public function activeItems(): HasMany
    {
        return $this->items()
            ->where('status', config('constants.QUICK_REPLY.STATUS.ACTIVE'))
            ->orderBy('sort')
            ->orderBy('id');
    }
}
