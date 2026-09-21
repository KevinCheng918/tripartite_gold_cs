<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_group 加入自動回覆開關與狀態
 *
 * 開關做在群組而不是全域：不同客戶的狀況差很多，
 * 難搞的客戶要能單獨關掉自動回覆轉人工。預設關閉。
 *
 * 另外三個欄位是自動回覆的對話狀態：
 *   - auto_reply_at       最後一次自動回覆的時間，同時用於問候語間隔、稍等冷卻、反問時限
 *   - auto_reply_item_id  上次命中的題目（null 代表上次回的是「稍等」）
 *   - auto_reply_pending  反問後正在等客人回答哪一個選項
 *
 * 注意 auto_reply_item_id 不是用來做「同一題不重複回」的 —— 客人重複問就要重複答。
 * 它只用在「上次是不是也回了稍等」這個判斷上。
 */
class AddAutoReplyToTelegramGroupTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('telegram_group', function (Blueprint $table) {
            if (!Schema::hasColumn('telegram_group', 'auto_reply')) {
                $table->boolean('auto_reply')->default(false)->after('assigned_user_id')
                    ->comment('自動回覆開關：1=開, 0=關（預設關）');
            }

            if (!Schema::hasColumn('telegram_group', 'auto_reply_at')) {
                $table->dateTime('auto_reply_at')->nullable()->after('auto_reply')
                    ->comment('最後一次自動回覆時間（問候語間隔、稍等冷卻、反問時限共用）');
            }

            // 不設外鍵：題庫被刪掉時留著舊 id 也無妨，不該因此連動改動群組資料
            if (!Schema::hasColumn('telegram_group', 'auto_reply_item_id')) {
                $table->unsignedBigInteger('auto_reply_item_id')->nullable()->after('auto_reply_at')
                    ->comment('最後一次命中的題庫 id，null=上次回的是稍等');
            }

            if (!Schema::hasColumn('telegram_group', 'auto_reply_pending')) {
                $table->string('auto_reply_pending', 200)->nullable()->after('auto_reply_item_id')
                    ->comment('反問中的候選題目 id（逗號分隔），null=沒有在等客人回答');
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('telegram_group', function (Blueprint $table) {
            $columns = ['auto_reply', 'auto_reply_at', 'auto_reply_item_id', 'auto_reply_pending'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('telegram_group', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
