<?php

namespace App\Repositories;

use App\Models\ConversationFollowup;
use Illuminate\Database\Eloquent\Collection;

/**
 * 待追蹤事項 Repository
 *
 * 四種查詢：去重（同對話同來源只留一張）、撈「該問當班人員了」的、
 * 找某個對話還活著的單（判斷對方是否回來了）、後台列表。
 */
class ConversationFollowupRepository
{
    /** @var array 待追蹤事項欄位 */
    private const COLUMNS = [
        'id', 'telegram_group_id', 'telegram_message_id', 'source',
        'sender_name', 'sender_user_id', 'wait_text', 'matched_keyword',
        'wait_at', 'status', 'wait_count', 'ask_round', 'ask_message_id',
        'remind_count', 'last_reminded_at', 'decided_by', 'decided_at',
        'created_at', 'updated_at',
    ];

    /**
     * 開一筆
     *
     * @param array $attributes
     * @return ConversationFollowup
     */
    public function create($attributes)
    {
        return ConversationFollowup::query()->create($attributes);
    }

    /**
     * @param ConversationFollowup $followup
     * @param array                $attributes
     * @return ConversationFollowup
     */
    public function update(ConversationFollowup $followup, $attributes)
    {
        $followup->fill($attributes);
        $followup->save();

        return $followup;
    }

    /**
     * @param int $id
     * @return ConversationFollowup|null
     */
    public function find($id)
    {
        return ConversationFollowup::query()
            ->select(self::COLUMNS)
            ->with(['group', 'message', 'sender'])
            ->find($id);
    }

    /**
     * 這個對話、這種來源還活著的那一筆
     *
     * 去重用：每說一次「稍等」就開一張的話，一個對話能堆出五張幾乎一樣的單。
     *
     * ⚠ 條件一定要帶 `source`：客人承諾與我方承諾是兩件事，不能互相蓋掉。
     *
     * @param int $groupId
     * @param int $source 見 constants.FOLLOWUP.SOURCE
     * @return ConversationFollowup|null
     */
    public function findOpenByGroupAndSource($groupId, $source)
    {
        return ConversationFollowup::query()
            ->select(self::COLUMNS)
            ->with(['group', 'message'])
            ->where('telegram_group_id', $groupId)
            ->where('source', $source)
            ->whereIn('status', $this->openStatuses())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 這個對話所有還活著的事項
     *
     * 對方傳新訊息時要靠這個判斷「他是不是回來了」。
     *
     * @param int $groupId
     * @return Collection<ConversationFollowup>
     */
    public function getOpenByGroup($groupId)
    {
        return ConversationFollowup::query()
            ->select(self::COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->whereIn('status', $this->openStatuses())
            ->orderBy('id')
            ->get();
    }

    /**
     * 該去問當班人員的事項
     *
     * 判斷邏輯跟求助單提醒一樣（`AutoReplyTicketRepository::getTicketsDueForRemind`），
     * 只是起算點不同：求助單從開單時間算，這裡從 `wait_at` 算 —— 對方每說一次話
     * 就把它往後推，所以實際語意是「這個對話安靜夠久了」。
     *
     * @param int $quietMinutes    安靜幾分鐘才問
     * @param int $intervalMinutes 沒人按按鈕時每隔幾分鐘再問
     * @param int $maxCount        最多問幾次
     * @return Collection<ConversationFollowup>
     */
    public function getDueForAsk($quietMinutes, $intervalMinutes, $maxCount)
    {
        $now = now();

        return ConversationFollowup::query()
            ->select(self::COLUMNS)
            ->with(['group', 'message', 'sender'])
            ->whereIn('status', $this->openStatuses())
            /*
             * 到上限的也要撈出來 —— 還差一次就是「收尾那則」。
             * 真正停下來是 `remind_count > $maxCount`，不是 `>=`。
             */
            ->where('remind_count', '<=', $maxCount)
            ->where(function ($query) use ($now, $quietMinutes, $intervalMinutes) {
                // 還沒問過：從計時起點算
                $query->where(function ($first) use ($now, $quietMinutes) {
                    $first->where('remind_count', 0)
                        ->whereNotNull('wait_at')
                        ->where('wait_at', '<=', $now->copy()->subMinutes($quietMinutes));
                })
                // 問過了：從上次問的時間算，所以間隔是「每隔」而不是「安靜後第 N 分鐘」
                ->orWhere(function ($again) use ($now, $intervalMinutes) {
                    $again->where('remind_count', '>', 0)
                        ->whereNotNull('last_reminded_at')
                        ->where('last_reminded_at', '<=', $now->copy()->subMinutes($intervalMinutes));
                })
                /*
                 * 問過了卻沒有 last_reminded_at —— 不該發生，但真的發生時
                 * 這筆會永遠撈不到、永遠不再被問。寧可當成「該問了」。
                 */
                ->orWhere(function ($broken) {
                    $broken->where('remind_count', '>', 0)->whereNull('last_reminded_at');
                });
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * 放太久還沒結果的事項
     *
     * ⚠ 排程**不掛這支**（`FOLLOWUP.STALE_DAYS` 預設 0 = 停用）：需求方要
     * 「不自動結案」，就不該有一條悄悄替他收單的路。要用時手動給天數。
     *
     * @param int $days 從計時起點算起超過幾天
     * @return Collection<ConversationFollowup>
     */
    public function getStale($days)
    {
        return ConversationFollowup::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->whereIn('status', $this->openStatuses())
            ->whereNotNull('wait_at')
            ->where('wait_at', '<=', now()->subDays($days))
            ->orderBy('id')
            ->get();
    }

    /**
     * 後台列表（分頁）
     *
     * 條件用 `Criteria` 封裝（來源／狀態／對話），這裡只負責套用與排序。
     *
     * ⚠ 排序是「計時起點最早的在前」—— 列表要回答的是「哪一筆放最久」，
     * 不是「哪一筆最新」。
     *
     * @param array<\App\Criteria\CriteriaInterface> $criteria
     * @param int                                    $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(array $criteria, $perPage = 20)
    {
        $query = ConversationFollowup::query()
            ->select(self::COLUMNS)
            // eager load：少了這行，列表每一列都會各查一次對話與人（N+1）
            ->with(['group', 'sender', 'decider']);

        foreach ($criteria as $one) {
            $query = $one->apply($query);
        }

        return $query->orderBy('wait_at')->paginate($perPage);
    }

    /**
     * 某一段期間新開了幾筆，依來源分組
     *
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d（含當天）
     * @return \Illuminate\Support\Collection<object{source:int, total:int}>
     */
    public function countNewByRange($startDate, $endDate)
    {
        return ConversationFollowup::query()
            ->selectRaw('source, COUNT(*) AS total')
            /*
             * 用 whereBetween 而不是 whereDate：後者等於
             * `DATE(created_at) = ?`，欄位被函式包住就吃不到索引。
             */
            ->whereBetween('created_at', [
                "{$startDate} 00:00:00",
                "{$endDate} 23:59:59",
            ])
            ->groupBy('source')
            ->get();
    }

    /**
     * 某一段期間有幾筆有了結果，依狀態分組
     *
     * 看 `decided_at` 之外還要看狀態：對方自己回來（ANSWERED）沒有人按按鈕，
     * 所以那一類沒有 `decided_at`。改用 `updated_at` 當落點。
     *
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d（含當天）
     * @return \Illuminate\Support\Collection<object{status:int, total:int}>
     */
    public function countClosedByRange($startDate, $endDate)
    {
        return ConversationFollowup::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->whereNotIn('status', $this->openStatuses())
            ->whereBetween('updated_at', [
                "{$startDate} 00:00:00",
                "{$endDate} 23:59:59",
            ])
            ->groupBy('status')
            ->get();
    }

    /**
     * 現在還掛著幾筆
     *
     * ⚠ 這個數字跟期間無關（是「此刻」的待辦量），所以不吃日期 ——
     * 統計那則要回答的是「我今天要處理幾件」。
     *
     * @return int
     */
    public function countOpen()
    {
        return ConversationFollowup::query()
            ->whereIn('status', $this->openStatuses())
            ->count();
    }

    /**
     * 還掛著的事項裡拖最久／拖最多次的幾筆
     *
     * @param string $orderBy wait_at（最久）或 wait_count（最多次）
     * @param int    $limit
     * @return Collection<ConversationFollowup>
     */
    public function getOpenTop($orderBy, $limit)
    {
        $query = ConversationFollowup::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->whereIn('status', $this->openStatuses());

        // wait_at 是「越早越久」，次數是「越大越嚴重」
        $orderBy === 'wait_at'
            ? $query->orderBy('wait_at')
            : $query->orderByDesc('wait_count');

        return $query->limit($limit)->get();
    }

    /**
     * 還活著的狀態
     *
     * 等待中與已追問之外都算結束。單獨一支是為了四個查詢共用同一份定義 ——
     * 各自寫一次 `whereIn` 遲早會漂移。
     *
     * @return array<int>
     */
    private function openStatuses()
    {
        $status = (array) config('constants.FOLLOWUP.STATUS');

        return [(int) $status['WAITING'], (int) $status['ASKED']];
    }
}
