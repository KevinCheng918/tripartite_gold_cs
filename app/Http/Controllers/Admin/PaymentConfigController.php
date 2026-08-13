<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentConfig;
use App\Services\PaymentConfigService;
use App\Services\StationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 繳款設定控制器
 */
class PaymentConfigController extends Controller
{
    private $service;
    private $stationService;

    public function __construct(PaymentConfigService $service, StationService $stationService)
    {
        $this->service = $service;
        $this->stationService = $stationService;
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
        $configs = $this->service->list($params['system_id'] ?? null);

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
            'template' => 'required|string',
            'station'  => 'nullable|string',
            'amount'   => 'nullable|string',
            'month'    => 'nullable|string',
        ]);

        $text = $this->service->renderTemplate($params['template'], [
            'station' => $params['station'] ?? '',
            'amount'  => $params['amount'] ?? '',
            'month'   => $params['month'] ?? '',
        ]);

        return response()->json(['text' => $text]);
    }
}
