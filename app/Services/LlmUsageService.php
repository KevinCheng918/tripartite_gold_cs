<?php

namespace App\Services;

use App\Repositories\LlmUsageRepository;

/**
 * LLM 用量統計
 *
 * 訂閱與備援要盯的數字完全不同：
 *   - 訂閱看「撞了幾次限額」—— 那是訂閱撐不撐得住的溫度計
 *   - 備援看「花了多少錢」—— 那是真的會出帳的
 */
class LlmUsageService
{
    private $llmUsageRepository;
    private $appSettingService;

    public function __construct(LlmUsageRepository $llmUsageRepository, AppSettingService $appSettingService)
    {
        $this->llmUsageRepository = $llmUsageRepository;
        $this->appSettingService = $appSettingService;
    }

    /**
     * 設定頁的用量摘要
     *
     * @return array today / month / daily
     */
    public function summary()
    {
        $today = now()->format('Y-m-d');
        $monthStart = now()->startOfMonth()->format('Y-m-d');
        $rangeStart = now()->subDays(29)->format('Y-m-d');

        return [
            'today' => $this->buildPeriod($today, $today),
            'month' => $this->buildPeriod($monthStart, $today),
            'daily' => $this->buildDaily($rangeStart, $today),
        ];
    }

    /**
     * 某個來源今天用了幾次（設定頁顯示離每日上限還有多少）
     *
     * @param int $source
     * @return int
     */
    public function countToday($source)
    {
        return $this->llmUsageRepository->countByDateAndSource(now()->format('Y-m-d'), $source);
    }

    /**
     * 區間統計，依來源分開
     *
     * @param string $from
     * @param string $to
     * @return array
     */
    private function buildPeriod($from, $to)
    {
        $rows = $this->llmUsageRepository->summarizeBySource($from, $to);
        $subscription = config('constants.AUTO_REPLY.SOURCE.SUBSCRIPTION');
        $fallback = config('constants.AUTO_REPLY.SOURCE.FALLBACK');

        $result = [
            'subscription' => $this->emptyStat(),
            'fallback'     => $this->emptyStat(),
        ];

        foreach ($rows as $row) {
            $key = (int) $row->source === (int) $fallback ? 'fallback' : 'subscription';

            $result[$key] = [
                'calls'           => (int) $row->calls,
                'errors'          => (int) $row->errors,
                'rate_limited'    => (int) $row->rate_limited,
                'input_tokens'    => (int) $row->input_tokens,
                'output_tokens'   => (int) $row->output_tokens,
                'avg_duration_ms' => (int) round($row->avg_duration_ms),
            ];
        }

        // 訂閱沒有金額可算，只有備援要估費用
        $result['fallback']['cost_usd'] = $this->estimateCost(
            $result['fallback']['input_tokens'],
            $result['fallback']['output_tokens']
        );
        $result['fallback']['cost_twd'] = round($result['fallback']['cost_usd'] * (float) config('auto_reply.usd_to_twd'), 2);

        unset($subscription);

        return $result;
    }

    /**
     * 近 30 天走勢
     *
     * @param string $from
     * @param string $to
     * @return array
     */
    private function buildDaily($from, $to)
    {
        $rows = $this->llmUsageRepository->summarizeByDate($from, $to);
        $fallback = config('constants.AUTO_REPLY.SOURCE.FALLBACK');
        $daily = [];

        foreach ($rows as $row) {
            $date = (string) $row->used_on;

            if (!isset($daily[$date])) {
                $daily[$date] = [
                    'date'                => $date,
                    'subscription_calls'  => 0,
                    'fallback_calls'      => 0,
                    'rate_limited'        => 0,
                    'fallback_cost_usd'   => 0,
                ];
            }

            $isFallback = (int) $row->source === (int) $fallback;
            $daily[$date][$isFallback ? 'fallback_calls' : 'subscription_calls'] = (int) $row->calls;
            $daily[$date]['rate_limited'] += (int) $row->rate_limited;

            if ($isFallback) {
                $daily[$date]['fallback_cost_usd'] = $this->estimateCost((int) $row->input_tokens, (int) $row->output_tokens);
            }
        }

        return array_values($daily);
    }

    /**
     * 估算備援花了多少錢
     *
     * 用 config 的單價換算，**是估算不是帳單** —— 實際金額以 Anthropic Console 為準。
     * 改版調價時要回去更新 config，否則這個數字會失真。
     *
     * @param int $inputTokens
     * @param int $outputTokens
     * @return float USD
     */
    private function estimateCost($inputTokens, $outputTokens)
    {
        $model = $this->appSettingService->get(AppSettingService::KEY_FALLBACK_MODEL, 'haiku');
        $pricing = config("auto_reply.pricing.{$model}");

        if (!filled($pricing)) {
            return 0.0;
        }

        $cost = ($inputTokens / 1000000) * $pricing['input'] + ($outputTokens / 1000000) * $pricing['output'];

        return round($cost, 4);
    }

    /**
     * @return array
     */
    private function emptyStat()
    {
        return [
            'calls'           => 0,
            'errors'          => 0,
            'rate_limited'    => 0,
            'input_tokens'    => 0,
            'output_tokens'   => 0,
            'avg_duration_ms' => 0,
        ];
    }
}
