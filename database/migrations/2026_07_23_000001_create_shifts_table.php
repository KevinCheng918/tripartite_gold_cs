<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立班別定義表（shifts）
 *
 * 存放系統中的固定班別（早班/午班/晚班），
 * Admin 可調整各班別的起訖時間，員工不可調整。
 */
class CreateShiftsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('shifts')) {
            return;
        }

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique()->comment('班別代碼，如 morning / afternoon / night');
            $table->string('display_name', 100)->comment('班別顯示名稱');
            $table->time('start_time')->comment('班別開始時間');
            $table->time('end_time')->comment('班別結束時間');
            $table->boolean('is_active')->default(true)->comment('是否啟用');
            $table->smallInteger('sort')->default(0)->comment('排序權重');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('shifts')) {
            return;
        }

        Schema::dropIfExists('shifts');
    }
}
