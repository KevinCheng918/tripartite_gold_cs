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
 * @property string|null $expense_date  支出日期
 * @property string|null $note          備註
 */
class FinanceExpense extends Model
{
    protected $table = 'finance_expense';

    protected $guarded = ['id'];

    protected $casts = [
        'amount'       => 'decimal:2',
        'expense_date' => 'date',
        'reimbursed'   => 'integer',
    ];

    /**
     * @return BelongsTo
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(FinanceRecord::class, 'finance_record_id');
    }
}
