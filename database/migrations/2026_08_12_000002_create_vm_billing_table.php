<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVmBillingTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('vm_billing')) {
            return;
        }

        Schema::create('vm_billing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vm_server_id')->constrained('vm_server')->cascadeOnDelete();
            $table->string('billing_month', 7)->comment('帳單月份（YYYY-MM）');
            $table->decimal('amount', 10, 2)->comment('應收金額');
            $table->tinyInteger('paid')->default(0)->comment('0=未收, 1=已收');
            $table->timestamp('paid_at')->nullable()->comment('收款時間');
            $table->date('due_date')->comment('應收日期');
            $table->text('note')->nullable()->comment('備註');
            $table->timestamps();

            $table->unique(['vm_server_id', 'billing_month']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('vm_billing');
    }
}
