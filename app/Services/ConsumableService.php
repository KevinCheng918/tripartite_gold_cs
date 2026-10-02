<?php

namespace App\Services;

use App\Models\ConsumableItem;
use App\Models\ConsumableRecord;
use App\Repositories\ConsumableRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;

/**
 * 內勤消耗品
 *
 * 內勤領到的消耗品（卡片、SIM 卡…）進出與餘額。
 *
 * 流水只有兩個方向：**領用（進）** 與 **使用（出）**，沒有歸還 ——
 * 消耗品用掉就沒了。
 *
 * ⚠ **餘額不存欄位，一律算出來**（`SUM(領用) - SUM(使用)`）。存一份餘額就有
 * 兩份真相，補登或修改歷史紀錄時一定會不同步。
 *
 * ⚠ **登記與修改都要檢查餘額不能變負**，包含三種情況：
 *   1. 新增「使用」—— 用超過手上有的
 *   2. 修改任何一筆 —— 把領用改小、或把使用改大
 *   3. 刪除「領用」—— 刪掉之後剩下的使用紀錄就沒有來源了
 */
class ConsumableService
{
    private $repository;
    private $userRepository;

    public function __construct(ConsumableRepository $repository, UserRepository $userRepository)
    {
        $this->repository = $repository;
        $this->userRepository = $userRepository;
    }

    // ---------------------------------------------------------------
    //  總覽
    // ---------------------------------------------------------------

    /**
     * 每個人每個品項的餘額總覽
     *
     * 餘額、人名、品項名各查一次就全部湊好 —— **不要在迴圈裡查** ，
     * 那是 N+1。資料量是「有紀錄的人 × 品項」，一次撈完很便宜。
     *
     * @param int|null $userId 只看某個人，null = 全部
     * @return array 每列：user_id, user_name, item_id, item_name, unit, issued, used, balance
     *
     * @phpstan-return array<int, array{user_id:int, user_name:string, item_id:int,
     *     item_name:string, unit:string|null, issued:int, used:int, balance:int}>
     */
    public function getOverview($userId = null)
    {
        $balances = $this->repository->getBalances($userId);

        if (blank($balances)) {
            return [];
        }

        /*
         * 用 getNamesByIds() 而不是 getMentionableByIds() —— 後者是為了
         * 「內部群組 tag 人」寫的（排除工程、只取正常狀態、必須有
         * telegram_username），拿來查人名會漏掉一整批人。
         */
        $users = $this->userRepository
            ->getNamesByIds($balances->pluck('user_id')->unique()->all())
            ->keyBy('id');

        $items = $this->repository->getAllItems()->keyBy('id');

        $rows = [];

        foreach ($balances as $balance) {
            $user = Arr::get($users, (int) $balance->user_id);
            $item = Arr::get($items, (int) $balance->consumable_item_id);

            $issued = (int) $balance->issued;
            $used = (int) $balance->used;

            $rows[] = [
                'user_id'   => (int) $balance->user_id,
                // 帳號被刪時流水也會跟著刪，所以理論上一定找得到人
                'user_name' => filled($user) ? $user->nickname : '—',
                'item_id'   => (int) $balance->consumable_item_id,
                'item_name' => filled($item) ? $item->name : '—',
                'unit'      => filled($item) ? $item->unit : null,
                'issued'    => $issued,
                'used'      => $used,
                'balance'   => $issued - $used,
            ];
        }

        return $rows;
    }

    /**
     * 流水清單
     *
     * @param array $criteria user_id / consumable_item_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function listRecords($criteria = [])
    {
        return $this->repository->getRecords($criteria);
    }

    // ---------------------------------------------------------------
    //  流水的增刪改
    // ---------------------------------------------------------------

    /**
     * 登記一筆領用或使用
     *
     * @param array $params user_id, consumable_item_id, type, quantity, happened_at, purpose, note
     * @param int   $operatorId 登記的人
     * @return ConsumableRecord
     * @throws \RuntimeException 品項停用、或餘額不足
     */
    public function createRecord($params, $operatorId)
    {
        $itemId = (int) Arr::get($params, 'consumable_item_id');
        $this->assertItemUsable($itemId);

        $userId = (int) Arr::get($params, 'user_id');
        $type = (int) Arr::get($params, 'type');
        $quantity = (int) Arr::get($params, 'quantity');

        // 只有「使用」會讓餘額減少，領用不必檢查
        if ($type === (int) config('constants.CONSUMABLE.TYPE.USE')) {
            $this->assertEnough($userId, $itemId, $quantity);
        }

        return $this->repository->createRecord([
            'user_id'            => $userId,
            'consumable_item_id' => $itemId,
            'type'               => $type,
            'quantity'           => $quantity,
            'happened_at'        => Arr::get($params, 'happened_at'),
            'purpose'            => Arr::get($params, 'purpose'),
            'note'               => Arr::get($params, 'note'),
            'created_by'         => $operatorId,
        ]);
    }

    /**
     * 修改一筆流水
     *
     * ⚠ 檢查時要**把這一筆排除掉**再算餘額，否則舊值會被重複計入 ——
     * 例如把使用 3 張改成 5 張，若不排除就會拿「已經扣掉 3 張」的餘額去比 5 張。
     *
     * @param ConsumableRecord $record
     * @param array            $params
     * @return ConsumableRecord
     * @throws \RuntimeException 餘額會變負
     */
    public function updateRecord(ConsumableRecord $record, $params)
    {
        $type = (int) Arr::get($params, 'type', $record->type);
        $quantity = (int) Arr::get($params, 'quantity', $record->quantity);
        $itemId = (int) Arr::get($params, 'consumable_item_id', $record->consumable_item_id);
        $userId = (int) Arr::get($params, 'user_id', $record->user_id);

        // 換品項時，新品項也要是能用的
        if ($itemId !== (int) $record->consumable_item_id) {
            $this->assertItemUsable($itemId);
        }

        $without = $this->repository->getBalance($userId, $itemId, $record->id);
        $after = $type === (int) config('constants.CONSUMABLE.TYPE.ISSUE')
            ? $without + $quantity
            : $without - $quantity;

        if ($after < 0) {
            throw new \RuntimeException(trans('staff_manage.msg.consumable_not_enough', [
                'balance' => $without,
            ]));
        }

        return $this->repository->updateRecord($record, [
            'user_id'            => $userId,
            'consumable_item_id' => $itemId,
            'type'               => $type,
            'quantity'           => $quantity,
            'happened_at'        => Arr::get($params, 'happened_at', $record->happened_at),
            'purpose'            => Arr::get($params, 'purpose'),
            'note'               => Arr::get($params, 'note'),
        ]);
    }

    /**
     * 刪除一筆流水
     *
     * 刪「領用」會讓餘額變少 —— 剩下的使用紀錄可能就沒有來源了，要擋。
     * 刪「使用」只會讓餘額變多，不必檢查。
     *
     * @param ConsumableRecord $record
     * @return void
     * @throws \RuntimeException 刪掉會讓餘額變負
     */
    public function deleteRecord(ConsumableRecord $record)
    {
        if ($record->isIssue()) {
            $after = $this->repository->getBalance(
                (int) $record->user_id,
                (int) $record->consumable_item_id,
                $record->id
            );

            if ($after < 0) {
                throw new \RuntimeException(trans('staff_manage.msg.consumable_delete_blocked', [
                    'quantity' => abs($after),
                ]));
            }
        }

        $this->repository->deleteRecord($record);
    }

    // ---------------------------------------------------------------
    //  品項
    // ---------------------------------------------------------------

    /**
     * @param bool $activeOnly 只要啟用中的（登記用的下拉）
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function listItems($activeOnly = false)
    {
        return $activeOnly ? $this->repository->getActiveItems() : $this->repository->getAllItems();
    }

    /**
     * @param array $params
     * @return ConsumableItem
     */
    public function createItem($params)
    {
        return $this->repository->createItem([
            'name'       => Arr::get($params, 'name'),
            'unit'       => Arr::get($params, 'unit'),
            'status'     => (int) Arr::get($params, 'status', config('constants.CONSUMABLE.STATUS.ACTIVE')),
            'sort_order' => (int) Arr::get($params, 'sort_order', 0),
            'note'       => Arr::get($params, 'note'),
        ]);
    }

    /**
     * @param ConsumableItem $item
     * @param array          $params
     * @return ConsumableItem
     */
    public function updateItem(ConsumableItem $item, $params)
    {
        return $this->repository->updateItem($item, [
            'name'       => Arr::get($params, 'name', $item->name),
            'unit'       => Arr::get($params, 'unit'),
            'status'     => (int) Arr::get($params, 'status', $item->status),
            'sort_order' => (int) Arr::get($params, 'sort_order', $item->sort_order),
            'note'       => Arr::get($params, 'note'),
        ]);
    }

    /**
     * 刪除品項
     *
     * 有流水就不准刪 —— 刪了之後那些紀錄會變成「不知道是什麼東西的 10 張」。
     * 外鍵是 restrictOnDelete，這裡先擋是為了給得出人看得懂的訊息。
     *
     * @param ConsumableItem $item
     * @return void
     * @throws \RuntimeException 這個品項已經有紀錄
     */
    public function deleteItem(ConsumableItem $item)
    {
        if ($this->repository->itemHasRecords($item->id)) {
            throw new \RuntimeException(trans('staff_manage.msg.consumable_item_in_use'));
        }

        $this->repository->deleteItem($item);
    }

    // ---------------------------------------------------------------
    //  檢查
    // ---------------------------------------------------------------

    /**
     * 這個品項還能不能登記
     *
     * @param int $itemId
     * @return void
     * @throws \RuntimeException 品項不存在或已停用
     */
    private function assertItemUsable($itemId)
    {
        $item = $this->repository->findItem($itemId);

        if (blank($item)) {
            throw new \RuntimeException(trans('staff_manage.msg.consumable_item_not_found'));
        }

        if (!$item->isActive()) {
            throw new \RuntimeException(trans('staff_manage.msg.consumable_item_disabled'));
        }
    }

    /**
     * 手上夠不夠扣
     *
     * @param int $userId
     * @param int $itemId
     * @param int $quantity
     * @return void
     * @throws \RuntimeException 餘額不足
     */
    private function assertEnough($userId, $itemId, $quantity)
    {
        $balance = $this->repository->getBalance($userId, $itemId);

        if ($balance < $quantity) {
            throw new \RuntimeException(trans('staff_manage.msg.consumable_not_enough', [
                'balance' => $balance,
            ]));
        }
    }
}
