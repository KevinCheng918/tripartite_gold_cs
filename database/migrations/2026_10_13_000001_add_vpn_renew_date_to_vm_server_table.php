<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 虛擬機加上「VPN 續約日期」
 *
 * 有買 VPN 的機器（`vpn_fee > 0`）要記下一次續約是哪天，
 * 到期前兩天開始每天提醒內部支援群組去續約 —— VPN 斷掉的後果
 * 比群組多幾則訊息嚴重得多。
 *
 * ⚠ **nullable**：絕大多數機器沒有 VPN（`vpn_fee = 0`），
 * 給一個預設日期等於替它們憑空造一個到期日。
 * 「有沒有 VPN」由 `vpn_fee > 0` 決定，不另外開一個開關 ——
 * 兩個可以互相矛盾的欄位是剛拿掉的 `status` 踩過的坑。
 */
class AddVpnRenewDateToVmServerTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('vm_server', 'vpn_renew_date')) {
            return;
        }

        Schema::table('vm_server', function (Blueprint $table) {
            $table->date('vpn_renew_date')->nullable()->after('vpn_fee')
                ->comment('VPN 下一次續約日期，vpn_fee > 0 才有意義');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('vm_server', 'vpn_renew_date')) {
            return;
        }

        Schema::table('vm_server', function (Blueprint $table) {
            $table->dropColumn('vpn_renew_date');
        });
    }
}
