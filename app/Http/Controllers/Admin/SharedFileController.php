<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SharedFile\MoveFileRequest;
use App\Http\Requests\SharedFile\UploadFileRequest;
use App\Models\SharedFile;
use App\Models\SharedFolder;
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
            $allUsers = $this->sharedFileService->getSelectableUsers(Auth::id());
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
            'name'      => 'required|string|max:100',
            'type'      => 'required|string|in:shared,personal',
            'parent_id' => 'nullable|integer|exists:shared_folder,id',
        ]);

        $user = Auth::user();

        // 共用文件夾需要上傳權限
        if ($params['type'] === 'shared' && !$user->isAdmin() && !$user->hasPermission('shared_file.upload')) {
            return response()->json(['message' => trans('shared_file.msg.no_permission')], 403);
        }

        try {
            $folder = $this->sharedFileService->createFolder($params, $user->id);

            return response()->json(['message' => trans('shared_file.msg.folder_created'), 'folder' => $folder]);
        } catch (\Exception $e) {
            Log::error('資料夾建立失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('shared_file.msg.create_failed')], 500);
        }
    }

    /**
     * Ajax 上傳檔案
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpload(UploadFileRequest $request)
    {
        $params = $request->validated();

        $user = Auth::user();
        $folder = $this->sharedFileService->findFolder((int) $params['folder_id']);

        if (!filled($folder)) {
            return response()->json(['message' => trans('shared_file.msg.folder_not_found')], 404);
        }

        if (!$this->canAccessFolder($user, $folder, 'shared_file.upload')) {
            return response()->json(['message' => trans('shared_file.msg.no_permission')], 403);
        }

        try {
            $file = $this->sharedFileService->uploadFile($folder->id, $request->file('file'), $user->id);

            return response()->json(['message' => trans('shared_file.msg.file_uploaded'), 'file' => $file]);
        } catch (\Exception $e) {
            Log::error('檔案上傳失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('shared_file.msg.upload_failed')], 500);
        }
    }

    /**
     * Ajax 刪除檔案
     *
     * @param SharedFile $file
     * @return \Illuminate\Http\JsonResponse
     */
    /**
     * Ajax 搬移檔案到其他資料夾
     *
     * @param MoveFileRequest $request
     * @param SharedFile      $file
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxMoveFile(MoveFileRequest $request, SharedFile $file)
    {
        $params = $request->validated();
        $user = Auth::user();
        $target = $this->sharedFileService->findFolder((int) $params['folder_id']);

        if (!filled($target)) {
            return response()->json(['message' => trans('shared_file.msg.folder_not_found')], 404);
        }

        // 來源與目標都要有權限。只檢查其中一邊的話，
        // 可以把別人的個人檔案搬到自己的資料夾，或把私人檔案丟進共用區
        if (!$this->canAccessFolder($user, $file->folder, 'shared_file.upload')
            || !$this->canAccessFolder($user, $target, 'shared_file.upload')) {
            return response()->json(['message' => trans('shared_file.msg.no_permission')], 403);
        }

        try {
            if (!$this->sharedFileService->moveFile($file->id, $target->id)) {
                return response()->json(['message' => trans('shared_file.msg.folder_not_found')], 404);
            }

            return response()->json(['message' => trans('shared_file.msg.file_moved')]);
        } catch (\Exception $e) {
            Log::error('檔案搬移失敗', ['error' => $e->getMessage(), 'file_id' => $file->id]);

            return response()->json(['message' => trans('shared_file.msg.move_failed')], 500);
        }
    }

    /**
     * 判斷使用者能不能對該資料夾做某個動作
     *
     * 共用區看權限、個人區看是不是自己的、管理者一律放行 ——
     * 上傳、搬移、刪除三處原本各寫一份幾乎相同的判斷，改一處會漏掉其他兩處。
     *
     * @param \App\Models\User              $user
     * @param \App\Models\SharedFolder|null $folder
     * @param string                        $permission 共用區所需的權限 keyword
     * @return bool
     */
    private function canAccessFolder($user, $folder, $permission)
    {
        if (!filled($folder)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($folder->type === 'shared') {
            return $user->hasPermission($permission);
        }

        return (int) $folder->user_id === $user->id;
    }

    public function ajaxDeleteFile(SharedFile $file)
    {
        $user = Auth::user();
        $folder = $file->folder;

        // 原本寫成 `$folder && ...`，資料夾取不到時反而會直接放行；
        // folder_id 是 NOT NULL + FK，正常不會發生，但要擋而不是放
        if (!$this->canAccessFolder($user, $folder, 'shared_file.delete')) {
            return response()->json(['message' => trans('shared_file.msg.no_permission')], 403);
        }

        try {
            $this->sharedFileService->deleteFile($file->id);

            return response()->json(['message' => trans('shared_file.msg.file_deleted')]);
        } catch (\Exception $e) {
            Log::error('檔案刪除失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('shared_file.msg.delete_failed')], 500);
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

        if (!$this->canAccessFolder($user, $folder, 'shared_file.delete')) {
            return response()->json(['message' => trans('shared_file.msg.no_permission')], 403);
        }

        try {
            $this->sharedFileService->deleteFolder($folder->id);

            return response()->json(['message' => trans('shared_file.msg.folder_deleted')]);
        } catch (\Exception $e) {
            Log::error('資料夾刪除失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('shared_file.msg.delete_failed')], 500);
        }
    }
}
