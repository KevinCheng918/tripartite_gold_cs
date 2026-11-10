<?php

namespace App\Repositories;

use App\Models\AutoReplyTicket;
use Illuminate\Database\Eloquent\Collection;

/**
 * 自動回覆求助單 Repository
 */
class AutoReplyTicketRepository
{
    /** @var array 求助單欄位 */
    private const COLUMNS = [
        'id', 'telegram_group_id', 'message_id', 'question',
        // ⚠ ask_chat_id 不能漏：少撈它會讓 Service 的 chat 比對永遠不相符，
        //   而症狀是「引用回覆完全沒反應」，不會報錯（2026-11-10 事故的那一欄）
        'ask_message_id', 'ask_chat_id', 'answer', 'answered_by', 'answered_at',
        'remind_count', 'last_reminded_at', 'status', 'quick_reply_item_id',
        'created_at',
    ];

    /**
     * 開單
     *
     * @param array $attributes
     * @return AutoReplyTicket
     */
    public function create($attributes)
    {
        return AutoReplyTicket::query()->create($attributes);
    }

    /**
     * @param int $id
     * @return AutoReplyTicket|null
     */
    public function find($id)
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->find($id);
    }

    /**
     * 依內部群組的求助訊息 id 找出**所有**可能的單
     *
     * 自己人引用回覆時，靠這個把答案對回原本那張單。
     *
     * ⚠⚠ **刻意回傳多筆而不是 `first()`**（2026-11-10 線上事故）：
     * Telegram 的 message_id **只在單一 chat 內唯一**，而這張表存著歷來
     * 所有支援群組的單 —— 支援群組換過 chat（開啟話題會讓群組升級成
     * supergroup 並換發 chat_id）之後，新舊兩批單的 ask_message_id 會重疊。
     * 當時是無排序的 `first()`，撈到哪一張是**未定義行為**，結果把答案
     * 送到了另一個客人的群組。
     *
     * 真正的對應鍵是 **(ask_chat_id, ask_message_id)**，但比對刻意留在
     * Service：那裡才知道「現在的支援群組是哪一個」，而且同一次查詢的結果
     * 還要用來分辨「對不到」與「對到別的 chat」——
     * 後者要回一句說明給同仁，不能跟閒聊一樣靜靜吃掉。
     *
     * ⚠ **不要為了省事改回在這裡 `where('ask_chat_id', ...)`**：那樣
     * 「這則引用其實屬於舊 chat」這個資訊就在 SQL 裡被丟掉了，
     * 同仁會看到跟閒聊一模一樣的「沒反應」。
     *
     * ⚠ 不設 `limit`：同一個 ask_message_id 最多也只有換過幾次 chat 那幾筆，
     * 而 `ask_message_id` 本來就有索引。
     *
     * @param int $askMessageId Telegram message_id
     * @return Collection<AutoReplyTicket> 新的在前
     */
    public function findByAskMessageId($askMessageId)
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->where('ask_message_id', $askMessageId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 該群組最近一張還沒處理完的單
     *
     * 客服自己人工回覆時，用來判斷要把哪張單關掉。
     *
     * @param int $groupId
     * @return AutoReplyTicket|null
     */
    public function findOpenByGroup($groupId)
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->whereIn('status', $this->openStatuses())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 同一個問題是不是已經有單在處理中
     *
     * 客人手滑重複貼、或等不及又問一次時，不必開第二張單洗版內部群組。
     * 不同的問題仍然要各開一張 —— 漏掉客戶的問題比多幾則訊息嚴重得多。
     *
     * @param int    $groupId
     * @param string $question 已 trim
     * @return AutoReplyTicket|null
     */
    public function findOpenByQuestion($groupId, $question)
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->where('question', $question)
            ->whereIn('status', $this->openStatuses())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 還沒處理完的狀態
     *
     * @return array
     */
    private function openStatuses()
    {
        return [
            config('constants.AUTO_REPLY.TICKET_STATUS.PENDING'),
            config('constants.AUTO_REPLY.TICKET_STATUS.ANSWERED'),
        ];
    }

    /**
     * 催到升級門檻、而且還沒人處理的單（逾時升級用）
     *
     * 催到第 N 次代表已經逾時一個多小時，該打電話把當班人叫起來了。
     *
     * ⚠ **狀態條件用 `openStatuses()`** —— 已回覆客人、已入題庫、被忽略、
     * 被收掉的單都不該再升級。這也是「事情處理了沒」唯一可信的訊號：
     * 電話接起來會斷線、私訊讀不到已讀，兩者都不能當確認。
     *
     * ⚠ **沒有上界**：一旦跨過門檻就一直撈得到，直到那張單被處理。
     * 要不要繼續升級由呼叫端看升級紀錄決定（撥滿 3 通就換私訊、
     * 私訊完就停），不是在這裡擋。
     *
     * @param int $triggerAt 催到第幾次開始升級
     * @return Collection<AutoReplyTicket>
     */
    public function getEscalationCandidates($triggerAt)
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->whereIn('status', $this->openStatuses())
            ->where('remind_count', '>=', $triggerAt)
            ->orderBy('id')
            ->get();
    }

    /**
     * 取得「該提醒了」的未回答求助單（排程提醒用）
     *
     * 2026-10-06 從固定兩階段改成持續提醒，所以判斷依據從
     * 「`remind_count` 等於某個階段」換成「**距離上次提醒已超過間隔**」。
     *
     * @param int $firstMinutes    開單後多久送第一次
     * @param int $intervalMinutes 之後每隔多久一次
     * @param int $maxCount        最多催幾次（含收尾那則）
     * @return Collection
     */
    public function getTicketsDueForRemind($firstMinutes, $intervalMinutes, $maxCount)
    {
        $now = now();

        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->where('status', config('constants.AUTO_REPLY.TICKET_STATUS.PENDING'))
            /*
             * 到上限的單也要撈出來 —— 還差一次就是「收尾那則」。
             * 真正停下來是 `remind_count > $maxCount`，不是 `>=`。
             */
            ->where('remind_count', '<=', $maxCount)
            ->where(function ($query) use ($now, $firstMinutes, $intervalMinutes) {
                // 還沒催過：從開單時間起算
                $query->where(function ($first) use ($now, $firstMinutes) {
                    $first->where('remind_count', 0)
                        ->where('created_at', '<=', $now->copy()->subMinutes($firstMinutes));
                })
                // 催過了：從上次提醒起算，所以間隔是「每隔」而不是「開單後第 N 分鐘」
                ->orWhere(function ($again) use ($now, $intervalMinutes) {
                    $again->where('remind_count', '>', 0)
                        ->whereNotNull('last_reminded_at')
                        ->where('last_reminded_at', '<=', $now->copy()->subMinutes($intervalMinutes));
                })
                /*
                 * 催過了卻沒有 last_reminded_at —— 不該發生，但真的發生時
                 * 這張單會永遠撈不到、永遠不再被催。寧可當成「該催了」。
                 */
                ->orWhere(function ($broken) {
                    $broken->where('remind_count', '>', 0)->whereNull('last_reminded_at');
                });
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * 所有還在等人回答的單（早班待接手清單用）
     *
     * 跟 `getTicketsDueForRemind()` 的差別：那支問「現在該催了嗎」（看時間），
     * 這支問「有哪些還沒人處理」（不看時間，連超過提醒上限的也要列）——
     * 撞到上限的單系統已經放手了，反而最需要人接手。
     *
     * 最舊的排前面：等最久的客人要先看到。
     *
     * @return Collection
     */
    public function getPendingTickets()
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->where('status', config('constants.AUTO_REPLY.TICKET_STATUS.PENDING'))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * 放太久還沒人處理的單（清理用）
     *
     * ⚠ 跟 `getPendingTickets()`（待接手清單）看的是同一批，差別只在多一個
     * 「開單超過 N 天」的條件 —— 那份清單列的就是這些還沒結束的單。
     *
     * @param int $days 開單超過幾天
     * @return Collection
     */
    public function getStalePending($days)
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->where('status', config('constants.AUTO_REPLY.TICKET_STATUS.PENDING'))
            ->where('created_at', '<', now()->subDays($days))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * 整批標成「放太久自動收掉」
     *
     * ⚠ 用一次 update 而不是逐筆 —— 積了兩週的單可能有上百張，
     * 逐筆更新就是上百次查詢。
     *
     * @param array $ids
     * @return int 實際更新幾筆
     */
    public function markExpired(array $ids)
    {
        if (blank($ids)) {
            return 0;
        }

        return AutoReplyTicket::query()
            ->whereIn('id', $ids)
            // 再確認一次狀態：撈出來到更新之間，可能剛好有人處理掉了
            ->where('status', config('constants.AUTO_REPLY.TICKET_STATUS.PENDING'))
            ->update([
                'status'     => config('constants.AUTO_REPLY.TICKET_STATUS.EXPIRED'),
                'updated_at' => now(),
            ]);
    }

    /**
     * 在後台把一張還掛著的單標成「客服已自行處理」
     *
     * ⚠ **狀態條件帶在 update 的 where 裡，不是先查再寫。** 同一張單很可能
     * 正好有人在 Telegram 按按鈕 —— 先查後寫的話兩邊都會成功，後寫的那個
     * 會把前一個蓋掉（例如把「已加入題庫」蓋回「客服已自行處理」）。
     * 用回傳筆數判斷誰先到：0 就代表別人已經處理過了。
     *
     * @param int $id
     * @return int 實際更新幾筆（0 = 這張單已經不在未處理狀態）
     */
    public function markHandledById($id)
    {
        return AutoReplyTicket::query()
            ->where('id', $id)
            ->whereIn('status', $this->openStatuses())
            ->update([
                'status'     => config('constants.AUTO_REPLY.TICKET_STATUS.IGNORED'),
                'updated_at' => now(),
            ]);
    }

    /**
     * 更新求助單
     *
     * @param AutoReplyTicket $ticket
     * @param array           $attributes
     * @return AutoReplyTicket
     */
    public function update(AutoReplyTicket $ticket, $attributes)
    {
        $ticket->update($attributes);

        return $ticket->refresh();
    }

    /**
     * 把該群組所有未處理完的單標為忽略
     *
     * 客服自己人工回覆客人之後，這些單就不該再自動轉出去。
     *
     * @param int $groupId
     * @return int 影響筆數
     */
    public function ignoreOpenByGroup($groupId)
    {
        $open = [
            config('constants.AUTO_REPLY.TICKET_STATUS.PENDING'),
            config('constants.AUTO_REPLY.TICKET_STATUS.ANSWERED'),
        ];

        return AutoReplyTicket::query()
            ->where('telegram_group_id', $groupId)
            ->whereIn('status', $open)
            ->update(['status' => config('constants.AUTO_REPLY.TICKET_STATUS.IGNORED')]);
    }

    /**
     * 分頁列表（後台查閱用）
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = 20)
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->with(['group', 'quickReplyItem'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
