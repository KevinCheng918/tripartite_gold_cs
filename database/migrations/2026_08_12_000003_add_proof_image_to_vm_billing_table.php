<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProofImageToVmBillingTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('vm_billing', 'proof_image')) {
            Schema::table('vm_billing', function (Blueprint $table) {
                $table->string('proof_image', 500)->nullable()->after('paid_at')->comment('繳款證明圖片路徑');
            });
        }
    }

    public function down()
    {
        Schema::table('vm_billing', function (Blueprint $table) {
            $table->dropColumn('proof_image');
        });
    }
}
