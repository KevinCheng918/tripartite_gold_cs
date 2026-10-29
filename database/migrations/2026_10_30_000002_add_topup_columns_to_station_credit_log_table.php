<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 餘點紀錄加上「這一筆的異動金額」與「這一筆是哪種點數」
 *
 * 需求方 2026-10-30：
 * 1.「點數報表，如果有補點，曲線圖要顯示原本是多少，補多少，現在是多少。」
 * 2.「商城點數要可以用另外一條線，可以跟原本的分開。」
 */
class AddTopupColumnsToStationCreditLogTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('station_credit_log', function (Blueprint $table) {
            if (!Schema::hasColumn('station_credit_log', 'change_amount')) {
                /*
                 * ⚠ **為什麼不能用「跟前一個點的差」當作補點金額**：
                 * 前一次同步到補點之間，客戶照樣在消耗點數 —— 兩點之差是
                 * 「補的 ＋ 這段時間消耗的」，不是補點金額。
                 * 要講「補多少」就得把那個數字存下來。
                 *
                 * ⚠ **帶正負號**：加點是正的、扣點是負的。
                 * 只存絕對值的話，報表算「原本 ＝ 現在 − 異動」時扣點會算反。
                 *
                 * ⚠ 精度對齊 `station_credit_log.credits`（decimal 20,2）——
                 * 兩邊不一致的話，同一筆異動相減會跑出小數尾巴。
                 *
                 * ⚠ 只有補點那幾筆會有值；同步寫的那些一律 null
                 * （同步只是取樣，本來就不知道中間變動了多少）。
                 */
                $table->decimal('change_amount', 20, 2)->nullable()->after('credits')
                    ->comment('這一筆的異動金額（帶正負號）；只有補點那幾筆有值');
            }

            if (!Schema::hasColumn('station_credit_log', 'credit_type')) {
                /*
                 * ⚠ **商城點數必須跟系統點數分開**（需求方 2026-10-30）：
                 * 主系統補點 API 回的 `data.credit` 是「被補的那一種」的餘額 ——
                 * 商城補點回的是商城餘額。混進同一條曲線會變成一個憑空的大跳。
                 *
                 * ⚠ 預設 `credit`：既有的紀錄全部來自 `siteInfo()` 的
                 * `admin_credit`，那是系統點數 —— 不補 default 的話，
                 * 舊資料會變成 null 而從兩條線上同時消失。
                 */
                $table->string('credit_type', 20)->default('credit')->after('credits')
                    ->comment('哪一種點數：credit 系統點數 / shop_credit 商城點數');
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('station_credit_log', function (Blueprint $table) {
            $table->dropColumn(['change_amount', 'credit_type']);
        });
    }
}
