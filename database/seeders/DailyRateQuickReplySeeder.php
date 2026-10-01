<?php

namespace Database\Seeders;

use App\Repositories\QuickReplyRepository;
use Illuminate\Database\Seeder;

/**
 * 題庫：客人問匯率的那一題
 *
 * 匯率每天不同，題庫存不了固定答案 —— 這一題的 `answer` 只是佔位，
 * 實際送出前會被 `AutoReplyService::resolveAnswer()` 換成當日報價
 * （已決定就報數字，還沒決定就請客人稍候）。
 *
 * 認的是 `import_key`，所以**題目與答案的文字可以隨意潤飾**，
 * 改了也不會讓動態替換失效。
 *
 * 已經建過就完全不動 —— 同 QuickReplyKnowledgeSeeder 的原則，
 * 重跑不該把客服潤飾過的語氣洗掉。
 */
class DailyRateQuickReplySeeder extends Seeder
{
    /** @var string 分類名稱，沒有就建一個 */
    private const CATEGORY = '其他';

    /**
     * @return void
     */
    public function run()
    {
        $repository = app(QuickReplyRepository::class);
        $key = (string) config('constants.DAILY_RATE.QUICK_REPLY_KEY');

        if (filled($repository->findItemByImportKey($key))) {
            $this->command->info("題庫已有匯率題（{$key}），不覆蓋");

            return;
        }

        $category = $repository->findCategoryByLabel(self::CATEGORY);
        $categoryId = filled($category)
            ? $category->id
            : $repository->createCategory([
                'label'  => self::CATEGORY,
                'sort'   => $repository->nextCategorySort(),
                'status' => config('constants.QUICK_REPLY.STATUS.ACTIVE'),
            ])->id;

        $repository->createItem([
            'category_id' => $categoryId,
            'label'       => '今天的匯率是多少？',
            /*
             * 這段不會真的送給客戶，但還是寫成通順的句子 ——
             * 萬一哪天動態替換出問題，送出去的至少是一句像話的話，
             * 而不是「{佔位}」之類的東西。
             */
            'answer'      => '您好，今日匯率正在確認中 🙏 確認後會第一時間回覆您，再請您稍候，謝謝！',
            'import_key'  => $key,
            'sort'        => $repository->nextItemSort($categoryId),
            'status'      => config('constants.QUICK_REPLY.STATUS.ACTIVE'),
        ]);

        $this->command->info("已建立匯率題（{$key}），答案會在送出前換成當日報價");
    }
}
