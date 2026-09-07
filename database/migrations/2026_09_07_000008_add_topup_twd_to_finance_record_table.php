<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * finance_record 新增台幣補點的手動覆蓋欄位
 *
 * 補點分 USDT 補點與台幣補點兩種，對帳時要能分開看。
 * 既有的 topup_usdt / topup_avg_rate / topup_credit 都有手動覆蓋，
 * 台幣沒有的話，財務一旦手動調整就會出現「USDT 與點數是手動值、
 * 台幣還是自動值」而對不起來。
 */
class AddTopupTwdToFinanceRecordTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('finance_record', function (Blueprint $table) {
            $table->decimal('topup_twd', 16, 2)->nullable()->after('topup_credit')
                ->comment('台幣補點總額，null 表示使用自動統計值');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('finance_record', function (Blueprint $table) {
            $table->dropColumn('topup_twd');
        });
    }
}
