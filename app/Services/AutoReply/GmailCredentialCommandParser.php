<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把「驗一下 Gmail 憑證，帳號 xxx 密碼 yyy refresh token zzz」解析出來
 *
 * ⚠ **一次問完，不像 spider_setting 那支分兩步**：這裡的欄位固定就是三組
 * 憑證（加一個選填的銀行），不必先認通道再帶欄位清單進去。
 *
 * ⚠ **值一律原文照抄**。憑證裡有 `-`、`_`、`/`、`.` 這些符號
 * （`1//0eXXXX`、`GOCSPX-xxxx`、`...apps.googleusercontent.com`），
 * 模型只要「順手整理一下」就全毀 —— 而驗證失敗時人會以為是憑證本身錯了。
 */
class GmailCredentialCommandParser
{
    /** @var string 要驗 Gmail 憑證 */
    const INTENT_GMAIL = 'gmail';

    /** @var string 不是在講這件事（呼叫端要換下一個 handler） */
    const INTENT_NOT_GMAIL = 'not_gmail';

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 解析一句話
     *
     * @param string $text 已經去掉 @機器人
     * @return array{intent: string, client_id: string, client_secret: string, refresh_token: string, bank: string}|null
     *         null = 模型不可用
     */
    public function parse($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return $this->notGmail();
        }

        $config = (array) config('constants.GMAIL_CREDENTIAL_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                strtr((string) Arr::get($config, 'PROMPT'), [
                    '{banks}' => $this->bankList(),
                ]),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('Gmail 憑證指令解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalize($result);
    }

    /**
     * 餵給模型的銀行清單（代碼 ＋ 名稱）
     *
     * ⚠ 用設定檔裡那 12 家，**不要讓模型自己想銀行代碼** —— 它很會編
     * （台灣的銀行代碼它記得不少，但能爬信的只有這 12 家），
     * 編出來的代碼查不到搜尋條件，第三步就白跑。
     *
     * @return string
     */
    private function bankList()
    {
        $lines = [];

        foreach ((array) config('gmail_bank_queries.BANKS') as $code => $bank) {
            $lines[] = "{$code}（" . Arr::get($bank, 'name') . '）';
        }

        return filled($lines) ? implode('、', $lines) : '（清單是空的）';
    }

    /**
     * @param array $result
     * @return array
     */
    private function normalize(array $result)
    {
        if ((string) Arr::get($result, 'intent') !== self::INTENT_GMAIL) {
            return $this->notGmail();
        }

        return [
            'intent'        => self::INTENT_GMAIL,
            'client_id'     => $this->clean(Arr::get($result, 'client_id')),
            'client_secret' => $this->clean(Arr::get($result, 'client_secret')),
            'refresh_token' => $this->clean(Arr::get($result, 'refresh_token')),
            'bank'          => $this->bankCode(Arr::get($result, 'bank')),
        ];
    }

    /**
     * 憑證的值
     *
     * ⚠ 只去頭尾空白，**中間一個字都不要動**。
     *
     * @param mixed $value
     * @return string
     */
    private function clean($value)
    {
        return trim((string) $value);
    }

    /**
     * 銀行代碼
     *
     * ⚠ 清單外的一律丟掉：模型編出一個 `822` 的話，`searchBankMail()` 查不到
     * 搜尋條件會回「不支援」，看的人會以為是這家銀行不能爬 ——
     * 而其實是他根本沒提到這家銀行。
     *
     * @param mixed $value
     * @return string 空字串 ＝ 沒講銀行
     */
    private function bankCode($value)
    {
        $code = trim((string) $value);

        if (blank($code)) {
            return '';
        }

        return filled(config("gmail_bank_queries.BANKS.{$code}")) ? $code : '';
    }

    /**
     * @return array
     */
    private function notGmail()
    {
        return [
            'intent'        => self::INTENT_NOT_GMAIL,
            'client_id'     => '',
            'client_secret' => '',
            'refresh_token' => '',
            'bank'          => '',
        ];
    }

    /**
     * @return array
     */
    private function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'intent' => [
                    'type'        => 'string',
                    'enum'        => [self::INTENT_GMAIL, self::INTENT_NOT_GMAIL],
                    'description' => '要驗 Gmail 憑證／做 Gmail 的 spider_setting（gmail），或不是在講這件事（not_gmail）',
                ],
                'client_id' => [
                    'type'        => ['string', 'null'],
                    'description' => '也叫「帳號」，通常長得像 123456-abcdef.apps.googleusercontent.com。原文照抄，沒給填 null',
                ],
                'client_secret' => [
                    'type'        => ['string', 'null'],
                    'description' => '也叫「密碼」、「金鑰」，通常以 GOCSPX- 開頭。原文照抄，沒給填 null',
                ],
                'refresh_token' => [
                    'type'        => ['string', 'null'],
                    'description' => '通常以 1// 開頭。原文照抄，沒給填 null',
                ],
                'bank' => [
                    'type'        => ['string', 'null'],
                    'description' => '他有提到是哪一家銀行的話，填清單裡對應的三位數代碼；沒提到就填 null',
                ],
            ],
            'required' => ['intent', 'client_id', 'client_secret', 'refresh_token', 'bank'],
        ];
    }
}
