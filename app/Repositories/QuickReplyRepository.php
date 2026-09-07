<?php

namespace App\Repositories;

use App\Models\QuickReplyCategory;
use App\Models\QuickReplyItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * 快速回覆 Repository
 */
class QuickReplyRepository
{
    /** @var array 類別列表欄位 */
    private const CATEGORY_COLUMNS = ['id', 'label', 'sort', 'status'];

    /** @var array 問答列表欄位 */
    private const ITEM_COLUMNS = ['id', 'category_id', 'label', 'answer', 'sort', 'status'];

    /**
     * 取得全部類別（含所有問答，管理頁用）
     *
     * @return Collection
     */
    public function getAllWithItems()
    {
        return QuickReplyCategory::query()
            ->select(self::CATEGORY_COLUMNS)
            ->with(['items' => function ($query) {
                $query->select(self::ITEM_COLUMNS)->orderBy('sort')->orderBy('id');
            }])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * 取得啟用中的類別與問答（聊天視窗選單用）
     *
     * @return Collection
     */
    public function getActiveWithItems()
    {
        return QuickReplyCategory::query()
            ->select(self::CATEGORY_COLUMNS)
            ->with(['activeItems' => function ($query) {
                $query->select(self::ITEM_COLUMNS);
            }])
            ->where('status', config('constants.QUICK_REPLY.STATUS.ACTIVE'))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * 類別排序用的下一個序號
     *
     * @return int
     */
    public function nextCategorySort()
    {
        return (int) QuickReplyCategory::query()->max('sort') + 1;
    }

    /**
     * 指定類別下問答排序用的下一個序號
     *
     * @param int $categoryId
     * @return int
     */
    public function nextItemSort($categoryId)
    {
        return (int) QuickReplyItem::query()->where('category_id', $categoryId)->max('sort') + 1;
    }

    /**
     * 依 id 取多筆類別（拖曳重排用）
     *
     * @param array $ids
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCategoriesByIds($ids)
    {
        return QuickReplyCategory::query()
            ->select(self::CATEGORY_COLUMNS)
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * 依 id 取多筆問答（拖曳重排用）
     *
     * @param array $ids
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getItemsByIds($ids)
    {
        return QuickReplyItem::query()
            ->select(self::ITEM_COLUMNS)
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * @param array $attributes
     * @return QuickReplyCategory
     */
    public function createCategory($attributes)
    {
        return QuickReplyCategory::query()->create($attributes);
    }

    /**
     * @param QuickReplyCategory $category
     * @param array              $attributes
     * @return QuickReplyCategory
     */
    public function updateCategory(QuickReplyCategory $category, $attributes)
    {
        $category->update($attributes);

        return $category->refresh();
    }

    /**
     * @param QuickReplyCategory $category
     * @return void
     */
    public function deleteCategory(QuickReplyCategory $category)
    {
        $category->delete();
    }

    /**
     * 類別底下的問答數量（刪除前檢查用）
     *
     * @param int $categoryId
     * @return int
     */
    public function countItems($categoryId)
    {
        return QuickReplyItem::query()->where('category_id', $categoryId)->count();
    }

    /**
     * @param array $attributes
     * @return QuickReplyItem
     */
    public function createItem($attributes)
    {
        return QuickReplyItem::query()->create($attributes);
    }

    /**
     * @param QuickReplyItem $item
     * @param array          $attributes
     * @return QuickReplyItem
     */
    public function updateItem(QuickReplyItem $item, $attributes)
    {
        $item->update($attributes);

        return $item->refresh();
    }

    /**
     * @param QuickReplyItem $item
     * @return void
     */
    public function deleteItem(QuickReplyItem $item)
    {
        $item->delete();
    }
}
