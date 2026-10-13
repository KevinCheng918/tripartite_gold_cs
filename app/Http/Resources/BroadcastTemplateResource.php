<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * 公告範本
 *
 * ⚠ 附件回的是**相對路徑原樣**（`[{path, name}]`）—— 選了範本之後前端會把
 * 這個陣列原封不動送回 `ajax-send`，後端直接寫進 `telegram_broadcast`。
 * 在這裡轉成網址的話，送出時還得反推路徑（`sendDocument` 要的是本地絕對路徑）。
 *
 * 預覽縮圖另外用 `preview_urls` 給 —— 那是純顯示用，不回送。
 *
 * @property \App\Models\BroadcastTemplate $resource
 */
class BroadcastTemplateResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            // 既有資料可能還沒補編號，前端顯示「—」
            'code'       => $this->code,
            'title'      => $this->title,
            'content'    => $this->content,
            'image_urls' => $this->image_urls ?: [],
            'file_urls'  => $this->file_urls ?: [],

            // 列表要顯示縮圖，但回送時用的是上面那組原始路徑
            'preview_urls' => $this->previewUrls(),

            'creator'    => $this->creator ? $this->creator->nickname : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i') : null,
        ];
    }

    /**
     * 圖片的公開網址（純顯示）
     *
     * @return array
     */
    private function previewUrls()
    {
        $urls = [];

        foreach ((array) $this->image_urls as $image) {
            // 舊資料可能是純字串路徑，新的是 {path, name}
            $path = is_array($image) ? Arr::get($image, 'path') : $image;

            if (filled($path)) {
                $urls[] = asset('storage/' . $path);
            }
        }

        return $urls;
    }
}
