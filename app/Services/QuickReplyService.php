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
     * 類別上下移：與相鄰的一筆交換 sort
     *
     * @param QuickReplyCategory $category
     * @param bool               $isUp
     * @return bool 是否有移動（已在頭尾時回 false）
     */
    public function moveCategory(QuickReplyCategory $category, $isUp)
    {
        $neighbor = $this->quickReplyRepository->findAdjacentCategory($category, $isUp);
        if (!filled($neighbor)) {
            return false;
        }

        $this->swapSort($category, $neighbor);

        return true;
    }

    /**
     * 問答上下移：與同類別中相鄰的一筆交換 sort
     *
     * @param QuickReplyItem $item
     * @param bool           $isUp
     * @return bool 是否有移動（已在頭尾時回 false）
     */
    public function moveItem(QuickReplyItem $item, $isUp)
    {
        $neighbor = $this->quickReplyRepository->findAdjacentItem($item, $isUp);
        if (!filled($neighbor)) {
            return false;
        }

        $this->swapSort($item, $neighbor);

        return true;
    }

    /**
     * 交換兩筆的 sort（兩張表通用，兩筆寫入需在同一個交易內）
     *
     * @param QuickReplyCategory|QuickReplyItem $a
     * @param QuickReplyCategory|QuickReplyItem $b
     * @return void
     */
    private function swapSort($a, $b)
    {
        DB::transaction(function () use ($a, $b) {
            $sortA = $a->sort;
            $a->update(['sort' => $b->sort]);
            $b->update(['sort' => $sortA]);
        });
    }
}
