<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立換班紀錄表（shift_swaps）
 *
 * 員工可向另一位員工發起換班請求，
 * 對方同意後雙方的 shift_assignment 互換 shift_id。
 */
class CreateShiftSwapsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('shift_swaps')) {
            return;
        }

        Schema::create('shift_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('user')->cascadeOnDelete()->comment('發起換班的員工 ID');
            $table->foreignId('target_id')->constrained('user')->cascadeOnDelete()->comment('被換班的員工 ID');
            $table->foreignId('requester_assignment_id')->constrained('shift_assignments')->cascadeOnDelete()->comment('發起方的排班紀錄 ID');
            $table->foreignId('target_assignment_id')->constrained('shift_assignments')->cascadeOnDelete()->comment('對方的排班紀錄 ID');
            $table->tinyInteger('status')->default(0)->comment('0=待確認, 1=已同意, 2=已拒絕');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('shift_swaps')) {
            return;
        }

        Schema::dropIfExists('shift_swaps');
    }
}
