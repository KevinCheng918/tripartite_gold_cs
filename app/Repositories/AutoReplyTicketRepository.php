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
        'ask_message_id', 'answer', 'answered_by', 'answered_at',
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
     * 依內部群組的求助訊息 id 找單
     *
     * 自己人引用回覆時，靠這個把答案對回原本那張單。
     *
     * @param int $askMessageId Telegram message_id
     * @return AutoReplyTicket|null
     */
    public function findByAskMessageId($askMessageId)
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->where('ask_message_id', $askMessageId)
            ->first();
    }

    /**
     * 該群組最近一張還沒處理完的單
     *
     * 用來判斷「這個群組已經開過單了，不要重複開」，
     * 以及客服自己人工回覆時要把哪張單關掉。
     *
     * @param int $groupId
     * @return AutoReplyTicket|null
     */
    public function findOpenByGroup($groupId)
    {
        $open = [
            config('constants.AUTO_REPLY.TICKET_STATUS.PENDING'),
            config('constants.AUTO_REPLY.TICKET_STATUS.ANSWERED'),
        ];

        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->where('telegram_group_id', $groupId)
            ->whereIn('status', $open)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 取得超時未回答、且還沒提醒到指定階段的單（排程提醒用）
     *
     * @param int $minutes      超時分鐘數
     * @param int $remindCount  目前的提醒階段（見 constants.AUTO_REPLY.REMIND）
     * @return Collection
     */
    public function getTimeoutTickets($minutes, $remindCount)
    {
        return AutoReplyTicket::query()
            ->select(self::COLUMNS)
            ->with('group')
            ->where('status', config('constants.AUTO_REPLY.TICKET_STATUS.PENDING'))
            ->where('remind_count', $remindCount)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->get();
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
