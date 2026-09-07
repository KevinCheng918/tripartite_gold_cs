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
            ->select(['id', 'name', 'parent_id', 'type', 'created_by', 'created_at'])
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
            ->select(['id', 'name', 'parent_id', 'type', 'user_id', 'created_by', 'created_at'])
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
        // 建子資料夾時要沿用父層的 type / user_id，這兩欄不能漏
        return SharedFolder::query()
            ->select(['id', 'name', 'parent_id', 'type', 'user_id', 'created_by'])
            ->find($id);
    }

    /**
     * 取得某資料夾的所有後代 id（不含自己）
     *
     * 逐層往下查而非遞迴 SQL —— MySQL 5.7 沒有 CTE，
     * 資料夾層數不會太深，逐層查的次數可以接受。
     *
     * @param int $folderId
     * @return array
     */
    public function getDescendantIds($folderId)
    {
        $all = [];
        $currentLevel = [$folderId];

        while (!empty($currentLevel)) {
            $childIds = SharedFolder::query()
                ->select(['id'])
                ->whereIn('parent_id', $currentLevel)
                ->pluck('id')
                ->all();

            if (empty($childIds)) {
                break;
            }

            $all = array_merge($all, $childIds);
            $currentLevel = $childIds;
        }

        return $all;
    }

    /**
     * 取得多個資料夾底下的所有檔案（刪除資料夾時一併清實體檔用）
     *
     * @param array $folderIds
     * @return Collection
     */
    public function getFilesByFolders($folderIds)
    {
        return SharedFile::query()
            ->select(['id', 'folder_id', 'file_path'])
            ->whereIn('folder_id', $folderIds)
            ->get();
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
     * 依 id 批次刪除資料夾（刪整棵子樹用）
     *
     * @param array $ids
     * @return void
     */
    public function deleteFoldersByIds($ids)
    {
        SharedFolder::query()->whereIn('id', $ids)->delete();
    }

    /**
     * 查詢檔案（含資料夾）
     *
     * @param int $id
     * @return SharedFile|null
     */
    public function findFile($id)
    {
        // folder_id 是 with('folder') 的關聯鍵，漏了關聯會撈不到
        return SharedFile::query()
            ->select(['id', 'folder_id', 'original_name', 'file_path', 'file_size', 'mime_type', 'uploaded_by', 'created_at'])
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
        return [
            'shared'   => $this->mapFoldersForTelegram($this->getSharedFolders()),
            'personal' => $this->mapFoldersForTelegram($this->getPersonalFolders($userId)),
        ];
    }

    /**
     * 把資料夾整理成 Telegram 選檔用的結構
     *
     * folder_name 帶完整路徑（父 / 子）—— 有了子資料夾之後，
     * 只顯示末端名稱會讓不同層的同名資料夾分不出來。
     *
     * @param Collection $folders
     * @return array
     */
    private function mapFoldersForTelegram($folders)
    {
        $nameById = $folders->pluck('name', 'id')->all();
        $parentById = $folders->pluck('parent_id', 'id')->all();
        $result = [];

        foreach ($folders as $folder) {
            $files = $this->getFilesByFolder($folder->id);
            if ($files->isEmpty()) {
                continue;
            }

            $result[] = [
                'folder_id'   => $folder->id,
                'folder_name' => $this->buildFolderPath($folder->id, $nameById, $parentById),
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

    /**
     * 組出「父 / 子 / 孫」的完整路徑
     *
     * @param int   $folderId
     * @param array $nameById
     * @param array $parentById
     * @return string
     */
    private function buildFolderPath($folderId, $nameById, $parentById)
    {
        $parts = [];
        $currentId = $folderId;

        // 資料若因故成環，最多往上追 20 層就停，不讓迴圈跑不完
        for ($depth = 0; $depth < 20; $depth++) {
            if (!isset($nameById[$currentId])) {
                break;
            }

            array_unshift($parts, $nameById[$currentId]);
            $currentId = $parentById[$currentId] ?? null;

            if (!filled($currentId)) {
                break;
            }
        }

        return implode(' / ', $parts);
    }
}
