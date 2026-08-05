<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 移除 shift_assignments 的 user_id + date 唯一約束
 *
 * 允許同一個人同一天排多個班。
 */
class DropShiftAssignmentsUserDateUnique extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('shift_assignments')) {
            return;
        }

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropUnique('shift_assignments_user_date_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('shift_assignments')) {
            return;
        }

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->unique(['user_id', 'date'], 'shift_assignments_user_date_unique');
        });
    }
}
