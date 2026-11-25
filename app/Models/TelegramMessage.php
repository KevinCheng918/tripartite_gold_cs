<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Telegram 訊息 Model
 *
 * 儲存收到和發送的 Telegram 訊息。
 *
 * @property int         $id
 * @property int         $telegram_group_id  所屬群組 ID
 * @property int         $direction          1=收（inbound）, 2=發（outbound）
 * @property int|null    $telegram_message_id Telegram 原始 message_id
 * @property string      $sender_name        發送者名稱
 * @property int|null    $sender_user_id     後台發送者 ID（僅 outbound）
 * @property bool        $is_auto            是否為自動回覆送出（僅 outbound 有意義）
 * @property string      $content            訊息內容
 * @property bool        $replied            是否已回覆（僅 inbound 有意義）
 * @property array|null  $reactions          表情回應 [{emoji: "👍", count: 1}]
 */
class TelegramMessage extends Model
{
    protected $table = 'telegram_message';
    protected $guarded = ['id'];

    protected $casts = [
        'direction' => 'integer',
        'replied'   => 'boolean',
        'is_auto'   => 'boolean',
        'reactions' => 'array',
        'mentions'  => 'array',
        'edited_at' => 'datetime',
        // 我方訊息的改掉／撤回（2026-11-25）
        'edited_by'   => 'integer',
        'recalled_at' => 'datetime',
        'recalled_by' => 'integer',
    ];

    /**
     * 這一則是我方送出去的嗎
     *
     * @return bool
     */
    public function isOutbound()
    {
        return (int) $this->direction === (int) config('constants.TELEGRAM.DIRECTION.OUTBOUND');
    }

    /**
     * 已經撤回了（客人那邊看不到了）
     *
     * ⚠ 撤回**不刪這一列** —— 「我們曾經送過這句話」仍然是事實。
     *
     * @return bool
     */
    public function isRecalled()
    {
        return filled($this->recalled_at);
    }

    /**
     * 現在還改得動嗎
     *
     * ⚠⚠ **四個條件缺一不可**（需求方 2026-11-25 拍板）：
     *
     * | 條件 | 為什麼 |
     * |---|---|
     * | 我方送出的 | 客人的訊息不是我們的 |
     * | 有 `telegram_message_id` | 沒有就指不到 Telegram 那一則。⚠ **不要只改後台** —— 那會變成「後台寫 A、客人看到 B」，比不能改更糟 |
     * | **不是自動回覆**（`is_auto`） | 改掉 AI 說的話＝把機器講的變成像人寫的，而出事時追的正是「誰說的」 |
     * | **純文字**（沒有 `media_type`） | `editMessageText` 只吃純文字；圖片要改就撤回重傳 |
     * | 還沒撤回、且在時限內 | 見 `EDIT_HOURS` |
     *
     * @return bool
     */
    public function canEdit()
    {
        if (!$this->isOutbound() || blank($this->telegram_message_id)) {
            return false;
        }

        if ($this->is_auto || filled($this->media_type) || $this->isRecalled()) {
            return false;
        }

        return $this->withinHours((int) config('constants.TELEGRAM.EDIT_HOURS'));
    }

    /**
     * 現在還撤得回嗎
     *
     * ⚠ **條件比 `canEdit()` 寬很多**：不挑訊息類型、不挑是人還是 AI 送的。
     * 圖片、檔案、自動回覆都撤得掉 —— `deleteMessage` 不分類型。
     *
     * @return bool
     */
    public function canRecall()
    {
        if (!$this->isOutbound() || blank($this->telegram_message_id) || $this->isRecalled()) {
            return false;
        }

        return $this->withinHours((int) config('constants.TELEGRAM.RECALL_HOURS'));
    }

    /**
     * 送出至今還沒超過這麼多小時嗎
     *
     * ⚠ 從 **`created_at`**（送出時間）算，不是從 `edited_at` ——
     * 不然改一次就能把時限無限往後推。
     *
     * @param int $hours 0 以下代表不限制
     * @return bool
     */
    private function withinHours($hours)
    {
        if ($hours < 1) {
            return true;
        }

        if (blank($this->created_at)) {
            return false;
        }

        return $this->created_at->copy()->addHours($hours)->isFuture();
    }

    /**
     * 所屬群組
     *
     * @return BelongsTo
     */
    public function group()
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    /**
     * 後台發送者（僅 outbound）
     *
     * @return BelongsTo
     */
    public function senderUser()
    {
        return $this->belongsTo(User::class, 'sender_user_id')->select(['id', 'account', 'nickname']);
    }

    /**
     * 把這一則改掉的人
     *
     * ⚠ 只有**我方送出、由客服改掉**的才有值。客人自己在 Telegram 改
     * 自己的話時我們也會寫 `edited_at`，但那種沒有 `edited_by` ——
     * 前端要靠「有沒有名字」分辨是誰改的。
     *
     * @return BelongsTo
     */
    public function editedByUser()
    {
        return $this->belongsTo(User::class, 'edited_by')->select(['id', 'nickname']);
    }

    /**
     * 把這一則撤回的人
     *
     * @return BelongsTo
     */
    public function recalledByUser()
    {
        return $this->belongsTo(User::class, 'recalled_by')->select(['id', 'nickname']);
    }
}
