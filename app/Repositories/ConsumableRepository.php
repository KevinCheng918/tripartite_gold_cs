<?php

namespace App\Repositories;

use App\Models\ConsumableRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * 消耗品 Repository
 *
 * 只有一張流水表。餘額不存欄位，一律在這裡用 SUM 算出來，
 * 依「使用者 + 品項名稱」分組。
 */
class ConsumableRepository
{
    /** @var array 流水欄位 */
    private const COLUMNS = [
        'id', 'user_id', 'item_name', 'type', 'quantity',
        'happened_at', 'note', 'created_by', 'created_at',
    ];

    /**
     * 每個人每個品項的餘額
     *
     * **一次查完所有人所有品項**，不要在迴圈裡一個一個算 —— 那是 N+1。
     *
     * 領用與使用在同一張表，用 `CASE WHEN` 在 SQL 裡分別加總，
     * 一次掃描就拿到進與出。
     *
     * @param int|null $userId 只看某個人，null = 全部
     * @return \Illuminate\Support\Collection 每列：user_id, item_name, issued, used
     */
    public function getBalances($userId = null)
    {
        $types = config('constants.CONSUMABLE.TYPE');

        $query = ConsumableRecord::query()
            ->selectRaw('user_id, item_name')
            ->selectRaw('SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) AS issued', [$types['ISSUE']])
            ->selectRaw('SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) AS used', [$types['USE']])
            ->groupBy('user_id', 'item_name')
            ->orderBy('item_name');

        if (filled($userId)) {
            $query->where('user_id', (int) $userId);
        }

        return $query->get();
    }

    /**
     * 某個人某個品項的餘額（登記使用前的檢查用）
     *
     * @param int         $userId
     * @param string      $itemName
     * @param int|null    $excludeRecordId 編輯既有紀錄時排除它自己，否則會重複計入
     * @return int
     */
    public function getBalance($userId, $itemName, $excludeRecordId = null)
    {
        $types = config('constants.CONSUMABLE.TYPE');

        $query = ConsumableRecord::query()
            ->where('user_id', (int) $userId)
            ->where('item_name', $itemName);

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
     * @param array $criteria user_id / item_name
     * @return Collection
     */
    public function getRecords($criteria = [])
    {
        $query = ConsumableRecord::query()
            ->select(self::COLUMNS)
            ->with(['user', 'creator']);

        $userId = Arr::get($criteria, 'user_id');
        $itemName = Arr::get($criteria, 'item_name');

        if (filled($userId)) {
            $query->where('user_id', (int) $userId);
        }

        if (filled($itemName)) {
            $query->where('item_name', $itemName);
        }

        // 同一天可能好幾筆，第二層用 id 讓順序穩定
        return $query->orderByDesc('happened_at')->orderByDesc('id')->get();
    }

    /**
     * 已經用過的品項名稱（登記時的建議清單）
     *
     * 沒有品項表，所以建議清單就是「大家已經打過什麼」—— 一般情況
     * 點一下就好，不必每次重打，也不會限制他只能選這些。
     *
     * @return array
     */
    public function getItemNames()
    {
        return ConsumableRecord::query()
            ->select('item_name')
            ->distinct()
            ->orderBy('item_name')
            ->pluck('item_name')
            ->all();
    }

    /**
     * @param int $id
     * @return ConsumableRecord|null
     */
    public function findRecord($id)
    {
        return ConsumableRecord::query()->select(self::COLUMNS)->find($id);
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
}
