<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Telegram 群發公告 Model
 *
 * @property int         $id
 * @property string      $content          公告內容
 * @property int         $target_type      1=全部群組, 2=指定群組
 * @property array|null  $target_group_ids 指定的群組 ID 陣列
 * @property int         $total_count      應發送群組數
 * @property int         $success_count    成功數
 * @property int         $fail_count       失敗數
 * @property int         $sender_id        發送者
 * @property string|null $sent_at          發送時間
 */
class TelegramBroadcast extends Model
{
    /** @var int 全部群組 */
    public const TARGET_ALL = 1;

    /** @var int 指定群組 */
    public const TARGET_SELECTED = 2;

    protected $table = 'telegram_broadcast';
    protected $guarded = ['id'];

    protected $casts = [
        'target_type'      => 'integer',
        'target_group_ids' => 'array',
        'send_results'     => 'array',
        'total_count'      => 'integer',
        'success_count'    => 'integer',
        'fail_count'       => 'integer',
        'sent_at'          => 'datetime',
    ];

    /**
     * 發送者
     *
     * @return BelongsTo
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id')->select(['id', 'account', 'nickname']);
    }
}
