<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 記下「這個人可以被 bot 私訊」
 *
 * ⚠ **為什麼不能只看 `telegram_user_id`。**
 *
 * Telegram 規定 bot 只能發訊給**曾經主動跟它對話過**的使用者，對沒互動過的
 * 人呼叫 sendMessage 會回 `403 Forbidden: bot can't initiate conversation`。
 *
 * 而 `telegram_user_id` 是 `StaffIgnoreService` 從**群組訊息**回填的 ——
 * 「在群組裡講過話」不等於「私訊過 bot」。拿那個欄位當判斷，就會對著
 * 一整批沒加過 bot 的人狂發 403。
 *
 * 所以另立一個欄位，只有在**收到那個人的私訊**時才會被設為 true。
 */
class AddTelegramDmReadyToUserTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('user', 'telegram_dm_ready')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            $table->boolean('telegram_dm_ready')->default(false)->after('telegram_user_id')
                ->comment('是否私訊過 bot（可被私訊）。只有收到他的私訊時才會設 true');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('user', 'telegram_dm_ready')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('telegram_dm_ready');
        });
    }
}
