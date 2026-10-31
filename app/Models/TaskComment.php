<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 任務留言 Model
 *
 * @property int                             $id
 * @property int                             $task_id
 * @property int                             $user_id
 * @property string                          $content
 * @property array                           $images
 * @property \Illuminate\Support\Carbon      $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TaskComment extends Model
{
    protected $table = 'task_comment';

    protected $guarded = ['id'];

    protected $casts = [
        'images' => 'array',
    ];

    /**
     * 留言者
     *
     * ⚠ `level` 與 `telegram_username` 的用途與 `TaskActivity::user()` 相同
     * （任務卡異動通知的「觸發者」要 `@mention`、管理者不標注）——
     * 留言也算觸發者，所以兩邊的 select 要一致，少一欄不會報錯只會安靜地
     * 標錯人。`TaskCommentResource` 只取 `nickname`，不會外洩。
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->select(['id', 'nickname', 'level', 'telegram_username']);
    }

    /**
     * @return BelongsTo
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
