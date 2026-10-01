<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 繳款設定加上「補點訊息」
 *
 * 餘點告警送出時，如果當天的匯率已經決定，就把這段接在告警後面一起發給客戶。
 *
 * 跟著 payment_config 走而不是放 app_setting：**不同系統的收款方式不一樣**，
 * 補點訊息要講的匯款資訊自然也不同，全域共用一份講不清楚。
 *
 * 留空就不附加 —— 不是每個系統都需要。
 */
class AddTopupTemplateToPaymentConfigTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('payment_config') || Schema::hasColumn('payment_config', 'topup_template')) {
            return;
        }

        Schema::table('payment_config', function (Blueprint $table) {
            $table->text('topup_template')->nullable()->after('template')
                ->comment('補點訊息，接在餘點告警後面。留空不附加，當日匯率未決定時也不附');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('payment_config') || !Schema::hasColumn('payment_config', 'topup_template')) {
            return;
        }

        Schema::table('payment_config', function (Blueprint $table) {
            $table->dropColumn('topup_template');
        });
    }
}
