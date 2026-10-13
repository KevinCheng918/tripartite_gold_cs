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
 * @property string|null $vpn_renew_date VPN 下一次續約日期（vpn_fee > 0 才有意義）
 * @property float       $google_fee
 * @property int         $billing_day
 * @property int         $power_status   1=開機, 0=關機
 * @property string|null $powered_off_at 關機日期
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
        'power_status'   => 'integer',
        'powered_off_at' => 'date',
        'vpn_renew_date' => 'date',
    ];

    /**
     * 這台有沒有買 VPN
     *
     * ⚠ 判準是**費用 > 0**，不另外開一個「啟用 VPN」的開關 ——
     * 兩個可以互相矛盾的欄位是 `status`（啟用／停用）踩過的坑。
     *
     * @return bool
     */
    public function hasVpn()
    {
        return (float) $this->vpn_fee > 0;
    }

    /**
     * 這台在跑嗎
     *
     * ⚠ 「開機了沒」是這個系統最常問的一件事（收不收錢、要不要續約 VPN、
     * 要不要出帳全看它），而原本每個地方各寫各的：`VmService` 寫死
     * `=== 1`、`VmBillingResource` 寫死 `!== 0`、指令查詢那邊比對 config。
     * 三種寫法要是哪天 `POWER.ON` 的值變了，只會改到其中一種。
     *
     * ⚠ 這裡**不包含** SQL 端的判斷 —— `VmRepository` 要在 WHERE 裡篩，
     * 不能走 PHP 的 predicate（撈回來再篩等於把關機的機器全部載進記憶體）。
     * 那邊維持 `where('power_status', config('constants.VM.POWER.ON'))`。
     *
     * @return bool
     */
    public function isPoweredOn()
    {
        return (int) $this->power_status === (int) config('constants.VM.POWER.ON');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class)->select(['id', 'name', 'domain', 'system_id', 'telegram_group_id']);
    }

    public function billings(): HasMany
    {
        return $this->hasMany(VmBilling::class);
    }
}
