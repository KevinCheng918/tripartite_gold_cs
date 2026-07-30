<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftCover\StoreCoverRequest;
use App\Http\Requests\ShiftCover\RespondCoverRequest;
use App\Http\Resources\ShiftCoverResource;
use App\Models\ShiftCover;
use App\Services\ShiftCoverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 代班管理控制器
 *
 * 處理代班申請、代班人回應、管理者審核。
 * 流程：原班人發起 → 代班人同意 → 管理者審核。
 */
class ShiftCoverController extends Controller
{
    private $coverService;

    public function __construct(ShiftCoverService $coverService)
    {
        $this->coverService = $coverService;
    }

    /**
     * Ajax 發起代班申請
     *
     * @param StoreCoverRequest $request
     * @return \Illuminate\Http\JsonResponse|ShiftCoverResource
     */
    public function ajaxRequest(StoreCoverRequest $request)
    {
        $params = $request->validated();

        try {
            $cover = $this->coverService->request($params, Auth::id());

            return new ShiftCoverResource($cover->load(['requester', 'coverUser', 'assignment.shift']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('代班申請失敗', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);

            return response()->json(['message' => trans('cover.msg.request_failed')], 500);
        }
    }

    /**
     * Ajax 代班人回應（同意或拒絕）
     *
     * @param RespondCoverRequest $request
     * @param ShiftCover          $cover
     * @return \Illuminate\Http\JsonResponse|ShiftCoverResource
     */
    public function ajaxRespondCoverUser(RespondCoverRequest $request, ShiftCover $cover)
    {
        $params = $request->validated();

        try {
            $cover = $this->coverService->respondByCoverUser($cover, (int) $params['status'], Auth::id());

            return new ShiftCoverResource($cover->load(['requester', 'coverUser', 'assignment.shift']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('代班回應失敗', ['error' => $e->getMessage(), 'cover_id' => $cover->id]);

            return response()->json(['message' => trans('cover.msg.respond_failed')], 500);
        }
    }

    /**
     * Ajax 管理者審核（核准或駁回）
     *
     * @param RespondCoverRequest $request
     * @param ShiftCover          $cover
     * @return \Illuminate\Http\JsonResponse|ShiftCoverResource
     */
    public function ajaxRespondAdmin(RespondCoverRequest $request, ShiftCover $cover)
    {
        $params = $request->validated();

        try {
            $cover = $this->coverService->respondByAdmin($cover, (int) $params['status'], Auth::id());

            return new ShiftCoverResource($cover->load(['requester', 'coverUser', 'admin', 'assignment.shift']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('代班審核失敗', ['error' => $e->getMessage(), 'cover_id' => $cover->id]);

            return response()->json(['message' => trans('cover.msg.review_failed')], 500);
        }
    }

    /**
     * Ajax 取得我的代班紀錄（客服用）
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxMyCovers(Request $request)
    {
        $params = $request->only(['per_page']);

        $covers = $this->coverService->listByUser(Auth::id(), (int) ($params['per_page'] ?? 20));

        return ShiftCoverResource::collection($covers);
    }

    /**
     * Ajax 取得待審核的代班紀錄（管理者用）
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxPendingCovers(Request $request)
    {
        $params = $request->only(['per_page']);

        $covers = $this->coverService->listPendingAdmin((int) ($params['per_page'] ?? 20));

        return ShiftCoverResource::collection($covers);
    }

    /**
     * Ajax 取得指定日期範圍的已核准代班紀錄（課表用）
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Http\JsonResponse
     */
    public function ajaxApprovedCovers(Request $request)
    {
        $params = $request->only(['date_from', 'date_to']);

        if (!filled($params['date_from'] ?? null) || !filled($params['date_to'] ?? null)) {
            return response()->json([]);
        }

        $covers = $this->coverService->listApprovedByDateRange($params['date_from'], $params['date_to']);

        return ShiftCoverResource::collection($covers);
    }

    /**
     * Ajax 取得所有代班紀錄（管理者用）
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function ajaxAllCovers(Request $request)
    {
        $params = $request->only(['per_page']);

        $covers = $this->coverService->listAll((int) ($params['per_page'] ?? 20));

        return ShiftCoverResource::collection($covers);
    }
}
