<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立 Telegram 訊息表（telegram_message）
 *
 * 儲存收到和發送的 Telegram 訊息，TTL 7 天後由排程清理。
 */
class CreateTelegramMessageTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('telegram_message')) {
            return;
        }

        Schema::create('telegram_message', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_group')->cascadeOnDelete()->comment('所屬群組 ID');
            $table->tinyInteger('direction')->comment('1=收（從 Telegram）, 2=發（從後台）');
            $table->unsignedBigInteger('telegram_message_id')->nullable()->comment('Telegram 原始 message_id');
            $table->string('sender_name', 100)->comment('發送者名稱（Telegram 用戶名或後台暱稱）');
            $table->foreignId('sender_user_id')->nullable()->constrained('user')->nullOnDelete()->comment('後台發送者 ID（僅 outbound）');
            $table->text('content')->comment('訊息內容');
            $table->boolean('replied')->default(false)->comment('此 inbound 訊息是否已回覆');
            $table->timestamps();

            $table->index(['telegram_group_id', 'created_at']);
            $table->index(['replied', 'created_at']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('telegram_message')) {
            return;
        }

        Schema::dropIfExists('telegram_message');
    }
}
