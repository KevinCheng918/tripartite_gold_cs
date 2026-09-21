<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Telegram 群組成員 Model
 *
 * 同時是「這個對話裡誰發言過」的名冊與「誰不自動回覆」的名單。
 *
 * @property int         $id
 * @property int         $telegram_group_id 所屬對話
 * @property int|null    $telegram_user_id  Telegram 使用者 ID，發言過才有
 * @property string|null $username          Telegram username，小寫且不含 @
 * @property string|null $display_name      最近一次看到的顯示名稱
 * @property string|null $last_seen_at      最後發言時間，null=手動加入的
 * @property bool        $ignored           是否不自動回覆
 * @property int|null    $ignored_by        誰設的
 * @property string|null $ignored_at        什麼時候設的
 */
class TelegramGroupMember extends Model
{
    protected $table = 'telegram_group_member';
    protected $guarded = ['id'];

    protected $casts = [
        'telegram_group_id' => 'integer',
        'telegram_user_id'  => 'integer',
        'ignored'           => 'boolean',
        'last_seen_at'      => 'datetime',
        'ignored_at'        => 'datetime',
    ];

    /**
     * 這筆是手動輸入的嗎
     *
     * 手動加入的只有 username、沒有發言紀錄，所以 last_seen_at 是空的 ——
     * 用它就分辨得出來，不必另外開一個欄位存重複的資訊。
     *
     * @return bool
     */
    public function isManual()
    {
        return blank($this->last_seen_at);
    }

    /**
     * 設定忽略的人
     *
     * @return BelongsTo
     */
    public function ignoredBy()
    {
        return $this->belongsTo(User::class, 'ignored_by')->select(['id', 'account', 'nickname']);
    }

    /**
     * 所屬對話
     *
     * @return BelongsTo
     */
    public function group()
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }
}
