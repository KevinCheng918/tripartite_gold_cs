<?php

namespace App\Services;

use App\Models\QuickReplyCategory;
use App\Models\QuickReplyItem;
use App\Repositories\QuickReplyRepository;
use Illuminate\Support\Facades\DB;

/**
 * 快速回覆題庫服務
 *
 * 題庫由客服在後台維護，聊天視窗的選單即時讀取 DB。
 */
class QuickReplyService
{
    private $quickReplyRepository;

    public function __construct(QuickReplyRepository $quickReplyRepository)
    {
        $this->quickReplyRepository = $quickReplyRepository;
    }

    /**
     * 聊天視窗選單格式：只回啟用中的類別與問答
     *
     * key 刻意加上 'c' / 'i' 前綴 —— JS 物件的純數字 key 會被自動依數值排序，
     * 會蓋掉我們的 sort 順序。
     *
     * @return array<string, array{label: string, items: array<int, array{key: string, label: string, answer: string}>}>
     */
    public function getForChat()
    {
        $categories = $this->quickReplyRepository->getActiveWithItems();
        $result = [];

        foreach ($categories as $category) {
            $items = [];
            foreach ($category->activeItems as $item) {
                $items[] = [
                    'key'    => "i{$item->id}",
                    'label'  => $item->label,
                    'answer' => $item->answer,
                ];
            }

            // 整個類別都沒有啟用中的問答就不顯示，避免點進去是空的
            if (!$items) {
                continue;
            }

            $result["c{$category->id}"] = [
                'label' => $category->label,
                'items' => $items,
            ];
        }

        return $result;
    }

    /**
     * 管理頁資料：含停用項目
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getForManage()
    {
        return $this->quickReplyRepository->getAllWithItems();
    }

    /**
     * @param array $params
     * @return QuickReplyCategory
     */
    public function createCategory($params)
    {
        return $this->quickReplyRepository->createCategory([
            'label'  => $params['label'],
            'status' => $params['status'] ?? config('constants.QUICK_REPLY.STATUS.ACTIVE'),
            'sort'   => $this->quickReplyRepository->nextCategorySort(),
        ]);
    }

    /**
     * @param QuickReplyCategory $category
     * @param array              $params
     * @return QuickReplyCategory
     */
    public function updateCategory(QuickReplyCategory $category, $params)
    {
        return $this->quickReplyRepository->updateCategory($category, $params);
    }

    /**
     * 刪除類別（底下還有問答時不允許，避免誤刪整批題目）
     *
     * @param QuickReplyCategory $category
     * @return bool 是否刪除成功
     */
    public function deleteCategory(QuickReplyCategory $category)
    {
        if ($this->quickReplyRepository->countItems($category->id) > 0) {
            return false;
        }

        $this->quickReplyRepository->deleteCategory($category);

        return true;
    }

    /**
     * @param array $params
     * @return QuickReplyItem
     */
    public function createItem($params)
    {
        return $this->quickReplyRepository->createItem([
            'category_id' => $params['category_id'],
            'label'       => $params['label'],
            'answer'      => $params['answer'],
            'status'      => $params['status'] ?? config('constants.QUICK_REPLY.STATUS.ACTIVE'),
            'sort'        => $this->quickReplyRepository->nextItemSort($params['category_id']),
        ]);
    }

    /**
     * @param QuickReplyItem $item
     * @param array          $params
     * @return QuickReplyItem
     */
    public function updateItem(QuickReplyItem $item, $params)
    {
        return $this->quickReplyRepository->updateItem($item, $params);
    }

    /**
     * @param QuickReplyItem $item
     * @return void
     */
    public function deleteItem(QuickReplyItem $item)
    {
        $this->quickReplyRepository->deleteItem($item);
    }

    /**
     * 依前端拖曳後的順序重排類別
     *
     * @param array $ids 由上到下的類別 id
     * @return void
     */
    public function reorderCategories($ids)
    {
        $this->applyOrder($this->quickReplyRepository->getCategoriesByIds($ids), $ids);
    }

    /**
     * 依前端拖曳後的順序重排問答
     *
     * @param array $ids 由上到下的問答 id
     * @return void
     */
    public function reorderItems($ids)
    {
        $this->applyOrder($this->quickReplyRepository->getItemsByIds($ids), $ids);
    }

    /**
     * 依 id 陣列的先後把 sort 重寫成 1..n
     *
     * 只更新查得到的資料 —— 前端送來的 id 若已被別人刪掉就跳過，
     * 不能因為一筆不存在就整批失敗。多筆寫入包在同一個交易內。
     *
     * @param \Illuminate\Support\Collection $records
     * @param array                          $ids
     * @return void
     */
    private function applyOrder($records, $ids)
    {
        $keyed = $records->keyBy('id');

        DB::transaction(function () use ($keyed, $ids) {
            $sort = 1;
            foreach ($ids as $id) {
                $record = $keyed->get($id);
                if (!filled($record)) {
                    continue;
                }

                $record->update(['sort' => $sort]);
                $sort++;
            }
        });
    }

}
