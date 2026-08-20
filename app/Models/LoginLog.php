<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 登入紀錄 Model
 *
 * @property int         $id
 * @property int|null    $user_id
 * @property string      $account
 * @property string      $ip
 * @property bool        $is_success
 * @property string|null $device
 * @property string|null $fail_reason
 * @property string      $created_at
 */
class LoginLog extends Model
{
    protected $table = 'login_log';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'account',
        'ip',
        'is_success',
        'device',
        'fail_reason',
    ];

    protected $casts = [
        'is_success' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * 關聯帳號
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
