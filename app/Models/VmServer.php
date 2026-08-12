<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 虛擬機 Model
 *
 * @property int         $id
 * @property int|null    $station_id
 * @property string      $hostname
 * @property string|null $internal_ip
 * @property string|null $external_ip
 * @property string|null $model_type
 * @property string      $spec
 * @property float       $monthly_fee
 * @property float       $vpn_fee
 * @property float       $google_fee
 * @property int         $billing_day
 * @property int         $power_status  1=開機, 0=關機
 * @property int         $status        1=啟用, 0=停用
 * @property string|null $note
 */
class VmServer extends Model
{
    use HasFactory;

    protected $table = 'vm_server';

    protected $guarded = ['id'];

    protected $casts = [
        'monthly_fee'  => 'decimal:2',
        'vpn_fee'      => 'decimal:2',
        'google_fee'   => 'decimal:2',
        'billing_day'  => 'integer',
        'power_status' => 'integer',
        'status'       => 'integer',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class)->select(['id', 'name', 'domain']);
    }

    public function billings(): HasMany
    {
        return $this->hasMany(VmBilling::class);
    }
}
