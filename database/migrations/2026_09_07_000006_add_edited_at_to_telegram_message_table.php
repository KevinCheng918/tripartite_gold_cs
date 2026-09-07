<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_message 加入 edited_at
 *
 * 客人在 Telegram 編輯訊息後，後台要同步更新內容並標示已編輯，
 * 否則客服會照著舊內容回覆。
 *
 * 不能用「updated_at > created_at」判斷 —— markMessagesReplied() 會批次
 * 更新 replied 欄位，連帶把 updated_at 一起動到，所有訊息都會被誤判為已編輯。
 */
class AddEditedAtToTelegramMessageTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('telegram_message', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_message', 'edited_at')) {
                return;
            }

            $table->timestamp('edited_at')->nullable()->after('content')
                ->comment('訊息在 Telegram 被編輯的時間，null 表示未編輯過');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('telegram_message', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
}
