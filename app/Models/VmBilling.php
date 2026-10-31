<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 虛擬機帳單 Model
 *
 * @property int         $id
 * @property int         $vm_server_id
 * @property string      $billing_month  YYYY-MM
 * @property float       $amount
 * @property int         $paid           0=未收, 1=已收, 2=待審核
 * @property string|null $proof_image       繳款證明圖片路徑
 * @property int|null    $proof_uploaded_by 繳款證明是誰上傳的
 * @property string|null $proof_uploaded_at 繳款證明的上傳時間
 * @property string|null $paid_at
 * @property string      $due_date
 * @property string|null $note
 */
class VmBilling extends Model
{
    use HasFactory;

    protected $table = 'vm_billing';

    protected $guarded = ['id'];

    protected $casts = [
        'amount'        => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'paid'          => 'integer',
        'paid_at' => 'datetime',
        'due_date' => 'date',
        'proof_uploaded_at' => 'datetime',
    ];

    public function vmServer(): BelongsTo
    {
        return $this->belongsTo(VmServer::class);
    }

    /**
     * 繳款證明是誰上傳的（需求方 2026-11-01）
     *
     * ⚠ **`withTrashed()`**：帳號是軟刪除的，而 FK 的 `nullOnDelete` 只在真的
     * `DELETE` 時觸發 —— 上傳者離職之後 `proof_uploaded_by` 還指著他。
     * 不加的話關聯被 `SoftDeletes` 的 global scope 濾成 null，帳務紀錄上
     * 就變成「—」，跟「這筆根本沒記上傳者」混在一起。
     *
     * 這是一份財務紀錄，「當時是誰上傳的」不該因為那個人離職就消失。
     *
     * @return BelongsTo
     */
    public function proofUploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proof_uploaded_by')
            ->withTrashed()
            ->select(['id', 'nickname']);
    }
}
