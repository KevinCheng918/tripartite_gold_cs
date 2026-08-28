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
    private $telegramChatService;

    public function __construct(
        CreditTopupRepository $topupRepository,
        MainSystemApiService $mainSystemApi,
        TelegramChatService $telegramChatService
    ) {
        $this->topupRepository = $topupRepository;
        $this->mainSystemApi = $mainSystemApi;
        $this->telegramChatService = $telegramChatService;
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
            'images'        => $params['images'] ?? [],
        ]);
    }

    /**
     * 審核通過 — 呼叫站台 API 補點/扣點
     *
     * 加扣點成功後，會把主站回傳的訊息送到站台對應的 Telegram 對話。
     *
     * @param CreditTopup $topup
     * @param int         $reviewerId
     * @param string|null $reviewerName 顯示在 Telegram 對話中的發送者名稱
     * @return CreditTopup
     * @throws ValidationException
     */
    public function approve(CreditTopup $topup, $reviewerId, $reviewerName = null)
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

        $amount = (float) $topup->credit_amount;
        $action = (int) $topup->action_type === CreditTopup::ACTION_DEDUCT ? 'deduct' : 'add';

        $result = $this->mainSystemApi->addCredit(
            $station->api_url,
            $station->api_key,
            $amount,
            $topup->credit_type,
            $action
        );

        $updated = DB::transaction(function () use ($topup, $reviewerId, $result) {
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

        // 通知放在交易外：Telegram 是外部呼叫，不該把交易撐在那邊等
        if ((int) $updated->status === CreditTopup::STATUS_COMPLETED) {
            $this->notifyTelegram($station, $result, $reviewerId, $reviewerName);
        }

        return $updated;
    }

    /**
     * 把主站回傳的訊息送到站台對應的 Telegram 對話
     *
     * 通知失敗不能影響補點結果（點數已經加扣完成），因此只記 log 不往外拋。
     *
     * @param \App\Models\Station $station
     * @param array               $result 主站 API 回應
     * @param int                 $reviewerId
     * @param string|null         $reviewerName
     * @return void
     */
    private function notifyTelegram($station, $result, $reviewerId, $reviewerName)
    {
        if (!filled($station->telegram_group_id)) {
            Log::info('站台未綁定 Telegram 群組，略過補點通知', [
                'station_id' => $station->id,
                'station'    => $station->name,
            ]);

            return;
        }

        $message = $result['msg'] ?? null;
        if (blank($message)) {
            Log::info('補點成功但主站未回傳訊息，略過 Telegram 通知', [
                'station_id' => $station->id,
                'response'   => $result,
            ]);

            return;
        }

        try {
            // 最後一個參數 false：這是系統通知，不該把客戶還在等的提問標記成已回覆
            $this->telegramChatService->sendReply(
                (int) $station->telegram_group_id,
                $message,
                $reviewerId,
                $reviewerName ?: '',
                null,
                false
            );

            Log::info('補點結果已發送 Telegram', [
                'station_id'        => $station->id,
                'telegram_group_id' => $station->telegram_group_id,
            ]);
        } catch (\Exception $e) {
            Log::error('補點結果發送 Telegram 失敗', [
                'station_id'        => $station->id,
                'telegram_group_id' => $station->telegram_group_id,
                'error'             => $e->getMessage(),
            ]);
        }
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
