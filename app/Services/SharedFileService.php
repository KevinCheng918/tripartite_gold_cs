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
     * 新增資料夾
     *
     * @param array $params
     * @param int   $userId
     * @return \App\Models\SharedFolder
     */
    public function createFolder($params, $userId)
    {
        return $this->repository->createFolder([
            'name'       => $params['name'],
            'type'       => $params['type'] ?? 'shared',
            'user_id'    => ($params['type'] ?? 'shared') === 'personal' ? $userId : null,
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
     * 刪除資料夾（含所有檔案）
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

        // 刪除資料夾下所有檔案的實體檔
        $files = $this->repository->getFilesByFolder($folderId);
        foreach ($files as $file) {
            Storage::disk('public')->delete($file->file_path);
        }

        $this->repository->deleteFolder($folder);
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
