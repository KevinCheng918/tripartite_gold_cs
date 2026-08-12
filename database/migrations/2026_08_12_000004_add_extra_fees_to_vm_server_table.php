<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExtraFeesToVmServerTable extends Migration
{
    public function up()
    {
        Schema::table('vm_server', function (Blueprint $table) {
            $table->decimal('vpn_fee', 10, 2)->default(0)->after('monthly_fee')->comment('VPN 月費');
            $table->decimal('google_fee', 10, 2)->default(0)->after('vpn_fee')->comment('Google 帳號月費');
        });
    }

    public function down()
    {
        Schema::table('vm_server', function (Blueprint $table) {
            $table->dropColumn(['vpn_fee', 'google_fee']);
        });
    }
}
