<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\AssignPermissionRequest;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\AccountService;
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

    public function __construct(
        AccountService $accountService,
        PermissionMapService $permissionMapService
    ) {
        $this->accountService = $accountService;
        $this->permissionMapService = $permissionMapService;
    }

    /**
     * 帳號管理頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $accounts = $this->accountService->list([]);

        return view('admin.accounts.index', [
            'accounts' => $accounts,
        ]);
    }

    /**
     * Ajax 修改個人資訊（暱稱、密碼）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateProfile(Request $request)
    {
        $params = $request->validate([
            'nickname' => 'sometimes|string|max:100',
            'password' => 'sometimes|nullable|' . config('rules.USER_PASSWORD_REGEX'),
        ]);

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
        $permissionMap = $this->permissionMapService->getGroupedKeywordsWithLabels();
        $currentKeywords = $user->permissions()->pluck('permission_keyword')->all();

        return view('admin.accounts.permissions', [
            'targetUser'      => $user,
            'permissionMap'   => $permissionMap,
            'currentKeywords' => $currentKeywords,
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
        $params = $request->validated();

        try {
            $this->accountService->assignPermissions($user, $params['permissions'] ?? []);

            return new AccountResource($user->load('permissions'));
        } catch (\Exception $e) {
            Log::error('權限設定失敗', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            return response()->json(['message' => trans('account.msg.assign_failed')], 500);
        }
    }
}
