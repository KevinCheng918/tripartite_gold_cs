<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 共用/個人檔案 Model
 *
 * @property int         $id
 * @property int         $folder_id
 * @property string      $original_name
 * @property string      $file_path
 * @property int         $file_size
 * @property string|null $mime_type
 * @property int|null    $uploaded_by
 */
class SharedFile extends Model
{
    protected $table = 'shared_file';

    protected $guarded = ['id'];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * @return BelongsTo
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(SharedFolder::class, 'folder_id');
    }

    /**
     * @return BelongsTo
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->select(['id', 'nickname']);
    }
}
