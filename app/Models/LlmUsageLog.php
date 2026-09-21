<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LLM 呼叫紀錄 Model
 *
 * 自動回覆只要有呼叫到 Claude 就記一筆 —— 成功、失敗、撞限額、逾時都算，
 * 因為它們都消耗了額度或至少嘗試消耗。
 *
 * 只有 created_at 沒有 updated_at：這是 append-only 的紀錄，寫完就不會再動。
 *
 * @property int         $id
 * @property string      $used_on          呼叫日期
 * @property int|null    $telegram_group_id
 * @property int         $source           1=訂閱, 2=備援 API（見 constants.AUTO_REPLY.SOURCE）
 * @property string|null $model
 * @property int         $duration_ms
 * @property bool        $is_error
 * @property bool        $is_rate_limited
 * @property int         $input_tokens
 * @property int         $output_tokens
 */
class LlmUsageLog extends Model
{
    protected $table = 'llm_usage_log';
    protected $guarded = ['id'];

    /** 沒有 updated_at 欄位，只讓 Eloquent 維護 created_at */
    const UPDATED_AT = null;

    protected $casts = [
        'used_on'         => 'date',
        'source'          => 'integer',
        'duration_ms'     => 'integer',
        'is_error'        => 'boolean',
        'is_rate_limited' => 'boolean',
        'input_tokens'    => 'integer',
        'output_tokens'   => 'integer',
    ];

    /**
     * 觸發這次呼叫的對話
     *
     * @return BelongsTo
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id')->select(['id', 'title']);
    }

    /**
     * 是否為備援 API 的呼叫（會實際產生費用）
     *
     * @return bool
     */
    public function isFallback()
    {
        return $this->source == config('constants.AUTO_REPLY.SOURCE.FALLBACK');
    }
}
