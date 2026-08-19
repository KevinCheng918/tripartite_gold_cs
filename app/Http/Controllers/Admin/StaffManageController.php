<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffManage\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use Illuminate\Support\Facades\Log;

/**
 * 內部管理控制器（到職日、設備管理）
 */
class StaffManageController extends Controller
{
    private $userRepository;
    private $accountService;

    public function __construct(UserRepository $userRepository, AccountService $accountService)
    {
        $this->userRepository = $userRepository;
        $this->accountService = $accountService;
    }

    /**
     * 內部管理頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $staffList = $this->userRepository->getAllCsUsers();

        return view('admin.staff-manage.index', [
            'staffList' => $staffList,
        ]);
    }

    /**
     * Ajax 取得員工列表（含到職日與設備）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxList()
    {
        $staffList = $this->userRepository->getAllCsUsersWithDetail();

        return StaffResource::collection($staffList);
    }

    /**
     * Ajax 更新員工到職日與設備
     *
     * @param UpdateStaffRequest $request
     * @param User               $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdate(UpdateStaffRequest $request, User $user)
    {
        $params = $request->validated();

        try {
            $this->accountService->update($user, $params);

            return response()->json(['message' => '已更新']);
        } catch (\Exception $e) {
            Log::error('內部管理更新失敗', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            return response()->json(['message' => '更新失敗'], 500);
        }
    }
}
