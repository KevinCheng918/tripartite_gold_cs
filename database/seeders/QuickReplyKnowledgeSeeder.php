<?php

namespace Database\Seeders;

use App\Repositories\QuickReplyRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * 主系統知識題庫
 *
 * 錯誤碼、狀態碼、API 規則這些答案本來只存在於主系統（tripartite_gold）
 * 的程式碼裡，客人一問就得轉人工。這支把它們寫進題庫。
 *
 * **寫法的三個原則**（客服實際使用後調整出來的）：
 *
 * 1. **對象是站台，不是商戶。** 我們的客戶是站台，商戶是站台底下的下游。
 *    所以 agent 要稱「代理帳號」而不是「商戶號」，遇到需要設定的事情
 *    （例如 IP 白名單）是**教站台怎麼自己操作**，不是說「我們幫您處理」。
 * 2. **一題只講一個錯誤。** 把好幾個錯誤碼塞進一題，客人還是得自己找。
 *    相似的錯誤（例如 -4 與 10007）則在答案裡說明差在哪。
 * 3. **不要列完整代碼表。** 對站台沒有意義，那在 API 文件裡有。
 *    他們要的是「遇到這個狀況怎麼解決」。
 * 4. **答案裡不要用 Markdown 標記。** 送 Telegram 時用的是 HTML parse_mode，
 *    `**粗體**` 不會被渲染，客戶會看到字面的星號。要強調就用文字本身表達。
 *
 * **已經建過的題目完全不動** —— 客服一定會再潤飾語氣（題庫答案是原文送給客戶的），
 * 重跑 seeder 不該把人的修改洗掉。靠 import_key 判斷，不是比對文字。
 *
 * 主系統改了規則時，回來維護這份檔案就好。內容出處：
 * - 狀態碼：config/constants.php 的 PAYMENT.DEPOSIT_STATUS / WITHDRAW_STATUS
 * - 錯誤碼：config/errors.php（代碼）+ resources/lang/tw/errors.php（訊息）
 * - 欄位規則：app/Http/Requests/ApiDepositApplyRequest.php、ApiWithdrawApplyRequest.php
 * - 加簽：app/Http/Middleware/AuthHashKey.php
 * - 回調：app/Payments/Payment.php 的 callbackRecordToAgent()
 * - IP 白名單：app/Http/Middleware/AllowIP.php、AllowWebIP.php、resources/lang/tw/user.php
 * - 商戶串接文件：resources/views/skins/architect/docs/content.blade.php
 *
 * ⚠️ 回調 status **以程式碼為準，不是以文件為準**。文件寫「02 拒絕」，
 * 但 callbackRecordToAgent() 直接把 DB 的 status 送出去、沒有轉換 ——
 * 代收的 02 其實是群組單成功、03 才是失敗，而文件沒列 03。
 * 對客戶的說法統一成「00 待處理、01 成功，其餘視為失敗」，這樣最安全。
 *
 * ⚠️ 主系統的文案混著簡體字與錯字（「订单異常」「持有人銀銀行名稱」），
 * 這裡已經修成正確的繁體 —— 這些字會原文送給客戶。
 */
class QuickReplyKnowledgeSeeder extends Seeder
{
    /**
     * 早期版本建立、現在已經拆掉或改寫的題目
     *
     * seeder 不會自己刪除題目（可能已經被客服改過），
     * 只列出來提醒人工到後台停用，避免與新題目互相干擾比對。
     *
     * @var array
     */
    private const RETIRED_KEYS = [
        'api.common_error'         => '已拆成各錯誤獨立的題目',
        'api.deposit_apply_error'  => '代碼表對站台沒有意義，已改為各狀況的處理方式',
        'api.withdraw_apply_error' => '同上',
        'api.query_error'          => '同上',
        'error.payment_channel'    => '已拆成 10003 與 10006 兩題',
        'error.throttle_blacklist' => '已拆成 10004、10005、10002 三題',
        'error.order_sn'           => '已拆成 -2 與 -3 兩題',
        'error.amount'             => '已拆成 -4 與 10007 兩題',
        'api.order_sn_format'      => '已拆成代收、代付各一題，另有一題比較兩者差異',
        'api.callback'             => '已拆成代收回調、代付回調、沒收到回調三題',
    ];

    /**
     * @param QuickReplyRepository $repository
     * @return void
     */
    public function run(QuickReplyRepository $repository)
    {
        $created = 0;
        $outdated = [];

        foreach ($this->items() as $item) {
            $existing = $repository->findItemByImportKey($item['key']);

            if (blank($existing)) {
                $this->createItem($repository, $item);
                $created++;

                continue;
            }

            // 內容不同就先記下來，要不要覆蓋由人決定
            if ($existing->label !== $item['label'] || $existing->answer !== trim($item['answer'])) {
                $outdated[] = ['item' => $existing, 'source' => $item];
            }
        }

        $this->command->info("知識題庫：新增 {$created} 題");

        $updated = $this->syncOutdated($repository, $outdated);

        /*
         * 題庫是整份塞進 Claude 的 system prompt 的，不清快取的話
         * 新題目最多要等快取過期才生效，而且很難察覺。
         */
        if ($created > 0 || $updated > 0) {
            Cache::forget(config('auto_reply.prompt_cache_key'));
        }

        $this->warnRetired($repository);
    }

    /**
     * 處理內容已經和 seeder 定義不同的題目
     *
     * **預設不覆蓋**：題庫答案是原文送給客戶的，客服潤飾過的語氣不該被洗掉。
     * 但 seeder 本身改版時又需要能同步下去，所以列出差異、問過才動。
     *
     * @param QuickReplyRepository $repository
     * @param array                $outdated
     * @return int 實際更新的題數
     */
    private function syncOutdated(QuickReplyRepository $repository, array $outdated)
    {
        if (blank($outdated)) {
            return 0;
        }

        $count = count($outdated);

        $this->command->warn("有 {$count} 題的後台內容與這份 seeder 不同：");

        foreach ($outdated as $row) {
            $this->command->line("  #{$row['item']->id}  {$row['item']->label}");
        }

        $this->command->line('');

        if (!$this->command->confirm('要用 seeder 的內容覆蓋嗎？（後台改過的語氣會被蓋掉）', false)) {
            $this->command->line('已保留後台現有內容。');

            return 0;
        }

        foreach ($outdated as $row) {
            $repository->updateItem($row['item'], [
                'label'  => $row['source']['label'],
                'answer' => trim($row['source']['answer']),
            ]);
        }

        $this->command->info("已更新 {$count} 題。");

        return $count;
    }

    /**
     * 建立一題
     *
     * @param QuickReplyRepository $repository
     * @param array                $item
     * @return void
     */
    private function createItem(QuickReplyRepository $repository, array $item)
    {
        $categoryId = $this->resolveCategoryId($repository, $item['category']);

        $repository->createItem([
            'category_id' => $categoryId,
            'label'       => $item['label'],
            'answer'      => trim($item['answer']),
            'import_key'  => $item['key'],
            'sort'        => $repository->nextItemSort($categoryId),
            'status'      => config('constants.QUICK_REPLY.STATUS.ACTIVE'),
        ]);
    }

    /**
     * 提醒已經被拆掉或改寫的舊題目
     *
     * @param QuickReplyRepository $repository
     * @return void
     */
    private function warnRetired(QuickReplyRepository $repository)
    {
        $found = [];

        foreach (self::RETIRED_KEYS as $key => $reason) {
            $item = $repository->findItemByImportKey($key);

            if (filled($item)) {
                $found[] = "  #{$item->id}  {$item->label}（{$reason}）";
            }
        }

        if (blank($found)) {
            return;
        }

        $this->command->warn('以下題目已被新題目取代，請到「快速回覆題庫」停用或刪除：');

        foreach ($found as $line) {
            $this->command->line($line);
        }
    }

    /**
     * 取得類別 id，不存在就建一個
     *
     * @param QuickReplyRepository $repository
     * @param string               $label
     * @return int
     */
    private function resolveCategoryId(QuickReplyRepository $repository, $label)
    {
        $category = $repository->findCategoryByLabel($label);

        if (filled($category)) {
            return $category->id;
        }

        return $repository->createCategory([
            'label'  => $label,
            'sort'   => $repository->nextCategorySort(),
            'status' => config('constants.QUICK_REPLY.STATUS.ACTIVE'),
        ])->id;
    }

    /**
     * 題目內容
     *
     * key 一旦上線就不要改 —— 改了會被當成新題目再建一次。
     *
     * @return array
     */
    private function items()
    {
        return array_merge(
            $this->orderItems(),
            $this->connectionItems(),
            $this->channelItems(),
            $this->orderSnItems(),
            $this->amountItems(),
            $this->fieldItems(),
            $this->specItems()
        );
    }

    /**
     * 訂單狀態與流程
     *
     * @return array
     */
    private function orderItems()
    {
        return [
            [
                'key'      => 'order.status_code',
                'category' => '訂單',
                'label'    => '訂單狀態碼（status）是什麼意思？收到 02 是成功還是失敗？',
                'answer'   => <<<'TEXT'
方便先跟您確認嗎？請問這筆是代收還是代付的訂單呢？

代收與代付的狀態碼定義並不相同，確認之後再為您說明對應的狀態。
TEXT,
            ],
            [
                'key'      => 'order.status_code_deposit',
                'category' => '訂單',
                'label'    => '代收的訂單狀態碼（status）各代表什麼意思？',
                'answer'   => <<<'TEXT'
代收訂單的狀態碼：

• 00：處理中（尚未付款）
• 01：成功
• 02：群組單成功
• 03：失敗

請留意代收的失敗是 03，不是 02。

若是要處理回調通知（異步通知），判斷方式可以更簡單：
只有 00 代表待處理、01 代表成功，其餘一律視為失敗處理即可。
TEXT,
            ],
            [
                'key'      => 'order.status_code_withdraw',
                'category' => '訂單',
                'label'    => '代付的訂單狀態碼（status）各代表什麼意思？',
                'answer'   => <<<'TEXT'
代付訂單的狀態碼：

• 00：處理中
• 01：成功
• 02：失敗

若是要處理回調通知（異步通知），判斷方式可以更簡單：
只有 00 代表待處理、01 代表成功，其餘一律視為失敗處理即可。
TEXT,
            ],
            [
                'key'      => 'order.system_bot',
                'category' => '訂單',
                'label'    => '「系統BOT」群組會通知哪些訊息？',
                'answer'   => <<<'TEXT'
系統會將這些訊息推送到「系統BOT」群組：

• 開單錯誤：建單失敗時的錯誤訊息
• 餘額下放：每日 12 點下放時，通知各商戶今日下放的點數
• 每日營業訊息：每日的營業相關通知

遇到開單失敗時，建議先到這個群組查看，通常可以直接找到錯誤原因。

要查詢單筆訂單的下放狀況，則請看後台的「餘額歷程」——
群組的通知是每日的彙總，餘額歷程才看得到每一張單的下放情形與預計日期。
TEXT,
            ],
            [
                'key'      => 'order.test_reversal',
                'category' => '訂單',
                'label'    => '商戶測試完畢後，測試的訂單與餘額要怎麼沖正？',
                'answer'   => <<<'TEXT'
商戶串接測試完畢後，需要把測試產生的訂單與餘額沖正，才能正式開跑。

沒有沖正的話，這些測試單之後仍會進入餘額下放，
導致對帳的金額對不起來。

整個流程分成三個部分：

1. 代收單處理：待處理的改為作廢，成功的選擇退款
2. 代付單處理：待處理的改為失敗，成功的需先開啟沖銷帳務功能再改
3. 將開給商戶測試的可用餘額歸零

三個部分都完成後，即可正式開跑。
TEXT,
            ],
            [
                'key'      => 'order.test_reversal_deposit',
                'category' => '訂單',
                'label'    => '測試的代收單要怎麼沖正？',
                'answer'   => <<<'TEXT'
依照該筆訂單目前的狀態處理：

【狀態為待處理】
點選該筆的「狀態設定」，改為「作廢」。

【狀態為成功】
點選該筆的「退款」，系統會產生一筆負數的退款紀錄，
例如 -1000.000000(refund)，代表該筆金額已經沖回。

兩種狀態的處理方式不同，請先確認訂單目前的狀態再操作。
TEXT,
            ],
            [
                'key'      => 'order.test_reversal_withdraw',
                'category' => '訂單',
                'label'    => '測試的代付單要怎麼沖正？成功的單改不了失敗？',
                'answer'   => <<<'TEXT'
依照該筆訂單目前的狀態處理：

【狀態為待處理】
點選該筆的「狀態設定」，改為「失敗」。

【狀態為成功】
成功的代付單預設無法直接改成失敗，需要先開啟功能：

1. 到「系統管理」的「全局預設值設定」，
   開啟「代付沖銷帳務（成功改失敗）功能」
2. 回到該筆訂單，點選「狀態設定」改為「失敗」
3. 完成後回到全局預設值設定，把「代付沖銷帳務」功能關閉

第 3 步請務必執行，這個功能只在沖正時才需要開啟。
TEXT,
            ],
            [
                'key'      => 'order.test_reversal_credit',
                'category' => '訂單',
                'label'    => '測試用的餘額要怎麼歸零？',
                'answer'   => <<<'TEXT'
在「商戶代理總覽」找到該商戶，點選「設定選擇」中的「餘額設定」。

接著：

1. 選擇「扣除額度」
2. 在可用餘額欄位輸入要扣除的金額
3. 備註填寫扣除的原因，例如「測試餘額歸零」
4. 按下確認

扣除成功後，系統 BOT 會推送一則後台操作訊息，
內容包含操作者、商店號、當前點數、扣除點數、最後點數與備註，
可以用來核對這次的調整是否正確。

調整的紀錄也會留在「餘額歷程」中可供查詢。
TEXT,
            ],
            [
                'key'      => 'order.payment_flow',
                'category' => '訂單',
                'label'    => '訂單的流程是怎麼跑的？卡住的時候怎麼判斷卡在哪一段？',
                'answer'   => <<<'TEXT'
一筆訂單的資料流以 L1 到 L4 來區分：

• L1：商戶開單到四方站台
• L2：四方站台打到第三方通道
• L3：第三方通道回調四方站台
• L4：四方站台回調給商戶

一般情況下只會有 L1 與 L4 兩段。
只有在使用第三方支付通道時（例如黑貓等），才會多出 L2 與 L3。

判斷卡在哪一段：

• 商戶開單當下就收到錯誤碼：卡在 L1，通常是參數或加簽問題
• 開單成功但訂單長時間維持待處理：若該筆有走第三方通道，
  可能卡在 L2 或 L3，也就是尚未送出或第三方還沒回覆結果
• 訂單已是最終狀態，商戶卻沒收到通知：卡在 L4，
  多為商戶的回調網址無法連線或未回應

提供資料時麻煩一併說明是哪一段：

• 開單的問題：請提供 L1 的請求與回應內容
• 沒收到通知的問題：請提供 L4 的請求與回應內容

另外也請附上代理帳號與商戶訂單號。
TEXT,
            ],
        ];
    }

    /**
     * 加簽、IP、授權
     *
     * @return array
     */
    private function connectionItems()
    {
        return [
            [
                'key'      => 'api.sign',
                'category' => 'API',
                'label'    => '加簽異常（10001）是什麼原因？sign 要怎麼計算？',
                'answer'   => <<<'TEXT'
加簽異常代表送出的 sign 與我們計算出來的不一致。

完整的計算流程：

1. 取出要送出的所有參數，不包含 sign
2. 依參數名稱由 a 到 z 排序
3. 依照 key=value&key1=value1 的規則組成字串
4. 在字串最後接上 &api_key={API_KEY}
5. 對整串文字取 md5，得到的結果就是 sign

實際範例：

參數 agent=abc、amount=1000、order_sn=AD1234567890
排序組串後為
agent=abc&amount=1000&order_sn=AD1234567890&api_key={API_KEY}
再對這整串取 md5 即為 sign。

最常被忽略的三件事：

• 值為 NULL 的參數不要加入組合
• 中文需要做 url encode，PHP 使用 http_build_query 會自動處理
• api_key 是接在字串最後，不參與前面的排序

我們提供線上加簽測試工具，可以直接比對算出來的結果：
{api-domain}/api/try-sign

另外請確認 sign 必須剛好 32 個字元。

若以上都確認無誤仍持續失敗，請商戶提供開單參數 Request，
我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'error.ip_denied',
                'category' => 'API',
                'label'    => '收到 IP Access Denied 怎麼處理？IP 白名單要在哪裡設定？',
                'answer'   => <<<'TEXT'
這代表發出請求的來源 IP 不在白名單內。

白名單分成兩種，用途不同，請確認要設定的是哪一種：

• [API] IP 白名單：呼叫 API 的伺服器 IP，串接用的是這一個
• [登入後台] IP 白名單：登入後台的 IP，留空則不限制

設定方式：登入後台後進入會員管理，找到該帳號並開啟 IP 白名單設定，
填入後即時生效。多組 IP 請用半形逗號分隔，最多可設定 100 組。

若要調整自己帳號的白名單，請到個人資料頁面的 IP 設定。

設定完仍被擋，通常是實際發出請求的出口 IP 與填入的不同
（例如經過代理或負載平衡），可以請對方確認實際的出口 IP 後再補上。
TEXT,
            ],
            [
                'key'      => 'error.unauthorized',
                'category' => 'API',
                'label'    => '代理未授權（10002）是什麼意思？',
                'answer'   => <<<'TEXT'
這代表 agent 參數帶的帳號無法通過驗證，可能的情況：

• 帳號不存在，或拼寫有誤
• 該帳號目前不是啟用狀態
• 上層代理的狀態異常，連帶影響底下的帳號
• 請求的 Content-Type 不正確，導致參數沒有被正確接收。
  參數需以表單格式送出（application/x-www-form-urlencoded），
  若格式不符，系統會收不到 agent，判定結果就會是未授權

另外也建議一併確認 API_KEY 是否正確。
API_KEY 錯誤通常會先收到加簽異常（10001），但若同時有其他參數問題，
也可能出現在這個環節。

若帳號狀態與送出格式都確認無誤，請商戶提供開單參數 Request
與使用的 Content-Type，我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'error.backend_login',
                'category' => 'API',
                'label'    => '後台登入失敗怎麼處理？',
                'answer'   => <<<'TEXT'
請依照畫面上顯示的訊息判斷：

【登入失敗】
帳號或密碼不正確，請確認大小寫，並留意是否有多餘的空白。

【帳號格式錯誤、密碼格式錯誤】
輸入的內容不符格式規定，請重新確認後再試。

【帳號密碼確定正確，但仍然登入不了】
可能是該帳號設定了登入後台的 IP 白名單，而目前的 IP 不在名單內。
請確認後台的 [登入後台] IP 白名單設定，該欄位留空則代表不限制。

若帳號已被鎖定，請與我們聯繫，我們會為您確認身分後協助後續處理。
TEXT,
            ],
            [
                'key'      => 'error.google_2fa',
                'category' => 'API',
                'label'    => 'Google 驗證碼一直錯誤要怎麼處理？驗證器遺失怎麼辦？',
                'answer'   => <<<'TEXT'
驗證碼錯誤最常見的原因是裝置時間與實際時間有落差。

請先確認：

• 手機的時間設定為自動校時（與網路時間同步）
• 輸入的是目前顯示的那一組，驗證碼每 30 秒會更換
• 確認掃描的是正確帳號的驗證器條碼

校時之後重新輸入一次通常就可以正常登入。

若驗證器遺失或更換了手機導致無法取得驗證碼，
請與我們聯繫，我們會為您確認身分後協助重新綁定。
TEXT,
            ],
        ];
    }

    /**
     * 通道與風控
     *
     * @return array
     */
    private function channelItems()
    {
        return [
            [
                'key'      => 'error.channel',
                'category' => 'API',
                'label'    => '支付管道異常（10003）是什麼意思？',
                'answer'   => <<<'TEXT'
這代表當下取不到可以承接這筆訂單的支付通道。

麻煩協助確認以下四項：

1. 通道是否已開啟
2. 通道的水位與筆數是否已滿
3. 商戶在該通道的金額上下限
4. 商戶本身的金額上下限

以上都可以在後台查看。若四項都確認過仍無法排除，
歡迎提供截圖，我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'error.type_disabled',
                'category' => 'API',
                'label'    => '代收類型未開啟（10006）怎麼處理？',
                'answer'   => <<<'TEXT'
這代表該帳號尚未開通所指定的代收類型。

代收類型無法自行開通，需要由我們協助設定。

請先確認送出的代收類型是否正確；若確實需要使用該類型，
麻煩提供代理帳號與要開通的代收類型，我們為您安排。
TEXT,
            ],
            [
                'key'      => 'error.throttle',
                'category' => 'API',
                'label'    => '請求頻率異常（10004）是什麼意思？要怎麼避免？',
                'answer'   => <<<'TEXT'
同一個訂單號每秒只允許請求一次，短時間內重複送出同一筆就會收到這個錯誤。

建議這樣處理：

• 若是重送機制造成的，請在重送之間加上間隔
• 要確認前一筆的結果，請改用訂單查詢，不需要重複建單
• 確認程式沒有因為逾時而立即重試同一筆

若請求頻率正常仍持續收到此錯誤，請商戶提供開單參數 Request
與送出的時間，我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'error.blacklist',
                'category' => 'API',
                'label'    => '黑名單異常資料（10005）是什麼意思？會驗證哪些欄位？',
                'answer'   => <<<'TEXT'
這代表該筆訂單的資料命中了黑名單。

系統共有四種黑名單驗證，各自獨立、需要在後台開啟該項驗證才會生效：

1. 電話：比對 user_phone
2. 銀行全碼資料：比對 bank_code 與 bank_account 的組合，
   僅在銀行卡與 ATM 類型時驗證；同單多組銀行資料也會逐組比對
3. 身分證號碼：比對 user_id_number
4. IP：比對 client_ip，僅在透過 API 建單時驗證

請特別留意：開啟哪一項驗證，對應的欄位就會變成必填。
例如開啟了電話驗證，該筆卻沒有帶 user_phone，
收到的會是付款人電話必填的錯誤，而不是黑名單異常。

要確認是哪一項觸發，請商戶提供開單參數 Request，
我們協助您一起確認。
TEXT,
            ],
        ];
    }

    /**
     * 訂單號
     *
     * @return array
     */
    private function orderSnItems()
    {
        return [
            [
                'key'      => 'error.order_sn_exists',
                'category' => 'API',
                'label'    => '建單回商戶訂單號已存在（-2）怎麼處理？',
                'answer'   => <<<'TEXT'
同一個代理帳號底下，訂單號不可以重複使用。

請先確認是哪一種情況：

• 若是重送同一筆單，請改用訂單查詢確認前一筆的狀態，不需要重新建單
• 若是要建立新的一筆，請改用不同的訂單號

這個錯誤與 -3 不同：-2 是格式正確但號碼已經用過，
-3 則是號碼本身的格式不符規定。

若確認沒有重複使用仍收到此錯誤，請商戶提供開單參數 Request，
我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'error.order_sn_format_deposit',
                'category' => 'API',
                'label'    => '代收建單回商戶訂單號格式異常（-3）怎麼處理？',
                'answer'   => <<<'TEXT'
代收的訂單號需符合這兩項規定：

• 長度 10～50 個字元
• 只接受英文、數字與連字號（-）

底線（_）在代收是不被接受的，若訂單號帶了底線就會收到這個錯誤。

這個錯誤與 -2 不同：-3 是號碼本身的格式不符，
-2 則是格式正確但這個號碼已經用過了。

若格式確認無誤仍收到此錯誤，請商戶提供開單參數 Request，
我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'error.order_sn_format_withdraw',
                'category' => 'API',
                'label'    => '代付建單回商戶訂單號格式異常（-3）怎麼處理？',
                'answer'   => <<<'TEXT'
代付的訂單號需符合這兩項規定：

• 長度 10～50 個字元
• 只接受英文、數字與底線（_）

連字號（-）在代付是不被接受的，若訂單號帶了連字號就會收到這個錯誤。

這個錯誤與 -2 不同：-3 是號碼本身的格式不符，
-2 則是格式正確但這個號碼已經用過了。

若格式確認無誤仍收到此錯誤，請商戶提供開單參數 Request，
我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'api.order_sn_diff',
                'category' => 'API',
                'label'    => '為什麼同一個訂單號格式，代收可以用、代付卻失敗？',
                'answer'   => <<<'TEXT'
因為代收與代付允許的符號並不相同：

• 代收：英文、數字、連字號（-）
• 代付：英文、數字、底線（_）

長度規定則相同，都是 10～50 個字元。

所以代收慣用的 abc-123 直接拿去送代付會被判格式異常；
反之代付的 out_016746 拿去送代收也會失敗。

若兩邊要共用同一套訂單號規則，建議只使用英文與數字，
這樣代收與代付都能通過。
TEXT,
            ],
            [
                'key'      => 'error.order_not_found',
                'category' => 'API',
                'label'    => '訂單查詢回商戶訂單號不存在（-2），但確定有建單成功？',
                'answer'   => <<<'TEXT'
請先確認這幾點：

• 建單當下收到的回應是否為成功（code 為 1）；
  若當時是錯誤碼，表示訂單其實沒有建立
• 查詢時帶的代理帳號與建單時是否為同一個
• 訂單號是否完全一致，包含大小寫
• 代收與代付的查詢是不同的介面，請確認用對了

若以上都確認無誤，請商戶提供開單參數 Request
與當下收到的 Response，我們協助您一起確認。
TEXT,
            ],
        ];
    }

    /**
     * 金額與餘額
     *
     * @return array
     */
    private function amountItems()
    {
        return [
            [
                'key'      => 'error.amount_invalid',
                'category' => 'API',
                'label'    => '建單回金額異常（-4）是什麼原因？',
                'answer'   => <<<'TEXT'
金額異常可以先檢查以下兩點：

1. 商戶本身的金額區間
2. 商戶通道的金額區間

另外也請確認金額格式，未開放小數的情況下需為整數，
且不可為 0 或負數。

這個錯誤與 10007 不同：-4 是參數還沒送到通道就被擋下，
10007 則是參數已經通過檢查，但通道端判定這筆金額無法承接。

若區間與格式都確認無誤，請商戶提供開單參數 Request，
我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'error.amount_channel',
                'category' => 'API',
                'label'    => '建單回金額異常（10007）是什麼原因？跟 -4 有什麼不同？',
                'answer'   => <<<'TEXT'
10007 代表參數本身已經通過檢查，但在向支付通道建單時，
通道端判定這筆金額無法承接。

可以先檢查以下兩點：

1. 商戶本身的金額區間
2. 商戶通道的金額區間

與 -4 的差別在發生的階段：-4 是參數檢查就被擋下，
10007 則是參數沒問題、卡在通道端，
所以這個錯誤更常是商戶通道的區間造成的。

若兩個區間都確認無誤，請商戶提供開單參數 Request，
我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'error.credit',
                'category' => 'API',
                'label'    => '代付建單回商戶餘額不足（-8）怎麼辦？',
                'answer'   => <<<'TEXT'
代付會先從餘額扣款，餘額不足時無法建單。

請先確認：

• 目前餘額是否足夠支付該筆金額
• 是否有多筆代付同時進行，額度已被先行占用

目前的餘額可以透過商戶資訊查詢取得。

若是在等待餘額下放，請到後台的「餘額歷程」查看，
該頁面會顯示每一張單是否已經下放，以及預計的下放日期。
TEXT,
            ],
        ];
    }

    /**
     * 欄位
     *
     * @return array
     */
    private function fieldItems()
    {
        return [
            [
                'key'      => 'error.required_field',
                'category' => 'API',
                'label'    => '一直回某個欄位必填，但確定有帶那個參數，是什麼原因？',
                'answer'   => <<<'TEXT'
若確定參數有送出，請往這幾個方向確認：

• 參數名稱是否完全一致，包含大小寫與底線
• 值是否為空字串或 null，那會被視為未填寫
• 是否送成巢狀結構，參數需為單層的表單格式

另外要提醒，不同的支付通道與代收類型，必填欄位並不相同，
例如超商繳費需要繳費內容與代碼，部分通道需要付款人身分證或電子信箱。

請商戶提供開單參數 Request，我們協助您一起逐項比對。
TEXT,
            ],
            [
                'key'      => 'error.bank_format',
                'category' => 'API',
                'label'    => '代付建單回銀行名稱、代碼、帳號或分行格式異常怎麼處理？',
                'answer'   => <<<'TEXT'
這一類錯誤是欄位有填，但內容不符規定，多半是長度超過上限：

• bank_name 銀行名稱：30 字以內
• bank_code 銀行代碼：10 字以內
• bank_account 銀行帳號：50 字以內
• bank_branch_name 分行名稱：30 字以內
• bank_branch_code 分行代碼：15 字以內
• user_phone 收款人電話：20 字以內

也請一併確認銀行帳號沒有夾帶空白或連字號等符號。

若長度與內容都確認無誤，請商戶提供開單參數 Request，
我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'error.apply_fail',
                'category' => 'API',
                'label'    => '建單回 -1 建單異常，沒有其他說明，這是什麼原因？',
                'answer'   => <<<'TEXT'
-1 是比較概括的錯誤，代表參數都通過檢查了，但實際建立訂單時沒有成功。

建議先到「系統BOT」群組查看，系統會將錯誤訊息通知到該群組，
通常可以直接看到這筆失敗的原因。

常見的原因：

• 支付通道當下無法受理該筆訂單
• 該筆金額沒有可承接的通道
• 通道端回覆異常

若在群組中找不到對應的紀錄，請商戶提供開單參數 Request，
我們協助您一起確認。
TEXT,
            ],
        ];
    }

    /**
     * 規格說明
     *
     * @return array
     */
    private function specItems()
    {
        return [
            [
                'key'      => 'api.callback_deposit',
                'category' => 'API',
                'label'    => '代收的回調（異步通知）會送哪些參數？收到後要回什麼？',
                'answer'   => <<<'TEXT'
回調網址請於後台設定，訂單狀態變動時會以 POST 通知。

代收回調送出的參數：

• agent：代理帳號
• system_sn：系統單號
• order_sn：商戶訂單號
• amount：金額
• total_commission：總佣金
• status：訂單狀態，00 為待處理、01 為成功，其餘一律視為失敗處理即可
• type：固定為 0，代表代收
• note：繳費資訊，例如超商店點或虛擬帳號
• created_at：建立時間
• completed_at：完成時間，可能為空字串
• overtime_at：失效時間
• sign：加簽

若該筆是同單多組銀行資料的群組單，order_sn 只會回傳主單號。

收到之後請回傳 OK，系統才會判定通知成功；
沒有收到 OK 的回應會被記錄為回調失敗。

驗證回調的 sign 時，只需要對上述參數驗證，計算方式與送單時相同。
TEXT,
            ],
            [
                'key'      => 'api.callback_withdraw',
                'category' => 'API',
                'label'    => '代付的回調（異步通知）會送哪些參數？收到後要回什麼？',
                'answer'   => <<<'TEXT'
回調網址請於後台設定，訂單狀態變動時會以 POST 通知。

代付回調送出的參數：

• agent：代理帳號
• system_sn：系統單號
• order_sn：商戶訂單號
• amount：金額
• total_commission：總佣金
• status：訂單狀態，00 為待處理、01 為成功，其餘一律視為失敗處理即可
• type：固定為 1，代表代付
• note：支付備註
• created_at：建立時間
• completed_at：完成時間，可能為空字串
• overtime_at：失效時間
• sign：加簽

收到之後請回傳 OK，系統才會判定通知成功；
沒有收到 OK 的回應會被記錄為回調失敗。

驗證回調的 sign 時，只需要對上述參數驗證，計算方式與送單時相同。
TEXT,
            ],
            [
                'key'      => 'api.callback_missing',
                'category' => 'API',
                'label'    => '沒有收到回調通知怎麼辦？',
                'answer'   => <<<'TEXT'
最常見的兩個原因，可以先從這兩個方向確認：

• 回調網址無法從外部連線，例如擋了外部來源或網址填錯
• 有收到通知，但回應內容沒有包含 OK，這樣會被判定為回調失敗

確認方式：

1. 檢查後台設定的回調網址是否正確，且可從外部正常連線
2. 確認收到通知後有回傳 OK
3. 查看該筆訂單目前的狀態，確認是否已經進入最終狀態

若以上都確認無誤，請商戶提供設定的回調網址
與他們那邊的接收紀錄，我們協助您一起確認。
TEXT,
            ],
            [
                'key'      => 'api.environment',
                'category' => 'API',
                'label'    => '商戶串接需要的資訊（API 網域、API_KEY）要去哪裡取得？',
                'answer'   => <<<'TEXT'
請到後台的「串接資訊」頁面，找到該商戶後複製以下資訊提供給他們：

• API 網域
• 代理帳號（agent）
• API_KEY

同一個頁面也可以修改這些設定。

另外提醒商戶，系統時區為 GMT+8，請求與回應的時間都以此為準。

串接文件與加簽測試工具的網址也可以向我們索取，一併提供給商戶。
TEXT,
            ],
            [
                'key'      => 'api.test_environment',
                'category' => 'API',
                'label'    => '有測試環境嗎？商戶要怎麼測試串接？',
                'answer'   => <<<'TEXT'
系統沒有獨立的測試環境，正式與測試是共用同一套，
商戶可以直接在正式環境進行串接測試。

測試前請先確認已經配置可用的通道給該商戶，
否則會因為取不到通道而無法建單。

測試完畢後，務必把測試產生的訂單與餘額沖正才能正式開跑 ——
成功的訂單要改掉，測試餘額要歸零，否則這些測試單之後仍會進入餘額下放，
造成對帳金額對不起來。

另外建議測試時使用較小的金額，避免影響實際的交易。
TEXT,
            ],
            [
                'key'      => 'api.deposit_apply_field',
                'category' => 'API',
                'label'    => '代收建單 API 有哪些必填欄位？',
                'answer'   => <<<'TEXT'
不論哪一種代收類型，這四個欄位都是必填：

• agent：代理帳號
• sign：加簽，需為 32 個字元
• order_sn：商戶訂單號
• amount：金額

其餘欄位會依代收類型而不同，方便先跟您確認兩件事嗎？

1. 這筆是使用哪一種代收類型呢？（銀行卡、超商、ATM、信用卡、USDT）
2. 該帳號有開啟哪些驗證？
   （防詐設定，以及黑名單的電話／銀行全碼／身分證／IP 驗證）

開啟上述任一項驗證後，被驗證的欄位也會跟著變成必填。
確認之後再為您提供完整的欄位清單。
TEXT,
            ],
            [
                'key'      => 'api.deposit_field_bank',
                'category' => 'API',
                'label'    => '代收的銀行卡類型（type 0）有哪些必填欄位？',
                'answer'   => <<<'TEXT'
銀行卡代收（type 帶 0，或不帶 type 時的預設）的必填欄位：

• agent：代理帳號
• sign：加簽，需為 32 個字元
• order_sn：商戶訂單號
• amount：金額

選填欄位：

• bank_account：付款人銀行帳號，只能是數字
• bank_data_json：同單多組銀行資料，需為 JSON 格式

另外這些驗證開啟後，對應的欄位會變成必填：

• 防詐設定：user_name（付款人姓名）
• 黑名單電話驗證：user_phone
• 黑名單銀行全碼驗證：bank_code 與 bank_account
• 黑名單身分證驗證：user_id_number
• 黑名單 IP 驗證：client_ip
TEXT,
            ],
            [
                'key'      => 'api.deposit_field_atm',
                'category' => 'API',
                'label'    => '代收的 ATM 類型（type 2）有哪些必填欄位？',
                'answer'   => <<<'TEXT'
ATM 代收（type 帶 2）的必填欄位：

• agent：代理帳號
• sign：加簽，需為 32 個字元
• order_sn：商戶訂單號
• amount：金額

另外這些驗證開啟後，對應的欄位會變成必填：

• 防詐設定：user_name（付款人姓名）
• 黑名單電話驗證：user_phone
• 黑名單銀行全碼驗證：bank_code 與 bank_account
  （此項驗證僅在銀行卡與 ATM 類型時生效）
• 黑名單身分證驗證：user_id_number
• 黑名單 IP 驗證：client_ip
TEXT,
            ],
            [
                'key'      => 'api.deposit_field_cvs',
                'category' => 'API',
                'label'    => '代收的超商類型（type 1）有哪些必填欄位？',
                'answer'   => <<<'TEXT'
超商代收（type 帶 1）的必填欄位：

• agent：代理帳號
• sign：加簽，需為 32 個字元
• order_sn：商戶訂單號
• amount：金額，超商有獨立的單筆上下限，與其他類型不同
• user_name：付款人姓名
• pay_content：繳費內容，100 字以內

另有 cvs_payment（超商繳費類型），可填 711、Family、HiLife、OK。

超商的金額上下限與一般代收是分開設定的，
若金額被判異常，請確認的是超商的限額而不是一般代收的限額。
TEXT,
            ],
            [
                'key'      => 'api.deposit_field_usdt',
                'category' => 'API',
                'label'    => '代收的 USDT 類型（type 4）有哪些必填欄位？',
                'answer'   => <<<'TEXT'
USDT 代收有兩種開單方式，必填欄位不同：

【方法一：由會員自行填寫地址、姓名、數量】

商戶開單時只需要帶四個欄位：

• agent：代理帳號
• type：填 4
• order_sn：商戶訂單號
• sign：加簽

會員會在收銀台自行填寫 USDT 地址、數量與付款人。

【方法二：商戶先填好，會員只需複製地址繳款】

商戶開單時需要帶：

• agent：代理帳號
• type：填 4
• order_sn：商戶訂單號
• amount：金額
• user_name：付款人姓名
• bank_account：USDT 地址
• sign：加簽

會員收到收銀台後，直接依照上面顯示的地址與數量繳款。

兩種方式的回應都會在 payment_account 欄位帶回收銀台網址，
將該網址提供給會員即可。
TEXT,
            ],
            [
                'key'      => 'api.deposit_field_cc',
                'category' => 'API',
                'label'    => '代收的信用卡類型（type 3）有哪些必填欄位？',
                'answer'   => <<<'TEXT'
信用卡代收（type 帶 3）的必填欄位：

• agent：代理帳號
• sign：加簽，需為 32 個字元
• order_sn：商戶訂單號
• amount：金額
• user_phone：付款人電話

若該帳號有開啟防詐設定，user_name（付款人姓名）也會變成必填。
TEXT,
            ],
            [
                'key'      => 'api.withdraw_apply_field',
                'category' => 'API',
                'label'    => '代付建單 API 有哪些必填欄位？欄位長度上限是多少？',
                'answer'   => <<<'TEXT'
代付建單的必填欄位：

• agent：代理帳號
• sign：加簽，需為 32 個字元
• order_sn：商戶訂單號，10～50 個英數字或底線（_），同一代理帳號底下不可重複
• amount：金額，須落在該帳號的代付單筆上下限之間，且餘額需足夠
• bank_name：銀行名稱，30 字以內
• bank_code：銀行代碼，10 字以內
• bank_account：銀行帳號，50 字以內

選填欄位與長度上限：

• bank_branch_name：分行名稱，30 字以內
• bank_branch_code：分行代碼，15 字以內
• user_phone：收款人電話，20 字以內（部分以手機號收款的通道為必填）

若送的是 USDT 代付（type 帶 1），則不需要填寫銀行名稱。
TEXT,
            ],
        ];
    }
}
