<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBotTokenToSystemTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('system', 'bot_token')) {
            Schema::table('system', function (Blueprint $table) {
                $table->string('bot_token', 255)->nullable()->after('name')->comment('Telegram Bot Token');
            });
        }
    }

    public function down()
    {
        Schema::table('system', function (Blueprint $table) {
            $table->dropColumn('bot_token');
        });
    }
}
