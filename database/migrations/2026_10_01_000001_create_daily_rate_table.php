<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 每日匯率報價
 *
 * 每天早上在內部支援群組報一次匯率，由自己人回覆決定當日對客報價。
 *
 * `date` 是唯一鍵 ——「今天的匯率定了沒」就是「今天這筆的 rate 有沒有值」，
 * 過了午夜自然查不到昨天的，所以**不需要一支清空的排程**，
 * 也不會有「清空失敗導致昨天的匯率被誤用」的風險。
 *
 * 舊資料一律保留：要回頭查「上個月某天報多少」就靠這張表。
 */
class CreateDailyRateTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('daily_rate')) {
            return;
        }

        Schema::create('daily_rate', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique()->comment('日期，一天只有一筆');

            $table->decimal('rate', 10, 4)->nullable()
                ->comment('當日對客報價。null = 還沒定下來');

            $table->decimal('reference_rate', 10, 4)->nullable()
                ->comment('報價當下 MAX 的 4H 均價原始值，事後對帳用');
            $table->decimal('suggested_rate', 10, 4)->nullable()
                ->comment('由 4H 均價算出的建議值（取一位小數、.95 以上保留 .95），回「好」時採用');
            $table->decimal('yesterday_rate', 10, 4)->nullable()
                ->comment('報價當下昨天的匯率快照，純參考');

            $table->unsignedBigInteger('ask_message_id')->nullable()
                ->comment('報價訊息的 Telegram message_id，引用回覆靠它對應');
            $table->timestamp('asked_at')->nullable()->comment('報價送出時間');

            /*
             * 回覆者離職被刪帳號時只把這裡清成 null，不要連匯率紀錄一起刪掉 ——
             * 歷史匯率是要留的。nullOnDelete 的欄位必須 nullable。
             */
            $table->unsignedBigInteger('replied_by')->nullable()->comment('誰決定的');
            $table->timestamp('replied_at')->nullable()->comment('決定的時間');

            $table->unsignedSmallInteger('remind_count')->default(0)->comment('已提醒次數');
            $table->timestamp('last_reminded_at')->nullable()->comment('上次提醒時間');

            $table->timestamps();

            $table->foreign('replied_by')->references('id')->on('user')->nullOnDelete();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('daily_rate');
    }
}
