<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStationIdToTaskTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task') && !Schema::hasColumn('task', 'station_id')) {
            Schema::table('task', function (Blueprint $table) {
                $table->unsignedBigInteger('station_id')->nullable()->after('project_id');
                $table->foreign('station_id')->references('id')->on('station')->nullOnDelete();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('task') && Schema::hasColumn('task', 'station_id')) {
            Schema::table('task', function (Blueprint $table) {
                $table->dropForeign(['station_id']);
                $table->dropColumn('station_id');
            });
        }
    }
}
