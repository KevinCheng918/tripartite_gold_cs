<?php

namespace App\Services;

use App\Repositories\PaymentConfigRepository;
use Illuminate\Database\Eloquent\Collection;

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
     * 套用模板變數，產生繳款文案
     *
     * @param string $template
     * @param array  $vars  ['station' => '...', 'amount' => '...', 'month' => '...']
     * @return string
     */
    public function renderTemplate($template, $vars)
    {
        $replacements = [
            '{station}' => $vars['station'] ?? '',
            '{amount}'  => $vars['amount'] ?? '',
            '{month}'   => $vars['month'] ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
