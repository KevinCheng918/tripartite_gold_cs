<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 任務異動紀錄 Model
 *
 * @property int         $id
 * @property int         $task_id
 * @property int|null    $user_id
 * @property string      $action
 * @property array|null  $changes
 */
class TaskActivity extends Model
{
    protected $table = 'task_activity';

    protected $guarded = ['id'];

    protected $casts = [
        'changes' => 'array',
    ];

    /**
     * 這筆異動是誰做的
     *
     * ⚠ `level` 與 `telegram_username` 是 2026-11-01 加的：任務卡異動通知的
     * 「觸發者」要寫成 `@mention`，而**管理者不標注** ——
     * 少 `telegram_username` 會永遠退回暱稱、少 `level` 會把管理者也標注，
     * 兩種都不會報錯（見 `TaskChangeNoticeService::mentionOrName()`）。
     *
     * ⚠ 多撈這兩欄**不會外洩到前端**：`TaskActivityResource` 只取
     * `nickname`，沒有把整個 user 丟出去。
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
