<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立題庫問法樣本表（quick_reply_phrasing）
 *
 * 客人問的其實題庫有答案，模型卻沒比對出來 —— 這種「假陰性」以前只能靠同仁
 * 自己打字答一遍，然後按「加入題庫」，結果就是題庫長出一題內容重複的
 * （2026-09-23 一次刪掉 17 題重複題，成因就是這個）。
 *
 * 改成：求助單上直接給「用 #111 回覆」的按鈕，同仁按一下就用題庫原文回客人，
 * 同時把客人那句原話記進這張表。這些真實問法會進 system prompt，
 * 下次同樣的問法就直接命中。
 *
 * **關鍵字不是人工填的，是這樣長出來的。** 叫客服對著一百多題逐題想同義詞
 * 填不完、新題會漏、半年後就跟題庫脫節；從真實沒命中的案例累積，
 * 長出來的還是客人真正用過的字。
 */
class CreateQuickReplyPhrasingTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('quick_reply_phrasing')) {
            return;
        }

        Schema::create('quick_reply_phrasing', function (Blueprint $table) {
            $table->id();
            // 題目被刪掉時樣本一起走：它存在的唯一意義就是幫那一題被比對到
            $table->foreignId('quick_reply_item_id')->constrained('quick_reply_item')->cascadeOnDelete()->comment('對應的題目');
            $table->text('text')->comment('客人的原話');
            // 群組是拿來回溯「這句話是誰在哪裡問的」，群組刪了樣本本身仍然有用
            $table->foreignId('telegram_group_id')->nullable()->constrained('telegram_group')->nullOnDelete()->comment('來源對話');
            $table->tinyInteger('source')->default(1)->comment('1=求助單按鈕, 2=後台手動新增');
            $table->timestamps();

            $table->index('quick_reply_item_id');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('quick_reply_phrasing')) {
            return;
        }

        Schema::dropIfExists('quick_reply_phrasing');
    }
}
