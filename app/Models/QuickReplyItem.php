<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 快速回覆問答 Model
 *
 * @property int    $id
 * @property int    $category_id
 * @property string $label
 * @property string      $answer
 * @property string|null $import_key seeder 建立的題目來源識別，人工新增的為 null
 * @property int         $sort
 * @property int         $status
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

    /**
     * 客人問過的實際說法
     *
     * 這些會進 system prompt，是讓同樣的問法下次直接命中的關鍵。
     *
     * @return HasMany
     */
    public function phrasings(): HasMany
    {
        return $this->hasMany(QuickReplyPhrasing::class, 'quick_reply_item_id');
    }
}
