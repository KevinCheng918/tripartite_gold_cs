<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 自動回覆關閉紀錄 Model
 *
 * 關掉自動回覆就寫一筆，排程靠它把忘記勾回來的群組勾回來。
 *
 * ⚠ 這張表記的是**事件**而不是狀態：`telegram_group.auto_reply = 0` 分不出
 * 「剛被關掉的」與「從來沒開過的」，所以不能拿欄位當判斷依據。
 * 詳見 .claude/knowledge/features/auto-reply-resume.md
 *
 * @property int         $id
 * @property int         $telegram_group_id
 * @property int|null    $user_id        關掉的人（帳號刪掉後為 null）
 * @property string|null $operator_name  關掉的人（名稱快照）
 * @property Carbon      $off_at         關閉時間（靜置基準之一）
 * @property int|null    $quiet_minutes  靜置幾分鐘後恢復；永久關閉時為 null
 * @property int         $mode           見 constants.AUTO_REPLY_RESUME.MODE
 * @property int         $status         見 constants.AUTO_REPLY_RESUME.STATUS
 * @property Carbon|null $resumed_at
 */
class AutoReplyResume extends Model
{
    protected $table = 'auto_reply_resume';
    protected $guarded = ['id'];

    protected $casts = [
        'telegram_group_id' => 'integer',
        'user_id'           => 'integer',
        'off_at'            => 'datetime',
        'quiet_minutes'     => 'integer',
        'mode'              => 'integer',
        'status'            => 'integer',
        'resumed_at'        => 'datetime',
    ];

    /**
     * 哪個對話
     *
     * @return BelongsTo
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id')
            ->select(['id', 'chat_id', 'title', 'status', 'auto_reply', 'last_message_at']);
    }

    /**
     * 關掉的人
     *
     * ⚠ 要 tag 他，所以 `telegram_username` 一定要撈進來 ——
     * 漏了這個欄位，通知就只剩純文字名字，需求方要的 tag 不會出現。
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->select(['id', 'account', 'nickname', 'telegram_username']);
    }

    /**
     * 靜置的起算時間
     *
     * ⚠ 取 `max(關閉時間, 群組最後一則訊息時間)`，不是只看後者 ——
     * 客服常是在客人安靜一陣子之後才關的，那時 `last_message_at` 比 `off_at`
     * 還早，只看它會讓這筆一關掉就立刻達標、下一分鐘就被恢復，
     * 人工處理還沒開始就結束了。
     *
     * ⚠ 關聯沒載入時只用 `off_at`（不回頭查 DB）：這支會在排程的迴圈裡
     * 逐筆呼叫，偷偷查一次就是 N+1。呼叫端負責 eager load。
     *
     * @return Carbon
     */
    public function quietSince(): Carbon
    {
        $lastMessageAt = $this->relationLoaded('group') && filled($this->group)
            ? $this->group->last_message_at
            : null;

        if (blank($lastMessageAt)) {
            return $this->off_at;
        }

        return $lastMessageAt->greaterThan($this->off_at) ? $lastMessageAt : $this->off_at;
    }

    /**
     * 預計恢復時間（聊天視窗顯示倒數用）
     *
     * @return Carbon|null 永久關閉或沒填分鐘數時為 null
     */
    public function resumeAt()
    {
        if (blank($this->quiet_minutes)) {
            return null;
        }

        return $this->quietSince()->copy()->addMinutes($this->quiet_minutes);
    }
}
