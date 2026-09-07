<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user 加入 Telegram 對話暱稱
 *
 * 客服在 Telegram 對話送出訊息時，結尾會附上「-暱稱」讓客戶知道是誰回的。
 * 未設定（null）就不附加，等於這功能是逐一帳號開啟的。
 */
class AddTelegramNicknameToUserTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('user', function (Blueprint $table) {
            if (Schema::hasColumn('user', 'telegram_nickname')) {
                return;
            }

            $table->string('telegram_nickname', 30)->nullable()->after('nickname')
                ->comment('Telegram 對話署名，送出訊息時附加於結尾');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('telegram_nickname');
        });
    }
}
