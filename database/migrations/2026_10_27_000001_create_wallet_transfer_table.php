<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 監聽中的 TRON 錢包轉帳紀錄
 *
 * 每分鐘向 TronGrid 問一次，新的轉帳寫一筆並私訊通知
 * （見 `WalletNoticeService`）。
 *
 * ⚠ **這張表的用途是去重與補發，不是查詢**（需求方 2026-10-27：
 * 「只有通知就好，不用做任何動作」）。沒有查詢頁，但資料都在 ——
 * 日後要加歷史頁不必重跑。
 */
class CreateWalletTransferTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('wallet_transfer')) {
            return;
        }

        Schema::create('wallet_transfer', function (Blueprint $table) {
            $table->id();

            // TRON 的 base58 地址固定 34 碼，留 64 給日後別條鏈
            $table->string('address', 64)->comment('監聽中的那個錢包');
            $table->string('tx_hash', 80)->comment('交易 hash');

            $table->string('direction', 3)->comment('in=轉入, out=轉出');
            $table->string('from_address', 64)->comment('轉出方');
            $table->string('to_address', 64)->comment('轉入方');

            /*
             * ⚠ **不能用 float**。TronGrid 回的是「未除以小數位的整數字串」，
             * USDT 是 6 位、有些 TRC20 代幣是 18 位 —— 用 float 換算會丟精度，
             * 而這是錢。Model 那邊也刻意不 cast
             * （同 `StationCreditLog::$credits`）。
             */
            $table->decimal('amount', 36, 8)->comment('已換算過小數位的金額');

            $table->string('symbol', 32)->comment('幣別，例如 USDT');
            $table->string('contract_address', 64)->comment('代幣合約地址');

            $table->dateTime('block_at')->comment('鏈上時間，輪詢的游標也看它');

            $table->dateTime('notified_at')->nullable()
                ->comment('送出私訊的時間；null = 還沒送出去');

            /*
             * null 表示「本來就該發」，所以 `notified_at IS NULL AND
             * skip_reason IS NULL` 就是待補發的那些。
             */
            $table->string('skip_reason', 20)->nullable()
                ->comment('baseline=首次建立基準, below_min=低於金額門檻');

            $table->timestamps();

            /*
             * ⚠ **唯一鍵是 (address, tx_hash)，不能只對 tx_hash 唯一** ——
             * 同一筆交易可能同時是 A 錢包的轉出與 B 錢包的轉入，
             * 兩邊都要各記一筆、各發一則。
             */
            $table->unique(['address', 'tx_hash'], 'wallet_transfer_address_hash_unq');

            // 輪詢游標：某個錢包已經看到哪個時間了
            $table->index(['address', 'block_at'], 'wallet_transfer_address_block_idx');

            // 補發那一查：還沒送出去、而且是最近的
            $table->index(['notified_at', 'block_at'], 'wallet_transfer_pending_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wallet_transfer');
    }
}
