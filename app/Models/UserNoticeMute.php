<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 「這個人關掉了哪些私訊」Model
 *
 * **有列 ＝ 關掉，沒列 ＝ 要收。** 所以沒有「是否啟用」之類的欄位。
 *
 * @property int    $id
 * @property int    $user_id    哪個同仁
 * @property string $notice_key 哪一種通知（對應 constants.NOTICE.PERSONAL）
 */
class UserNoticeMute extends Model
{
    protected $table = 'user_notice_mute';
    protected $guarded = ['id'];

    protected $casts = [
        'user_id' => 'integer',
    ];
}
