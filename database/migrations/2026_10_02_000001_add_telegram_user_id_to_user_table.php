<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user 加入 Telegram 使用者 ID
 *
 * 「後台帳號一律不自動回覆」用的（見 StaffIgnoreService）。
 *
 * 原本只能靠 telegram_username 比對，而 username 是**可以隨時改的** ——
 * 同事改掉之後屏蔽會靜默失效，沒有任何錯誤訊息。Telegram 的使用者 ID
 * 則終生不變。
 *
 * **不要求填寫**：第一次用 username 命中時由系統自動回填，
 * 之後就以 ID 為主、username 為輔。帳號管理的表單不會出現這一欄 ——
 * 這串數字沒人記得，也不該要求人去查。
 */
class AddTelegramUserIdToUserTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('user', function (Blueprint $table) {
            if (Schema::hasColumn('user', 'telegram_user_id')) {
                return;
            }

            /*
             * unsignedBigInteger 而不是 integer：Telegram 的使用者 ID 已經
             * 超過 32 位元整數的範圍（新帳號是 10 位數起跳），用 integer 會溢位。
             *
             * 不設 unique —— 同一個人理論上只會對到一個帳號，但萬一兩個帳號
             * 填了同一個 username（人為填錯），unique 會讓回填直接噴錯而中斷收訊。
             * 回填本身已經限定「只補還沒有 ID 的那一筆」。
             */
            $table->unsignedBigInteger('telegram_user_id')->nullable()->after('telegram_username')
                ->comment('Telegram 使用者 ID，首次比對命中時自動回填，username 改掉也認得');

            $table->index('telegram_user_id');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('user', function (Blueprint $table) {
            if (!Schema::hasColumn('user', 'telegram_user_id')) {
                return;
            }

            $table->dropIndex(['telegram_user_id']);
            $table->dropColumn('telegram_user_id');
        });
    }
}
