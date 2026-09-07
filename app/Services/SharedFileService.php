<?php

namespace App\Services;

use App\Repositories\SharedFileRepository;
use Illuminate\Support\Facades\Storage;

/**
 * 共用文件區 Service
 */
class SharedFileService
{
    private $repository;

    public function __construct(SharedFileRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * 取得資料夾列表
     *
     * @param string   $type     shared|personal
     * @param int      $userId
     * @param bool     $isAdmin
     * @param int|null $targetUserId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getFolders($type, $userId, $isAdmin = false, $targetUserId = null)
    {
        if ($type === 'personal') {
            return $this->repository->getPersonalFolders($userId, $isAdmin, $targetUserId);
        }

        return $this->repository->getSharedFolders();
    }

    /**
     * 取得資料夾內的檔案
     *
     * @param int $folderId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getFiles($folderId)
    {
        return $this->repository->getFilesByFolder($folderId);
    }

    /**
     * 新增資料夾（可指定上層成為子資料夾）
     *
     * 有指定上層時，type 與 user_id 一律沿用上層 ——
     * 否則會出現共用資料夾底下掛著個人資料夾這種矛盾的結構。
     *
     * @param array $params 含 name、type、parent_id
     * @param int   $userId
     * @return \App\Models\SharedFolder
     */
    public function createFolder($params, $userId)
    {
        $type = $params['type'] ?? 'shared';
        $parentId = $params['parent_id'] ?? null;
        $parent = filled($parentId) ? $this->repository->findFolder($parentId) : null;

        if (filled($parent)) {
            $type = $parent->type;
        }

        return $this->repository->createFolder([
            'name'       => $params['name'],
            'parent_id'  => filled($parent) ? $parent->id : null,
            'type'       => $type,
            'user_id'    => filled($parent) ? $parent->user_id : ($type === 'personal' ? $userId : null),
            'created_by' => $userId,
        ]);
    }

    /**
     * 上傳檔案
     *
     * @param int                                  $folderId
     * @param \Illuminate\Http\UploadedFile        $file
     * @param int                                  $userId
     * @return \App\Models\SharedFile
     */
    public function uploadFile($folderId, $file, $userId)
    {
        $originalName = $file->getClientOriginalName();
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('uploads/shared-files', $filename, 'public');

        return $this->repository->createFile([
            'folder_id'     => $folderId,
            'original_name' => $originalName,
            'file_path'     => $path,
            'file_size'     => $file->getSize(),
            'mime_type'     => $file->getMimeType(),
            'uploaded_by'   => $userId,
        ]);
    }

    /**
     * 刪除檔案
     *
     * @param int $fileId
     * @return void
     */
    public function deleteFile($fileId)
    {
        $file = $this->repository->findFile($fileId);
        if (!$file) {
            return;
        }

        Storage::disk('public')->delete($file->file_path);
        $this->repository->deleteFile($file);
    }

    /**
     * 刪除資料夾（含所有子資料夾與其中的檔案）
     *
     * 自己在 Service 遞迴刪，不依賴 DB 的 FK 串接 ——
     * MySQL 自我參照的 cascade 行為不可靠，而且實體檔本來就得自己清。
     *
     * @param int $folderId
     * @return void
     */
    public function deleteFolder($folderId)
    {
        $folder = $this->repository->findFolder($folderId);
        if (!$folder) {
            return;
        }

        // 自己 + 所有後代
        $folderIds = array_merge([$folder->id], $this->repository->getDescendantIds($folder->id));

        // 先刪實體檔：DB 刪掉後就查不到 file_path，硬碟上會留下孤兒檔
        $files = $this->repository->getFilesByFolders($folderIds);
        foreach ($files as $file) {
            Storage::disk('public')->delete($file->file_path);
        }

        // 由下往上刪，避免子資料夾的 parent_id 指向已消失的父層
        $this->repository->deleteFoldersByIds(array_reverse($folderIds));
    }

    /**
     * 統計刪除某資料夾會一併移除的子資料夾與檔案數
     *
     * 給刪除確認視窗顯示用 —— 子資料夾整棵砍掉是不可逆的，
     * 要讓人在按下去之前就知道會少掉什麼。
     *
     * @param int $folderId
     * @return array{folders: int, files: int}
     */
    public function getDeleteImpact($folderId)
    {
        $descendantIds = $this->repository->getDescendantIds($folderId);
        $folderIds = array_merge([$folderId], $descendantIds);

        return [
            'folders' => count($descendantIds),
            'files'   => $this->repository->getFilesByFolders($folderIds)->count(),
        ];
    }

    /**
     * 取得 Telegram 選檔用的檔案列表
     *
     * @param int $userId
     * @return array
     */
    public function getFilesForTelegram($userId)
    {
        return $this->repository->getFilesForTelegram($userId);
    }

    /**
     * 取得檔案的絕對路徑（sendDocument 用）
     *
     * @param int $fileId
     * @return string|null
     */
    public function getFileDiskPath($fileId)
    {
        $file = $this->repository->findFile($fileId);
        if (!$file) {
            return null;
        }

        return Storage::disk('public')->path($file->file_path);
    }

    /**
     * 取得檔案原始名稱
     *
     * @param int $fileId
     * @return string|null
     */
    public function getFileOriginalName($fileId)
    {
        $file = $this->repository->findFile($fileId);

        return $file ? $file->original_name : null;
    }
}
