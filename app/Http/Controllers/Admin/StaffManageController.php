<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Consumable\StoreItemRequest;
use App\Http\Requests\Consumable\StoreRecordRequest;
use App\Http\Requests\StaffManage\UpdateStaffRequest;
use App\Http\Resources\ConsumableItemResource;
use App\Http\Resources\ConsumableRecordResource;
use App\Http\Resources\StaffResource;
use App\Models\ConsumableItem;
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
     * Ajax 消耗品總覽（每人每品項的領用／使用／剩餘）與品項清單
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
            'items'    => ConsumableItemResource::collection($this->consumableService->listItems()),
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
            'user_id'            => $this->scopedUserId($request->input('user_id')),
            'consumable_item_id' => $request->input('consumable_item_id'),
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
        $params = $request->params();

        if (!$this->canManage(Arr::get($params, 'user_id'))) {
            return response()->json(['message' => trans('staff_manage.msg.consumable_forbidden')], 403);
        }

        try {
            $this->consumableService->createRecord($params, (int) Auth::id());

            return response()->json(['message' => trans('staff_manage.msg.consumable_saved')]);
        } catch (\RuntimeException $e) {
            // 餘額不足、品項停用 —— 都是使用者看得懂且能自己處理的狀況
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
        $params = $request->params();

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
     * Ajax 新增品項
     *
     * @param StoreItemRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxConsumableItemStore(StoreItemRequest $request)
    {
        try {
            $this->consumableService->createItem($request->validated());

            return response()->json(['message' => trans('staff_manage.msg.consumable_item_saved')]);
        } catch (\Exception $e) {
            Log::error('消耗品品項新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('staff_manage.msg.consumable_failed')], 500);
        }
    }

    /**
     * Ajax 修改品項
     *
     * @param StoreItemRequest $request
     * @param ConsumableItem   $item
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxConsumableItemUpdate(StoreItemRequest $request, ConsumableItem $item)
    {
        try {
            $this->consumableService->updateItem($item, $request->validated());

            return response()->json(['message' => trans('staff_manage.msg.consumable_item_saved')]);
        } catch (\Exception $e) {
            Log::error('消耗品品項修改失敗', ['error' => $e->getMessage(), 'item_id' => $item->id]);

            return response()->json(['message' => trans('staff_manage.msg.consumable_failed')], 500);
        }
    }

    /**
     * Ajax 刪除品項
     *
     * @param ConsumableItem $item
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxConsumableItemDelete(ConsumableItem $item)
    {
        try {
            $this->consumableService->deleteItem($item);

            return response()->json(['message' => trans('staff_manage.msg.consumable_item_deleted')]);
        } catch (\RuntimeException $e) {
            // 已經有流水的品項不准刪，請改成停用
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('消耗品品項刪除失敗', ['error' => $e->getMessage(), 'item_id' => $item->id]);

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
