<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 記下「這個專案是什麼時候被重新啟用的」
 *
 * ⚠ **為什麼需要這個欄位。**
 *
 * 封存的任務卡超過 30 天會被真的刪掉（`TaskRepository::deleteArchivedOlderThan()`，
 * 在打開封存清單時順手跑）。專案停用期間它的卡片完全不顯示，也不會被刪 ——
 * 但重新啟用的那一刻，那些已經放超過 30 天的卡會在下一次有人打開封存清單時
 * 立刻被清掉，等於「專案改回正常，卡片卻回不來」。
 *
 * 需求方要的是**停用那段時間不計入那 30 天**（2026-10-10）。
 * 做法是重新啟用後整個專案重新給 30 天，所以得知道「上次啟用是什麼時候」。
 *
 * ⚠ **不存「停用時間」而存「啟用時間」**：停用時間要搭配「現在」才算得出
 * 暫停多久，而且得再找地方放補償結果；存啟用時間則是單純的
 * 「這個時間點之後 30 天內不清理」，一個欄位就夠，`task` 表一個字都不用動。
 *
 * ⚠ `project.updated_at` 不能拿來當這個用 —— 改名、改描述都會動到它。
 *
 * ⚠ 既有資料全部是 null，代表「從來沒有重新啟用過」，照舊規則走，
 * 行為跟加欄位之前一模一樣。目前就已經是停用狀態的專案也不必回填：
 * 它們下次被啟用時就會寫入，從那一刻起享有 30 天。
 */
class AddReactivatedAtToProjectTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('project', 'reactivated_at')) {
            return;
        }

        Schema::table('project', function (Blueprint $table) {
            $table->dateTime('reactivated_at')->nullable()->after('status')
                ->comment('上次由停用改回啟用的時間。這個時間起 30 天內不清理封存卡');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('project', 'reactivated_at')) {
            return;
        }

        Schema::table('project', function (Blueprint $table) {
            $table->dropColumn('reactivated_at');
        });
    }
}
