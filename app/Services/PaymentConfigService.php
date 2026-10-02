<?php

namespace App\Services;

use App\Presenters\NumberPresenter;
use App\Repositories\PaymentConfigRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * 繳款設定 Service
 */
class PaymentConfigService
{
    private $repository;

    public function __construct(PaymentConfigRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * 查詢所有繳款設定
     *
     * @param int|null $systemId
     * @return Collection
     */
    public function list($systemId = null)
    {
        return $this->repository->all($systemId);
    }

    /**
     * 新增繳款設定
     *
     * @param array $params
     * @return \App\Models\PaymentConfig
     */
    public function create($params)
    {
        if (isset($params['image']) && $params['image'] instanceof \Illuminate\Http\UploadedFile) {
            $params['image'] = $params['image']->store('payment-config', 'public');
        }

        return $this->repository->create($params);
    }

    /**
     * 更新繳款設定
     *
     * @param \App\Models\PaymentConfig $config
     * @param array $params
     * @return \App\Models\PaymentConfig
     */
    public function update($config, $params)
    {
        if (isset($params['image']) && $params['image'] instanceof \Illuminate\Http\UploadedFile) {
            $params['image'] = $params['image']->store('payment-config', 'public');
        }

        return $this->repository->update($config, $params);
    }

    /**
     * 刪除繳款設定
     *
     * @param \App\Models\PaymentConfig $config
     * @return bool
     */
    public function delete($config)
    {
        return $this->repository->delete($config);
    }

    /**
     * 依系統 ID 取得啟用中的繳款設定
     *
     * @param int $systemId
     * @return Collection
     */
    public function getActiveBySystem($systemId)
    {
        return $this->repository->getActiveBySystem($systemId);
    }

    /**
     * 套用模板變數，產生文案
     *
     * 繳款通知與站台餘點告警共用這支 —— 兩邊的公版都是客服在後台自己維護的，
     * 變數認得多一點不會有副作用：公版裡沒寫到的變數不會出現在結果裡。
     *
     * @param string $template
     * @param array  $vars ['station' => '...', 'amount' => '...', 'credit' => '...', ...]
     * @return string
     */
    public function renderTemplate($template, $vars)
    {
        $replacements = [
            // 繳款通知
            '{station}'   => Arr::get($vars, 'station', ''),
            '{amount}'    => Arr::get($vars, 'amount', ''),
            '{month}'     => Arr::get($vars, 'month', ''),
            '{due_date}'  => Arr::get($vars, 'due_date', ''),
            '{content}'   => Arr::get($vars, 'content', ''),
            // 餘點告警
            '{credit}'    => Arr::get($vars, 'credit', ''),
            '{threshold}' => Arr::get($vars, 'threshold', ''),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * 組補點訊息（文字 + 要附的圖）
     *
     * 兩個地方共用：
     *   1. 餘點告警的第二則（`StationCreditAlertService`）
     *   2. 客人問匯率、而今天的匯率已經決定時的回覆（`AutoReplyService`）
     *
     * ⚠ 這一支**只負責組裝、不做任何查詢** —— 繳款設定與匯率都由呼叫端查好傳進來。
     *
     * 查詢策略兩邊不一樣，硬要統一只會兩邊都不合用：告警是一輪掃過所有站台，
     * 需要「整輪只查一次」的快取；客人問匯率是單次請求，查了就用。
     * 共用的價值在於**組出來的訊息一字不差**，不在於共用查詢。
     *
     * @param \App\Models\PaymentConfig|null $config 這個系統的繳款設定
     * @param float|null                     $rate   今日匯率，還沒決定就傳 null
     * @return array{text: string, image_url: string|null}
     *         沒有補點訊息可組時 text 是空字串、image_url 是 null
     *
     * @phpstan-return array{text: string, image_url: string|null}
     */
    public function buildTopupMessage($config, $rate)
    {
        $empty = ['text' => '', 'image_url' => null];

        /*
         * 匯率還沒決定就不組 —— 沒有匯率的補點訊息對客戶沒有意義
         * （他不知道要匯多少台幣），而附一個過期的昨日匯率更糟。
         */
        if (blank($rate) || blank($config) || blank($config->topup_template)) {
            return $empty;
        }

        /*
         * 匯率用 trimZeros 而不是固定小數：匯率是去尾零（30.5 而不是 30.50），
         * 匯率報價訊息也是這樣顯示 —— 同一個數字在兩個地方要長一樣。
         */
        $text = strtr($config->topup_template, [
            // 不補零的 10/1 而不是 10/01 —— 對客訊息習慣這樣寫
            '{date}'    => now()->format('n/j'),
            '{rate}'    => NumberPresenter::trimZeros($rate, 4),
            '{usdt}'    => $this->usdtForBaseCredit((float) $rate),
            '{content}' => (string) $config->content,
        ]);

        return [
            'text' => $text,
            /*
             * 圖跟文字一起回，而不是另開一支方法 ——
             * 原本的 topupImage() 要吃 topupMessage() 的結果當參數才知道
             * 「有沒有要附圖」，兩支永遠得成對呼叫，拆開沒有好處。
             */
            'image_url' => filled($config->image) ? asset('storage/' . $config->image) : null,
        ];
    }

    /**
     * 補一筆基準點數需要多少 USDT
     *
     * 基準是 `constants.STATION.CREDIT_ALERT.TOPUP_USDT_BASE`（預設 50000 點）。
     *
     * **無條件進位到整數** —— 進位的那個零頭是我們這邊收，
     * 四捨五入會讓一半的情況少收。例：
     *
     *     50000 / 31.9 = 1567.398…  →  1568
     *     50000 / 32   = 1562.5     →  1563
     *     50000 / 25   = 2000       →  2000（整除就不動）
     *
     * @param float $rate 今日匯率
     * @return string
     */
    private function usdtForBaseCredit($rate)
    {
        $base = (float) config('constants.STATION.CREDIT_ALERT.TOPUP_USDT_BASE');

        if ($rate <= 0 || $base <= 0) {
            return '—';
        }

        return (string) (int) ceil($base / $rate);
    }
}
