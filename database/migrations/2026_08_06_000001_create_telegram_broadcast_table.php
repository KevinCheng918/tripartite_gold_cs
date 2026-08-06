<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立 Telegram 群發公告表（telegram_broadcast）
 *
 * 記錄每次群發的內容、對象、發送結果。
 */
class CreateTelegramBroadcastTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('telegram_broadcast')) {
            return;
        }

        Schema::create('telegram_broadcast', function (Blueprint $table) {
            $table->id();
            $table->text('content')->comment('公告內容');
            $table->tinyInteger('target_type')->comment('1=全部群組, 2=指定群組');
            $table->json('target_group_ids')->nullable()->comment('指定的群組 ID 陣列（target_type=2 時使用）');
            $table->integer('total_count')->default(0)->comment('應發送群組數');
            $table->integer('success_count')->default(0)->comment('成功發送數');
            $table->integer('fail_count')->default(0)->comment('失敗發送數');
            $table->foreignId('sender_id')->constrained('user')->cascadeOnDelete()->comment('發送者');
            $table->timestamp('sent_at')->nullable()->comment('發送時間');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('telegram_broadcast')) {
            return;
        }

        Schema::dropIfExists('telegram_broadcast');
    }
}
