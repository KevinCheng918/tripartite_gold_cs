<?php

namespace Database\Seeders;

use App\Models\QuickReplyCategory;
use Illuminate\Database\Seeder;

/**
 * 建立「需要補充資訊」題庫類別
 *
 * 客人只丟一句「訂單沒收到款」，同仁得先問代理帳號與訂單號才查得下去。
 * 同仁在求助單按「先問客人」時，那句追問就以這個類別存進題庫；
 * 之後 AI 比對到同樣的問法就會自己追問，不必再轉一次人工。
 *
 * 類別名稱同時是給模型看的線索 —— prompt 裡會標出每題的類別，
 * 它靠這個分辨「這題是答案」還是「這題是要資料」。
 * **所以類別名稱不要隨便改**，要改就連 constants 一起改。
 *
 * @example php artisan db:seed --class=AskInfoCategorySeeder
 */
class AskInfoCategorySeeder extends Seeder
{
    /** @var int 排序值。排在「待整理」前面一點，但仍靠後 */
    private const SORT = 9998;

    /**
     * @return void
     */
    public function run()
    {
        $label = config('constants.AUTO_REPLY.ASK_INFO_CATEGORY');

        // 已經有就不動 —— 重跑 seeder 不該覆蓋使用者調整過的排序或狀態
        $exists = QuickReplyCategory::query()
            ->select(['id'])
            ->where('label', $label)
            ->exists();

        if ($exists) {
            $this->command->info("類別「{$label}」已存在，略過");

            return;
        }

        QuickReplyCategory::query()->create([
            'label'  => $label,
            'sort'   => self::SORT,
            'status' => config('constants.QUICK_REPLY.STATUS.ACTIVE'),
        ]);

        $this->command->info("已建立類別「{$label}」");
    }
}
