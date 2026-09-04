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
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $filename = time() . '_' . uniqid() . '_' . $safeName;

        Storage::disk(self::DISK)->putFileAs($dir, $file, $filename);

        return "{$dir}/{$filename}";
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
