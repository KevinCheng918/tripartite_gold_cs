<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立代班紀錄表（shift_covers）
 *
 * 原班人發起代班申請 → 代班人同意 → 管理者審核，
 * 三方確認後生效。支援指定部分時段代班。
 */
class CreateShiftCoversTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('shift_covers')) {
            return;
        }

        Schema::create('shift_covers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('shift_assignments')->cascadeOnDelete()->comment('原排班紀錄 ID');
            $table->foreignId('requester_id')->constrained('user')->cascadeOnDelete()->comment('原班人（發起者）');
            $table->foreignId('cover_user_id')->constrained('user')->cascadeOnDelete()->comment('代班人');
            $table->time('cover_start')->comment('代班開始時間');
            $table->time('cover_end')->comment('代班結束時間');
            $table->string('reason', 255)->nullable()->comment('代班原因');
            $table->tinyInteger('cover_user_status')->default(0)->comment('代班人回應：0=待確認, 1=同意, 2=拒絕');
            $table->tinyInteger('admin_status')->default(0)->comment('管理者審核：0=待審核, 1=核准, 2=駁回');
            $table->foreignId('admin_id')->nullable()->constrained('user')->nullOnDelete()->comment('審核的管理者 ID');
            $table->timestamp('cover_user_responded_at')->nullable()->comment('代班人回應時間');
            $table->timestamp('admin_responded_at')->nullable()->comment('管理者審核時間');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('shift_covers')) {
            return;
        }

        Schema::dropIfExists('shift_covers');
    }
}
