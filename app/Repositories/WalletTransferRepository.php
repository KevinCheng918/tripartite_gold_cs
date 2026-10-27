<?php

namespace App\Repositories;

use App\Models\WalletTransfer;
use Illuminate\Database\Eloquent\Collection;

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
     * 寫一筆，已經有了就不動它
     *
     * ⚠ 靠 `(address, tx_hash)` 的唯一索引擋重複。回傳的第二個值是
     * 「這次是不是新建的」—— 呼叫端要靠它決定「要不要發通知」，
     * 不然重跑一輪會把舊的全部重發一次。
     *
     * @param array $attributes 必須含 address 與 tx_hash
     * @return array{0: WalletTransfer, 1: bool} [紀錄, 是不是這次才建的]
     */
    public function firstOrCreate(array $attributes)
    {
        $existing = WalletTransfer::query()
            ->select(self::NOTICE_COLUMNS)
            ->where('address', $attributes['address'])
            ->where('tx_hash', $attributes['tx_hash'])
            ->first();

        if (filled($existing)) {
            return [$existing, false];
        }

        return [WalletTransfer::query()->create($attributes), true];
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
