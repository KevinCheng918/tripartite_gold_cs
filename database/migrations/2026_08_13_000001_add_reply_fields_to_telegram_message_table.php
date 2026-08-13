<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReplyFieldsToTelegramMessageTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('telegram_message', 'reply_to_sender')) {
            Schema::table('telegram_message', function (Blueprint $table) {
                $table->string('reply_to_sender', 100)->nullable()->after('content')->comment('被引用訊息的發送者');
            });
        }

        if (!Schema::hasColumn('telegram_message', 'reply_to_text')) {
            Schema::table('telegram_message', function (Blueprint $table) {
                $table->text('reply_to_text')->nullable()->after('reply_to_sender')->comment('被引用訊息的內容片段');
            });
        }
    }

    public function down()
    {
        Schema::table('telegram_message', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_message', 'reply_to_sender')) {
                $table->dropColumn('reply_to_sender');
            }
            if (Schema::hasColumn('telegram_message', 'reply_to_text')) {
                $table->dropColumn('reply_to_text');
            }
        });
    }
}
