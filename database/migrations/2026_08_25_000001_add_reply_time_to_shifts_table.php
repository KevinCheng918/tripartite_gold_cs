<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReplyTimeToShiftsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('shifts', 'reply_start_time')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->string('reply_start_time', 5)->nullable()->after('end_time')->comment('主要回訊開始時間 HH:mm');
                $table->string('reply_end_time', 5)->nullable()->after('reply_start_time')->comment('主要回訊結束時間 HH:mm');
            });
        }
    }

    public function down()
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'reply_start_time')) {
                $table->dropColumn(['reply_start_time', 'reply_end_time']);
            }
        });
    }
}
