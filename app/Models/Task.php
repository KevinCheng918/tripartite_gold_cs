<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 任務 Model
 *
 * @property int         $id
 * @property int         $project_id
 * @property string      $title
 * @property string|null $description
 * @property int         $status      1=待處理, 2=進行中, 3=審核中, 4=已解決
 * @property int         $priority    1=低, 2=中, 3=高, 4=緊急
 * @property int|null    $assignee_id
 * @property int         $creator_id
 * @property string|null $due_date
 * @property int         $sort_order
 */
class Task extends Model
{
    protected $table = 'task';

    protected $guarded = ['id'];

    protected $casts = [
        'status'     => 'integer',
        'priority'   => 'integer',
        'due_date'   => 'date',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsTo
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class)->select(['id', 'system_id', 'name']);
    }

    /**
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->select(['id', 'name']);
    }

    /**
     * @return BelongsTo
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id')->select(['id', 'nickname']);
    }

    /**
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id')->select(['id', 'nickname']);
    }

    /**
     * @return HasMany
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderByDesc('created_at');
    }
}
