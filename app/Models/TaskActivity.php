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
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->select(['id', 'nickname']);
    }

    /**
     * @return BelongsTo
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
