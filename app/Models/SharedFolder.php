<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 共用/個人資料夾 Model
 *
 * @property int         $id
 * @property string      $name
 * @property int|null    $parent_id 上層資料夾，null 表示最上層
 * @property string      $type      shared=共用, personal=個人
 * @property int|null    $user_id   個人文件區擁有者
 * @property int|null    $created_by
 */
class SharedFolder extends Model
{
    protected $table = 'shared_folder';

    protected $guarded = ['id'];

    /**
     * 上層資料夾
     *
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->select(['id', 'name', 'parent_id', 'type', 'user_id']);
    }

    /**
     * 直屬子資料夾
     *
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /**
     * @return HasMany
     */
    public function files(): HasMany
    {
        return $this->hasMany(SharedFile::class, 'folder_id')->orderByDesc('id');
    }

    /**
     * @return BelongsTo
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'nickname']);
    }

    /**
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'nickname']);
    }
}
