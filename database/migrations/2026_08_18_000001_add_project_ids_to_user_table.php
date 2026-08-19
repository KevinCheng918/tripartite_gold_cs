<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProjectIdsToUserTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('user') && !Schema::hasColumn('user', 'project_ids')) {
            Schema::table('user', function (Blueprint $table) {
                $table->json('project_ids')->nullable()->after('status')->comment('參與專案 ID 陣列');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('user') && Schema::hasColumn('user', 'project_ids')) {
            Schema::table('user', function (Blueprint $table) {
                $table->dropColumn('project_ids');
            });
        }
    }
}
