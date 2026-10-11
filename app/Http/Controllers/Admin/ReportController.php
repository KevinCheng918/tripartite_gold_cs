<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\MarkHandledRequest;
use App\Http\Requests\Report\ShowReportRequest;
use App\Services\AttendanceReportService;
use App\Services\AutoReplySupportService;
use App\Services\RemindReportService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 報表（內務管理 → 報表）
 *
 * 把原本只在 Telegram 看得到的統計搬到後台，用分頁區分：
 *
 * | 分頁 | 權限 |
 * |---|---|
 * | 打卡報表 | `report.attendance` |
 * | 超時提醒統計 | `report.remind` |
 * | 超時統計裡的「標記為已處理」 | `report.remind_handle` |
 *
 * 兩個分頁都是「自己選起訖日期 ＋ 一排快捷鈕」，比照補點紀錄。
 * 後端只收起訖，不收「日／週／月」—— 快捷鈕在前端就換算成日期了，
 * 多一個 type 參數會讓「上週」有兩套算法。
 *
 * ⚠ **數字跟 Telegram 那份是同一組**：兩邊都走 `AttendanceReportService`
 * 與 `RemindReportService`，差別只在這裡回陣列、那裡回字串。各查一次的話，
 * 後台顯示的跟主管手機上收到的會對不起來 —— 那種不一致最難查，
 * 因為兩邊各自都「對」。
 */
class ReportController extends Controller
{
    private $attendanceReport;
    private $remindReport;
    private $supportService;

    public function __construct(
        AttendanceReportService $attendanceReport,
        RemindReportService $remindReport,
        AutoReplySupportService $supportService
    ) {
        // 統計本體跟通知共用，不各查一次
        $this->attendanceReport = $attendanceReport;
        $this->remindReport = $remindReport;
        /*
         * 「標記為已處理」動的是求助單。狀態轉移一律走這支 Service ——
         * 在報表這邊自己改一次，Telegram 那條路的規則（已處理過就不能再動、
         * 要回報群組）就有兩份，改一邊忘另一邊是遲早的事。
         */
        $this->supportService = $supportService;
    }

    /**
     * 報表頁
     *
     * ⚠ 這條路由不能用 `can:` middleware —— 兩個分頁各自一個權限，有**其中一個**
     * 就該進得來，而 `can:` 是 AND。所以在這裡自己擋：兩個都沒有的人直接 403，
     * 不然他會看到一個完全空白的頁面，還以為是壞了。
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $user = Auth::user();

        if (blank($user) || (! $user->hasPermission('report.attendance') && ! $user->hasPermission('report.remind'))) {
            abort(403);
        }

        return view('admin.report.index');
    }

    /**
     * Ajax 打卡報表
     *
     * @param ShowReportRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxAttendance(ShowReportRequest $request)
    {
        $params = $request->validated();

        try {
            $range = $this->rangeOf($params);

            return response()->json([
                'range' => $range,
                'rows'  => $this->attendanceReport->forPage($range),
            ]);
        } catch (\Exception $e) {
            Log::error('打卡報表載入失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('report.msg.load_failed')], 500);
        }
    }

    /**
     * Ajax 超時提醒統計
     *
     * @param ShowReportRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRemind(ShowReportRequest $request)
    {
        $params = $request->validated();

        try {
            return response()->json($this->remindReport->forPage($this->rangeOf($params)));
        } catch (\Exception $e) {
            Log::error('超時提醒統計載入失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('report.msg.load_failed')], 500);
        }
    }

    /**
     * Ajax 把一張還掛著的求助單標成「客服已自行處理」
     *
     * 報表上看得到「仍未處理」，卻沒有任何地方可以處理它 —— 支援群組那則
     * 求助訊息被洗掉、或同仁其實是在客戶群直接回客人的時候，那一列會永遠紅著。
     * 這條路是那個出口。
     *
     * ⚠ 權限用 `report.remind_handle` 而不是 `report.remind`：看得到統計
     * 不等於可以替別人結案 —— 比照待追蹤事項把「看」與「做」分成兩個 keyword。
     *
     * @param MarkHandledRequest $request
     * @param int                $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxMarkHandled(MarkHandledRequest $request, $id)
    {
        $params = $request->validated();

        try {
            $this->supportService->markHandled(
                (int) Arr::get($params, 'id'),
                (string) Auth::user()->nickname
            );

            return response()->json(['message' => trans('report.msg.ticket_handled')]);
        } catch (\RuntimeException $e) {
            // 找不到、或剛剛有人在 Telegram 按過了 —— 不是系統錯誤，訊息直接給使用者看
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('求助單標記已處理失敗', [
                'ticket_id' => $id,
                'user_id'   => Auth::id(),
                'error'     => $e->getMessage(),
            ]);

            return response()->json(['message' => trans('report.msg.handle_failed')], 500);
        }
    }

    /**
     * 把 Request 的兩個欄位整理成 Service 要的形狀
     *
     * @param array $params
     * @return array start / end（都是 Y-m-d）
     */
    private function rangeOf(array $params)
    {
        return [
            'start' => (string) Arr::get($params, 'start'),
            'end'   => (string) Arr::get($params, 'end'),
        ];
    }
}
