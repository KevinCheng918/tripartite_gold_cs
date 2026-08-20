<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSendResultsToTelegramBroadcastTable extends Migration
{
    public function up()
    {
        Schema::table('telegram_broadcast', function (Blueprint $table) {
            $table->json('send_results')->nullable()->after('target_group_ids')->comment('每站台發送結果 [{station_id, name, success}]');
        });
    }

    public function down()
    {
        Schema::table('telegram_broadcast', function (Blueprint $table) {
            $table->dropColumn('send_results');
        });
    }
}
