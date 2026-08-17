<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddImagesToTaskTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task') && !Schema::hasColumn('task', 'images')) {
            Schema::table('task', function (Blueprint $table) {
                $table->json('images')->nullable()->after('description')->comment('上傳圖片路徑陣列');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('task') && Schema::hasColumn('task', 'images')) {
            Schema::table('task', function (Blueprint $table) {
                $table->dropColumn('images');
            });
        }
    }
}
