<?php

namespace App\Repositories;

use App\Models\SharedFile;
use App\Models\SharedFolder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 共用文件區 Repository
 */
class SharedFileRepository
{
    /**
     * 取得共用資料夾列表
     *
     * @return Collection
     */
    public function getSharedFolders()
    {
        return SharedFolder::query()
            ->select(['id', 'name', 'type', 'created_by', 'created_at'])
            ->with('creator')
            ->where('type', 'shared')
            ->orderBy('name')
            ->get();
    }

    /**
     * 取得個人資料夾列表
     *
     * @param int      $userId
     * @param bool     $isAdmin 管理者可看全部
     * @param int|null $targetUserId 管理者查看特定用戶
     * @return Collection
     */
    public function getPersonalFolders($userId, $isAdmin = false, $targetUserId = null)
    {
        $query = SharedFolder::query()
            ->select(['id', 'name', 'type', 'user_id', 'created_by', 'created_at'])
            ->with(['owner', 'creator'])
            ->where('type', 'personal');

        if ($isAdmin && filled($targetUserId)) {
            $query->where('user_id', (int) $targetUserId);
        } elseif (!$isAdmin) {
            $query->where('user_id', $userId);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * 取得資料夾內的檔案
     *
     * @param int $folderId
     * @return Collection
     */
    public function getFilesByFolder($folderId)
    {
        return SharedFile::query()
            ->select(['id', 'folder_id', 'original_name', 'file_path', 'file_size', 'mime_type', 'uploaded_by', 'created_at'])
            ->with('uploader')
            ->where('folder_id', $folderId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 查詢資料夾
     *
     * @param int $id
     * @return SharedFolder|null
     */
    public function findFolder($id)
    {
        return SharedFolder::query()->find($id);
    }

    /**
     * 新增資料夾
     *
     * @param array $attributes
     * @return SharedFolder
     */
    public function createFolder($attributes)
    {
        return SharedFolder::query()->create($attributes);
    }

    /**
     * 刪除資料夾（含所有檔案）
     *
     * @param SharedFolder $folder
     * @return void
     */
    public function deleteFolder(SharedFolder $folder)
    {
        $folder->delete();
    }

    /**
     * 查詢檔案（含資料夾）
     *
     * @param int $id
     * @return SharedFile|null
     */
    public function findFile($id)
    {
        return SharedFile::query()
            ->with('folder')
            ->find($id);
    }

    /**
     * 新增檔案
     *
     * @param array $attributes
     * @return SharedFile
     */
    public function createFile($attributes)
    {
        return SharedFile::query()->create($attributes);
    }

    /**
     * 刪除檔案
     *
     * @param SharedFile $file
     * @return void
     */
    public function deleteFile(SharedFile $file)
    {
        $file->delete();
    }

    /**
     * 取得所有共用 + 個人檔案（Telegram 選檔用）
     *
     * @param int $userId
     * @return array ['shared' => [...], 'personal' => [...]]
     */
    public function getFilesForTelegram($userId)
    {
        $sharedFolders = $this->getSharedFolders();
        $personalFolders = $this->getPersonalFolders($userId);

        $result = ['shared' => [], 'personal' => []];

        foreach ($sharedFolders as $folder) {
            $files = $this->getFilesByFolder($folder->id);
            if ($files->isEmpty()) {
                continue;
            }
            $result['shared'][] = [
                'folder_id'   => $folder->id,
                'folder_name' => $folder->name,
                'files'       => $files->map(function ($f) {
                    return [
                        'id'            => $f->id,
                        'original_name' => $f->original_name,
                        'file_size'     => $f->file_size,
                        'mime_type'     => $f->mime_type,
                    ];
                })->values()->all(),
            ];
        }

        foreach ($personalFolders as $folder) {
            $files = $this->getFilesByFolder($folder->id);
            if ($files->isEmpty()) {
                continue;
            }
            $result['personal'][] = [
                'folder_id'   => $folder->id,
                'folder_name' => $folder->name,
                'files'       => $files->map(function ($f) {
                    return [
                        'id'            => $f->id,
                        'original_name' => $f->original_name,
                        'file_size'     => $f->file_size,
                        'mime_type'     => $f->mime_type,
                    ];
                })->values()->all(),
            ];
        }

        return $result;
    }
}
