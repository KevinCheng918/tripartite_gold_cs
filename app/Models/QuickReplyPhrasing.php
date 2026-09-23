<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 題庫問法樣本 Model
 *
 * 客人實際問過、但模型沒比對到的那些句子。累積在這裡並進 system prompt，
 * 讓同樣的問法下次直接命中。來源見 constants.QUICK_REPLY.PHRASING_SOURCE。
 *
 * @property int         $id
 * @property int         $quick_reply_item_id
 * @property string      $text                客人的原話
 * @property int|null    $telegram_group_id
 * @property int         $source              1=求助單按鈕, 2=後台手動新增
 */
class QuickReplyPhrasing extends Model
{
    protected $table = 'quick_reply_phrasing';

    protected $guarded = ['id'];

    protected $casts = [
        'quick_reply_item_id' => 'integer',
        'telegram_group_id'   => 'integer',
        'source'              => 'integer',
    ];

    /**
     * @return BelongsTo
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(QuickReplyItem::class, 'quick_reply_item_id');
    }
}
