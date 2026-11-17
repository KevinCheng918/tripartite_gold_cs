<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 求助單加上「最後是怎麼被處理掉的」（close_action）
 *
 * 每日統計要回答的是「這張單有沒有讓題庫長大」（需求方 2026-11-18），
 * 而現有的 `status` 算不出來：
 *
 *   - 按「回覆並加入題庫」是兩步，選完類別時 `REPLIED` 被改寫成 `SAVED`
 *     ⇒「回覆客人」這件事在資料上消失
 *   - 「直接回覆」與「用既有題目回覆」的 `status` 一模一樣，
 *     但前者什麼都沒留下、後者記下了客人的問法樣本
 *   - `IGNORED` 混了三條路，其中「客服在對話窗自己回掉」是
 *     **客人有被回覆但題庫沒長**，跟「同仁決定不處理」相反
 *
 * 值的定義在 `constants.AUTO_REPLY.CLOSE_ACTION`。
 *
 * ⚠⚠ **舊資料刻意不回填。** 回覆與入題庫在舊單裡分不出來
 * （就是上面那三點的結果），猜一個填下去等於把錯誤固化成資料 ——
 * 同 2026-11-10 那支 migration 的原則。報表只看當天開的單，
 * 上線隔天起就不會再出現 null；真的出現會被印成「未分類（上線前的舊單）」。
 *
 * ⚠ `status` 一個值都不動 —— 它管的是流程（還能不能作答、要不要催），
 * 那套邏輯正在運作。
 */
class AddCloseActionToAutoReplyTicketTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('auto_reply_ticket') || Schema::hasColumn('auto_reply_ticket', 'close_action')) {
            return;
        }

        Schema::table('auto_reply_ticket', function (Blueprint $table) {
            $table->tinyInteger('close_action')
                ->nullable()
                ->after('status')
                ->comment('最後怎麼處理的，見 constants.AUTO_REPLY.CLOSE_ACTION；null = 還沒結案或上線前的舊單');

            /*
             * 統計就是「某一天開的單，依 close_action 分組」——
             * created_at 放前面，因為它是範圍條件、close_action 是分組欄位。
             */
            $table->index(['created_at', 'close_action'], 'idx_ticket_created_close_action');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('auto_reply_ticket') || !Schema::hasColumn('auto_reply_ticket', 'close_action')) {
            return;
        }

        Schema::table('auto_reply_ticket', function (Blueprint $table) {
            $table->dropIndex('idx_ticket_created_close_action');
            $table->dropColumn('close_action');
        });
    }
}
