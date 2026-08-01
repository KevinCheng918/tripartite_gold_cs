<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 帳號權限 Model
 *
 * 權限直接綁定帳號（不經過角色），
 * 每筆紀錄代表一個帳號擁有的一個權限 keyword。
 *
 * @property int    $id
 * @property int    $user_id             帳號 ID
 * @property string $permission_keyword  權限 keyword
 */
class UserPermission extends Model
{
    protected $guarded = ['id'];

    /**
     * 所屬帳號
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
