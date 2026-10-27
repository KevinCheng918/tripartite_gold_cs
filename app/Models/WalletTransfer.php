<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 監聽中的 TRON 錢包轉帳紀錄
 *
 * @property int         $id
 * @property string      $address          監聽中的那個錢包
 * @property string      $tx_hash
 * @property string      $direction        in／out（見 DIRECTION_*）
 * @property string      $from_address
 * @property string      $to_address
 * @property string      $amount           已換算過小數位的金額（字串，刻意不轉 float）
 * @property string      $symbol           幣別，例如 USDT
 * @property string      $contract_address
 * @property \Illuminate\Support\Carbon      $block_at
 * @property \Illuminate\Support\Carbon|null $notified_at
 * @property string|null $skip_reason      見 SKIP_*；null 表示本來就該發
 */
class WalletTransfer extends Model
{
    protected $table = 'wallet_transfer';

    protected $guarded = ['id'];

    /** @var string 轉入（監聽的錢包是收款方） */
    public const DIRECTION_IN = 'in';

    /** @var string 轉出（監聽的錢包是付款方） */
    public const DIRECTION_OUT = 'out';

    /**
     * 首次加入這個錢包時補進來的歷史紀錄
     *
     * ⚠ 這些**刻意不發通知**：新增錢包時鏈上可能有好幾千筆歷史轉帳，
     * 照發會在存檔那一刻把收件人炸掉。第一輪只建立基準，之後才開始通知。
     *
     * @var string
     */
    public const SKIP_BASELINE = 'baseline';

    /**
     * 低於金額門檻
     *
     * ⚠ TRON 有「地址投毒」攻擊：從長得很像的地址丟 0.000001 USDT 進來，
     * 讓人下次複製到錯的地址。不擋的話通知會被洗版 —— 但**還是要記一筆**，
     * 事後要查「那天到底有沒有這筆」才查得到。
     *
     * @var string
     */
    public const SKIP_BELOW_MIN = 'below_min';

    /**
     * 自己轉給自己（from 與 to 都是這個錢包）
     *
     * ⚠ 不是錯誤，但也沒有「進來還是出去」可言 —— 記著就好、不通知。
     * 跟 `SKIP_BELOW_MIN` 分開是為了事後查得出來：把它併進「低於門檻」的話，
     * 有人問「為什麼這筆沒通知」就會得到錯的答案。
     *
     * @var string
     */
    public const SKIP_SELF = 'self';

    /*
     * ⚠ `amount` **不要 cast 成 float**：USDT 是 6 位小數、有些 TRC20 代幣
     * 是 18 位，decimal 轉 float 會丟精度，而這是錢。
     * 保持字串，要運算時由呼叫端自己決定（同 `StationCreditLog::$credits`）。
     */
    protected $casts = [
        'block_at'    => 'datetime',
        'notified_at' => 'datetime',
    ];

    /**
     * 這筆是轉入嗎
     *
     * @return bool
     */
    public function isIncoming()
    {
        return $this->direction === self::DIRECTION_IN;
    }
}
