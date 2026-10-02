<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Consumable\StoreRecordRequest;
use App\Http\Requests\StaffManage\UpdateStaffRequest;
use App\Http\Resources\ConsumableRecordResource;
use App\Http\Resources\StaffResource;
use App\Models\ConsumableRecord;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\ConsumableService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 內部管理控制器（到職日、設備管理、消耗品）
 */
class StaffManageController extends Controller
{
    private $userRepository;
    private $accountService;
    private $consumableService;

    public function __construct(
        UserRepository $userRepository,
        AccountService $accountService,
        ConsumableService $consumableService
    ) {
        $this->userRepository = $userRepository;
        $this->accountService = $accountService;
        $this->consumableService = $consumableService;
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

            return response()->json(['message' => trans('staff_manage.msg.updated')]);
        } catch (\Exception $e) {
            Log::error('內部管理更新失敗', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            return response()->json(['message' => trans('staff_manage.msg.update_failed')], 500);
        }
    }

    // ---------------------------------------------------------------
    //  消耗品
    // ---------------------------------------------------------------

    /**
     * Ajax 消耗品總覽（每人每品項的領用／使用／剩餘）與品項建議清單
     *
     * 一次給完整資料，搜尋與篩選在前端做 —— 與這頁另外兩個分頁同一個模式。
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxConsumableOverview(Request $request)
    {
        $params = ['user_id' => $request->input('user_id')];

        return response()->json([
            'balances' => $this->consumableService->getOverview($this->scopedUserId(Arr::get($params, 'user_id'))),
            // 登記時的建議清單 —— 就是大家已經打過的名稱，不限制只能選這些
            'item_names' => $this->consumableService->listItemNames(),
            /*
             * 可以登記給誰。
             *
             * ⚠ **不能從 balances 推導** —— 那只有「已經有紀錄的人」，
             * 一筆紀錄都還沒有時下拉會是空的，第一筆就登記不了。
             */
            'users' => $this->consumableUsers(),
            // 前端要知道「我是誰」才能決定哪幾筆可以改（沒有管理權限時只能動自己的）
            'me'       => (int) Auth::id(),
            'can_edit' => Auth::user()->hasPermission('staff_manage.edit'),
            // 沒有這個權限就不顯示人員篩選 —— 反正後端也只會回他自己的
            'can_view_all' => $this->canViewAll(),
        ]);
    }

    /**
     * Ajax 流水清單
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxConsumableRecords(Request $request)
    {
        $params = [
            // 沒有 view_all 的人一律只拿得到自己的，不管他送什麼 user_id 上來
            'user_id'   => $this->scopedUserId($request->input('user_id')),
            'item_name' => $request->input('item_name'),
        ];

        return ConsumableRecordResource::collection($this->consumableService->listRecords($params));
    }

    /**
     * Ajax 登記領用或使用
     *
     * @param StoreRecordRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxConsumableStore(StoreRecordRequest $request)
    {
        $params = $request->validated();

        if (!$this->canManage(Arr::get($params, 'user_id'))) {
            return response()->json(['message' => trans('staff_manage.msg.consumable_forbidden')], 403);
        }

        try {
            $this->consumableService->createRecord($params, (int) Auth::id());

            return response()->json(['message' => trans('staff_manage.msg.consumable_saved')]);
        } catch (\RuntimeException $e) {
            // 餘額不足 —— 使用者看得懂且能自己處理的狀況
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('消耗品登記失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('staff_manage.msg.consumable_failed')], 500);
        }
    }

    /**
     * Ajax 修改流水
     *
     * @param StoreRecordRequest $request
     * @param ConsumableRecord   $record
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxConsumableUpdate(StoreRecordRequest $request, ConsumableRecord $record)
    {
        $params = $request->validated();

        // 改到別人身上、或改別人的紀錄，都要有管理權限
        if (!$this->canManage($record->user_id) || !$this->canManage(Arr::get($params, 'user_id'))) {
            return response()->json(['message' => trans('staff_manage.msg.consumable_forbidden')], 403);
        }

        try {
            $this->consumableService->updateRecord($record, $params);

            return response()->json(['message' => trans('staff_manage.msg.consumable_saved')]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('消耗品修改失敗', ['error' => $e->getMessage(), 'record_id' => $record->id]);

            return response()->json(['message' => trans('staff_manage.msg.consumable_failed')], 500);
        }
    }

    /**
     * Ajax 刪除流水
     *
     * @param ConsumableRecord $record
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxConsumableDelete(ConsumableRecord $record)
    {
        if (!$this->canManage($record->user_id)) {
            return response()->json(['message' => trans('staff_manage.msg.consumable_forbidden')], 403);
        }

        try {
            $this->consumableService->deleteRecord($record);

            return response()->json(['message' => trans('staff_manage.msg.consumable_deleted')]);
        } catch (\RuntimeException $e) {
            // 刪掉會讓餘額變負
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('消耗品刪除失敗', ['error' => $e->getMessage(), 'record_id' => $record->id]);

            return response()->json(['message' => trans('staff_manage.msg.consumable_failed')], 500);
        }
    }

    /**
     * 這個登入者動得了這個人的消耗品嗎
     *
     * 本人隨時可以動自己的；要動別人的就得有 `staff_manage.edit`。
     *
     * 權限 middleware 只擋得住「有沒有資格進這個端點」，擋不住
     * 「送上來的 user_id 是不是自己」—— 那是這裡要判的。
     *
     * @param int|null $targetUserId
     * @return bool
     */
    private function canManage($targetUserId)
    {
        if ((int) $targetUserId === (int) Auth::id()) {
            return true;
        }

        return Auth::user()->hasPermission('staff_manage.edit');
    }

    /**
     * 消耗品可以登記給誰
     *
     * 重用「人員管理」分頁那一支（`level != ADMIN`）而不是另寫一個條件 ——
     * **「人員管理看得到誰」就該是「消耗品登記得了誰」**，條件只有一份，
     * 日後改「誰算內勤」只要改一個地方。
     *
     * 多撈了幾個用不到的欄位（到職日、設備），但內勤就幾十個人，
     * 比起讓同一個條件散落兩處划算得多。
     *
     * 沒有 `consumable_view_all` 的人只拿得到自己 —— 他本來也只能登記自己的。
     *
     * @return array 每筆：id, nickname
     */
    private function consumableUsers()
    {
        /*
         * ⚠ **ADMIN 一律排除，連登入者自己是 ADMIN 也不例外** —— 需求方明確
         * 要求「不能有 admin」。管理者不領消耗品，他只是幫別人登記。
         *
         * 看得到所有人時直接用「人員管理」那一支（`level != ADMIN`）；
         * 只看得到自己時，自己是 ADMIN 就回空清單。
         */
        if ($this->canViewAll()) {
            return $this->userRepository->getAllCsUsersWithDetail()
                ->map(function ($user) {
                    return ['id' => (int) $user->id, 'nickname' => $user->nickname];
                })->values()->all();
        }

        $me = $this->userRepository->getNamesByIds([(int) Auth::id()])->first();

        if (blank($me) || (int) $me->level === (int) config('constants.USER.LEVEL.ADMIN')) {
            return [];
        }

        return [['id' => (int) $me->id, 'nickname' => $me->nickname]];
    }

    /**
     * 看得到所有人的消耗品嗎
     *
     * @return bool
     */
    private function canViewAll()
    {
        return Auth::user()->hasPermission('staff_manage.consumable_view_all');
    }

    /**
     * 這次查詢實際要套用的 user_id
     *
     * ⚠ **範圍限制在後端強制套用，不是靠前端不顯示。** 沒有
     * `consumable_view_all` 的人，不管送什麼 `user_id` 上來，一律只拿得到
     * 自己的 —— 前端藏起來的東西，改個網址就看得到。
     *
     * @param int|null $requested 前端送來的篩選值
     * @return int|null null = 全部（只有看得到全部的人才拿得到 null）
     */
    private function scopedUserId($requested)
    {
        if (!$this->canViewAll()) {
            return (int) Auth::id();
        }

        return filled($requested) ? (int) $requested : null;
    }
}
