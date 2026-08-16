<?php

namespace App\Services;

use App\Models\CreditTopup;
use App\Repositories\CreditTopupRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 站台補點/扣點 Service
 */
class CreditTopupService
{
    private $topupRepository;
    private $mainSystemApi;

    public function __construct(
        CreditTopupRepository $topupRepository,
        MainSystemApiService $mainSystemApi
    ) {
        $this->topupRepository = $topupRepository;
        $this->mainSystemApi = $mainSystemApi;
    }

    /**
     * 查詢紀錄列表
     *
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function list($filters = [])
    {
        return $this->topupRepository->all($filters);
    }

    /**
     * 申請補點/扣點
     *
     * @param array $params
     * @param int   $requesterId
     * @return CreditTopup
     */
    public function request($params, $requesterId)
    {
        return $this->topupRepository->create([
            'station_id'    => $params['station_id'],
            'action_type'   => (int) $params['action_type'],
            'credit_type'   => $params['credit_type'] ?? 'credit',
            'usdt_amount'   => $params['usdt_amount'],
            'exchange_rate' => $params['exchange_rate'],
            'credit_amount' => $params['credit_amount'],
            'requested_by'  => $requesterId,
            'note'          => $params['note'] ?? null,
        ]);
    }

    /**
     * 審核通過 — 呼叫站台 API 補點/扣點
     *
     * @param CreditTopup $topup
     * @param int         $reviewerId
     * @return CreditTopup
     * @throws ValidationException
     */
    public function approve(CreditTopup $topup, $reviewerId)
    {
        if ((int) $topup->status !== CreditTopup::STATUS_PENDING
            && (int) $topup->status !== CreditTopup::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'topup' => ['此申請已審核過'],
            ]);
        }

        /** @var \App\Models\Station|null $station */
        $station = $topup->station;
        if (!$station || blank($station->api_url) || blank($station->api_key)) {
            return $this->topupRepository->update($topup, [
                'status'       => CreditTopup::STATUS_FAILED,
                'api_response' => '站台 API 未設定',
                'reviewed_by'  => $reviewerId,
                'reviewed_at'  => now(),
            ]);
        }

        // 扣點時 amount 帶負數
        /** @var float $amount 實際傳送至 API 的點數（扣點為負值） */
        $amount = (float) $topup->credit_amount;
        if ((int) $topup->action_type === CreditTopup::ACTION_DEDUCT) {
            $amount = -$amount;
        }

        $result = $this->mainSystemApi->addCredit(
            $station->api_url,
            $station->api_key,
            $amount,
            $topup->credit_type
        );

        return DB::transaction(function () use ($topup, $reviewerId, $result) {
            if ($result && ($result['code'] ?? 0) === 1) {
                return $this->topupRepository->update($topup, [
                    'status'       => CreditTopup::STATUS_COMPLETED,
                    'api_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'reviewed_by'  => $reviewerId,
                    'reviewed_at'  => now(),
                ]);
            }

            Log::error('站台補點 API 失敗', [
                'topup_id'   => $topup->id,
                'station_id' => $topup->station_id,
                'response'   => $result,
            ]);

            return $this->topupRepository->update($topup, [
                'status'       => CreditTopup::STATUS_FAILED,
                'api_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'reviewed_by'  => $reviewerId,
                'reviewed_at'  => now(),
            ]);
        });
    }

    /**
     * 審核拒絕
     *
     * @param CreditTopup $topup
     * @param int         $reviewerId
     * @return CreditTopup
     * @throws ValidationException
     */
    public function reject(CreditTopup $topup, $reviewerId)
    {
        if ($topup->status !== CreditTopup::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'topup' => ['此申請已審核過'],
            ]);
        }

        return $this->topupRepository->update($topup, [
            'status'      => CreditTopup::STATUS_REJECTED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);
    }
}
