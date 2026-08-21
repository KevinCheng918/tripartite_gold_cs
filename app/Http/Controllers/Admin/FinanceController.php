<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffManage\StoreExpenseRequest;
use App\Models\FinanceExpense;
use App\Models\FinanceRecord;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 財務管理控制器
 */
class FinanceController extends Controller
{
    private $financeService;

    public function __construct(FinanceService $financeService)
    {
        $this->financeService = $financeService;
    }

    /**
     * 財務管理頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $categories = config('constants.FINANCE.CATEGORY');
        $currencies = config('constants.FINANCE.CURRENCY');

        return view('admin.finance.index', [
            'categories' => $categories,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Ajax 取得某月財務明細
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDetail(Request $request)
    {
        $params = $request->validate(['month' => 'required|string|size:7']);

        $detail = $this->financeService->getMonthDetail($params['month'], Auth::id());

        return response()->json($detail);
    }

    /**
     * Ajax 新增支出
     *
     * @param StoreExpenseRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreExpense(StoreExpenseRequest $request)
    {
        $params = $request->validated();

        try {
            $expense = $this->financeService->addExpense($params['year_month'], $params, Auth::id());

            return response()->json(['message' => '已新增', 'expense' => $expense]);
        } catch (\Exception $e) {
            Log::error('財務支出新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '新增失敗'], 500);
        }
    }

    /**
     * Ajax 更新補點/VM 統計（手動覆蓋）
     *
     * @param Request        $request
     * @param FinanceRecord  $record
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateSummary(Request $request, FinanceRecord $record)
    {
        $params = $request->validate([
            'topup_usdt'      => 'nullable|numeric',
            'topup_avg_rate'  => 'nullable|numeric',
            'topup_credit'    => 'nullable|numeric',
            'vm_income_usdt'  => 'nullable|numeric',
            'vm_income_count' => 'nullable|integer',
            'reset_field'     => 'nullable|string|in:topup,vm',
        ]);

        try {
            if (filled($params['reset_field'] ?? null)) {
                $this->financeService->resetToAuto($record->id, $params['reset_field']);
            } else {
                $params['year_month'] = $record->year_month;
                $this->financeService->updateSummary($record->id, $params);
            }

            return response()->json(['message' => '已更新']);
        } catch (\Exception $e) {
            Log::error('財務統計更新失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '更新失敗'], 500);
        }
    }

    /**
     * Ajax 刪除支出
     *
     * @param FinanceExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDeleteExpense(FinanceExpense $expense)
    {
        try {
            $this->financeService->deleteExpense($expense->id);

            return response()->json(['message' => '已刪除']);
        } catch (\Exception $e) {
            Log::error('財務支出刪除失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '刪除失敗'], 500);
        }
    }
}
