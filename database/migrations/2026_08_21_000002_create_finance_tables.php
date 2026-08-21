<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFinanceTables extends Migration
{
    public function up()
    {
        Schema::create('finance_record', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7)->unique()->comment('月份 YYYY-MM');
            $table->decimal('topup_usdt', 16, 4)->nullable()->comment('補點總 USDT');
            $table->decimal('topup_avg_rate', 10, 4)->nullable()->comment('補點平均匯率');
            $table->decimal('topup_credit', 16, 2)->nullable()->comment('補點換算總點數');
            $table->decimal('vm_income_usdt', 16, 4)->nullable()->comment('VM 收入總 USDT');
            $table->unsignedInteger('vm_income_count')->nullable()->comment('VM 收入筆數');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('user')->nullOnDelete();
        });

        Schema::create('finance_expense', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('finance_record_id');
            $table->string('type', 20)->comment('misc=雜項, server=雲端伺服器');
            $table->string('category', 50)->nullable()->comment('雜項分類');
            $table->string('name', 200)->comment('品名/項目名稱');
            $table->decimal('amount', 16, 2)->comment('金額');
            $table->string('currency', 10)->default('TWD')->comment('幣別 TWD/USD/USDT');
            $table->date('expense_date')->nullable()->comment('支出日期');
            $table->tinyInteger('reimbursed')->default(0)->comment('是否請款 0=否 1=是');
            $table->text('note')->nullable()->comment('備註');
            $table->timestamps();

            $table->foreign('finance_record_id')->references('id')->on('finance_record')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('finance_expense');
        Schema::dropIfExists('finance_record');
    }
}
