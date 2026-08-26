<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\AssignPermissionRequest;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\AccountResource;
use App\Http\Resources\LoginLogResource;
use App\Models\User;
use App\Repositories\ProjectRepository;
use App\Services\AccountService;
use App\Services\LoginLogService;
use App\Services\PermissionMapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 帳號管理控制器
 *
 * 融合帳號管理與權限設定，管理者可建立客服帳號並直接指派權限。
 * Controller 僅負責接收 Request → 呼叫 Service → 回傳 Response，
 * 不處理商業邏輯，不直接呼叫 DB。
 */
class AccountController extends Controller
{
    private $accountService;
    private $permissionMapService;
    private $projectRepository;
    private $loginLogService;

    public function __construct(
        AccountService $accountService,
        PermissionMapService $permissionMapService,
        ProjectRepository $projectRepository,
        LoginLogService $loginLogService
    ) {
        $this->accountService = $accountService;
        $this->permissionMapService = $permissionMapService;
        $this->projectRepository = $projectRepository;
        $this->loginLogService = $loginLogService;
    }

    /**
     * 帳號管理頁面
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $params = $request->only(['account', 'nickname', 'status', 'level', 'per_page']);
        $operator = Auth::user();
        $params['exclude_id'] = $operator->id;
        if (!$operator->isAdmin()) {
            $params['min_level'] = $operator->level + 1;
        }
        $accounts = $this->accountService->list($params);
        $accountStats = $this->accountService->getStatusStats();

        $projects = $this->projectRepository->getActive();

        return view('admin.accounts.index', [
            'accounts'     => $accounts,
            'filters'      => $params,
            'accountStats' => $accountStats,
            'projects'     => $projects,
        ]);
    }

    /**
     * Ajax 修改個人資訊（暱稱、密碼）
     *
     * @param UpdateProfileRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateProfile(UpdateProfileRequest $request)
    {
        $params = $request->validated();

        try {
            $this->accountService->update(Auth::user(), $params);

            return response()->json(['message' => trans('profile.msg.update_success')]);
        } catch (\Exception $e) {
            Log::error('個人資訊修改失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('profile.msg.update_failed')], 500);
        }
    }

    /**
     * Ajax 取得帳號列表
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxList(Request $request)
    {
        $params = $request->only(['keyword', 'per_page']);
        $params['exclude_id'] = Auth::id();
        $operator = Auth::user();
        if (!$operator->isAdmin()) {
            $params['min_level'] = $operator->level + 1;
        }

        $accounts = $this->accountService->list($params);

        return AccountResource::collection($accounts);
    }

    /**
     * 權限設定頁面
     *
     * @param \App\Models\User $user
     * @return \Illuminate\View\View
     */
    public function permissionsPage(\App\Models\User $user)
    {
        $operator = Auth::user();

        // 不能編輯 level <= 自己的帳號（Admin 除外）
        if (!$operator->isAdmin() && $user->level <= $operator->level) {
            abort(403);
        }

        $permissionMap = $this->permissionMapService->getGroupedKeywordsWithLabels();
        $currentKeywords = $user->permissions()->pluck('permission_keyword')->all();
        $operatorKeywords = $operator->isAdmin() ? null : $operator->permissions()->pluck('permission_keyword')->all();

        return view('admin.accounts.permissions', [
            'targetUser'       => $user,
            'permissionMap'    => $permissionMap,
            'currentKeywords'  => $currentKeywords,
            'operatorKeywords' => $operatorKeywords,
        ]);
    }

    /**
     * Ajax 取得權限地圖（分組 + 翻譯後的 label）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxPermissionMap()
    {
        return response()->json($this->permissionMapService->getGroupedKeywordsWithLabels());
    }

    /**
     * Ajax 新增帳號
     *
     * @param StoreAccountRequest $request
     * @return \Illuminate\Http\JsonResponse|AccountResource
     */
    public function ajaxStore(StoreAccountRequest $request)
    {
        $params = $request->validated();

        try {
            $account = $this->accountService->create($params);

            return new AccountResource($account->load('permissions'));
        } catch (\Exception $e) {
            Log::error('帳號新增失敗', ['error' => $e->getMessage(), 'params' => $params]);

            return response()->json(['message' => trans('account.msg.create_failed')], 500);
        }
    }

    /**
     * Ajax 更新帳號
     *
     * @param UpdateAccountRequest $request
     * @param User                 $user
     * @return \Illuminate\Http\JsonResponse|AccountResource
     */
    public function ajaxUpdate(UpdateAccountRequest $request, User $user)
    {
        $params = $request->validated();

        try {
            $account = $this->accountService->update($user, $params);

            return new AccountResource($account->load('permissions'));
        } catch (\Exception $e) {
            Log::error('帳號更新失敗', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            return response()->json(['message' => trans('account.msg.update_failed')], 500);
        }
    }

    /**
     * Ajax 設定權限（checkbox 勾選的 keywords）
     *
     * @param AssignPermissionRequest $request
     * @param User                    $user
     * @return \Illuminate\Http\JsonResponse|AccountResource
     */
    public function ajaxAssignPermissions(AssignPermissionRequest $request, User $user)
    {
        $operator = Auth::user();

        // 不能編輯 level <= 自己的帳號（Admin 除外）
        if (!$operator->isAdmin() && $user->level <= $operator->level) {
            return response()->json(['message' => '無權限操作此帳號'], 403);
        }

        $params = $request->validated();
        $submitted = $params['permissions'] ?? [];

        if (!$operator->isAdmin()) {
            // 非 admin：只能操作自己有的權限，其餘保留目標用戶原值
            $operatorKeywords = $operator->permissions()->pluck('permission_keyword')->all();
            $targetCurrent = $user->permissions()->pluck('permission_keyword')->all();

            // 保留操作者無權操作的權限原值
            $preserved = array_filter($targetCurrent, function ($kw) use ($operatorKeywords) {
                return !in_array($kw, $operatorKeywords);
            });

            // 合併：操作者可控範圍的新值 + 保留的舊值
            $submitted = array_unique(array_merge($submitted, $preserved));
        }

        try {
            $this->accountService->assignPermissions($user, $submitted);

            return new AccountResource($user->load('permissions'));
        } catch (\Exception $e) {
            Log::error('權限設定失敗', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            return response()->json(['message' => trans('account.msg.assign_failed')], 500);
        }
    }

    /**
     * Ajax 取得指定帳號的登入紀錄
     *
     * @param Request $request
     * @param User    $user
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxLoginLog(Request $request, User $user)
    {
        $params = $request->only(['per_page']);
        $perPage = $params['per_page'] ?? 10;

        $logs = $this->loginLogService->listByUser($user->id, $perPage);

        return LoginLogResource::collection($logs);
    }
}
