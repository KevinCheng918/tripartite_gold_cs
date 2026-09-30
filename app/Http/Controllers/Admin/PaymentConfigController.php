<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentConfig\UpdateAlertSettingRequest;
use App\Models\PaymentConfig;
use App\Services\AppSettingService;
use App\Services\PaymentConfigService;
use App\Services\StationCreditAlertService;
use App\Services\StationService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 繳款設定控制器
 */
class PaymentConfigController extends Controller
{
    private $service;
    private $stationService;
    private $appSettingService;
    private $creditAlertService;

    public function __construct(
        PaymentConfigService $service,
        StationService $stationService,
        AppSettingService $appSettingService,
        StationCreditAlertService $creditAlertService
    ) {
        $this->service = $service;
        $this->stationService = $stationService;
        $this->appSettingService = $appSettingService;
        $this->creditAlertService = $creditAlertService;
    }

    /**
     * 繳款設定頁面
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $systemId = $request->input('system_id');
        $systems = $this->stationService->getActiveSystems();
        $configs = $this->service->list($systemId);

        return view('admin.payment-config.index', [
            'systems'  => $systems,
            'configs'  => $configs,
            'systemId' => $systemId,
            // 餘點告警的公版與門檻。沒設定過時 globalSettings() 會給 constants 的預設值
            'alertSetting' => $this->creditAlertService->globalSettings(),
        ]);
    }

    /**
     * Ajax 取得繳款設定列表
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxList(Request $request)
    {
        $params = $request->only(['system_id']);
        $configs = $this->service->list(Arr::get($params, 'system_id'));

        return response()->json($configs->map(function ($c) {
            return [
                'id'         => $c->id,
                'system_id'  => $c->system_id,
                'system'     => $c->system ? ['id' => $c->system->id, 'name' => $c->system->name] : null,
                'title'      => $c->title,
                'content'    => $c->content,
                'template'   => $c->template,
                'image'      => $c->image ? asset("storage/{$c->image}") : null,
                'status'     => $c->status,
                'sort_order' => $c->sort_order,
            ];
        }));
    }

    /**
     * Ajax 新增繳款設定
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStore(Request $request)
    {
        $params = $request->validate([
            'system_id'  => 'required|integer|exists:system,id',
            'title'      => 'required|string|max:100',
            'content'    => 'required|string',
            'template'   => 'nullable|string',
            'image'      => 'nullable|image|max:5120',
            'sort_order' => 'nullable|integer',
        ]);

        try {
            $this->service->create($params);

            return response()->json(['message' => trans('payment_config.msg.created')]);
        } catch (\Exception $e) {
            Log::error('繳款設定新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('payment_config.msg.create_failed')], 500);
        }
    }

    /**
     * Ajax 更新繳款設定
     *
     * @param Request       $request
     * @param PaymentConfig $config
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdate(Request $request, PaymentConfig $config)
    {
        $params = $request->validate([
            'system_id'  => 'sometimes|integer|exists:system,id',
            'title'      => 'sometimes|string|max:100',
            'content'    => 'sometimes|string',
            'template'   => 'nullable|string',
            'image'      => 'nullable|image|max:5120',
            'status'     => 'sometimes|integer|in:0,1',
            'sort_order' => 'nullable|integer',
        ]);

        try {
            $this->service->update($config, $params);

            return response()->json(['message' => trans('payment_config.msg.updated')]);
        } catch (\Exception $e) {
            Log::error('繳款設定更新失敗', ['error' => $e->getMessage(), 'id' => $config->id]);

            return response()->json(['message' => trans('payment_config.msg.update_failed')], 500);
        }
    }

    /**
     * Ajax 刪除繳款設定
     *
     * @param PaymentConfig $config
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxDelete(PaymentConfig $config)
    {
        try {
            $this->service->delete($config);

            return response()->json(['message' => trans('payment_config.msg.deleted')]);
        } catch (\Exception $e) {
            Log::error('繳款設定刪除失敗', ['error' => $e->getMessage(), 'id' => $config->id]);

            return response()->json(['message' => trans('payment_config.msg.delete_failed')], 500);
        }
    }

    /**
     * Ajax 依系統取得繳款設定（供帳務紀錄使用）
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxBySystem(Request $request)
    {
        $params = $request->validate([
            'system_id' => 'required|integer',
        ]);

        $configs = $this->service->getActiveBySystem($params['system_id']);

        return response()->json($configs->map(function ($c) {
            return [
                'id'       => $c->id,
                'title'    => $c->title,
                'content'  => $c->content,
                'template' => $c->template,
                'image'    => $c->image ? asset("storage/{$c->image}") : null,
            ];
        }));
    }

    /**
     * Ajax 渲染模板文案
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxRenderTemplate(Request $request)
    {
        $params = $request->validate([
            'template'  => 'required|string',
            'station'   => 'nullable|string',
            'amount'    => 'nullable|string',
            'month'     => 'nullable|string',
            // 餘點告警的公版也用這支預覽，不另開端點
            'credit'    => 'nullable|string',
            'threshold' => 'nullable|string',
        ]);

        $text = $this->service->renderTemplate(Arr::get($params, 'template'), [
            'station'   => Arr::get($params, 'station', ''),
            'amount'    => Arr::get($params, 'amount', ''),
            'month'     => Arr::get($params, 'month', ''),
            'credit'    => Arr::get($params, 'credit', ''),
            'threshold' => Arr::get($params, 'threshold', ''),
        ]);

        return response()->json(['text' => $text]);
    }

    /**
     * Ajax 儲存站台餘點告警設定
     *
     * 公版、門檻、冷卻天數存在 app_setting，不是 payment_config 的一筆 ——
     * payment_config 是每個 system 一組，而餘點告警是全站台共用的一份。
     *
     * @param UpdateAlertSettingRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateAlertSetting(UpdateAlertSettingRequest $request)
    {
        $params = $request->validated();

        try {
            $this->appSettingService->putMany([
                AppSettingService::KEY_CREDIT_ALERT_TEMPLATE      => Arr::get($params, 'alert_template'),
                AppSettingService::KEY_CREDIT_ALERT_THRESHOLD     => Arr::get($params, 'threshold'),
                AppSettingService::KEY_CREDIT_ALERT_COOLDOWN_DAYS => Arr::get($params, 'cooldown_days'),
            ], Auth::id());

            return response()->json(['message' => trans('payment_config.msg.alert_saved')]);
        } catch (\Exception $e) {
            Log::error('餘點告警設定儲存失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('payment_config.msg.alert_save_failed')], 500);
        }
    }
}
