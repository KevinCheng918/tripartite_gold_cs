<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 繳款設定 Model
 *
 * @property int         $id
 * @property int         $system_id
 * @property string      $title       繳款方式名稱
 * @property string      $content     繳款資訊內容
 * @property string|null $template    文案模板
 * @property string|null $image       繳款圖片路徑
 * @property int         $status      1=啟用, 0=停用
 * @property int         $sort_order  排序
 */
class PaymentConfig extends Model
{
    use HasFactory;

    protected $table = 'payment_config';

    protected $guarded = ['id'];

    protected $casts = [
        'status'     => 'integer',
        'sort_order' => 'integer',
    ];

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class)->select(['id', 'name']);
    }
}
