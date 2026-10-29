<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 系統規章
 *
 * @property int         $id
 * @property int         $project_id
 * @property string      $title      規章標題，例如「三方規章」
 * @property string      $content    規章內容（純文字）
 * @property int         $status     1=啟用, 0=停用
 * @property int         $sort
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ProjectRegulation extends Model
{
    protected $table = 'project_regulation';

    protected $guarded = ['id'];

    /** @var int 啟用 */
    public const STATUS_ACTIVE = 1;

    /** @var int 停用 */
    public const STATUS_DISABLED = 0;

    protected $casts = [
        'project_id' => 'integer',
        'status'     => 'integer',
        'sort'       => 'integer',
    ];

    /**
     * 所屬專案
     *
     * ⚠ 只取 id 與 name —— 規章列表與小幫手都只需要專案名稱。
     *
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->select(['id', 'name']);
    }

    /**
     * 最後修改者
     *
     * @return BelongsTo
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->select(['id', 'nickname']);
    }

    /**
     * 這份規章還在用嗎
     *
     * @return bool
     */
    public function isActive()
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }
}
