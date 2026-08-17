<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddAssigneeIdsToTaskTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task') && !Schema::hasColumn('task', 'assignee_ids')) {
            Schema::table('task', function (Blueprint $table) {
                $table->json('assignee_ids')->nullable()->after('assignee_id')->comment('指派人員 ID 陣列');
            });

            // 將既有 assignee_id 資料遷移到 assignee_ids
            DB::table('task')
                ->whereNotNull('assignee_id')
                ->get(['id', 'assignee_id'])
                ->each(function ($row) {
                    DB::table('task')
                        ->where('id', $row->id)
                        ->update(['assignee_ids' => json_encode([(int) $row->assignee_id])]);
                });
        }
    }

    public function down()
    {
        if (Schema::hasTable('task') && Schema::hasColumn('task', 'assignee_ids')) {
            Schema::table('task', function (Blueprint $table) {
                $table->dropColumn('assignee_ids');
            });
        }
    }
}
