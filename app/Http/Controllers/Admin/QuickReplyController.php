<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuickReply\MoveRequest;
use App\Http\Requests\QuickReply\StoreCategoryRequest;
use App\Http\Requests\QuickReply\StoreItemRequest;
use App\Http\Requests\QuickReply\UpdateCategoryRequest;
use App\Http\Requests\QuickReply\UpdateItemRequest;
use App\Http\Resources\QuickReplyCategoryResource;
use App\Http\Resources\QuickReplyItemResource;
use App\Models\QuickReplyCategory;
use App\Models\QuickReplyItem;
use App\Services\QuickReplyService;
use Illuminate\Support\Facades\Log;

/**
 * 快速回覆題庫管理控制器
 *
 * 題庫供 Telegram 聊天視窗的快速回覆選單使用，由客服自行維護。
 */
class QuickReplyController extends Controller
{
    private $quickReplyService;

    public function __construct(QuickReplyService $quickReplyService)
    {
        $this->quickReplyService = $quickReplyService;
    }

    /**
     * 題庫管理頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.quick-reply.index');
    }

    /**
     * Ajax 取得題庫（含停用項目）
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxList()
    {
        $categories = $this->quickReplyService->getForManage();

        return QuickReplyCategoryResource::collection($categories);
    }

    /**
     * Ajax 新增類別
     *
     * @param StoreCategoryRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreCategory(StoreCategoryRequest $request)
    {
        $params = $request->validated();

        try {
            $category = $this->quickReplyService->createCategory($params);

            return response()->json([
                'message'  => trans('quick_reply.msg.category_created'),
                'category' => new QuickReplyCategoryResource($category),
            ]);
        } catch (\Exception $e) {
            Log::error('快速回覆類別新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('quick_reply.msg.category_create_failed')], 500);
        }
    }

    /**
     * Ajax 更新類別
     *
     * @param UpdateCategoryRequest $request
     * @param QuickReplyCategory    $category
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateCategory(UpdateCategoryRequest $request, QuickReplyCategory $category)
    {
        $params = $request->validated();

        try {
            $updated = $this->quickReplyService->updateCategory($category, $params);

            return response()->json([
                'message'  => trans('quick_reply.msg.category_updated'),
                'category' => new QuickReplyCategoryResource($updated),
            ]);
        } catch (\Exception $e) {
            Log::error('快速回覆類別更新失敗', ['error' => $e->getMessage(), 'category_id' => $category->id]);

            return response()->json(['message' => trans('quick_reply.msg.category_update_failed')], 500);
        }
    }

    /**
     * Ajax 刪除類別（底下還有問答時不允許）
     *
     * @param QuickReplyCategory $category
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteCategory(QuickReplyCategory $category)
    {
        try {
            if (!$this->quickReplyService->deleteCategory($category)) {
                return response()->json(['message' => trans('quick_reply.msg.category_has_items')], 422);
            }

            return response()->json(['message' => trans('quick_reply.msg.category_deleted')]);
        } catch (\Exception $e) {
            Log::error('快速回覆類別刪除失敗', ['error' => $e->getMessage(), 'category_id' => $category->id]);

            return response()->json(['message' => trans('quick_reply.msg.category_delete_failed')], 500);
        }
    }

    /**
     * Ajax 新增問答
     *
     * @param StoreItemRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreItem(StoreItemRequest $request)
    {
        $params = $request->validated();

        try {
            $item = $this->quickReplyService->createItem($params);

            return response()->json([
                'message' => trans('quick_reply.msg.item_created'),
                'item'    => new QuickReplyItemResource($item),
            ]);
        } catch (\Exception $e) {
            Log::error('快速回覆問答新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('quick_reply.msg.item_create_failed')], 500);
        }
    }

    /**
     * Ajax 更新問答
     *
     * @param UpdateItemRequest $request
     * @param QuickReplyItem    $item
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateItem(UpdateItemRequest $request, QuickReplyItem $item)
    {
        $params = $request->validated();

        try {
            $updated = $this->quickReplyService->updateItem($item, $params);

            return response()->json([
                'message' => trans('quick_reply.msg.item_updated'),
                'item'    => new QuickReplyItemResource($updated),
            ]);
        } catch (\Exception $e) {
            Log::error('快速回覆問答更新失敗', ['error' => $e->getMessage(), 'item_id' => $item->id]);

            return response()->json(['message' => trans('quick_reply.msg.item_update_failed')], 500);
        }
    }

    /**
     * Ajax 刪除問答
     *
     * @param QuickReplyItem $item
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteItem(QuickReplyItem $item)
    {
        try {
            $this->quickReplyService->deleteItem($item);

            return response()->json(['message' => trans('quick_reply.msg.item_deleted')]);
        } catch (\Exception $e) {
            Log::error('快速回覆問答刪除失敗', ['error' => $e->getMessage(), 'item_id' => $item->id]);

            return response()->json(['message' => trans('quick_reply.msg.item_delete_failed')], 500);
        }
    }

    /**
     * Ajax 類別上下移
     *
     * @param MoveRequest        $request
     * @param QuickReplyCategory $category
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxMoveCategory(MoveRequest $request, QuickReplyCategory $category)
    {
        try {
            $this->quickReplyService->moveCategory($category, $request->isUp());

            return response()->json(['message' => trans('quick_reply.msg.sorted')]);
        } catch (\Exception $e) {
            Log::error('快速回覆類別排序失敗', ['error' => $e->getMessage(), 'category_id' => $category->id]);

            return response()->json(['message' => trans('quick_reply.msg.sort_failed')], 500);
        }
    }

    /**
     * Ajax 問答上下移
     *
     * @param MoveRequest    $request
     * @param QuickReplyItem $item
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxMoveItem(MoveRequest $request, QuickReplyItem $item)
    {
        try {
            $this->quickReplyService->moveItem($item, $request->isUp());

            return response()->json(['message' => trans('quick_reply.msg.sorted')]);
        } catch (\Exception $e) {
            Log::error('快速回覆問答排序失敗', ['error' => $e->getMessage(), 'item_id' => $item->id]);

            return response()->json(['message' => trans('quick_reply.msg.sort_failed')], 500);
        }
    }
}
