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
 * @property string|null $proof_image    繳款證明圖片路徑
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
    ];

    public function vmServer(): BelongsTo
    {
        return $this->belongsTo(VmServer::class);
    }
}
