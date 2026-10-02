<?php

namespace App\Repositories;

use App\Models\ConsumableItem;
use App\Models\ConsumableRecord;
use Illuminate\Database\Eloquent\Collection;

/**
 * 消耗品 Repository
 *
 * 品項與流水的所有 DB 操作。餘額不存欄位，一律在這裡用 SUM 算出來。
 */
class ConsumableRepository
{
    /** @var array 流水欄位 */
    private const RECORD_COLUMNS = [
        'id', 'user_id', 'consumable_item_id', 'type', 'quantity',
        'happened_at', 'purpose', 'note', 'created_by', 'created_at',
    ];

    /** @var array 品項欄位 */
    private const ITEM_COLUMNS = ['id', 'name', 'unit', 'status', 'sort_order', 'note'];

    // ---------------------------------------------------------------
    //  品項
    // ---------------------------------------------------------------

    /**
     * 所有品項（含停用的）
     *
     * 管理頁要看得到停用的才改得回來；登記用的下拉另外走 getActiveItems()。
     *
     * @return Collection
     */
    public function getAllItems()
    {
        return ConsumableItem::query()
            ->select(self::ITEM_COLUMNS)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * 啟用中的品項（登記時的下拉用）
     *
     * @return Collection
     */
    public function getActiveItems()
    {
        return ConsumableItem::query()
            ->select(self::ITEM_COLUMNS)
            ->where('status', config('constants.CONSUMABLE.STATUS.ACTIVE'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param int $id
     * @return ConsumableItem|null
     */
    public function findItem($id)
    {
        return ConsumableItem::query()->select(self::ITEM_COLUMNS)->find($id);
    }

    /**
     * @param array $attributes
     * @return ConsumableItem
     */
    public function createItem($attributes)
    {
        return ConsumableItem::query()->create($attributes);
    }

    /**
     * @param ConsumableItem $item
     * @param array          $attributes
     * @return ConsumableItem
     */
    public function updateItem(ConsumableItem $item, $attributes)
    {
        $item->update($attributes);

        return $item->fresh();
    }

    /**
     * 這個品項有沒有流水
     *
     * 刪除前要擋 —— 有紀錄的品項刪掉之後，那些流水會變成
     * 「不知道是什麼東西的 10 張」。
     *
     * @param int $itemId
     * @return bool
     */
    public function itemHasRecords($itemId)
    {
        return ConsumableRecord::query()->where('consumable_item_id', (int) $itemId)->exists();
    }

    /**
     * @param ConsumableItem $item
     * @return bool
     */
    public function deleteItem(ConsumableItem $item)
    {
        return (bool) $item->delete();
    }

    // ---------------------------------------------------------------
    //  流水
    // ---------------------------------------------------------------

    /**
     * 每個人每個品項的餘額
     *
     * **一次查完所有人所有品項**，不要在迴圈裡一個一個算 —— 那是 N+1。
     *
     * 領用與使用在同一張表，用 `CASE WHEN` 在 SQL 裡分別加總，
     * 一次掃描就拿到進、出與淨餘額。
     *
     * @param int|null $userId 只看某個人，null = 全部
     * @return \Illuminate\Support\Collection 每列：user_id, consumable_item_id, issued, used
     */
    public function getBalances($userId = null)
    {
        $types = config('constants.CONSUMABLE.TYPE');

        $query = ConsumableRecord::query()
            ->selectRaw('user_id, consumable_item_id')
            ->selectRaw('SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) AS issued', [$types['ISSUE']])
            ->selectRaw('SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) AS used', [$types['USE']])
            ->groupBy('user_id', 'consumable_item_id');

        if (filled($userId)) {
            $query->where('user_id', (int) $userId);
        }

        return $query->get();
    }

    /**
     * 某個人某個品項的餘額（登記使用前的檢查用）
     *
     * @param int      $userId
     * @param int      $itemId
     * @param int|null $excludeRecordId 編輯既有紀錄時排除它自己，否則會重複計入
     * @return int
     */
    public function getBalance($userId, $itemId, $excludeRecordId = null)
    {
        $types = config('constants.CONSUMABLE.TYPE');

        $query = ConsumableRecord::query()
            ->where('user_id', (int) $userId)
            ->where('consumable_item_id', (int) $itemId);

        if (filled($excludeRecordId)) {
            $query->where('id', '!=', (int) $excludeRecordId);
        }

        $row = $query->selectRaw(
            'COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE -quantity END), 0) AS balance',
            [$types['ISSUE']]
        )->first();

        return (int) (filled($row) ? $row->balance : 0);
    }

    /**
     * 流水清單
     *
     * @param array $criteria user_id / consumable_item_id
     * @return Collection
     */
    public function getRecords($criteria = [])
    {
        $query = ConsumableRecord::query()
            ->select(self::RECORD_COLUMNS)
            ->with(['item', 'user', 'creator']);

        if (filled($criteria['user_id'] ?? null)) {
            $query->where('user_id', (int) $criteria['user_id']);
        }

        if (filled($criteria['consumable_item_id'] ?? null)) {
            $query->where('consumable_item_id', (int) $criteria['consumable_item_id']);
        }

        // 同一天可能好幾筆，第二層用 id 讓順序穩定
        return $query->orderByDesc('happened_at')->orderByDesc('id')->get();
    }

    /**
     * @param int $id
     * @return ConsumableRecord|null
     */
    public function findRecord($id)
    {
        return ConsumableRecord::query()->select(self::RECORD_COLUMNS)->find($id);
    }

    /**
     * @param array $attributes
     * @return ConsumableRecord
     */
    public function createRecord($attributes)
    {
        return ConsumableRecord::query()->create($attributes);
    }

    /**
     * @param ConsumableRecord $record
     * @param array            $attributes
     * @return ConsumableRecord
     */
    public function updateRecord(ConsumableRecord $record, $attributes)
    {
        $record->update($attributes);

        return $record->fresh();
    }

    /**
     * @param ConsumableRecord $record
     * @return bool
     */
    public function deleteRecord(ConsumableRecord $record)
    {
        return (bool) $record->delete();
    }

    /**
     * 有消耗品紀錄的人（給總覽列出人名用）
     *
     * 直接從流水撈 distinct user_id —— 比掃全部帳號再過濾便宜，
     * 而且沒有紀錄的人本來就不該出現在總覽上。
     *
     * @return array user_id 陣列
     */
    public function getUserIdsWithRecords()
    {
        return ConsumableRecord::query()
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }
}
