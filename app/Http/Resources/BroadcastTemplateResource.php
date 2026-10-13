<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * 公告範本
 *
 * ⚠ 附件**只回 `{name, url}`，不回 `path`**。前端要的只有「叫什麼、長什麼樣」，
 * 送回來時給的是索引（見 `SendBroadcastRequest` 的註解）—— 路徑一律由後端
 * 自己從 DB 取，所以它根本不需要出現在回應裡。
 *
 * ⚠ **圖片與檔案在 DB 裡的形狀不一樣**，不要假設兩邊可以用同一套解讀：
 *
 * | 欄位 | 存什麼 | 誰決定的 |
 * |---|---|---|
 * | `image_urls` | 公開**網址字串** | `uploadImages()` —— `sendPhoto` 可以直接吃 URL |
 * | `file_urls`  | `[{path, name}]` | `uploadFiles()` —— `sendDocument` 要的是本地絕對路徑 |
 *
 * 所以下面要兩種都認得：字串就是現成的網址，陣列才需要組。
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
            // 既有資料可能還沒補編號，前端顯示「-」
            'code'       => $this->code,
            'title'      => $this->title,
            'content'    => $this->content,
            'image_urls' => $this->attachments($this->image_urls),
            'file_urls'  => $this->attachments($this->file_urls),
            'creator'    => $this->creator ? $this->creator->nickname : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i') : null,
        ];
    }

    /**
     * 轉成畫面要的 `[{name, url}]`
     *
     * 兩種來源都認：
     *
     * - **字串** → 已經是公開網址（`image_urls`），檔名從網址尾巴取
     * - **陣列** → `{path, name}`（`file_urls`），網址要自己組
     *
     * ⚠ **索引順序要原樣保留**（包括壞掉的那幾筆）—— 前端是用索引告訴後端
     * 「留哪幾個」，這裡跳過一筆就會整串對不上，刪掉 A 變成刪掉 B。
     *
     * @param mixed $items
     * @return array [{name, url}]
     */
    private function attachments($items)
    {
        $result = [];

        foreach ((array) $items as $item) {
            if (is_array($item)) {
                $path = Arr::get($item, 'path');

                $result[] = [
                    'name' => (string) Arr::get($item, 'name', '-'),
                    'url'  => filled($path) ? asset('storage/' . $path) : null,
                ];

                continue;
            }

            // 字串＝現成的網址。再包一次 asset() 會變成 /storage/http://… 這種死連結
            $result[] = [
                'name' => filled($item) ? basename(parse_url($item, PHP_URL_PATH)) : '-',
                'url'  => filled($item) ? $item : null,
            ];
        }

        return $result;
    }
}
