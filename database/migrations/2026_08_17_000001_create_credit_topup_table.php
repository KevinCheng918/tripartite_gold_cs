<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCreditTopupTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('credit_topup')) {
            return;
        }

        Schema::create('credit_topup', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('station')->cascadeOnDelete();
            $table->tinyInteger('action_type')->default(1)->comment('1=加點, 2=扣點');
            $table->string('credit_type', 20)->default('credit')->comment('credit 或 shop_credit');
            $table->decimal('usdt_amount', 16, 4)->comment('USDT 金額');
            $table->decimal('exchange_rate', 10, 4)->comment('匯率');
            $table->decimal('credit_amount', 16, 2)->comment('換算點數');
            $table->tinyInteger('status')->default(0)->comment('0=待審核, 1=已完成, 2=拒絕, 3=API失敗');
            $table->text('api_response')->nullable()->comment('API 回傳');
            $table->foreignId('requested_by')->nullable()->constrained('user')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('user')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->comment('審核時間');
            $table->text('note')->nullable()->comment('備註');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('credit_topup');
    }
}
