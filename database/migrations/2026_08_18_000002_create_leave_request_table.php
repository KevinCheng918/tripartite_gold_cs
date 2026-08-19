<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeaveRequestTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('leave_request')) {
            Schema::create('leave_request', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->date('start_date');
                $table->date('end_date');
                $table->tinyInteger('is_full_day')->default(1)->comment('1=整天, 0=時段');
                $table->time('start_time')->nullable()->comment('起始時間（時段假）');
                $table->time('end_time')->nullable()->comment('結束時間（時段假）');
                $table->text('reason')->nullable();
                $table->tinyInteger('status')->default(0)->comment('0=待審核, 1=通過, 2=拒絕');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('user')->cascadeOnDelete();
                $table->foreign('reviewed_by')->references('id')->on('user')->nullOnDelete();
                $table->index(['user_id', 'start_date', 'end_date']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('leave_request');
    }
}
