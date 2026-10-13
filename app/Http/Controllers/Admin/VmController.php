<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vm\GenerateBillingRequest;
use App\Http\Requests\Vm\StoreServerRequest;
use App\Http\Requests\Vm\UpdateServerRequest;
use App\Http\Resources\VmBillingResource;
use App\Http\Resources\VmServerResource;
use App\Models\VmBilling;
use App\Models\VmServer;
use App\Services\StationService;
use App\Services\TelegramChatService;
use App\Services\VmPaymentNoticeService;
use App\Services\VmService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 虛擬機管理控制器
 */
class VmController extends Controller
{
    private $vmService;
    private $noticeService;
    private $chatService;
    private $stationService;

    public function __construct(
        VmService $vmService,
        VmPaymentNoticeService $noticeService,
        TelegramChatService $chatService,
        StationService $stationService
    ) {
        $this->vmService = $vmService;
        // 手動發送與每天 09:30 的自動發送共用同一份文案組裝
        $this->noticeService = $noticeService;
        $this->chatService = $chatService;
        $this->stationService = $stationService;
    }

    /**
     * 虛擬機管理頁面
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $user = auth()->user();
        if (!$user->hasPermission('vm.view') && !$user->hasPermission('vm.billing_view')) {
            abort(403);
        }

        $systems = $this->stationService->getActiveSystems();
        $stations = $this->stationService->allForDropdown();

        return view('admin.vm.index', ['systems' => $systems, 'stations' => $stations]);
    }

    /**
     * Ajax VM 列表
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxList(Request $request)
    {
        $params = $request->only(['keyword', 'station_id', 'system_id', 'hostname', 'internal_ip', 'external_ip', 'power_status', 'per_page']);
        $servers = $this->vmService->listServers($params);

        return VmServerResource::collection($servers);
    }

    /**
     * Ajax 新增 VM
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|VmServerResource
     */
    public function ajaxStore(StoreServerRequest $request)
    {
        $params = $request->validated();

        try {
            $server = $this->vmService->createServer($params);

            return new VmServerResource($server->load('station'));
        } catch (\Exception $e) {
            Log::error('VM 新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('vm.msg.create_failed')], 500);
        }
    }

    /**
     * Ajax 更新 VM
     *
     * @param Request  $request
     * @param VmServer $vm
     * @return \Illuminate\Http\JsonResponse|VmServerResource
     */
    public function ajaxUpdate(UpdateServerRequest $request, VmServer $vm)
    {
        $params = $request->validated();

        try {
            $server = $this->vmService->updateServer($vm, $params);

            return new VmServerResource($server->load('station'));
        } catch (\Exception $e) {
            Log::error('VM 更新失敗', ['error' => $e->getMessage(), 'vm_id' => $vm->id]);

            return response()->json(['message' => trans('vm.msg.update_failed')], 500);
        }
    }

    /**
     * Ajax 切換開關機
     *
     * @param VmServer $vm
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxTogglePower(VmServer $vm)
    {
        try {
            $server = $this->vmService->togglePower($vm);

            return response()->json([
                'message'      => trans('vm.msg.power_toggled'),
                'power_status' => $server->power_status,
            ]);
        } catch (\Exception $e) {
            Log::error('VM 開關機切換失敗', ['error' => $e->getMessage(), 'vm_id' => $vm->id]);

            return response()->json(['message' => trans('vm.msg.toggle_failed')], 500);
        }
    }

    /**
     * Ajax 標記 VPN 已續約（日期往後推一個月）
     *
     * ⚠ 從**原本的日期**推而不是從今天 —— 理由見 `VmService::markVpnRenewed()`。
     *
     * @param VmServer $vm
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRenewVpn(VmServer $vm)
    {
        try {
            $server = $this->vmService->markVpnRenewed($vm);

            return response()->json([
                'message'        => trans('vm.msg.vpn_renewed'),
                'vpn_renew_date' => $server->vpn_renew_date->toDateString(),
            ]);
        } catch (\RuntimeException $e) {
            // 這台沒有 VPN 或沒設過日期 —— 不是系統錯誤
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('VPN 續約標記失敗', ['error' => $e->getMessage(), 'vm_id' => $vm->id]);

            return response()->json(['message' => trans('vm.msg.action_failed')], 500);
        }
    }

    /**
     * Ajax 帳單列表
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxBillingList(Request $request)
    {
        $params = $request->only(['billing_month', 'paid', 'overdue', 'shutdown', 'vm_server_id', 'system_id', 'station_id', 'per_page']);
        $billings = $this->vmService->listBillings($params);

        return VmBillingResource::collection($billings);
    }

    /**
     * Ajax 上傳繳款證明（客服用）
     *
     * @param Request   $request
     * @param VmBilling $billing
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUploadProof(Request $request, VmBilling $billing)
    {
        $params = $request->validate([
            'proof' => 'required|image|max:5120',
        ]);

        try {
            $this->vmService->uploadProof($billing, $params['proof']);

            return response()->json(['message' => trans('vm.msg.proof_uploaded')]);
        } catch (\Exception $e) {
            Log::error('繳款證明上傳失敗', ['error' => $e->getMessage(), 'billing_id' => $billing->id]);

            return response()->json(['message' => trans('vm.msg.upload_failed')], 500);
        }
    }

    /**
     * Ajax 審核通過（管理者用）
     *
     * @param VmBilling $billing
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxApprovePaid(VmBilling $billing)
    {
        try {
            $this->vmService->approvePaid($billing);

            return response()->json(['message' => trans('vm.msg.approved')]);
        } catch (\Exception $e) {
            Log::error('帳單審核失敗', ['error' => $e->getMessage(), 'billing_id' => $billing->id]);

            return response()->json(['message' => trans('vm.msg.approve_failed')], 500);
        }
    }

    /**
     * Ajax 直接標記已收款（管理者用）
     *
     * @param VmBilling $billing
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxMarkPaid(VmBilling $billing)
    {
        try {
            $this->vmService->markPaid($billing);

            return response()->json(['message' => trans('vm.msg.marked_paid')]);
        } catch (\Exception $e) {
            Log::error('帳單收款標記失敗', ['error' => $e->getMessage(), 'billing_id' => $billing->id]);

            return response()->json(['message' => trans('vm.msg.mark_failed')], 500);
        }
    }

    /**
     * Ajax 產生帳單
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxGenerateBilling(GenerateBillingRequest $request)
    {
        $params = $request->validated();

        try {
            $forceUpdate = !empty($params['force_update']);
            $result = $this->vmService->generateMonthlyBillings($params['month'] ?? null, $forceUpdate);

            return response()->json([
                'message'    => trans('vm.msg.billing_generated', ['count' => $result['generated']]),
                'generated'  => $result['generated'],
                'skipped'    => $result['skipped'],
                'updated'    => $result['updated'],
                'mismatches' => $result['mismatches'],
            ]);
        } catch (\Exception $e) {
            Log::error('帳單產生失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('vm.msg.generate_failed')], 500);
        }
    }

    /**
     * Ajax 發送繳款通知到 Telegram（含圖片）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSendPaymentNotice(Request $request)
    {
        $params = $request->validate([
            'group_id'  => 'required|integer',
            'system_id' => 'required|integer',
            'station'   => 'required|string',
            'amount'    => 'required|string',
            'month'     => 'required|string',
            'due_date'  => 'nullable|string',
        ]);

        try {
            /*
             * 文案與圖都交給 VmService 組 —— 每天 09:30 的自動發送走同一支，
             * 兩邊各寫一份的話，改了文案規則只會改到一邊。
             */
            $notice = $this->noticeService->renderPaymentNotice((int) $params['system_id'], [
                'station'  => $params['station'],
                'amount'   => $params['amount'],
                'month'    => $params['month'],
                'due_date' => Arr::get($params, 'due_date', ''),
            ]);

            if (blank(Arr::get($notice, 'text'))) {
                return response()->json(['message' => trans('payment_config.msg.no_config')], 422);
            }

            // mark_replied = false：這是系統通知，不該把客戶還在等的提問標記成已回覆
            $this->chatService->sendReply(
                (int) $params['group_id'],
                Arr::get($notice, 'text'),
                Auth::id(),
                Auth::user()->nickname,
                /*
                 * ⚠ `is_auto` 要傳 true，即使這是人按的按鈕。那個旗標問的是
                 * 「這則訊息算不算客服親自回覆客人」—— 繳款通知是系統組的帳單內容，
                 * 跟客人問的問題無關。傳 false 的話 `sendReply()` 會順手把這個群
                 * 所有還掛著的求助單標成「客服已自行處理」，客人的問題其實沒人回。
                 * 跟 `mark_replied => false` 同一個理由。
                 */
                [
                    'image_url'    => Arr::get($notice, 'image_url'),
                    'mark_replied' => false,
                    'is_auto'      => true,
                ]
            );

            return response()->json(['message' => trans('payment_config.msg.sent')]);
        } catch (\Exception $e) {
            Log::error('繳款通知發送失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('payment_config.msg.send_failed')], 500);
        }
    }
}
