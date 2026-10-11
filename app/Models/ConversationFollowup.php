<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 待追蹤事項 Model
 *
 * 有人在對話裡說「稍等／晚點給你」就記一筆，對話安靜一段時間後到內部群組
 * 問當班人員要不要追蹤。跟求助單反方向：求助單是我們欠客人答案，
 * 這裡是對方欠我們東西。
 *
 * `source` 決定按下「要追蹤」之後做什麼 —— 客人承諾發訊息問客人，
 * 我方承諾只在群組 tag 當班人員，**絕不回頭問客人**。
 *
 * @property int         $id
 * @property int         $telegram_group_id
 * @property int|null    $telegram_message_id 說那句話的訊息（purge 後為 null）
 * @property int         $source              見 constants.FOLLOWUP.SOURCE
 * @property string|null $sender_name         誰說的（快照）
 * @property int|null    $sender_user_id      我方承諾時對應的後台帳號
 * @property string|null $wait_text           那句原話（快照）
 * @property string|null $matched_keyword     命中哪個等待詞
 * @property string|null $wait_at             計時起點
 * @property int         $status              見 constants.FOLLOWUP.STATUS
 * @property int         $wait_count          對方說了第幾次「稍等」
 * @property int         $ask_round           已經追問幾輪
 * @property int|null    $ask_message_id      群組詢問訊息的 telegram message_id
 * @property int         $remind_count        問了當班人員幾次
 * @property string|null $last_reminded_at
 * @property int|null    $decided_by
 * @property string|null $decided_at
 */
class ConversationFollowup extends Model
{
    protected $table = 'conversation_followup';
    protected $guarded = ['id'];

    protected $casts = [
        'telegram_group_id'   => 'integer',
        'telegram_message_id' => 'integer',
        'source'              => 'integer',
        'sender_user_id'      => 'integer',
        'wait_at'             => 'datetime',
        'status'              => 'integer',
        'wait_count'          => 'integer',
        'ask_round'           => 'integer',
        'ask_message_id'      => 'integer',
        'remind_count'        => 'integer',
        'last_reminded_at'    => 'datetime',
        'decided_by'          => 'integer',
        'decided_at'          => 'datetime',
    ];

    /**
     * 對方所在的對話
     *
     * @return BelongsTo
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id')
            ->select(['id', 'chat_id', 'title']);
    }

    /**
     * 說那句話的訊息
     *
     * 引用回覆要用它的 `telegram_message_id`（Telegram 那邊的 id，
     * 不是本表的外鍵）。訊息被 purge 掉時查不到是正常情況。
     *
     * @return BelongsTo
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TelegramMessage::class, 'telegram_message_id')
            ->select(['id', 'telegram_group_id', 'telegram_message_id', 'content']);
    }

    /**
     * 我方承諾時說那句話的同事
     *
     * ⚠ 只拿來顯示「是誰講的」。**不會 tag 他**（需求方指定只 tag 當班人員，
     * 他今天不一定上班）。
     *
     * @return BelongsTo
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id')
            ->select(['id', 'nickname']);
    }

    /**
     * 按按鈕／手動處理的人
     *
     * @return BelongsTo
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')
            ->select(['id', 'nickname']);
    }

    /**
     * 是不是客人承諾的
     *
     * @return bool
     */
    public function isFromCustomer()
    {
        return $this->source == config('constants.FOLLOWUP.SOURCE.CUSTOMER');
    }

    /**
     * 還在等對方自己回來
     *
     * @return bool
     */
    public function isWaiting()
    {
        return $this->status == config('constants.FOLLOWUP.STATUS.WAITING');
    }

    /**
     * 已經追問過，等對方回應
     *
     * @return bool
     */
    public function isAsked()
    {
        return $this->status == config('constants.FOLLOWUP.STATUS.ASKED');
    }

    /**
     * 已經結束（四種出口都算）
     *
     * 等待中與已追問都還活著，其餘都是結束 —— 新增狀態時這裡不必跟著改。
     *
     * @return bool
     */
    public function isClosed()
    {
        return !$this->isWaiting() && !$this->isAsked();
    }
}
