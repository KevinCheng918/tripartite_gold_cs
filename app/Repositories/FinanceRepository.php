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
     * @param string $yearMonth YYYY-MM
     * @return array ['usdt' => float, 'avg_rate' => float, 'credit' => float]
     */
    public function calcTopupStats($yearMonth)
    {
        $row = DB::table('credit_topup')
            ->selectRaw('SUM(usdt_amount) as usdt, AVG(exchange_rate) as avg_rate, SUM(credit_amount) as credit')
            ->where('status', CreditTopup::STATUS_COMPLETED)
            ->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$yearMonth])
            ->first();

        return [
            'usdt'     => (float) ($row->usdt ?? 0),
            'avg_rate' => (float) ($row->avg_rate ?? 0),
            'credit'   => (float) ($row->credit ?? 0),
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
