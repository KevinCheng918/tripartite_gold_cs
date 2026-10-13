<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 公告範本 Model
 *
 * 事先建檔的公告，發送時選一則帶進表單。**帶進去之後還能改** ——
 * 這是範本不是固定公版，送出的是當下表單裡的東西。
 *
 * ⚠ `image_urls` / `file_urls` 跟 `TelegramBroadcast` 同形狀
 * （`[{path, name}]`），發送時整個陣列搬過去。
 *
 * @property int         $id
 * @property string|null $code       公告編號，人工輸入（既有資料可能是空的）
 * @property string      $title      範本標題（只在後台辨識用）
 * @property string      $content    公告內容
 * @property array|null  $image_urls 圖片 [{path, name}]
 * @property array|null  $file_urls  檔案 [{path, name}]
 * @property int|null    $created_by 建檔的人
 */
class BroadcastTemplate extends Model
{
    protected $table = 'broadcast_template';
    protected $guarded = ['id'];

    protected $casts = [
        'image_urls' => 'array',
        'file_urls'  => 'array',
        'created_by' => 'integer',
    ];

    /**
     * 建檔的人
     *
     * 帳號被刪時 `created_by` 會變 null（nullOnDelete），UI 顯示「—」。
     *
     * @return BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'account', 'nickname']);
    }
}
