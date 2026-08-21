<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 財務紀錄 Model（每月一筆）
 *
 * @property int         $id
 * @property string      $year_month
 * @property float|null  $topup_usdt
 * @property float|null  $topup_avg_rate
 * @property float|null  $topup_credit
 * @property float|null  $vm_income_usdt
 * @property int|null    $vm_income_count
 * @property int|null    $created_by
 */
class FinanceRecord extends Model
{
    protected $table = 'finance_record';

    protected $guarded = ['id'];

    protected $casts = [
        'topup_usdt'      => 'decimal:4',
        'topup_avg_rate'   => 'decimal:4',
        'topup_credit'     => 'decimal:2',
        'vm_income_usdt'   => 'decimal:4',
        'vm_income_count'  => 'integer',
    ];

    /**
     * @return HasMany
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class)->orderByDesc('id');
    }

    /**
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'nickname']);
    }
}
