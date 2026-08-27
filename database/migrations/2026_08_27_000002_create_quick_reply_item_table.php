<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateQuickReplyItemTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('quick_reply_item')) {
            Schema::create('quick_reply_item', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('category_id');
                $table->string('label', 200)->comment('問題');
                $table->text('answer')->comment('答案');
                $table->unsignedInteger('sort')->default(0)->comment('排序，小的在前');
                $table->tinyInteger('status')->default(1)->comment('1=啟用, 0=停用');
                $table->timestamps();

                $table->foreign('category_id')->references('id')->on('quick_reply_category')->cascadeOnDelete();
                $table->index(['category_id', 'status', 'sort']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('quick_reply_item');
    }
}
