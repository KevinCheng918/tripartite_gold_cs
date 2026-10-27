<?php

namespace App\Repositories;

use App\Models\WalletTransfer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 錢包轉帳紀錄
 */
class WalletTransferRepository
{
    /**
     * 組通知要用的欄位
     *
     * @var array<string>
     */
    private const NOTICE_COLUMNS = [
        'id', 'address', 'tx_hash', 'direction', 'from_address', 'to_address',
        'amount', 'symbol', 'block_at', 'notified_at', 'skip_reason',
    ];

    /**
     * 這個錢包已經看到哪個時間了（輪詢的游標）
     *
     * ⚠ 看 `block_at` 不是 `created_at`：前者是鏈上時間，才是問 TronGrid 時
     * 要帶的 `min_timestamp`。拿寫入時間去問，重跑或補資料就會整段錯開。
     *
     * @param string $address
     * @return \Illuminate\Support\Carbon|null 還沒有任何紀錄時回 null
     */
    public function latestBlockAt($address)
    {
        $latest = WalletTransfer::query()
            ->select(['id', 'block_at'])
            ->where('address', $address)
            ->orderByDesc('block_at')
            ->first();

        return filled($latest) ? $latest->block_at : null;
    }

    /**
     * 這一批 hash 裡，哪些已經記過了
     *
     * ⚠ **一次問完，不要逐筆去查**。建立基準那一輪會一口氣拿回 200 筆
     * （翻頁上限內最多 1000 筆），逐筆查就是 1000 次 SELECT。
     *
     * @param string        $address
     * @param array<string> $hashes
     * @return array<string, bool> 以 tx_hash 當 key，方便 O(1) 判斷
     */
    public function existingHashes($address, array $hashes)
    {
        if (blank($hashes)) {
            return [];
        }

        $found = WalletTransfer::query()
            ->select(['id', 'tx_hash'])
            ->where('address', $address)
            ->whereIn('tx_hash', $hashes)
            ->pluck('tx_hash')
            ->all();

        return array_fill_keys($found, true);
    }

    /**
     * 寫一筆
     *
     * ⚠ 去重由呼叫端用 `existingHashes()` 先做掉，這裡只負責寫 ——
     * 但 `(address, tx_hash)` 的唯一索引還是最後一道：兩輪重疊時
     * （`withoutOverlapping` 擋不住的極端情況）可能同時要寫同一筆。
     * 撞到就回 null，呼叫端當成「已經有了」跳過，不要讓一筆卡住整批。
     *
     * @param array $attributes 必須含 address 與 tx_hash
     * @return WalletTransfer|null 撞到唯一索引時回 null
     */
    public function create(array $attributes)
    {
        try {
            return WalletTransfer::query()->create($attributes);
        } catch (QueryException $e) {
            Log::warning('錢包轉帳紀錄重複寫入，略過這一筆', [
                'address' => Arr::get($attributes, 'address'),
                'tx_hash' => Arr::get($attributes, 'tx_hash'),
            ]);

            return null;
        }
    }

    /**
     * 標記成已送出
     *
     * @param WalletTransfer $transfer
     * @return void
     */
    public function markNotified(WalletTransfer $transfer)
    {
        $transfer->update(['notified_at' => now()]);
    }

    /**
     * 標記成「不發，因為⋯⋯」
     *
     * @param WalletTransfer $transfer
     * @param string         $reason
     * @return void
     */
    public function markSkipped(WalletTransfer $transfer, $reason)
    {
        $transfer->update(['skip_reason' => $reason]);
    }

    /**
     * 還沒送出去、而且還值得補發的那些
     *
     * ⚠ `skip_reason` 要是 null —— 低於門檻與首次基準那些本來就不該發，
     * 不加這個條件會把它們全部補發出去。
     *
     * ⚠ 只抓 `$since` 之後的：服務中斷一週後恢復，不該一次倒幾百則出來。
     *
     * @param string $address
     * @param string $since Y-m-d H:i:s
     * @return Collection
     */
    public function getPending($address, $since)
    {
        return WalletTransfer::query()
            ->select(self::NOTICE_COLUMNS)
            ->where('address', $address)
            ->whereNull('notified_at')
            ->whereNull('skip_reason')
            ->where('block_at', '>=', $since)
            ->orderBy('block_at')
            ->get();
    }
}
