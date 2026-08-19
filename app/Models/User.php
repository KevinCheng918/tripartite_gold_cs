<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 使用者 Model
 *
 * 對齊主系統 tripartite_gold 的命名慣例，表名為 `user`，
 * 以 account 作為登入帳號，密碼以 Crypt::encrypt 加密。
 * level 欄位區分身份（見 config/constants.php）。
 *
 * @property int         $id
 * @property string      $account    登入帳號
 * @property string      $nickname   顯示暱稱
 * @property string      $password   密碼（Crypt::encrypt 加密）
 * @property int         $status     狀態（見 constants.USER.STATUS）
 * @property int         $level      身份（見 constants.USER.LEVEL）
 * @property string|null $deleted_at 軟刪除時間
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'user';
    protected $guarded = ['id'];
    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'status'      => 'integer',
        'level'       => 'integer',
        'project_ids' => 'array',
        'hired_at'    => 'date',
        'resigned_at' => 'date',
        'equipments'  => 'array',
    ];

    /**
     * 密碼寫入時自動以 Crypt::encrypt 加密
     *
     * @param string $password
     */
    public function setPasswordAttribute($password)
    {
        $this->attributes['password'] = \Crypt::encrypt($password);
    }

    /**
     * 是否為管理者
     *
     * @return bool
     */
    public function isAdmin()
    {
        return $this->level == config('constants.USER.LEVEL.ADMIN');
    }

    /**
     * 是否為主管以上（Admin / Boss / Leader）
     *
     * @return bool
     */
    public function isLeaderUp()
    {
        return $this->level <= config('constants.USER.LEVEL.LEADER');
    }

    /**
     * 是否為客服
     *
     * @return bool
     */
    public function isCs()
    {
        return $this->level == config('constants.USER.LEVEL.CS');
    }

    /**
     * 是否正常狀態
     *
     * @return bool
     */
    public function isNormalStatus()
    {
        return $this->status == config('constants.USER.STATUS.NORMAL');
    }

    /**
     * 是否鎖定狀態
     *
     * @return bool
     */
    public function isLockStatus()
    {
        return $this->status == config('constants.USER.STATUS.LOCK');
    }

    /**
     * 該帳號擁有的權限 keywords
     *
     * @return HasMany
     */
    public function permissions()
    {
        return $this->hasMany(UserPermission::class);
    }

    /**
     * 檢查是否擁有指定權限
     * 管理者自動 bypass 所有權限檢查。
     *
     * @param string $keyword 權限 keyword
     * @return bool
     */
    public function hasPermission($keyword)
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->permissions()
            ->where('permission_keyword', $keyword)
            ->exists();
    }
}
