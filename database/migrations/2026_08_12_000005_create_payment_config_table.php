<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentConfigTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('payment_config')) {
            return;
        }

        Schema::create('payment_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained('system')->cascadeOnDelete();
            $table->string('title', 100)->comment('繳款方式名稱（如 銀行轉帳、USDT）');
            $table->text('content')->comment('繳款資訊內容');
            $table->text('template')->nullable()->comment('文案模板，支援 {station} {amount} {month} 變數');
            $table->string('image', 500)->nullable()->comment('繳款圖片（QR code 等）');
            $table->tinyInteger('status')->default(1)->comment('1=啟用, 0=停用');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_config');
    }
}
