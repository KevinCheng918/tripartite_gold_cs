<?php

namespace App\Services;

use App\Models\CreditTopup;
use App\Repositories\CreditTopupRepository;
use Illuminate\Support\Arr;
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
    private $stationService;

    public function __construct(
        CreditTopupRepository $topupRepository,
        MainSystemApiService $mainSystemApi,
        TelegramChatService $telegramChatService,
        StationService $stationService
    ) {
        $this->topupRepository = $topupRepository;
        $this->mainSystemApi = $mainSystemApi;
        $this->telegramChatService = $telegramChatService;
        // 補點通過要在點數報表上留一個點，見 recordCreditLog()
        $this->stationService = $stationService;
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
     * 直接輸入點數時沒有 USDT 與匯率，兩欄存 0 並標記 input_type，
     * 讓財務統計能把這種紀錄排除在均匯率之外。
     *
     * @param array $params
     * @param int   $requesterId
     * @return CreditTopup
     */
    public function request($params, $requesterId)
    {
        $inputType = (int) ($params['input_type'] ?? CreditTopup::TYPE_USDT);
        $isDirectCredit = $inputType === CreditTopup::TYPE_CREDIT;

        return $this->topupRepository->create([
            'station_id'    => $params['station_id'],
            'action_type'   => (int) $params['action_type'],
            'credit_type'   => $params['credit_type'] ?? 'credit',
            'input_type'    => $inputType,
            'usdt_amount'   => $isDirectCredit ? 0 : $params['usdt_amount'],
            'exchange_rate' => $isDirectCredit ? 0 : $params['exchange_rate'],
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

        /*
         * ⚠ 「其他」**在這裡就結束**（需求方 2026-10-14）：不呼叫主系統 API、
         * 不檢查站台有沒有設 API、也不發 Telegram 通知。
         *
         * 這一類是「記一筆帳」—— 主系統的點數不會動，所以沒有東西可以通知
         * 客戶；硬發一則「已為您加點」會變成騙人。
         *
         * ⚠ 擺在站台 API 檢查**之前**：「其他」本來就不需要 API，
         * 擺後面的話，沒設 API 的站台連記一筆帳都會被判成失敗。
         */
        if ($topup->isOther()) {
            return $this->approveOther($topup, $reviewerId);
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
            $this->recordCreditLog($station, $updated, $result);
            $this->notifyTelegram($station, $updated, $result, $reviewerId, $reviewerName);
        }

        return $updated;
    }

    /**
     * 在點數報表上留一個點
     *
     * 需求方 2026-10-30：「點數報表，如果有補點，曲線圖要顯示原本是多少，
     * 補多少，現在是多少。」沒有這一筆的話，報表只看得到一小時一次的取樣 ——
     * 曲線上那一跳到底是補點還是客戶突然不消耗了，分不出來。
     *
     * ⚠ **異動金額是存下來的，不是算出來的**：前一次同步到補點之間，客戶照樣
     * 在消耗點數 —— 「跟前一個點的差」是「補的 ＋ 這段時間消耗的」。
     * 要講「補多少」只能用申請單上的金額。
     *
     * ⚠ **`credit_type` 原樣往下傳**：商城補點回的 `data.credit` 是商城餘額，
     * 記進系統點數那條曲線會變成一個憑空的大跳，所以兩種分成兩條線
     * （需求方 2026-10-30：「商城點數要可以用另外一條線」）。
     *
     * ⚠ 「其他」不會走到這裡 —— `approve()` 一開頭就轉去 `approveOther()`，
     * 那一類主系統的點數根本沒動，記進曲線會變成假的異動。
     *
     * ⚠ 整段的失敗處理在 `StationService::logCredit()`：寫報表用的紀錄失敗
     * 不能影響補點本身，點數已經加扣完成了。
     *
     * @param \App\Models\Station $station
     * @param CreditTopup         $topup
     * @param array|null          $result 主站 API 回應
     * @return void
     */
    private function recordCreditLog($station, CreditTopup $topup, $result)
    {
        $credits = Arr::get((array) $result, 'data.credit');

        /*
         * ⚠ 拿不到新餘額就**不要記**：填 0 或填舊值都會在圖上畫出一個
         * 假的點，比缺一個點難查得多。
         */
        if (blank($credits)) {
            Log::warning('補點成功但主站未回傳餘額，略過點數報表紀錄', [
                'topup_id'   => $topup->id,
                'station_id' => $station->id,
            ]);

            return;
        }

        /*
         * ⚠ 正負號用**字串**接上去，不要 `-1 * (float) $amount`：點數是
         * decimal，轉 float 在大數字上會丟精度，而且大到一定程度會印成
         * `1.0E+25` 寫進 decimal 欄位。同 `StationCreditLog::$credits`
         * 不 cast float 的理由。
         */
        $amount = ltrim((string) $topup->credit_amount, '-');
        $isDeduct = (int) $topup->action_type === CreditTopup::ACTION_DEDUCT;

        $this->stationService->recordTopupCredit(
            $station,
            (string) $credits,
            $isDeduct ? "-{$amount}" : $amount,
            (string) $topup->credit_type
        );
    }

    /**
     * 審核通過「其他」類型：只把這筆帳記成已完成
     *
     * ⚠ 這裡**沒有外部呼叫**，所以也沒有「API 失敗」這種結果 ——
     * 通過就是通過。`api_response` 留一句話說明為什麼是空的，
     * 不然事後看紀錄的人會以為 API 回傳掉了。
     *
     * @param CreditTopup $topup
     * @param int         $reviewerId
     * @return CreditTopup
     */
    private function approveOther(CreditTopup $topup, $reviewerId)
    {
        $updated = $this->topupRepository->update($topup, [
            'status'       => CreditTopup::STATUS_COMPLETED,
            'api_response' => (string) config('constants.STATION.TOPUP.OTHER_API_RESPONSE'),
            'reviewed_by'  => $reviewerId,
            'reviewed_at'  => now(),
        ]);

        Log::info('補點審核通過（其他，未呼叫主系統 API）', [
            'topup_id'   => $topup->id,
            'station_id' => $topup->station_id,
            'amount'     => $topup->credit_amount,
            'action'     => $topup->action_type,
        ]);

        return $updated;
    }

    /**
     * 把主站回傳的訊息送到站台對應的 Telegram 對話
     *
     * 通知失敗不能影響補點結果（點數已經加扣完成），因此只記 log 不往外拋。
     *
     * ⚠ **內容一律是主站回的 `msg` 原文**（需求方 2026-10-27 指定）——
     * 客服系統不自己組。這支只負責「送出去，並記下有沒有送到」。
     *
     * @param \App\Models\Station $station
     * @param CreditTopup         $topup  要記通知狀態的那一筆
     * @param array               $result 主站 API 回應
     * @param int                 $reviewerId
     * @param string|null         $reviewerName
     * @return void
     */
    private function notifyTelegram($station, CreditTopup $topup, $result, $reviewerId, $reviewerName)
    {
        // 以下兩種情況是「該通知卻沒通知」，用 warning 而非 info：
        // 正式環境常把 LOG_LEVEL 設在 warning/error，info 會被整個濾掉而無從追查
        if (!filled($station->telegram_group_id)) {
            Log::warning('站台未綁定 Telegram 群組，略過補點通知', [
                'station_id' => $station->id,
                'station'    => $station->name,
            ]);

            $this->markNotice($topup, null, CreditTopup::ISSUE_NO_GROUP);

            return;
        }

        $message = Arr::get((array) $result, 'msg');

        if (blank($message)) {
            Log::warning('補點成功但主站未回傳訊息，略過 Telegram 通知', [
                'station_id' => $station->id,
                'response'   => $result,
            ]);

            $this->markNotice($topup, null, CreditTopup::ISSUE_NO_MESSAGE);

            return;
        }

        /*
         * ⚠ **內容一律用主站回的原文**（需求方 2026-10-27 指定）。
         * 客服系統不自己組 —— 點數異動的敘述是主系統的事。
         *
         * 但格式舊的要標出來：主系統 `AdminCreditService` 2026-08-28 才改成
         * 「原本點數／操作點數／當前點數」三行，停在那之前的站台回的是
         * 「◯◯支付系統餘額已增加 N; 當前為 M」那一句。
         * 訊息照發（那是主站的事實），只是在後台標一下該更新那台主系統了。
         */
        $issue = $this->looksOldFormat($message) ? CreditTopup::ISSUE_OLD_FORMAT : null;

        // 主站回的是點數異動結果，補一句提醒讓客戶知道要去確認
        $footer = config('constants.STATION.TOPUP_NOTIFY_FOOTER');
        $content = filled($footer) ? "{$message}\n\n{$footer}" : $message;

        try {
            // mark_replied = false：這是系統通知，不該把客戶還在等的提問標記成已回覆
            $this->telegramChatService->sendReply(
                (int) $station->telegram_group_id,
                $content,
                $reviewerId,
                $reviewerName ?: '',
                /*
                 * ⚠ `is_auto` 要傳 true，即使觸發的是審核人員。那個旗標問的是
                 * 「這則訊息算不算客服親自回覆客人」—— 補點結果是系統組的通知，
                 * 跟客人問的問題無關。傳 false 的話 `sendReply()` 會順手把這個群
                 * 所有還掛著的求助單標成「客服已自行處理」。
                 * 跟 `mark_replied => false` 同一個理由。
                 */
                ['mark_replied' => false, 'is_auto' => true]
            );

            Log::info('補點結果已發送 Telegram', [
                'station_id'        => $station->id,
                'telegram_group_id' => $station->telegram_group_id,
                'issue'             => $issue,
            ]);

            $this->markNotice($topup, now(), $issue);
        } catch (\Exception $e) {
            Log::error('補點結果發送 Telegram 失敗', [
                'station_id'        => $station->id,
                'telegram_group_id' => $station->telegram_group_id,
                'error'             => $e->getMessage(),
            ]);

            $this->markNotice($topup, null, CreditTopup::ISSUE_SEND_FAILED);
        }
    }

    /**
     * 記下這一筆通知到底有沒有送出去、有什麼問題
     *
     * ⚠ `notified_at` 與 `notify_issue` **是兩件事**：舊格式那一種是
     * 「有送出去但格式舊」，所以兩欄都會有值。合併成一個狀態欄就表達不了。
     *
     * ⚠ 整段 try/catch：**記狀態失敗不能影響補點本身**。點數已經加扣完成，
     * 為了一個顯示用的欄位把審核流程往上拋，同仁會以為沒審過而再審一次。
     *
     * @param CreditTopup                        $topup
     * @param \Illuminate\Support\Carbon|null    $notifiedAt null＝沒送出去
     * @param string|null                        $issue      見 CreditTopup::ISSUE_*
     * @return void
     */
    private function markNotice(CreditTopup $topup, $notifiedAt, $issue)
    {
        try {
            $this->topupRepository->update($topup, [
                'notified_at'  => $notifiedAt,
                'notify_issue' => $issue,
            ]);
        } catch (\Exception $e) {
            Log::warning('補點通知狀態寫入失敗', [
                'topup_id' => $topup->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * 主站回的是不是舊格式
     *
     * 主系統 `AdminCreditService` 在 2026-08-28（commit `406fbfdc`）才改成
     * 「原本點數／操作點數／當前點數」三行；停在那之前的站台回的是
     * 「◯◯支付系統餘額已增加 N; 當前為 M」那一句，**少了原本點數、
     * 數字也沒有千分位**。
     *
     * ⚠ 認的是「有沒有**原本點數**」而不是整段比對：主系統日後再加欄位時，
     * 整段比對會把新格式也判成舊的。
     *
     * @param string $message
     * @return bool
     */
    private function looksOldFormat($message)
    {
        $marker = (string) config('constants.STATION.TOPUP.NEW_FORMAT_MARKER');

        // 短路：沒設定比對字串就不判斷（寧可不標，也不要全部標成舊的）
        if (blank($marker)) {
            return false;
        }

        return mb_strpos((string) $message, $marker) === false;
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
