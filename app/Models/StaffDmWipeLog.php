<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 私訊清除稽核 Model
 *
 * ⚠⚠ **這張表不能被清除功能自己清掉，也不要給它 TTL** ——
 * 可以被清掉的稽核等於沒有稽核（理由見 migration）。
 *
 * 詳見 .claude/knowledge/features/telegram-dm-wipe.md
 *
 * @property int         $id
 * @property int|null    $operator_user_id
 * @property string      $operator_name  名稱快照（帳號刪掉後還看得出是誰）
 * @property int|null    $target_user_id
 * @property string      $target_name    名稱快照
 * @property int         $source         見 constants.STAFF_DM_WIPE.SOURCE
 * @property int         $cleared_count  已清除（含同仁自己早就刪掉的）
 * @property int         $expired_count  超過 48 小時、永久刪不掉的
 * @property int         $failed_count   這次送出失敗（維持未處理，下一輪再試）
 * @property Carbon      $created_at
 */
class StaffDmWipeLog extends Model
{
    protected $table = 'staff_dm_wipe_log';

    public $timestamps = false;

    protected $fillable = [
        'operator_user_id',
        'operator_name',
        'target_user_id',
        'target_name',
        'source',
        'cleared_count',
        'expired_count',
        'failed_count',
    ];

    protected $casts = [
        'operator_user_id' => 'integer',
        'target_user_id'   => 'integer',
        'source'           => 'integer',
        'cleared_count'    => 'integer',
        'expired_count'    => 'integer',
        'failed_count'     => 'integer',
        'created_at'       => 'datetime',
    ];

    /**
     * 被清除的同仁（帳號刪掉後為 null，看 `target_name`）
     *
     * @return BelongsTo
     */
    public function target()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
