<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立 Telegram 群組表（telegram_group）
 *
 * 每個 Telegram 群組對應一個獨立的客服對話。
 */
class CreateTelegramGroupTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('telegram_group')) {
            return;
        }

        Schema::create('telegram_group', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique()->comment('Telegram 群組 chat ID（可為負數）');
            $table->string('title', 255)->comment('群組名稱');
            $table->tinyInteger('status')->default(1)->comment('1=啟用, 0=封存');
            $table->foreignId('assigned_user_id')->nullable()->constrained('user')->nullOnDelete()->comment('值班客服 ID');
            $table->timestamp('last_message_at')->nullable()->comment('最後訊息時間');
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('telegram_group')) {
            return;
        }

        Schema::dropIfExists('telegram_group');
    }
}
