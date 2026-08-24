<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SharedFile;
use App\Models\SharedFolder;
use App\Models\User;
use App\Services\SharedFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 共用文件區控制器
 *
 * 共用文件需要 shared_file.view/upload/delete 權限
 * 個人文件所有登入用戶都可使用（自己的資料夾）
 */
class SharedFileController extends Controller
{
    private $sharedFileService;

    public function __construct(SharedFileService $sharedFileService)
    {
        $this->sharedFileService = $sharedFileService;
    }

    /**
     * 文件區頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $allUsers = [];
        if (Auth::user()->isAdmin()) {
            $allUsers = User::query()
                ->select(['id', 'nickname', 'account'])
                ->where('id', '!=', Auth::id())
                ->orderBy('nickname')
                ->get();
        }

        return view('admin.shared-file.index', [
            'allUsers' => $allUsers,
        ]);
    }

    /**
     * Ajax 取得資料夾 + 檔案列表
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxList(Request $request)
    {
        $params = $request->validate([
            'type'      => 'required|string|in:shared,personal',
            'user_id'   => 'nullable|integer',
            'folder_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        $type = $params['type'];

        // 共用文件需要權限
        if ($type === 'shared' && !$user->isAdmin() && !$user->hasPermission('shared_file.view')) {
            return response()->json(['folders' => [], 'files' => []]);
        }

        $targetUserId = $params['user_id'] ?? null;
        $folders = $this->sharedFileService->getFolders($type, $user->id, $user->isAdmin(), $targetUserId);

        $files = [];
        if (filled($params['folder_id'] ?? null)) {
            $files = $this->sharedFileService->getFiles((int) $params['folder_id']);
        }

        return response()->json([
            'folders' => $folders,
            'files'   => $files,
        ]);
    }

    /**
     * Ajax 新增資料夾
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreFolder(Request $request)
    {
        $params = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:shared,personal',
        ]);

        $user = Auth::user();

        // 共用文件夾需要上傳權限
        if ($params['type'] === 'shared' && !$user->isAdmin() && !$user->hasPermission('shared_file.upload')) {
            return response()->json(['message' => '無權限'], 403);
        }

        try {
            $folder = $this->sharedFileService->createFolder($params, $user->id);

            return response()->json(['message' => '資料夾已建立', 'folder' => $folder]);
        } catch (\Exception $e) {
            Log::error('資料夾建立失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '建立失敗'], 500);
        }
    }

    /**
     * Ajax 上傳檔案
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpload(Request $request)
    {
        $request->validate([
            'folder_id' => 'required|integer|exists:shared_folder,id',
            'file'      => 'required|file|max:20480',
        ]);

        $user = Auth::user();
        $folder = SharedFolder::query()->find((int) $request->input('folder_id'));

        if (!$folder) {
            return response()->json(['message' => '資料夾不存在'], 404);
        }

        // 共用文件夾需要上傳權限，個人文件夾需要是自己的
        if ($folder->type === 'shared' && !$user->isAdmin() && !$user->hasPermission('shared_file.upload')) {
            return response()->json(['message' => '無權限'], 403);
        }
        if ($folder->type === 'personal' && !$user->isAdmin() && (int) $folder->user_id !== $user->id) {
            return response()->json(['message' => '無權限'], 403);
        }

        try {
            $file = $this->sharedFileService->uploadFile($folder->id, $request->file('file'), $user->id);

            return response()->json(['message' => '檔案已上傳', 'file' => $file]);
        } catch (\Exception $e) {
            Log::error('檔案上傳失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '上傳失敗'], 500);
        }
    }

    /**
     * Ajax 刪除檔案
     *
     * @param SharedFile $file
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteFile(SharedFile $file)
    {
        $user = Auth::user();
        $folder = $file->folder;

        // 共用：需 delete 權限；個人：自己的或管理者
        if ($folder && $folder->type === 'shared' && !$user->isAdmin() && !$user->hasPermission('shared_file.delete')) {
            return response()->json(['message' => '無權限'], 403);
        }
        if ($folder && $folder->type === 'personal' && !$user->isAdmin() && (int) $folder->user_id !== $user->id) {
            return response()->json(['message' => '無權限'], 403);
        }

        try {
            $this->sharedFileService->deleteFile($file->id);

            return response()->json(['message' => '檔案已刪除']);
        } catch (\Exception $e) {
            Log::error('檔案刪除失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '刪除失敗'], 500);
        }
    }

    /**
     * Ajax 刪除資料夾
     *
     * @param SharedFolder $folder
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteFolder(SharedFolder $folder)
    {
        $user = Auth::user();

        // 共用：需 delete 權限；個人：自己的或管理者
        if ($folder->type === 'shared' && !$user->isAdmin() && !$user->hasPermission('shared_file.delete')) {
            return response()->json(['message' => '無權限'], 403);
        }
        if ($folder->type === 'personal' && !$user->isAdmin() && (int) $folder->user_id !== $user->id) {
            return response()->json(['message' => '無權限'], 403);
        }

        try {
            $this->sharedFileService->deleteFolder($folder->id);

            return response()->json(['message' => '資料夾已刪除']);
        } catch (\Exception $e) {
            Log::error('資料夾刪除失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '刪除失敗'], 500);
        }
    }
}
