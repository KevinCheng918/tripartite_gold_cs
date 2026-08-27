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
