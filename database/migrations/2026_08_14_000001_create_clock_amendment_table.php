<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClockAmendmentTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('clock_amendment')) {
            return;
        }

        Schema::create('clock_amendment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete();
            $table->date('date')->comment('補打卡日期');
            $table->tinyInteger('type')->comment('1=補上班卡, 2=補下班卡');
            $table->time('clock_time')->comment('申請的打卡時間');
            $table->text('reason')->nullable()->comment('申請原因');
            $table->tinyInteger('status')->default(0)->comment('0=待審核, 1=通過, 2=拒絕');
            $table->foreignId('reviewed_by')->nullable()->constrained('user')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->comment('審核時間');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('clock_amendment');
    }
}
