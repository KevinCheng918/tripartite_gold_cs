<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立排班紀錄表（shift_assignments）
 *
 * 每筆紀錄代表一位員工在某日的班別指派。
 * unique(user_id, date) 確保同一員工同一天只能有一筆排班。
 */
class CreateShiftAssignmentsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('shift_assignments')) {
            return;
        }

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete()->comment('員工 ID');
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete()->comment('班別 ID');
            $table->date('date')->comment('排班日期');
            $table->timestamps();

            $table->unique(['user_id', 'date'], 'shift_assignments_user_date_unique');
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

        Schema::dropIfExists('shift_assignments');
    }
}
