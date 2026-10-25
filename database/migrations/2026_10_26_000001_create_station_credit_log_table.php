<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 站台餘點的同步紀錄（點數報表的資料來源）
 *
 * 每同步一次寫一筆，供「站台管理 → 點數報表」畫餘點曲線。
 *
 * ⚠ **寫入點只有 `StationService::syncInfo()` 一處**。所有同步路徑
 * （每日排程、列表的同步按鈕、小幫手查餘點、餘點告警、虛擬機試算）
 * 都走那一支，掛在那裡就全部涵蓋，日後新增路徑也不必記得補。
 *
 * ⚠ `credits` **可以是 null** —— 同步失敗時也要留一筆（需求方 2026-10-26）。
 * 沒有這一筆的話，圖上某天沒有點就分不出是「沒人同步」還是「同步失敗」。
 * 失敗那幾筆在曲線上會是斷點，下方清單才列出來。
 *
 * ⚠ **點數沒變也照記**（需求方 2026-10-26）：原話是「如果有去同步，就寫一筆
 * 資料」。圖上會有一串平點，但那正好表達「這幾天確實有在同步，只是客戶沒動」。
 */
class CreateStationCreditLogTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('station_credit_log')) {
            return;
        }

        Schema::create('station_credit_log', function (Blueprint $table) {
            $table->id();

            $table->foreignId('station_id')->constrained('station')->cascadeOnDelete()
                ->comment('哪個站台');

            /*
             * ⚠ 精度對齊 `station.credits`（decimal 20,2）——
             * 兩邊不一致的話，同一個數字在列表與報表會長得不一樣。
             */
            $table->decimal('credits', 20, 2)->nullable()
                ->comment('同步當下的餘點；null = 這次同步失敗');

            $table->string('source', 20)
                ->comment('怎麼觸發的：schedule/manual/command/alert/afford');

            $table->timestamps();

            /*
             * 報表一定是「某站台某區間」，所以複合索引照這個順序。
             * 單獨的 station_id 索引不必另外加 —— 複合索引的前綴就涵蓋了。
             */
            $table->index(['station_id', 'created_at'], 'station_credit_log_station_created_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('station_credit_log');
    }
}
