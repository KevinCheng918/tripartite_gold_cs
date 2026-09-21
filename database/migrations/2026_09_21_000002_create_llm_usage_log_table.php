<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立 LLM 呼叫紀錄表（llm_usage_log）
 *
 * 自動回覆**只要有呼叫到 Claude 就記一筆** —— 成功、失敗、撞限額、逾時都算，
 * 因為它們都消耗了額度或至少嘗試消耗。
 *
 * source 區分訂閱與備援 API，兩者要盯的數字完全不同：
 * 訂閱看「撞了幾次限額」（撐不撐得住），備援看「花了多少錢」。
 */
class CreateLlmUsageLogTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('llm_usage_log')) {
            return;
        }

        Schema::create('llm_usage_log', function (Blueprint $table) {
            $table->id();
            $table->date('used_on')->comment('呼叫日期，統計用');
            $table->foreignId('telegram_group_id')->nullable()->constrained('telegram_group')->nullOnDelete()->comment('哪個對話觸發的');
            $table->tinyInteger('source')->comment('1=訂閱（Claude Code）, 2=備援 API Key');
            $table->string('model', 50)->nullable()->comment('實際使用的模型');
            $table->unsignedInteger('duration_ms')->default(0)->comment('耗時（毫秒）');
            $table->boolean('is_error')->default(false)->comment('是否失敗（含逾時、解析失敗）');
            $table->boolean('is_rate_limited')->default(false)->comment('是否因額度上限而失敗');
            $table->unsignedInteger('input_tokens')->default(0)->comment('輸入 token 數');
            $table->unsignedInteger('output_tokens')->default(0)->comment('輸出 token 數');
            $table->timestamp('created_at')->nullable()->comment('呼叫時間');

            $table->index(['used_on', 'source']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('llm_usage_log')) {
            return;
        }

        Schema::dropIfExists('llm_usage_log');
    }
}
