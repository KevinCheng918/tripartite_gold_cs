<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 快速回覆問答 Model
 *
 * @property int    $id
 * @property int    $category_id
 * @property string $label
 * @property string $answer
 * @property int    $sort
 * @property int    $status
 */
class QuickReplyItem extends Model
{
    protected $table = 'quick_reply_item';

    protected $guarded = ['id'];

    protected $casts = [
        'category_id' => 'integer',
        'sort'        => 'integer',
        'status'      => 'integer',
    ];

    /**
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(QuickReplyCategory::class, 'category_id');
    }
}
