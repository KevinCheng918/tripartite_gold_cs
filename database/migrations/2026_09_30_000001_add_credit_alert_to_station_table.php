<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 站台加上餘點告警的門檻與冷卻記錄
 *
 * `credit_alert_threshold` 是「這個站台自己的門檻」，留空就沿用全域設定
 * （繳款設定頁）。大站台的營運水位本來就高，用同一個數字會不是每次都合理。
 *
 * ⚠ 這兩個值不能塞進 station.settings JSON。
 * `StationService::syncInfo()` 是 `'settings' => $info` 整包覆寫，
 * 放進去會在下一次同步被主系統 API 的回傳值沖掉。
 *
 * `credit_alerted_at` 記最後一次真的把告警送出去的時間，給冷卻天數判斷用；
 * 只在成功送出後才寫，API 失敗或條件不符都不會動它。
 */
class AddCreditAlertToStationTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('station')) {
            return;
        }

        Schema::table('station', function (Blueprint $table) {
            if (!Schema::hasColumn('station', 'credit_alert_threshold')) {
                $table->decimal('credit_alert_threshold', 15, 2)->nullable()->after('credits')
                    ->comment('此站台的餘點告警門檻，null 表示沿用全域設定');
            }

            if (!Schema::hasColumn('station', 'credit_alerted_at')) {
                $table->timestamp('credit_alerted_at')->nullable()->after('synced_at')
                    ->comment('最後一次送出餘點告警的時間，冷卻判斷用');
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('station')) {
            return;
        }

        Schema::table('station', function (Blueprint $table) {
            $columns = array_filter(
                ['credit_alert_threshold', 'credit_alerted_at'],
                function ($column) {
                    return Schema::hasColumn('station', $column);
                }
            );

            if (filled($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
}
