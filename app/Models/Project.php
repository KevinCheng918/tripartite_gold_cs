<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 專案 Model
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property int         $status
 * @property int         $created_by
 */
class Project extends Model
{
    protected $table = 'project';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => 'integer',
    ];

    /**
     * @return HasMany
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'nickname']);
    }
}
