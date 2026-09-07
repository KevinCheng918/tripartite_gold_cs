<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 財務支出明細 Model
 *
 * @property int         $id
 * @property int         $finance_record_id
 * @property string      $type          misc=雜項, server=雲端伺服器
 * @property string|null $category      雜項分類
 * @property string      $name          品名
 * @property float       $amount        金額
 * @property string      $currency      幣別
 * @property float|null  $exchange_rate 外幣支出的換算匯率，TWD 時為 null
 * @property string|null $expense_date  支出日期
 * @property string|null $note          備註
 * @property-read float  $twd_amount    換算成台幣後的金額
 */
class FinanceExpense extends Model
{
    protected $table = 'finance_expense';

    protected $guarded = ['id'];

    protected $casts = [
        'amount'        => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'expense_date'  => 'date',
        'reimbursed'    => 'integer',
    ];

    /** @var array 前端的支出合計都以台幣為準，換算值一併輸出 */
    protected $appends = ['twd_amount'];

    /**
     * 換算成台幣的金額
     *
     * TWD 支出直接回金額；外幣支出乘上輸入時記下的匯率。
     * 舊資料沒有匯率（欄位是後來才加的），此時無從換算，回 0 而不是拿原幣值
     * 硬充台幣，避免把 USDT 金額直接當台幣灌進合計。
     *
     * @return float
     */
    public function getTwdAmountAttribute()
    {
        if ($this->currency === 'TWD') {
            return (float) $this->amount;
        }

        return filled($this->exchange_rate)
            ? round((float) $this->amount * (float) $this->exchange_rate, 2)
            : 0.0;
    }

    /**
     * @return BelongsTo
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(FinanceRecord::class, 'finance_record_id');
    }
}
