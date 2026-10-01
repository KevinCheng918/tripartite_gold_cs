<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 每日匯率報價 Model
 *
 * 一天一筆（`date` 唯一）。`rate` 是 null 就代表今天的匯率還沒定下來 ——
 * 客人這時候問匯率要請他稍候，而不是回一個空值。
 *
 * @property int         $id
 * @property string      $date            日期（Y-m-d）
 * @property float|null  $rate            當日對客報價，null = 還沒定
 * @property float|null  $reference_rate  報價當下 MAX 的 4H 均價原始值
 * @property float|null  $suggested_rate  由 4H 均價算出的建議值，回「好」時採用
 * @property float|null  $yesterday_rate  報價當下昨天的匯率快照
 * @property int|null    $ask_message_id  報價訊息的 Telegram message_id
 * @property int|null    $replied_by      誰決定的
 * @property int         $remind_count    已提醒次數
 * @property \Illuminate\Support\Carbon|null $asked_at
 * @property \Illuminate\Support\Carbon|null $replied_at
 * @property \Illuminate\Support\Carbon|null $last_reminded_at
 */
class DailyRate extends Model
{
    use HasFactory;

    protected $table = 'daily_rate';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        /*
         * 匯率一律 float 不用 decimal cast —— decimal 會轉成字串，
         * 而這些值要拿去做數值比較與運算。顯示時再自己格式化。
         */
        'rate'             => 'float',
        'reference_rate'   => 'float',
        'suggested_rate'   => 'float',
        'yesterday_rate'   => 'float',
        'remind_count'     => 'integer',
        'asked_at'         => 'datetime',
        'replied_at'       => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    /**
     * 今天的匯率定下來了嗎
     *
     * @return bool
     */
    public function isDecided()
    {
        return filled($this->rate);
    }

    /**
     * 決定匯率的人
     *
     * @return BelongsTo
     */
    public function replier()
    {
        return $this->belongsTo(User::class, 'replied_by')->select(['id', 'account', 'nickname']);
    }
}
