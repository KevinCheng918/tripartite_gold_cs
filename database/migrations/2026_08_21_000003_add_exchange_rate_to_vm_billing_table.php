<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExchangeRateToVmBillingTable extends Migration
{
    public function up()
    {
        Schema::table('vm_billing', function (Blueprint $table) {
            $table->decimal('exchange_rate', 10, 4)->nullable()->after('amount')->comment('繳款時 USDT/TWD 4H 均價');
        });
    }

    public function down()
    {
        Schema::table('vm_billing', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
}
