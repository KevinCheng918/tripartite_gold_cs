<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 自動回覆求助單 Model
 *
 * 題庫裡找不到答案時開一張單，轉到內部支援群組請自己人回答，
 * 再用按鈕決定要回覆客人／加入題庫。
 *
 * (ask_chat_id, ask_message_id) 是整條迴路的對應鍵 —— 內部群組同時有好幾張單
 * 在跑時，只有 Telegram 的 reply_to_message.message_id 能把答案精準對回原本那張單。
 *
 * ⚠⚠ **一定要兩個欄位一起比對**（2026-11-10 線上事故）：message_id 只在
 * 單一 chat 內唯一，支援群組換過 chat 之後新舊兩批單的 ask_message_id 會
 * 重疊 —— 只比 ask_message_id 的話，答案會被送到**另一個客人**的群組。
 *
 * @property int         $id
 * @property int         $telegram_group_id
 * @property int|null    $message_id          客人那則訊息（TTL 清理後為 null）
 * @property string      $question            客人問題原文（快照）
 * @property int|null    $ask_message_id      求助訊息在內部群組的 telegram message_id
 * @property int|null    $ask_chat_id         那則求助訊息發在哪個支援群組（舊資料可能為 null）
 * @property string|null $answer              自己人的回答原文
 * @property string|null $answered_by         回答者的 Telegram 顯示名稱
 * @property string|null $answered_at
 * @property int         $remind_count        見 constants.AUTO_REPLY.REMIND
 * @property string|null $last_reminded_at
 * @property int         $status              見 constants.AUTO_REPLY.TICKET_STATUS
 * @property int|null    $quick_reply_item_id 加入題庫後的題目 id
 */
class AutoReplyTicket extends Model
{
    protected $table = 'auto_reply_ticket';
    protected $guarded = ['id'];

    protected $casts = [
        'telegram_group_id'   => 'integer',
        'message_id'          => 'integer',
        'ask_message_id'      => 'integer',
        'ask_chat_id'         => 'integer',
        'answered_at'         => 'datetime',
        'remind_count'        => 'integer',
        'last_reminded_at'    => 'datetime',
        'status'              => 'integer',
        'quick_reply_item_id' => 'integer',
    ];

    /**
     * 客人所在群組
     *
     * @return BelongsTo
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id')
            ->select(['id', 'chat_id', 'title']);
    }

    /**
     * 加入題庫後產生的題目
     *
     * 刻意不設外鍵，所以這裡查不到（題目被刪）是正常情況，不是資料損毀。
     *
     * @return BelongsTo
     */
    public function quickReplyItem(): BelongsTo
    {
        return $this->belongsTo(QuickReplyItem::class, 'quick_reply_item_id')
            ->select(['id', 'category_id', 'label', 'answer']);
    }

    /**
     * 是否還在等自己人回答
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->status == config('constants.AUTO_REPLY.TICKET_STATUS.PENDING');
    }

    /**
     * 答案已經送給客人、或已經進了題庫 —— 這張單不該再接受新答案
     *
     * ⚠⚠ **IGNORED 與 EXPIRED 刻意不算在內。**（2026-11-07 修的 bug）
     *
     * 這支原本叫 `isClosed()`，把 IGNORED 也當成「不准再作答」。問題是
     * `AutoReplyTicketRepository::ignoreOpenByGroup()` 會在客服對客人送出
     * **任何一則**人工訊息時，把那個對話**全部**未結案的單標成 IGNORED ——
     * 包含客服還沒作答的那幾張。於是：
     *
     *   客服先跟客人說一句「稍等，我確認一下」
     *     → 那個對話所有求助單變成 IGNORED
     *     → 回支援群組要作答，卻只拿到「這張單已經處理過了」
     *     → 只能登入後台處理
     *
     * IGNORED 真正的語意是**「不要再催這張單」**（否則會一路催到上限、
     * 報表永遠紅著），不是「不准回答」。兩件事被同一個狀態表達，
     * 而這道判斷挑了錯的那個意思。
     *
     * ⚠ EXPIRED（放太久被 `ticket:close-stale` 收掉）同理：單被系統收掉，
     * 不代表同仁現在補的答案沒有價值 —— 那個答案還要進題庫給下一次用。
     *
     * ⚠ 真正該擋的只有 REPLIED／SAVED：答案已經送出去或已經存進題庫了，
     * 再回一次是重複，要改內容該去後台題庫改。
     *
     * ⚠ 不必擔心重新作答會讓催促復活：催促的查詢只看
     * `status = PENDING`（`getTicketsDueForRemind()`），而重新作答之後是
     * ANSWERED，撈不到。
     *
     * @return bool
     */
    public function isAnswerDelivered()
    {
        $delivered = [
            (int) config('constants.AUTO_REPLY.TICKET_STATUS.REPLIED'),
            (int) config('constants.AUTO_REPLY.TICKET_STATUS.SAVED'),
        ];

        // status 有 cast 成 integer，所以用嚴格比對
        return in_array((int) $this->status, $delivered, true);
    }
}
