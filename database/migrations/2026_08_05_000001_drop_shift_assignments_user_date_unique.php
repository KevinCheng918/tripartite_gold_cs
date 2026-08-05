<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 移除 shift_assignments 的 user_id + date 唯一約束
 *
 * 允許同一個人同一天排多個班。
 * MySQL 的 foreign key 依賴 user_id 的 index，
 * 需要先建一個普通 index 替代後才能刪除 unique index。
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
            // 先加一個普通 index，讓 foreign key 有 index 可以依賴
            $table->index('user_id', 'shift_assignments_user_id_index');
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            // 再刪除 unique index
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

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropIndex('shift_assignments_user_id_index');
        });
    }
}
