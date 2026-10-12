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
 * @property string      $account           登入帳號
 * @property string      $nickname          顯示暱稱
 * @property string      $password          密碼（Crypt::encrypt 加密）
 * @property int         $status            狀態（見 constants.USER.STATUS）
 * @property int         $level             身份（見 constants.USER.LEVEL）
 * @property string|null $telegram_nickname Telegram 對話署名，簽在客戶訊息結尾
 * @property string|null $telegram_username Telegram 帳號（不含 @），內部群組提醒時用來 tag 本人
 * @property int|null    $telegram_user_id  Telegram 使用者 ID，首次比對命中時自動回填（見 StaffIgnoreService）
 * @property int         $work_mode         出勤模式（見 constants.USER.WORK_MODE）
 * @property string|null $work_start_time   固定班的上班時間 HH:mm:ss
 * @property string|null $work_end_time     固定班的下班時間 HH:mm:ss，<= 上班時間代表跨日
 * @property string|null $work_days         固定班的上班日，逗號分隔的 1-7（1=週一）
 * @property bool        $show_in_shift     固定班是否顯示在排班紀錄
 * @property string|null $deleted_at        軟刪除時間
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
        'work_mode'     => 'integer',
        'show_in_shift' => 'boolean',
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
     * 是否為固定上下班時間（不吃排班）
     *
     * @return bool
     */
    public function isFixedWorkMode()
    {
        return $this->work_mode == config('constants.USER.WORK_MODE.FIXED');
    }

    /**
     * 固定班的上班日（1=週一 … 7=週日）
     *
     * ⚠ 回陣列而不是讓呼叫端自己 explode：空字串 explode 出來是 `['']`，
     * 每個呼叫端都得自己記得濾掉那一格。
     *
     * @return array<int>
     */
    public function workDayList()
    {
        if (blank($this->work_days)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $this->work_days))));
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
