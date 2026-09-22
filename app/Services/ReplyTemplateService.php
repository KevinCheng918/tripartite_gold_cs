<?php

namespace App\Services;

/**
 * 對客話術服務
 *
 * 自動回覆送給客人的訊息內容。答案取自題庫原文，這裡管的是包在外層的語氣。
 *
 * ⚠️ 改版後開場白由 AI 的承接句負責，**這些模板預設只剩 `{答案}`**。
 * 在這裡加固定的問候語或結尾語，客人連著問就會看到一模一樣的句子 ——
 * 那正是改版前被嫌罐頭的原因。`wait_*` 是承接句被護欄擋下時的退路。
 *
 * 與 Claude 憑證刻意拆成兩頁兩組權限：客服要能自己調語氣，但不該碰得到 token。
 */
class ReplyTemplateService
{
    /**
     * 欄位 => 必須包含的變數（null 代表沒有必填變數）
     *
     * 少了變數的模板會讓客人收到一則沒有內容的客套話，
     * 而那在畫面上看不出來 —— 所以這份定義同時給表單渲染與後端驗證用，
     * 兩邊共用一份才不會漂移。
     *
     * @var array
     */
    const FIELDS = [
        'answer_full'   => '{答案}',
        'answer_short'  => '{答案}',
        'wait_full'     => null,
        'wait_short'    => null,
        'support_full'  => '{答案}',
        'support_short' => '{答案}',
    ];

    /** @var array 欄位前綴 => 對應的設定 key */
    private const KEY_MAP = [
        'answer_full'   => AppSettingService::KEY_TPL_ANSWER_FULL,
        'answer_short'  => AppSettingService::KEY_TPL_ANSWER_SHORT,
        'wait_full'     => AppSettingService::KEY_TPL_WAIT_FULL,
        'wait_short'    => AppSettingService::KEY_TPL_WAIT_SHORT,
        'support_full'  => AppSettingService::KEY_TPL_SUPPORT_FULL,
        'support_short' => AppSettingService::KEY_TPL_SUPPORT_SHORT,
    ];

    private $appSettingService;

    public function __construct(AppSettingService $appSettingService)
    {
        $this->appSettingService = $appSettingService;
    }

    /**
     * 頁面要顯示的全部模板
     *
     * @return array 欄位 => 內容
     */
    public function forPage()
    {
        $templates = [];

        foreach (self::KEY_MAP as $field => $key) {
            $templates[$field] = $this->appSettingService->get($key);
        }

        return $templates;
    }

    /**
     * 更新模板
     *
     * @param array    $params
     * @param int|null $userId
     * @return void
     */
    public function update($params, $userId = null)
    {
        $values = [];

        foreach (self::KEY_MAP as $field => $key) {
            if (array_key_exists($field, $params)) {
                $values[$key] = $params[$field];
            }
        }

        $this->appSettingService->putMany($values, $userId);
    }
}
