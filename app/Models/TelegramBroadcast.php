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
 * @property int         $status           1=已發送, 2=待發送, 3=已取消
 * @property string|null $scheduled_at     預約發送時間，null 表示立即發送
 * @property array|null  $image_urls       附帶圖片網址陣列
 * @property array|null  $file_urls        附件陣列 [{path, name}]
 * @property string|null $sent_at          發送時間
 */
class TelegramBroadcast extends Model
{
    /** @var int 全部群組 */
    public const TARGET_ALL = 1;

    /** @var int 指定群組 */
    public const TARGET_SELECTED = 2;

    /** @var int 已發送 */
    public const STATUS_SENT = 1;

    /** @var int 待發送（預約中） */
    public const STATUS_PENDING = 2;

    /** @var int 已取消 */
    public const STATUS_CANCELED = 3;

    protected $table = 'telegram_broadcast';
    protected $guarded = ['id'];

    protected $casts = [
        'target_type'      => 'integer',
        'target_group_ids' => 'array',
        'send_results'     => 'array',
        'image_urls'       => 'array',
        'file_urls'        => 'array',
        'status'           => 'integer',
        'total_count'      => 'integer',
        'success_count'    => 'integer',
        'fail_count'       => 'integer',
        'scheduled_at'     => 'datetime',
        'sent_at'          => 'datetime',
    ];

    /**
     * 預約中且尚未取消，才可以取消
     *
     * @return bool
     */
    public function isCancelable()
    {
        return $this->status === self::STATUS_PENDING;
    }

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
