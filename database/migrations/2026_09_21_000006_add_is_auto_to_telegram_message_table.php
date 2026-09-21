<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_message 標示自動回覆送出的訊息
 *
 * 對話視窗要能標出哪幾則是機器回的，事後對帳也才分得出來。
 * 只有 outbound 有意義；自動回覆一律署名 -A。
 */
class AddIsAutoToTelegramMessageTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('telegram_message', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_message', 'is_auto')) {
                return;
            }

            $table->boolean('is_auto')->default(false)->after('sender_user_id')
                ->comment('是否為自動回覆送出：1=是, 0=否');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('telegram_message', function (Blueprint $table) {
            if (!Schema::hasColumn('telegram_message', 'is_auto')) {
                return;
            }

            $table->dropColumn('is_auto');
        });
    }
}
