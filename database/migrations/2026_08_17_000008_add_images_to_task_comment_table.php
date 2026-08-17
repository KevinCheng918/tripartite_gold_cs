<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddImagesToTaskCommentTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task_comment') && !Schema::hasColumn('task_comment', 'images')) {
            Schema::table('task_comment', function (Blueprint $table) {
                $table->json('images')->nullable()->after('content')->comment('留言圖片路徑陣列');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('task_comment') && Schema::hasColumn('task_comment', 'images')) {
            Schema::table('task_comment', function (Blueprint $table) {
                $table->dropColumn('images');
            });
        }
    }
}
