<?php

namespace App\Services;

use App\Presenters\NumberPresenter;
use App\Repositories\FinanceRepository;

/**
 * 財務管理 Service
 */
class FinanceService
{
    private $financeRepository;

    public function __construct(FinanceRepository $financeRepository)
    {
        $this->financeRepository = $financeRepository;
    }

    /**
     * 取得某月財務明細（含自動統計值）
     *
     * @param string   $yearMonth YYYY-MM
     * @param int|null $userId
     * @return array
     */
    public function getMonthDetail($yearMonth, $userId = null)
    {
        $record = $this->financeRepository->findOrCreateByMonth($yearMonth, $userId);
        $record->load('expenses');

        // 自動統計
        $autoTopup = $this->financeRepository->calcTopupStats($yearMonth);
        $autoVm = $this->financeRepository->calcVmIncomeStats($yearMonth);

        // 使用手動值（非 null）或自動值
        $topupUsdt    = filled($record->topup_usdt) ? (float) $record->topup_usdt : $autoTopup['usdt'];
        $topupAvgRate = filled($record->topup_avg_rate) ? (float) $record->topup_avg_rate : $autoTopup['avg_rate'];
        $topupCredit  = filled($record->topup_credit) ? (float) $record->topup_credit : $autoTopup['credit'];

        $topup = [
            'usdt'          => $topupUsdt,
            'avg_rate'      => $topupAvgRate,
            'credit'        => $topupCredit,
            'usdt_fmt'      => NumberPresenter::trimZeros($topupUsdt),
            'avg_rate_fmt'  => NumberPresenter::trimZeros($topupAvgRate),
            'credit_fmt'    => NumberPresenter::trimZeros($topupCredit, 2),
            'is_manual'     => filled($record->topup_usdt),
        ];

        $vmUsdt    = filled($record->vm_income_usdt) ? (float) $record->vm_income_usdt : $autoVm['usdt'];
        $vmCount   = filled($record->vm_income_count) ? (int) $record->vm_income_count : $autoVm['count'];
        $vmAvgRate = $autoVm['avg_rate'];
        $vmTwd     = $autoVm['twd'];

        $vm = [
            'usdt'          => $vmUsdt,
            'count'         => $vmCount,
            'avg_rate'      => $vmAvgRate,
            'twd'           => $vmTwd,
            'usdt_fmt'      => NumberPresenter::trimZeros($vmUsdt),
            'avg_rate_fmt'  => NumberPresenter::trimZeros($vmAvgRate),
            'twd_fmt'       => NumberPresenter::trimZeros($vmTwd, 2),
            'is_manual'     => filled($record->vm_income_usdt),
        ];

        // 支出分組
        $miscExpenses = $record->expenses->where('type', config('constants.FINANCE.EXPENSE_TYPE.MISC'))->values();
        $serverExpenses = $record->expenses->where('type', config('constants.FINANCE.EXPENSE_TYPE.SERVER'))->values();

        return [
            'record_id'       => $record->id,
            'year_month'      => $record->year_month,
            'topup'           => $topup,
            'auto_topup'      => $autoTopup,
            'vm'              => $vm,
            'auto_vm'         => $autoVm,
            'misc_expenses'   => $miscExpenses,
            'server_expenses' => $serverExpenses,
        ];
    }

    /**
     * 新增支出
     *
     * @param string   $yearMonth
     * @param array    $params
     * @param int|null $userId
     * @return \App\Models\FinanceExpense
     */
    public function addExpense($yearMonth, $params, $userId = null)
    {
        $record = $this->financeRepository->findOrCreateByMonth($yearMonth, $userId);

        return $this->financeRepository->createExpense([
            'finance_record_id' => $record->id,
            'type'              => $params['type'],
            'category'          => $params['category'] ?? null,
            'name'              => $params['name'],
            'amount'            => (float) $params['amount'],
            'currency'          => $params['currency'] ?? 'TWD',
            'expense_date'      => $params['expense_date'] ?? null,
            'reimbursed'        => (int) ($params['reimbursed'] ?? 0),
            'note'              => $params['note'] ?? null,
        ]);
    }

    /**
     * 更新支出
     *
     * @param int   $expenseId
     * @param array $params
     * @return \App\Models\FinanceExpense|null
     */
    public function updateExpense($expenseId, $params)
    {
        $expense = $this->financeRepository->findExpense($expenseId);
        if (!$expense) {
            return null;
        }

        return $this->financeRepository->updateExpense($expense, [
            'category'     => $params['category'] ?? null,
            'name'         => $params['name'],
            'amount'       => (float) $params['amount'],
            'currency'     => $params['currency'] ?? 'TWD',
            'expense_date' => $params['expense_date'] ?? null,
            'reimbursed'   => (int) ($params['reimbursed'] ?? 0),
            'note'         => $params['note'] ?? null,
        ]);
    }

    /**
     * 刪除支出
     *
     * @param int $expenseId
     * @return void
     */
    public function deleteExpense($expenseId)
    {
        $expense = $this->financeRepository->findExpense($expenseId);
        if ($expense) {
            $this->financeRepository->deleteExpense($expense);
        }
    }

    /**
     * 更新補點/VM 統計（手動覆蓋）
     *
     * @param int   $recordId
     * @param array $params
     * @return \App\Models\FinanceRecord
     */
    public function updateSummary($recordId, $params)
    {
        $record = $this->financeRepository->findOrCreateByMonth($params['year_month'] ?? '');

        $data = [];
        if (array_key_exists('topup_usdt', $params)) {
            $data['topup_usdt'] = $params['topup_usdt'];
            $data['topup_avg_rate'] = $params['topup_avg_rate'] ?? null;
            $data['topup_credit'] = $params['topup_credit'] ?? null;
        }
        if (array_key_exists('vm_income_usdt', $params)) {
            $data['vm_income_usdt'] = $params['vm_income_usdt'];
            $data['vm_income_count'] = $params['vm_income_count'] ?? null;
        }

        return $this->financeRepository->updateRecord($record, $data);
    }

    /**
     * 重置為自動值（清除手動覆蓋）
     *
     * @param int    $recordId
     * @param string $field topup 或 vm
     * @return \App\Models\FinanceRecord
     */
    public function resetToAuto($recordId, $field)
    {
        $record = $this->financeRepository->findRecord($recordId);
        if (!$record) {
            return null;
        }

        $data = [];
        if ($field === 'topup') {
            $data = ['topup_usdt' => null, 'topup_avg_rate' => null, 'topup_credit' => null];
        } elseif ($field === 'vm') {
            $data = ['vm_income_usdt' => null, 'vm_income_count' => null];
        }

        return $this->financeRepository->updateRecord($record, $data);
    }
}
