<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * 共用圖片上傳 Service
 *
 * 統一管理圖片儲存路徑與 disk，所有功能模組的圖片上傳皆透過此 Service。
 */
class ImageUploadService
{
    /** @var string Storage disk */
    private const DISK = 'public';

    /** @var string 根目錄 */
    private const BASE_PATH = 'uploads';

    /**
     * 保留原始檔名時，清理後的檔名最多幾個 byte
     *
     * ⚠ 單位是 **byte 不是字數**：ext4 單一檔名上限 255 byte，而中文一個字
     * 在 UTF-8 佔 3 byte。留 200 是因為前面還要接 `time()_uniqid()_`
     * （25 byte），剩下的餘裕給檔案系統與未來的前綴變動。
     *
     * @var int
     */
    private const FILENAME_MAX_BYTES = 200;

    /**
     * 上傳單張圖片
     *
     * @param UploadedFile $file      上傳的檔案
     * @param string       $subFolder 子目錄（例如 'topup', 'telegram', 'broadcast'）
     * @return string 相對路徑（storage 下），前端顯示時用 asset("storage/{$path}") 組合完整 URL
     */
    public function upload(UploadedFile $file, $subFolder)
    {
        $dir = self::BASE_PATH . "/{$subFolder}";
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

        Storage::disk(self::DISK)->putFileAs($dir, $file, $filename);

        return "{$dir}/{$filename}";
    }

    /**
     * 上傳多張圖片
     *
     * @param UploadedFile[] $files     上傳的檔案陣列
     * @param string         $subFolder 子目錄
     * @return string[] 圖片 URL 陣列
     */
    public function uploadMultiple(array $files, $subFolder)
    {
        $urls = [];

        foreach ($files as $file) {
            $urls[] = $this->upload($file, $subFolder);
        }

        return $urls;
    }

    /**
     * 上傳附件並在檔名中保留原始名稱
     *
     * 圖片可以直接看縮圖，但 pdf、xlsx 這類附件必須顯示檔名，
     * upload() 的 uniqid 命名會讓人完全看不出那是什麼檔案。
     *
     * @param UploadedFile $file
     * @param string       $subFolder
     * @return string 相對路徑（storage 下）
     */
    public function uploadKeepName(UploadedFile $file, $subFolder)
    {
        $dir = self::BASE_PATH . "/{$subFolder}";
        $filename = time() . '_' . uniqid() . '_' . $this->safeFileName($file->getClientOriginalName());

        Storage::disk(self::DISK)->putFileAs($dir, $file, $filename);

        return "{$dir}/{$filename}";
    }

    /**
     * 把原始檔名清成「放得進檔案系統、又看得出是什麼檔案」的樣子
     *
     * ⚠ **`/u` 旗標不可以漏，而且字元類別要用 `\p{L}\p{N}` 不是 `a-zA-Z0-9`。**
     *
     * 舊版是 `preg_replace('/[^a-zA-Z0-9._-]/', '_', $name)` —— 沒有 `/u`，
     * `preg_replace` 就逐 **byte** 處理，而一個中文字在 UTF-8 是 3 個 byte，
     * 於是一個字變成三個底線：
     *
     *     營運報表.xlsx          → ____________.xlsx
     *     2026年10月對帳單.pdf   → 2026___10____________.pdf
     *
     * 數字與英文會活下來、中文全變底線，所以使用者看到的是「一串底線夾著
     * 看起來像日期的數字」（需求方 2026-10-30 回報）。
     *
     * ⚠ 換成 `\p{L}\p{N}` 之後路徑攻擊照樣擋得住：`/` 與 `\` 都不是文字或
     * 數字，一樣會被換成底線（`../../etc/passwd` → `.._.._etc_passwd`）。
     *
     * @param string $originalName
     * @return string
     */
    private function safeFileName($originalName)
    {
        $safe = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $originalName);

        // 短路：絕大多數檔名根本不長，不必走下面的截斷
        if (strlen($safe) <= self::FILENAME_MAX_BYTES) {
            return $safe;
        }

        /*
         * ⚠ 長度上限是這次放寬字元類別**才出現**的風險：ext4 單一檔名上限
         * 255 byte，中文一個字佔 3 byte —— 以前只留 ASCII 要 255 個字才會超過，
         * 現在 85 個中文字就滿了。超過的話 `putFileAs()` 直接丟例外，
         * 使用者得到的是「上傳失敗」而看不出原因是檔名太長。
         *
         * ⚠ 副檔名要留著（它決定開啟方式），所以只砍主檔名那一段。
         * 用 `strrpos`／`substr` 找點是安全的：UTF-8 的續接位元組一定 >= 0x80，
         * 不可能等於 `.`（0x2E），所以不會切在字元中間。
         */
        $dotAt = strrpos($safe, '.');
        $extension = $dotAt === false ? '' : substr($safe, $dotAt);
        $base = $dotAt === false ? $safe : substr($safe, 0, $dotAt);

        // 副檔名本身就長到塞不下時至少留一個字，不要讓長度變成負數
        $budget = max(1, self::FILENAME_MAX_BYTES - strlen($extension));

        /*
         * ⚠ 用 `mb_strcut()` 不是 `mb_substr()`：這裡要砍的是 **byte 數**
         * （檔案系統的上限是 byte），而 `mb_substr()` 數的是字元。
         * `mb_strcut()` 會自己避開切在多位元組字元中間。
         */
        return mb_strcut($base, 0, $budget, 'UTF-8') . $extension;
    }

    /**
     * 上傳多個附件（保留原始檔名）
     *
     * @param UploadedFile[] $files
     * @param string         $subFolder
     * @return string[]
     */
    public function uploadMultipleKeepName(array $files, $subFolder)
    {
        $paths = [];

        foreach ($files as $file) {
            $paths[] = $this->uploadKeepName($file, $subFolder);
        }

        return $paths;
    }
}
