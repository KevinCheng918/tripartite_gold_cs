<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user 表新增 level 欄位
 *
 * 對齊主系統 constants.USER.LEVEL：0=管理者, 1=客服
 */
class AddLevelToUserTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('user', 'level')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            $table->tinyInteger('level')->default(1)->after('status')->comment('0=管理者, 1=客服');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('user', 'level')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
}
