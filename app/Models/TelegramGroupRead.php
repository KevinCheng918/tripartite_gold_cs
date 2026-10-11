<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 「誰讀到哪」Model
 *
 * 每個客服在每個對話各一列，記最後一次點進去的時間。
 * 未讀＝這個時間之後客人又發的訊息。
 *
 * ⚠ 沒有這一列代表**從來沒點進去過**，那個對話的訊息全部算未讀。
 * 查詢端要用 LEFT JOIN 並處理 `last_read_at IS NULL`，不能 INNER JOIN。
 *
 * @property int    $id
 * @property int    $user_id           哪個客服
 * @property int    $telegram_group_id 哪個對話
 * @property string $last_read_at      最後一次點進去的時間
 */
class TelegramGroupRead extends Model
{
    protected $table = 'telegram_group_read';
    protected $guarded = ['id'];

    protected $casts = [
        'user_id'           => 'integer',
        'telegram_group_id' => 'integer',
        'last_read_at'      => 'datetime',
    ];
}
