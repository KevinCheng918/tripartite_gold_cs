<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_message 加入 reactions 欄位（表情回應支援）
 */
class AddReactionsToTelegramMessageTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('telegram_message')) {
            return;
        }

        if (Schema::hasColumn('telegram_message', 'reactions')) {
            return;
        }

        Schema::table('telegram_message', function (Blueprint $table) {
            $table->json('reactions')->nullable()->after('media_url')->comment('表情回應 [{emoji: "👍", count: 1}]');
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

        Schema::table('telegram_message', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_message', 'reactions')) {
                $table->dropColumn('reactions');
            }
        });
    }
}
