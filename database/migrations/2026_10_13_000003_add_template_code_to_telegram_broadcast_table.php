<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 發送紀錄記下「用了哪則建檔公告」（需求方 2026-10-13）
 *
 * ⚠ 存的是**編號快照字串，不是 FK**。理由跟「刪公告不影響歷史紀錄」是同一條：
 *
 *   - 公告被刪 → FK 會變 null，歷史紀錄就再也查不出當時發的是哪一則
 *   - 公告改了編號 → 跟著 FK 顯示的會是新編號，但當時明明是用舊的發的
 *
 * 快照留住的是「發送當下的事實」，那正是歷史紀錄該回答的問題。
 *
 * ⚠ 自己打的公告沒有編號，這一欄會是 null，畫面顯示「-」。
 */
class AddTemplateCodeToTelegramBroadcastTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('telegram_broadcast') || Schema::hasColumn('telegram_broadcast', 'template_code')) {
            return;
        }

        Schema::table('telegram_broadcast', function (Blueprint $table) {
            $table->string('template_code', 32)->nullable()->after('content')
                ->comment('發送當下用的公告編號快照；自己打的為 null');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('telegram_broadcast') || !Schema::hasColumn('telegram_broadcast', 'template_code')) {
            return;
        }

        Schema::table('telegram_broadcast', function (Blueprint $table) {
            $table->dropColumn('template_code');
        });
    }
}
