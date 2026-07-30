<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立打卡紀錄表（attendance_records）
 *
 * 每筆紀錄包含上班打卡和下班打卡時間，
 * 以及 IP、裝置等相關資訊。
 */
class CreateAttendanceRecordsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('attendance_records')) {
            return;
        }

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete()->comment('員工 ID');
            $table->foreignId('assignment_id')->nullable()->constrained('shift_assignments')->nullOnDelete()->comment('對應的排班紀錄 ID');
            $table->date('date')->comment('打卡日期');
            $table->timestamp('clock_in')->nullable()->comment('上班打卡時間');
            $table->timestamp('clock_out')->nullable()->comment('下班打卡時間');
            $table->string('clock_in_ip', 45)->nullable()->comment('上班打卡 IP');
            $table->string('clock_out_ip', 45)->nullable()->comment('下班打卡 IP');
            $table->string('clock_in_device', 255)->nullable()->comment('上班打卡裝置（User-Agent）');
            $table->string('clock_out_device', 255)->nullable()->comment('下班打卡裝置（User-Agent）');
            $table->integer('late_minutes')->default(0)->comment('遲到分鐘數');
            $table->integer('early_leave_minutes')->default(0)->comment('早退分鐘數');
            $table->integer('overtime_minutes')->default(0)->comment('加班分鐘數');
            $table->tinyInteger('status')->default(0)->comment('0=未完成, 1=正常, 2=遲到, 3=早退, 4=遲到+早退, 5=曠工');
            $table->timestamps();

            $table->unique(['user_id', 'date'], 'attendance_user_date_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('attendance_records')) {
            return;
        }

        Schema::dropIfExists('attendance_records');
    }
}
