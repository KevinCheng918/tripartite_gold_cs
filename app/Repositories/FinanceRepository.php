<?php

namespace App\Repositories;

use App\Models\CreditTopup;
use App\Models\FinanceExpense;
use App\Models\FinanceRecord;
use App\Models\VmBilling;
use Illuminate\Support\Facades\DB;

/**
 * 財務管理 Repository
 */
class FinanceRepository
{
    /**
     * 依月份取得或建立紀錄
     *
     * @param string   $yearMonth YYYY-MM
     * @param int|null $userId
     * @return FinanceRecord
     */
    public function findOrCreateByMonth($yearMonth, $userId = null)
    {
        $record = FinanceRecord::query()
            ->where('year_month', $yearMonth)
            ->first();

        if (!$record) {
            $record = FinanceRecord::query()->create([
                'year_month' => $yearMonth,
                'created_by' => $userId,
            ]);
        }

        return $record;
    }

    /**
     * 依月份取得紀錄（含支出明細）
     *
     * @param string $yearMonth
     * @return FinanceRecord|null
     */
    public function findByMonth($yearMonth)
    {
        return FinanceRecord::query()
            ->with('expenses')
            ->where('year_month', $yearMonth)
            ->first();
    }

    /**
     * 更新紀錄
     *
     * @param FinanceRecord $record
     * @param array         $attributes
     * @return FinanceRecord
     */
    public function updateRecord(FinanceRecord $record, $attributes)
    {
        $record->update($attributes);

        return $record->refresh();
    }

    /**
     * 依 ID 查詢紀錄
     *
     * @param int $id
     * @return FinanceRecord|null
     */
    public function findRecord($id)
    {
        return FinanceRecord::query()->find($id);
    }

    /**
     * 新增支出
     *
     * @param array $attributes
     * @return FinanceExpense
     */
    public function createExpense($attributes)
    {
        return FinanceExpense::query()->create($attributes);
    }

    /**
     * 查詢支出
     *
     * @param int $id
     * @return FinanceExpense|null
     */
    public function findExpense($id)
    {
        return FinanceExpense::query()->find($id);
    }

    /**
     * 刪除支出
     *
     * @param FinanceExpense $expense
     * @return void
     */
    /**
     * 更新支出
     *
     * @param FinanceExpense $expense
     * @param array          $attributes
     * @return FinanceExpense
     */
    public function updateExpense(FinanceExpense $expense, $attributes)
    {
        $expense->update($attributes);

        return $expense->refresh();
    }

    public function deleteExpense(FinanceExpense $expense)
    {
        $expense->delete();
    }

    /**
     * 自動統計補點數據（該月已完成的）
     *
     * 補點分兩種，對帳時要能分開看：
     * - USDT 補點（input_type=1）：收 USDT，依匯率換算成點數
     * - 台幣補點（input_type=2）：收台幣，與點數 1:1，沒有 USDT 也沒有匯率
     *
     * 台幣補點的 usdt_amount / exchange_rate 都存 0，SUM 加 0 沒差，
     * 但 AVG 會把 0 算進分母拉低平均，因此均匯率必須用 CASE WHEN 排除。
     *
     * @param string $yearMonth YYYY-MM
     * @return array{
     *     usdt: float, twd: float, credit: float, credit_from_usdt: float,
     *     avg_rate: float, count: int, usdt_count: int, twd_count: int
     * }
     */
    public function calcTopupStats($yearMonth)
    {
        $row = DB::table('credit_topup')
            ->selectRaw(
                'SUM(usdt_amount) as usdt,'
                . ' SUM(CASE WHEN input_type = ? THEN credit_amount ELSE 0 END) as twd,'
                . ' SUM(credit_amount) as credit,'
                . ' SUM(CASE WHEN input_type = ? THEN credit_amount ELSE 0 END) as credit_from_usdt,'
                . ' AVG(CASE WHEN input_type = ? THEN exchange_rate END) as avg_rate,'
                . ' COUNT(*) as cnt,'
                . ' SUM(CASE WHEN input_type = ? THEN 1 ELSE 0 END) as usdt_cnt,'
                . ' SUM(CASE WHEN input_type = ? THEN 1 ELSE 0 END) as twd_cnt',
                [
                    CreditTopup::TYPE_CREDIT,
                    CreditTopup::TYPE_USDT,
                    CreditTopup::TYPE_USDT,
                    CreditTopup::TYPE_USDT,
                    CreditTopup::TYPE_CREDIT,
                ]
            )
            ->where('status', CreditTopup::STATUS_COMPLETED)
            ->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$yearMonth])
            ->first();

        return [
            'usdt'             => (float) ($row->usdt ?? 0),
            'twd'              => (float) ($row->twd ?? 0),
            'credit'           => (float) ($row->credit ?? 0),
            'credit_from_usdt' => (float) ($row->credit_from_usdt ?? 0),
            'avg_rate'         => (float) ($row->avg_rate ?? 0),
            'count'            => (int) ($row->cnt ?? 0),
            'usdt_count'       => (int) ($row->usdt_cnt ?? 0),
            'twd_count'        => (int) ($row->twd_cnt ?? 0),
        ];
    }

    /**
     * 自動統計 VM 收入數據（該月已收款的）
     *
     * @param string $yearMonth YYYY-MM
     * @return array ['usdt' => float, 'count' => int]
     */
    public function calcVmIncomeStats($yearMonth)
    {
        $row = DB::table('vm_billing')
            ->selectRaw('SUM(amount) as usdt, COUNT(*) as cnt, AVG(exchange_rate) as avg_rate')
            ->where('paid', config('constants.VM.BILLING.PAID'))
            ->where('billing_month', $yearMonth)
            ->first();

        $usdt = (float) ($row->usdt ?? 0);
        $avgRate = (float) ($row->avg_rate ?? 0);

        return [
            'usdt'     => $usdt,
            'count'    => (int) ($row->cnt ?? 0),
            'avg_rate' => $avgRate,
            'twd'      => $avgRate > 0 ? round($usdt * $avgRate, 2) : 0,
        ];
    }
}
