<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * credit_topup 支援直接輸入點數
 *
 * 有些客人直接補點數而非 USDT，這種紀錄沒有 USDT 金額與匯率，
 * usdt_amount / exchange_rate 一律存 0（欄位維持 NOT NULL）。
 *
 * 注意：SUM() 加 0 不受影響，但 AVG() 會把 0 算進分母，
 * 因此所有均匯率計算都必須用 input_type 明確排除 TYPE_CREDIT。
 */
class AddInputTypeToCreditTopupTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('credit_topup', function (Blueprint $table) {
            $table->tinyInteger('input_type')->default(1)->after('credit_type')
                ->comment('1=輸入 USDT 換算, 2=直接輸入點數');
        });
        // 既有資料都是 USDT 換算而來，維持欄位預設值 1
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('credit_topup', function (Blueprint $table) {
            $table->dropColumn('input_type');
        });
    }
}
