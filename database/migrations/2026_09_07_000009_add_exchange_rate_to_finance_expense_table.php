<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * finance_expense 新增匯率欄位
 *
 * 支出幣別為 USDT / USD 時要輸入當下匯率換算成台幣，
 * 否則外幣支出無法併入台幣的支出合計與損益。
 * 幣別為 TWD 時此欄為 null。
 *
 * 匯率存當下輸入的值而非即時查詢，因為對帳看的是支出發生當時的成本。
 */
class AddExchangeRateToFinanceExpenseTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('finance_expense', function (Blueprint $table) {
            $table->decimal('exchange_rate', 10, 4)->nullable()->after('currency')
                ->comment('外幣支出的換算匯率，TWD 時為 null');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('finance_expense', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
}
