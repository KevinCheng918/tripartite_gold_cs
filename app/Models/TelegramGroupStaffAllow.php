<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 「這個對話特別放行哪個同事」Model
 *
 * 內部員工預設在所有對話都不自動回覆；這張表是反向的例外。
 * **有列 = 特別打開，沒列 = 預設忽略**，所以沒有「是否啟用」之類的欄位。
 *
 * @property int         $id
 * @property int         $telegram_group_id 哪個對話
 * @property int         $user_id           哪個同事（後台帳號）
 * @property int|null    $allowed_by        誰打開的
 * @property string|null $allowed_at        什麼時候打開的
 */
class TelegramGroupStaffAllow extends Model
{
    protected $table = 'telegram_group_staff_allow';
    protected $guarded = ['id'];

    protected $casts = [
        'telegram_group_id' => 'integer',
        'user_id'           => 'integer',
        'allowed_by'        => 'integer',
        'allowed_at'        => 'datetime',
    ];

    /**
     * 打開的人
     *
     * 帳號被刪時 allowed_by 會變 null（nullOnDelete），UI 顯示「已離職人員」——
     * 與忽略名單的 ignoredBy() 同樣處理。
     *
     * @return BelongsTo
     */
    public function allowedBy()
    {
        return $this->belongsTo(User::class, 'allowed_by')->select(['id', 'account', 'nickname']);
    }

    /**
     * 被放行的同事
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'account', 'nickname']);
    }
}
