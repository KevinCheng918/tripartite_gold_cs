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
     * 取得所有啟用中的問答（自動回覆比對用）
     *
     * 這份資料會整份塞進 LLM 的 system prompt，所以：
     *   - 一定要帶 answer —— 答案裡常寫著標題沒提到的資訊（例如某題答案才提到 NordVPN）
     *   - 帶類別名稱，讓模型知道這題屬於哪個領域
     *   - 用 join 一次撈完，不要 with 之後再逐筆取類別
     *
     * 停用的類別底下的問答也要排除，否則會挑到不該用的答案。
     *
     * @return Collection
     */
    public function getActiveItemsForMatch()
    {
        return QuickReplyItem::query()
            ->select([
                'quick_reply_item.id',
                'quick_reply_item.label',
                'quick_reply_item.answer',
                'quick_reply_category.label as category_label',
            ])
            ->join('quick_reply_category', 'quick_reply_category.id', '=', 'quick_reply_item.category_id')
            ->where('quick_reply_item.status', config('constants.QUICK_REPLY.STATUS.ACTIVE'))
            ->where('quick_reply_category.status', config('constants.QUICK_REPLY.STATUS.ACTIVE'))
            ->orderBy('quick_reply_item.category_id')
            ->orderBy('quick_reply_item.sort')
            ->get();
    }

    /**
     * 取單筆啟用中的問答（自動回覆送出答案前取用）
     *
     * 只取啟用中的 —— 模型可能挑到剛被停用的題目，那種不該送給客戶。
     *
     * @param int $id
     * @return QuickReplyItem|null
     */
    public function findActiveItem($id)
    {
        return QuickReplyItem::query()
            ->select(self::ITEM_COLUMNS)
            ->where('status', config('constants.QUICK_REPLY.STATUS.ACTIVE'))
            ->find($id);
    }

    /**
     * 依 id 批次取啟用中的問答（反問的候選題目用）
     *
     * @param array $ids
     * @return Collection
     */
    public function getActiveItemsByIds($ids)
    {
        if (empty($ids)) {
            return new Collection();
        }

        return QuickReplyItem::query()
            ->select(self::ITEM_COLUMNS)
            ->whereIn('id', $ids)
            ->where('status', config('constants.QUICK_REPLY.STATUS.ACTIVE'))
            ->get();
    }

    /**
     * 取得啟用中的類別（支援群組「選類別」按鈕用）
     *
     * @return Collection
     */
    public function getActiveCategories()
    {
        return QuickReplyCategory::query()
            ->select(self::CATEGORY_COLUMNS)
            ->where('status', config('constants.QUICK_REPLY.STATUS.ACTIVE'))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * 依名稱找類別（取「待整理」類別用）
     *
     * @param string $label
     * @return QuickReplyCategory|null
     */
    public function findCategoryByLabel($label)
    {
        return QuickReplyCategory::query()
            ->select(self::CATEGORY_COLUMNS)
            ->where('label', $label)
            ->first();
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
