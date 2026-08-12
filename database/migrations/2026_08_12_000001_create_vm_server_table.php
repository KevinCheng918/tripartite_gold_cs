<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVmServerTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('vm_server')) {
            return;
        }

        Schema::create('vm_server', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->nullable()->constrained('station')->nullOnDelete();
            $table->string('hostname', 100)->comment('主機名稱');
            $table->string('internal_ip', 45)->nullable()->comment('內網 IP');
            $table->string('external_ip', 45)->nullable()->comment('外網 IP');
            $table->string('model_type', 100)->nullable()->comment('機型（如 AWS t3.medium）');
            $table->string('spec', 255)->comment('規格（如 2C4G 50GB）');
            $table->decimal('monthly_fee', 10, 2)->default(0)->comment('每月費用');
            $table->tinyInteger('billing_day')->default(1)->comment('每月帳單日（1-28）');
            $table->tinyInteger('power_status')->default(1)->comment('1=開機, 0=關機');
            $table->tinyInteger('status')->default(1)->comment('1=啟用, 0=停用');
            $table->text('note')->nullable()->comment('備註');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('vm_server');
    }
}
