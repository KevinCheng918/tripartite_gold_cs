<?php

namespace App\Services;

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
}
