<?php

namespace App\Repositories;

use App\Models\DailyRate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

/**
 * 每日匯率 Repository
 */
class DailyRateRepository
{
    /** @var array 列表查詢欄位 */
    private const LIST_COLUMNS = [
        'id', 'date', 'rate', 'reference_rate', 'suggested_rate', 'yesterday_rate',
        'ask_message_id', 'asked_at', 'replied_by', 'replied_at',
        'remind_count', 'last_reminded_at',
    ];

    /**
     * 資料表建好了沒
     *
     * 給上線前檢查用（`rate:check`）—— migration 還沒跑的話，
     * 整個功能的任何查詢都會直接噴 SQL 錯誤。
     *
     * @return bool
     */
    public function tableExists()
    {
        return Schema::hasTable((new DailyRate())->getTable());
    }

    /**
     * 查某一天
     *
     * @param string $date Y-m-d
     * @return DailyRate|null
     */
    public function findByDate($date)
    {
        return DailyRate::query()
            ->select(self::LIST_COLUMNS)
            ->where('date', $date)
            ->first();
    }

    /**
     * 依報價訊息的 message_id 查
     *
     * 自己人引用回覆時，webhook 拿到的是 reply_to_message.message_id，
     * 靠它對回當天那筆。
     *
     * @param int $messageId
     * @return DailyRate|null
     */
    public function findByAskMessageId($messageId)
    {
        return DailyRate::query()
            ->select(self::LIST_COLUMNS)
            ->where('ask_message_id', (int) $messageId)
            ->first();
    }

    /**
     * 最近一筆「已經決定」的匯率
     *
     * 報價訊息要附上「上次報多少」當參考。刻意不寫死「昨天」——
     * 昨天可能根本沒人回，那樣就沒有參考值可附了。
     *
     * @param string|null $before 只看這天以前（不含），預設不限
     * @return DailyRate|null
     */
    public function latestDecided($before = null)
    {
        $query = DailyRate::query()
            ->select(self::LIST_COLUMNS)
            ->whereNotNull('rate');

        if (filled($before)) {
            $query->where('date', '<', $before);
        }

        return $query->orderByDesc('date')->first();
    }

    /**
     * 今天已經報過、但還沒有人決定匯率的那筆
     *
     * 提醒排程用。
     *
     * @param string $date Y-m-d
     * @return DailyRate|null
     */
    public function findUndecided($date)
    {
        return DailyRate::query()
            ->select(self::LIST_COLUMNS)
            ->where('date', $date)
            ->whereNull('rate')
            ->whereNotNull('ask_message_id')
            ->first();
    }

    /**
     * 歷史列表（新的在前）
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginate($perPage = 30)
    {
        return DailyRate::query()
            ->select(self::LIST_COLUMNS)
            ->with(['replier'])
            ->orderByDesc('date')
            ->paginate($perPage);
    }

    /**
     * 建立或取得某天那筆
     *
     * date 有 unique index，用 firstOrCreate 讓同一天重複呼叫不會炸。
     *
     * @param string $date
     * @param array  $attributes
     * @return DailyRate
     */
    public function firstOrCreateByDate($date, $attributes = [])
    {
        return DailyRate::query()->firstOrCreate(['date' => $date], $attributes);
    }

    /**
     * 更新
     *
     * @param DailyRate $rate
     * @param array     $attributes
     * @return DailyRate
     */
    public function update(DailyRate $rate, $attributes)
    {
        $rate->update($attributes);

        return $rate;
    }
}
