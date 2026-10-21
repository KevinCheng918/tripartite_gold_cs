<?php

namespace App\Services;

use App\Services\AutoReply\ApplyDumpParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 客人貼開單 request 過來時，照規則表預排查，找出錯在哪一欄
 *
 * ⚠⚠ **一定要查兩層。** 這是整支存在的理由。
 *
 * `-1 订单異常` 是主系統 `PaymentController` 最外層 `catch (\Exception)`
 * 掉出來的通用錯誤。欄位驗證失敗都有各自的專屬碼（`-3`、`-4`、`-18`…），
 * 所以**只比對 Laravel 的驗證規則，永遠解釋不了 `-1`**。
 *
 * 真正的 `-1` 來源是**驗證放行、但寫不進資料庫**的那段落差：
 * `bank_branch_code` 驗 `max:15` 而欄位是 `varchar(10)` —— 11~15 字過得了
 * 驗證，`INSERT` 時爆掉，拋的是 PDO 例外不是 `ApiErrorException`。
 *
 * 需求方 2026-10-21 給的實例就是這一種（分行代碼填了 14 字的分行名稱）。
 *
 * 所以結果分三種，**意義完全不同**：
 * - `blocked`：違反驗證規則 → API 會回那一欄的專屬碼（**不會是 -1**）
 * - `fatal`：過得了驗證但超過資料庫上限 → **這才是 -1 的原因**
 * - `suspicious`：都合規，但內容看起來放錯（分行代碼填了名稱）→ 只提醒，不斷定
 */
class ApplyErrorDiagnoseService
{
    /** @var int 貼上來的內容至少要有幾個特徵才當成開單紀錄 */
    const MARKER_THRESHOLD = 2;

    /**
     * 開單紀錄的特徵字
     *
     * ⚠ 這裡**可以**用關鍵字粗篩，跟 2026-10-13 拿掉指令粗篩的理由不衝突：
     * 那邊篩的是**人**講的話（講法追不完），這裡篩的是**機器**印出來的
     * dump（`print_r` 的格式固定）。而且漏接只是退回原本的轉人工，不是出錯。
     *
     * @var array<string>
     */
    const MARKERS = [
        'order_sn',
        '/api/withdraw',
        '/api/deposit',
        '原始參數',
        '原始参数',
        '请求URL',
        '請求URL',
        'bank_account',
        'notify_url',
    ];

    /** @var string 違反驗證規則 */
    const LEVEL_BLOCKED = 'blocked';

    /** @var string 過驗證但寫不進資料庫（＝ -1 的來源） */
    const LEVEL_FATAL = 'fatal';

    /** @var string 合規但內容可疑 */
    const LEVEL_SUSPICIOUS = 'suspicious';

    private $parser;

    public function __construct(ApplyDumpParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * 這段話像不像客人貼過來的開單紀錄
     *
     * @param string $text
     * @return bool
     */
    public function looksLikeDump($text)
    {
        $text = (string) $text;

        // 短路：太短的不可能是整坨 dump，不必逐字找特徵
        if (mb_strlen($text) < 60) {
            return false;
        }

        $hits = 0;

        foreach (self::MARKERS as $marker) {
            if (mb_stripos($text, $marker) === false) {
                continue;
            }

            $hits++;

            if ($hits >= self::MARKER_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /**
     * 診斷
     *
     * @param string $text 客人貼過來的那一坨
     * @return array{flow: string, code: int|null, findings: array, customer_text: string, staff_text: string}|null
     *         null ＝ 不是開單紀錄，或模型不可用
     */
    public function diagnose($text)
    {
        // 短路：形狀都不像就不要燒模型額度
        if (!$this->looksLikeDump($text)) {
            return null;
        }

        $parsed = $this->parser->parse($text);

        if (blank($parsed)) {
            return null;
        }

        $fields = (array) Arr::get($parsed, 'fields', []);

        // 一個欄位都沒抓到 ＝ 其實不是開單紀錄（只是剛好有幾個特徵字）
        if (blank($fields)) {
            return null;
        }

        $flow = (string) Arr::get($parsed, 'flow');
        $code = Arr::get($parsed, 'code');

        /*
         * ⚠ 判不出代收還是代付時**兩套規則都跑**，再挑說得通的那一套。
         *
         * 兩邊的 `order_sn` 正則不一樣（代收允許 `-`、代付允許 `_`），
         * 猜錯邊會報一個根本不存在的格式錯誤。
         *
         * ⚠ **挑的順序是「能不能解釋客人收到的那個碼」優先**，違規少的只是
         * 退而求其次。只看「違規少」會挑錯：訂單號 `abc-def-ghij` 在代收規則下
         * 完全合規，於是挑了代收並回「欄位都正常」—— 但客人收到的是 `-3`
         * 訂單號格式異常，那正是代付規則擋下來的。能對上碼的那一套才是真相。
         */
        $flows = ($flow === ApplyDumpParser::FLOW_UNKNOWN)
            ? [ApplyDumpParser::FLOW_WITHDRAW, ApplyDumpParser::FLOW_DEPOSIT]
            : [$flow];

        $best = null;

        foreach ($flows as $candidate) {
            $findings = $this->check($candidate, $fields);

            $score = [
                $this->explainsCode($findings, $code) ? 0 : 1,
                count(Arr::get($findings, self::LEVEL_BLOCKED, [])),
            ];

            if (blank($best) || $score < Arr::get($best, 'score')) {
                $best = ['flow' => $candidate, 'findings' => $findings, 'score' => $score];
            }
        }

        $findings = (array) Arr::get($best, 'findings');
        $resolved = (string) Arr::get($best, 'flow');

        Log::info('開單錯誤預排查', [
            'flow'       => $resolved,
            'guessed'    => $flow === ApplyDumpParser::FLOW_UNKNOWN,
            'code'       => $code,
            'blocked'    => array_column(Arr::get($findings, self::LEVEL_BLOCKED, []), 'field'),
            'fatal'      => array_column(Arr::get($findings, self::LEVEL_FATAL, []), 'field'),
            'suspicious' => array_column(Arr::get($findings, self::LEVEL_SUSPICIOUS, []), 'field'),
        ]);

        return [
            'flow'          => $resolved,
            'code'          => $code,
            'findings'      => $findings,
            'customer_text' => $this->customerText($findings, $code, $resolved),
            'staff_text'    => $this->staffText($resolved, $code, $flow, $findings),
        ];
    }

    /**
     * 這一套規則判出來的違規，有沒有對上客人收到的那個碼
     *
     * ⚠ 只看 `blocked`：那一層的違規會讓 API 回該欄位的專屬碼，所以對得起來
     * 才算「解釋得了」。`fatal` 一律變成通用碼，對不上任何專屬碼。
     *
     * @param array    $findings
     * @param int|null $code
     * @return bool
     */
    private function explainsCode(array $findings, $code)
    {
        if ($code === null) {
            return false;
        }

        foreach ((array) Arr::get($findings, self::LEVEL_BLOCKED, []) as $finding) {
            if ((int) Arr::get($finding, 'code') === (int) $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * 逐欄比對規則
     *
     * @param string $flow
     * @param array  $fields
     * @return array{blocked: array, fatal: array, suspicious: array}
     */
    private function check($flow, array $fields)
    {
        $findings = [
            self::LEVEL_BLOCKED    => [],
            self::LEVEL_FATAL      => [],
            self::LEVEL_SUSPICIOUS => [],
        ];

        foreach ((array) config("apply_rules.FIELDS.{$flow}") as $name => $spec) {
            $value = Arr::get($fields, $name);

            // 這一坨裡沒有這個欄位 —— 只有「真的必填」才算缺漏
            if ($value === null) {
                $this->checkMissing($name, $spec, $findings);

                continue;
            }

            if (blank($value)) {
                $this->checkMissing($name, $spec, $findings);

                continue;
            }

            $this->checkValue($name, $spec, $value, $findings);
        }

        return $findings;
    }

    /**
     * 沒給值
     *
     * ⚠ `required` 是 `'conditional'` 的**不算缺漏**：那是「看類型或設定才
     * 必填」（超商才要 `pay_content`、開了實名驗證才要 `user_name`），
     * 客服系統不知道對方是哪一種，報了就是誤報。
     *
     * @param string $name
     * @param array  $spec
     * @param array  $findings
     * @return void
     */
    private function checkMissing($name, array $spec, array &$findings)
    {
        if (Arr::get($spec, 'required') !== true) {
            return;
        }

        $findings[self::LEVEL_BLOCKED][] = [
            'field'  => $name,
            'label'  => (string) Arr::get($spec, 'label', $name),
            'reason' => 'blank',
            'code'   => Arr::get($spec, 'code_blank'),
        ];
    }

    /**
     * 有給值，逐條規則比對
     *
     * ⚠ **順序有意義**：先驗證層（`regex`／`max`／`in`）再資料庫層（`db`）。
     * 驗證層擋下的話 API 回的是專屬碼，根本走不到寫入 —— 兩邊都報會讓人
     * 以為有兩個問題。
     *
     * @param string $name
     * @param array  $spec
     * @param string $value
     * @param array  $findings
     * @return void
     */
    private function checkValue($name, array $spec, $value, array &$findings)
    {
        $label = (string) Arr::get($spec, 'label', $name);
        $length = mb_strlen($value);

        $regex = Arr::get($spec, 'regex');

        if (filled($regex) && !preg_match($regex, $value)) {
            $findings[self::LEVEL_BLOCKED][] = [
                'field'  => $name,
                'label'  => $label,
                'reason' => 'regex',
                // 給客服看的說明也用文件的講法，兩邊講同一件事比較好對
                'hint'   => (string) Arr::get($spec, 'doc_hint'),
                'length' => $length,
                'code'   => Arr::get($spec, 'code_format'),
            ];

            return;
        }

        $in = (array) Arr::get($spec, 'in', []);

        if (filled($in) && !in_array($value, $in, true)) {
            $findings[self::LEVEL_BLOCKED][] = [
                'field'  => $name,
                'label'  => $label,
                'reason' => 'in',
                // 只放清單本身 —— 前後的句子由文案模板決定（對客與對內講法不同）
                'hint'   => implode('、', $in),
                'code'   => Arr::get($spec, 'code_format'),
            ];

            return;
        }

        if (Arr::get($spec, 'expect') === 'json' && blank(json_decode($value, true))) {
            $findings[self::LEVEL_BLOCKED][] = [
                'field'  => $name,
                'label'  => $label,
                'reason' => 'json',
                'code'   => Arr::get($spec, 'code_format'),
            ];

            return;
        }

        $max = Arr::get($spec, 'max');

        if (filled($max) && $length > (int) $max) {
            $findings[self::LEVEL_BLOCKED][] = [
                'field'  => $name,
                'label'  => $label,
                'reason' => 'max',
                'limit'  => (int) $max,
                'length' => $length,
                'code'   => Arr::get($spec, 'code_format'),
            ];

            return;
        }

        /*
         * ⚠ **這一段就是 `-1` 的來源。**
         *
         * 走到這裡代表驗證層全部放行，但值超過資料庫欄位能存的長度 ——
         * `INSERT` 會爆，而拋出來的是 PDO 例外不是 `ApiErrorException`，
         * 所以客人收到的是通用的 `-1`，看不出是哪一欄。
         */
        $db = Arr::get($spec, 'db');

        if (filled($db) && $length > (int) $db) {
            $findings[self::LEVEL_FATAL][] = [
                'field'  => $name,
                'label'  => $label,
                'reason' => 'db_length',
                'limit'  => (int) $db,
                'length' => $length,
                'value'  => $value,
            ];

            return;
        }

        /*
         * ⚠ **`strtotime()` 失敗回的是 `false`，不能用 `blank()` 判**：
         * `blank(false)` 回的是 **false**（`blank()` 對布林一律回 false），
         * 所以寫成 `blank(strtotime($value))` 這一條永遠不會成立 ——
         * 生日填「民國80年3月」會被當成正常，而它其實是 -1 的原因之一。
         * 同一個坑見 bugfix/2026-10-12-filled-on-boolean。
         */
        if (Arr::get($spec, 'db_type') === 'date' && strtotime($value) === false) {
            $findings[self::LEVEL_FATAL][] = [
                'field'  => $name,
                'label'  => $label,
                'reason' => 'db_date',
                'value'  => $value,
            ];

            return;
        }

        /*
         * 合規，但內容看起來放錯。
         *
         * ⚠ 只提醒**不斷定**：`bank_branch_code` 填「台北富邦鼓山分行(6823)」
         * 長度剛好過得去的話，驗證與寫入都不會擋，但那顯然是分行名稱。
         */
        if (Arr::get($spec, 'expect') === 'code' && preg_match('/\p{Han}/u', $value)) {
            $findings[self::LEVEL_SUSPICIOUS][] = [
                'field'  => $name,
                'label'  => $label,
                'reason' => 'looks_like_name',
                'value'  => $value,
                'guess'  => $this->guessCode($value),
            ];
        }
    }

    /**
     * 從「台北富邦鼓山分行(6823)」猜出 `6823`
     *
     * @param string $value
     * @return string 猜不出來回空字串
     */
    private function guessCode($value)
    {
        if (preg_match('/(\d{3,})/', $value, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * 回客人的那一段
     *
     * ⚠ **只有「找得到明確原因」才回客人**（需求方 2026-10-21）：
     * `fatal` 或 `blocked` 有東西才回。只有 `suspicious` 的時候不回 ——
     * 那是猜的，叫客人改一個我們沒把握的欄位，他改完還是一樣錯。
     *
     * ⚠ 這段話會**直接送到客人眼前**，所以：不講 `varchar`、不講
     * 「資料庫」、不講 log，只講「這一欄最多幾個字、麻煩改成什麼」。
     *
     * @param array    $findings
     * @param int|null $code
     * @return string 空字串 ＝ 不回客人
     */
    private function customerText(array $findings, $code, $flow)
    {
        $config = (array) config('constants.APPLY_DIAGNOSE');

        $tell = $this->findingsToTell($findings, $code);

        if (blank($tell)) {
            return '';
        }

        $lines = [];

        foreach ($tell as $finding) {
            $lines[] = $this->customerLine($finding, $config, $flow);
        }

        return strtr((string) Arr::get($config, 'CUSTOMER_TEXT'), [
            '{items}' => implode("\n", array_filter($lines)),
        ]);
    }

    /**
     * 哪幾條可以講給客人聽
     *
     * ⚠ **不是全部都講。** 主系統的驗證只回**第一個**錯誤
     * （`$validator->errors()->first()`），所以客人收到的碼只對應到一欄 ——
     * 其他「違規」很可能只是他**沒貼完整**（只貼了前幾個參數），
     * 照單全報會變成叫他補一堆其實有帶的欄位。
     *
     * 所以：
     * - 碼是某一欄的專屬碼 → 只講對得上那個碼的
     * - 碼是通用碼（-1） → 只講 `fatal`（那才是 -1 的原因）
     * - 沒有碼 → 盡力而為，`fatal` 與 `blocked` 都講
     *
     * ⚠ `suspicious` 一律不講給客人 —— 那是猜的。
     *
     * @param array    $findings
     * @param int|null $code
     * @return array
     */
    private function findingsToTell(array $findings, $code)
    {
        $fatal = (array) Arr::get($findings, self::LEVEL_FATAL, []);
        $blocked = (array) Arr::get($findings, self::LEVEL_BLOCKED, []);

        if ($code === null) {
            return array_merge($fatal, $blocked);
        }

        if ($this->isGeneric($code)) {
            return $fatal;
        }

        $matched = [];

        foreach ($blocked as $finding) {
            if ((int) Arr::get($finding, 'code') === (int) $code) {
                $matched[] = $finding;
            }
        }

        return $matched;
    }

    /**
     * 回客人的其中一條
     *
     * ⚠ **一律引文件的規格與範例**（需求方 2026-10-21）。
     *
     * 原本寫成「看起來應該是 6823，麻煩改填這個」—— 那像是我們在幫他猜，
     * 還顯得是我們系統有毛病。實際上他違反的是**文件規格**，所以要講
     * 「文件上限幾碼、範例長什麼樣、您這筆是幾碼」。
     *
     * ⚠ 數字一律取 `doc_*`，**不是** Laravel 的 `max` 也不是資料庫的 `db`。
     * 文件沒寫的欄位就退成不帶數字的說法 —— 寧可講得籠統，也不要把內部的
     * 欄位長度交出去。
     *
     * @param array  $finding
     * @param array  $config
     * @param string $flow
     * @return string
     */
    private function customerLine(array $finding, array $config, $flow)
    {
        $field = (string) Arr::get($finding, 'field');
        $doc = (array) config("apply_rules.FIELDS.{$flow}.{$field}");

        $replace = [
            '{label}'       => (string) Arr::get($finding, 'label'),
            '{length}'      => (string) Arr::get($finding, 'length'),
            '{hint}'        => (string) Arr::get($finding, 'hint'),
            '{doc_max}'     => (string) Arr::get($doc, 'doc_max'),
            '{doc_hint}'    => (string) Arr::get($doc, 'doc_hint'),
            '{doc_example}' => (string) Arr::get($doc, 'doc_example'),
        ];

        return strtr((string) Arr::get($config, 'CUSTOMER_LINE.' . $this->customerLineKey($finding, $doc)), $replace);
    }

    /**
     * 這一條要用哪一句模板
     *
     * @param array $finding
     * @param array $doc 這個欄位在規則表裡的設定
     * @return string
     */
    private function customerLineKey(array $finding, array $doc)
    {
        $reason = (string) Arr::get($finding, 'reason');

        // 長度問題（不管是驗證層還是寫入層，對客人都是「太長」）
        if (in_array($reason, ['db_length', 'max'], true)) {
            return filled(Arr::get($doc, 'doc_max')) ? 'doc_max' : 'too_long';
        }

        // 格式問題
        if (in_array($reason, ['regex', 'db_date'], true)) {
            return filled(Arr::get($doc, 'doc_hint')) ? 'doc_format' : 'format';
        }

        if ($reason === 'blank') {
            return filled(Arr::get($doc, 'doc_example')) ? 'blank' : 'blank_plain';
        }

        return $reason;
    }


    /**
     * 給客服看的那一段（內部，可以講技術細節）
     *
     * @param string   $flow
     * @param int|null $code
     * @param string   $parsedFlow 模型原本判的（unknown 時要講明是猜的）
     * @param array    $findings
     * @return string
     */
    private function staffText($flow, $code, $parsedFlow, array $findings)
    {
        $config = (array) config('constants.APPLY_DIAGNOSE');

        $text = strtr((string) Arr::get($config, 'STAFF_TITLE'), [
            '{flow}' => (string) config("apply_rules.FLOW_LABEL.{$flow}"),
            '{code}' => $this->codeText($flow, $code, $config),
        ]);

        if ($parsedFlow === ApplyDumpParser::FLOW_UNKNOWN) {
            $text .= (string) Arr::get($config, 'STAFF_GUESSED');
        }

        $text .= $this->staffSection($findings, self::LEVEL_FATAL, 'STAFF_FATAL', $config)
            . $this->staffSection($findings, self::LEVEL_BLOCKED, 'STAFF_BLOCKED', $config)
            . $this->staffSection($findings, self::LEVEL_SUSPICIOUS, 'STAFF_SUSPICIOUS', $config);

        $fatal = (array) Arr::get($findings, self::LEVEL_FATAL, []);
        $blocked = (array) Arr::get($findings, self::LEVEL_BLOCKED, []);

        // 什麼都沒查到，而且是通用碼 —— 要講明下一步去哪裡查
        if (blank($fatal) && blank($blocked) && $this->isGeneric($code)) {
            return $text . (string) Arr::get($config, 'STAFF_NOTHING');
        }

        /*
         * 內部列的比跟客人講的多時要說明一句，不然同仁會以為訊息漏送。
         */
        $told = count($this->findingsToTell($findings, $code));

        if ($told > 0 && $told < (count($fatal) + count($blocked))) {
            $text .= (string) Arr::get($config, 'STAFF_TOLD_NOTE');
        }

        return $text;
    }

    /**
     * 給客服的其中一區
     *
     * @param array  $findings
     * @param string $level
     * @param string $titleKey
     * @param array  $config
     * @return string
     */
    private function staffSection(array $findings, $level, $titleKey, array $config)
    {
        $items = (array) Arr::get($findings, $level, []);

        if (blank($items)) {
            return '';
        }

        $text = (string) Arr::get($config, $titleKey);

        foreach ($items as $finding) {
            $text .= strtr((string) Arr::get($config, 'STAFF_LINE'), [
                '{field}'  => (string) Arr::get($finding, 'field'),
                '{label}'  => (string) Arr::get($finding, 'label'),
                '{detail}' => $this->staffDetail($finding, $config),
            ]);
        }

        return $text;
    }

    /**
     * 給客服的細節文字
     *
     * @param array $finding
     * @param array $config
     * @return string
     */
    private function staffDetail(array $finding, array $config)
    {
        $reason = (string) Arr::get($finding, 'reason');

        return strtr((string) Arr::get($config, "STAFF_DETAIL.{$reason}"), [
            '{limit}'  => (string) Arr::get($finding, 'limit'),
            '{length}' => (string) Arr::get($finding, 'length'),
            '{hint}'   => (string) Arr::get($finding, 'hint'),
            '{value}'  => mb_substr((string) Arr::get($finding, 'value'), 0, 40),
            '{guess}'  => (string) Arr::get($finding, 'guess'),
            '{code}'   => (string) Arr::get($finding, 'code'),
        ]);
    }

    /**
     * 錯誤碼的說明
     *
     * @param string   $flow
     * @param int|null $code
     * @param array    $config
     * @return string
     */
    private function codeText($flow, $code, array $config)
    {
        if ($code === null) {
            return (string) Arr::get($config, 'CODE_UNKNOWN');
        }

        $label = config("apply_rules.CODES.{$flow}.{$code}");

        if (blank($label)) {
            $label = config("apply_rules.GLOBAL_CODES.{$code}");
        }

        $text = filled($label) ? "{$code}（{$label}）" : (string) $code;

        // 通用碼要特別講明「不是欄位驗證失敗」
        if ($this->isGeneric($code)) {
            $text .= (string) Arr::get($config, 'CODE_GENERIC_NOTE');
        }

        return $text;
    }

    /**
     * 這個碼是不是「通用錯誤」
     *
     * @param int|null $code
     * @return bool
     */
    private function isGeneric($code)
    {
        if ($code === null) {
            return false;
        }

        return in_array((int) $code, (array) config('apply_rules.GENERIC_CODES', []), true);
    }
}
