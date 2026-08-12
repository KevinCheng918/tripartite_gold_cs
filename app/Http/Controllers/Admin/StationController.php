<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StationResource;
use App\Models\Station;
use App\Services\StationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 站台管理控制器
 */
class StationController extends Controller
{
    private $stationService;

    public function __construct(StationService $stationService)
    {
        $this->stationService = $stationService;
    }

    /**
     * 站台列表頁面
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $params = $request->only(['keyword', 'domain', 'system_id', 'status', 'credits_min', 'credits_max', 'support_shop', 'score_runner', 'per_page']);
        $stations = $this->stationService->list($params);
        $systems = $this->stationService->getActiveSystems();

        return view('admin.station.index', [
            'stations' => $stations,
            'systems'  => $systems,
            'filters'  => $params,
        ]);
    }

    /**
     * Ajax 取得站台列表
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxList(Request $request)
    {
        $params = $request->only(['keyword', 'system_id', 'status', 'credits_min', 'credits_max', 'support_shop', 'score_runner', 'per_page']);

        $stations = $this->stationService->list($params);

        return StationResource::collection($stations);
    }

    /**
     * Ajax 新增站台
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|StationResource
     */
    public function ajaxStore(Request $request)
    {
        $params = $request->validate([
            'system_id'         => 'nullable|integer|exists:system,id',
            'name'              => 'required|string|max:100',
            'domain'            => 'nullable|string|max:255',
            'api_url'           => 'nullable|string|max:255',
            'api_key'           => 'nullable|string|max:64',
            'telegram_chat_id'  => 'nullable|string|max:30',
            'note'              => 'nullable|string',
        ]);

        try {
            $station = $this->stationService->create($params);

            return new StationResource($station->load('telegramGroup'));
        } catch (\Exception $e) {
            Log::error('站台新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('station.msg.create_failed')], 500);
        }
    }

    /**
     * Ajax 更新站台
     *
     * @param Request $request
     * @param Station $station
     * @return \Illuminate\Http\JsonResponse|StationResource
     */
    public function ajaxUpdate(Request $request, Station $station)
    {
        $params = $request->validate([
            'system_id'         => 'nullable|integer|exists:system,id',
            'name'              => 'sometimes|string|max:100',
            'domain'            => 'nullable|string|max:255',
            'api_url'           => 'nullable|string|max:255',
            'api_key'           => 'nullable|string|max:64',
            'telegram_chat_id'  => 'nullable|string|max:30',
            'status'            => 'sometimes|integer|in:0,1,2',
            'note'              => 'nullable|string',
        ]);

        try {
            $station = $this->stationService->update($station, $params);

            return new StationResource($station->load('telegramGroup'));
        } catch (\Exception $e) {
            Log::error('站台更新失敗', ['error' => $e->getMessage(), 'station_id' => $station->id]);

            return response()->json(['message' => trans('station.msg.update_failed')], 500);
        }
    }

    /**
     * Ajax 同步站台點數（從主系統 API）
     *
     * @param Station $station
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSyncCredits(Station $station)
    {
        try {
            $info = $this->stationService->syncInfo($station);

            if (!$info) {
                return response()->json(['message' => trans('station.msg.sync_failed')], 500);
            }

            return response()->json([
                'message' => trans('station.msg.sync_success'),
            ]);
        } catch (\Exception $e) {
            Log::error('站台點數同步失敗', ['error' => $e->getMessage(), 'station_id' => $station->id]);

            return response()->json(['message' => trans('station.msg.sync_failed')], 500);
        }
    }

    /**
     * Ajax 取得系統列表（供下拉選單）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxSystems()
    {
        $systems = $this->stationService->getActiveSystems();

        return response()->json($systems);
    }

    /**
     * Ajax 讀取機器人所在的 Telegram 群組
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxBotGroups()
    {
        try {
            $groups = $this->stationService->fetchBotGroups();

            return response()->json($groups);
        } catch (\Exception $e) {
            Log::error('讀取 Bot 群組失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => '讀取失敗：' . $e->getMessage()], 500);
        }
    }

    /**
     * Ajax 新增系統
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxStoreSystem(Request $request)
    {
        $params = $request->validate([
            'name'      => 'required|string|max:100|unique:system,name',
            'bot_token' => 'nullable|string|max:255',
        ]);

        try {
            $system = $this->stationService->createSystem($params['name'], $params['bot_token'] ?? null);

            return response()->json($system);
        } catch (\Exception $e) {
            Log::error('系統新增失敗', ['error' => $e->getMessage()]);

            return response()->json(['message' => trans('station.msg.create_failed')], 500);
        }
    }

    /**
     * Ajax 更新系統（Bot Token）
     *
     * @param Request $request
     * @param \App\Models\System $system
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajaxUpdateSystem(Request $request, \App\Models\System $system)
    {
        $params = $request->validate([
            'bot_token' => 'nullable|string|max:255',
        ]);

        try {
            $this->stationService->updateSystem($system, $params);

            return response()->json(['message' => trans('station.msg.updated')]);
        } catch (\Exception $e) {
            Log::error('系統更新失敗', ['error' => $e->getMessage(), 'system_id' => $system->id]);

            return response()->json(['message' => trans('station.msg.update_failed')], 500);
        }
    }
}
