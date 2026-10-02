<?php

namespace App\Services;

use App\Models\ConsumableRecord;
use App\Repositories\ConsumableRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;

/**
 * 內勤消耗品
 *
 * 內勤領到的消耗品（卡片、SIM 卡…）進出與餘額。
 *
 * **品項直接打名稱，沒有品項表** —— 登記時想記什麼就記什麼，
 * 不必先去後台建一筆再回來。前端會把已經用過的名稱列成建議清單。
 *
 * 流水只有兩個方向：**領用（進）** 與 **使用（出）**，沒有歸還 ——
 * 消耗品用掉就沒了。
 *
 * ⚠ **餘額不存欄位，一律算出來**（`SUM(領用) - SUM(使用)`，依使用者 + 品項名稱
 * 分組）。存一份餘額就有兩份真相，補登或修改歷史紀錄時一定會不同步。
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

    /**
     * 每個人每個品項的餘額總覽
     *
     * 餘額與人名各查一次就湊好 —— **不要在迴圈裡查**，那是 N+1。
     *
     * @param int|null $userId 只看某個人，null = 全部
     * @return array 每列：user_id, user_name, item_name, issued, used, balance
     *
     * @phpstan-return array<int, array{user_id:int, user_name:string,
     *     item_name:string, issued:int, used:int, balance:int}>
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

        $rows = [];

        foreach ($balances as $balance) {
            $user = Arr::get($users, (int) $balance->user_id);
            $issued = (int) $balance->issued;
            $used = (int) $balance->used;

            $rows[] = [
                'user_id'   => (int) $balance->user_id,
                // 帳號被刪時流水也會跟著刪，所以理論上一定找得到人
                'user_name' => filled($user) ? $user->nickname : '—',
                'item_name' => (string) $balance->item_name,
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
     * @param array $criteria user_id / item_name
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function listRecords($criteria = [])
    {
        return $this->repository->getRecords($criteria);
    }

    /**
     * 已經用過的品項名稱（登記時的建議清單）
     *
     * @return array
     */
    public function listItemNames()
    {
        return $this->repository->getItemNames();
    }

    /**
     * 登記一筆領用或使用
     *
     * @param array $params user_id, item_name, type, quantity, happened_at, note
     * @param int   $operatorId 登記的人
     * @return ConsumableRecord
     * @throws \RuntimeException 餘額不足
     */
    public function createRecord($params, $operatorId)
    {
        $userId = (int) Arr::get($params, 'user_id');
        $itemName = $this->normalizeItemName(Arr::get($params, 'item_name'));
        $type = (int) Arr::get($params, 'type');
        $quantity = (int) Arr::get($params, 'quantity');

        // 只有「使用」會讓餘額減少，領用不必檢查
        if ($type === (int) config('constants.CONSUMABLE.TYPE.USE')) {
            $this->assertEnough($userId, $itemName, $quantity);
        }

        return $this->repository->createRecord([
            'user_id'     => $userId,
            'item_name'   => $itemName,
            'type'        => $type,
            'quantity'    => $quantity,
            'happened_at' => Arr::get($params, 'happened_at'),
            'note'        => Arr::get($params, 'note'),
            'created_by'  => $operatorId,
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
        $userId = (int) Arr::get($params, 'user_id', $record->user_id);
        $itemName = $this->normalizeItemName(Arr::get($params, 'item_name', $record->item_name));
        $type = (int) Arr::get($params, 'type', $record->type);
        $quantity = (int) Arr::get($params, 'quantity', $record->quantity);

        $without = $this->repository->getBalance($userId, $itemName, $record->id);
        $after = $type === (int) config('constants.CONSUMABLE.TYPE.ISSUE')
            ? $without + $quantity
            : $without - $quantity;

        if ($after < 0) {
            throw new \RuntimeException(trans('staff_manage.msg.consumable_not_enough', [
                'balance' => $without,
            ]));
        }

        return $this->repository->updateRecord($record, [
            'user_id'     => $userId,
            'item_name'   => $itemName,
            'type'        => $type,
            'quantity'    => $quantity,
            'happened_at' => Arr::get($params, 'happened_at', $record->happened_at),
            'note'        => Arr::get($params, 'note'),
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
                (string) $record->item_name,
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

    /**
     * 品項名稱正規化
     *
     * 去頭尾空白、把中間的連續空白縮成一個 —— 沒有品項表，餘額是按
     * **字串**分組的，「卡片 」與「卡片」會變成兩個品項各自算餘額。
     *
     * 只做這點程度的清理：再多（例如全半形轉換、大小寫統一）就會開始
     * 改變使用者打的東西，那不是這個欄位該做的事。
     *
     * @param string|null $name
     * @return string
     */
    private function normalizeItemName($name)
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $name));
    }

    /**
     * 手上夠不夠扣
     *
     * @param int    $userId
     * @param string $itemName
     * @param int    $quantity
     * @return void
     * @throws \RuntimeException 餘額不足
     */
    private function assertEnough($userId, $itemName, $quantity)
    {
        $balance = $this->repository->getBalance($userId, $itemName);

        if ($balance < $quantity) {
            throw new \RuntimeException(trans('staff_manage.msg.consumable_not_enough', [
                'balance' => $balance,
            ]));
        }
    }
}
