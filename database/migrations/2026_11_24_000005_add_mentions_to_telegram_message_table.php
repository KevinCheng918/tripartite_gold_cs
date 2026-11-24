<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_message 加入 mentions
 *
 * 客人這則 tag 了誰、那個人是不是我們的人。給自動回覆判斷
 * 「這句話是講給誰聽的」用（`intent = not_for_us`）。
 *
 * ⚠ **非存不可，不能事後從文字拆回來**：Telegram 的 tag 是靠 `entities`
 * 表示的，沒有 username 的人（`text_mention`）文字裡根本沒有 `@` ——
 * 「龍 所以改好了嗎？」存進 content 之後，誰都看不出「龍」是在叫人。
 *
 * 對話脈絡要帶這份標注模型才判得準：對方主管先發一則只有「Amy 2.0」的
 * 訊息、下一則才講話時，光看文字那兩則都只是普通句子。
 */
class AddMentionsToTelegramMessageTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('telegram_message', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_message', 'mentions')) {
                return;
            }

            // 一則最多幾個 tag 沒有上限，但實務上是個位數；
            // 存 JSON（[{label, ours}]）而不是關聯表 —— 只會整包讀、整包寫，
            // 不會單獨查某個被 tag 的人
            $table->text('mentions')->nullable()->after('content')
                ->comment('這則 tag 到的人 [{label, ours}]，null 表示沒有 tag 任何人');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('telegram_message', function (Blueprint $table) {
            $table->dropColumn('mentions');
        });
    }
}
