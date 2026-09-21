<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user 加入 Telegram 帳號（username）
 *
 * 求助單超時沒人回時，系統要在內部支援群組 @ 人：
 * 第一次 tag 當下排班的人員，再沒回就 tag 主管與老闆（兩階段都跳過工程）。
 *
 * 與 telegram_nickname 的差別：
 *   - telegram_nickname 是**簽在客戶訊息結尾**的名字（-小明）
 *   - telegram_username 是**在內部群組被 @ 到**的帳號（@ming）
 *
 * 未設定（null）的人不會被 tag，這是靜默的，不會報錯。
 */
class AddTelegramUsernameToUserTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('user', function (Blueprint $table) {
            if (Schema::hasColumn('user', 'telegram_username')) {
                return;
            }

            $table->string('telegram_username', 50)->nullable()->after('telegram_nickname')
                ->comment('Telegram 帳號（不含 @），內部群組提醒時用來 tag 本人');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('user', function (Blueprint $table) {
            if (!Schema::hasColumn('user', 'telegram_username')) {
                return;
            }

            $table->dropColumn('telegram_username');
        });
    }
}
