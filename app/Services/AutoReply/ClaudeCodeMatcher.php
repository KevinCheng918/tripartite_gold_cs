<?php

namespace App\Services\AutoReply;

use App\Contracts\AutoReplyMatcher;
use App\Repositories\LlmUsageRepository;
use App\Repositories\QuickReplyRepository;
use App\Services\AppSettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * 用 Claude Code CLI 從題庫挑答案
 *
 * 走訂閱制，所以是叫 CLI 而不是打 API —— 官方 PHP SDK 需要 php ^8.1，本專案是 7.4。
 *
 * 三級降級：訂閱 → 備援 API Key → 回 null（交給上層走人工流程）。
 * 只有「撞到額度上限」才會切備援；逾時或格式錯誤直接放棄，
 * 那些切過去也只是再等一次。
 */
class ClaudeCodeMatcher implements AutoReplyMatcher
{
    /** @var array CLI 輸出中代表額度用盡的關鍵字（小寫比對） */
    private const RATE_LIMIT_HINTS = ['usage limit', 'rate limit', 'quota', 'limit reached', 'too many requests'];

    private $appSettingService;
    private $quickReplyRepository;
    private $llmUsageRepository;

    public function __construct(
        AppSettingService $appSettingService,
        QuickReplyRepository $quickReplyRepository,
        LlmUsageRepository $llmUsageRepository
    ) {
        $this->appSettingService = $appSettingService;
        $this->quickReplyRepository = $quickReplyRepository;
        $this->llmUsageRepository = $llmUsageRepository;
    }

    /**
     * {@inheritdoc}
     *
     * 回傳的陣列會多帶一個 source（見 constants.AUTO_REPLY.SOURCE），
     * 讓上層知道這次是訂閱還是備援答的 —— 第一次切備援要通知內部群組。
     */
    public function resolve($text, array $context = [])
    {
        $token = $this->appSettingService->get(AppSettingService::KEY_CLAUDE_TOKEN);

        // 連 token 都沒設定就不用往下走了，省一個 process
        if (!filled($token)) {
            Log::warning('自動回覆未設定 Claude Token，略過比對');

            return null;
        }

        $prompt = $this->buildSystemPrompt();

        if (!filled($prompt)) {
            Log::warning('題庫沒有啟用中的問答，自動回覆略過比對');

            return null;
        }

        $subscription = config('constants.AUTO_REPLY.SOURCE.SUBSCRIPTION');
        $model = $this->appSettingService->get(AppSettingService::KEY_CLAUDE_MODEL, 'opus');

        $result = $this->execute($text, $prompt, $context, [
            'source' => $subscription,
            'model'  => $model,
            'env'    => $this->buildEnv([
                'CLAUDE_CODE_OAUTH_TOKEN' => $token,
                // 明確移除，避免兩把憑證同時存在時分不清這次花的是誰的額度
                'ANTHROPIC_API_KEY'       => false,
            ]),
        ]);

        if (filled($result)) {
            return $result;
        }

        return $this->resolveByFallback($text, $prompt, $context);
    }

    /**
     * 驗證一把憑證能不能用
     *
     * 設定頁換 token 時用：**驗證通過才存**，驗不過就不動原本的，
     * 避免貼錯一次就讓整個自動回覆停擺。
     *
     * 做法是跑一次極小的呼叫 —— 會消耗一點點額度，但那是確認憑證有效的唯一方式。
     *
     * @param string $credential
     * @param bool   $isFallback true=API Key, false=訂閱 token
     * @return bool
     */
    public function verifyCredential($credential, $isFallback = false)
    {
        if (!filled($credential)) {
            return false;
        }

        $env = $this->buildEnv($isFallback
            ? ['ANTHROPIC_API_KEY' => $credential, 'CLAUDE_CODE_OAUTH_TOKEN' => false]
            : ['CLAUDE_CODE_OAUTH_TOKEN' => $credential, 'ANTHROPIC_API_KEY' => false]);

        $command = [
            config('auto_reply.cli_path'),
            '-p', 'ok',
            '--output-format', 'json',
            '--allowedTools', '',
            '--disable-slash-commands',
            '--setting-sources', '',
        ];

        try {
            $process = new Process($command, storage_path('app'), $env, null, (float) config('auto_reply.timeout'));
            $process->run();
        } catch (\Exception $e) {
            Log::error('Claude 憑證驗證失敗', ['fallback' => $isFallback, 'error' => $e->getMessage()]);

            return false;
        }

        $envelope = json_decode($process->getOutput(), true);

        // 同 execute()：憑證不對時 CLI 仍以 exit 0 結束（result 會是 "Not logged in"），
        // 只看 exit code 會把一把錯的 token 當成驗證通過存進去
        if (!$process->isSuccessful() || (is_array($envelope) && !empty($envelope['is_error']))) {
            Log::warning('Claude 憑證驗證未通過', [
                'fallback' => $isFallback,
                'reason'   => mb_substr(trim($this->envelopeMessage($envelope)), 0, 200),
                'stderr'   => mb_substr($process->getErrorOutput(), 0, 300),
            ]);

            return false;
        }

        return true;
    }

    /**
     * 取出 CLI envelope 裡的錯誤說明
     *
     * 失敗時 result 欄位放的是人看得懂的原因（例如 Not logged in），
     * 這是判斷「為什麼失敗」最直接的來源。
     *
     * @param mixed $envelope
     * @return string
     */
    private function envelopeMessage($envelope)
    {
        if (!is_array($envelope)) {
            return '';
        }

        $message = isset($envelope['result']) ? $envelope['result'] : '';

        return is_string($message) ? $message : '';
    }

    /**
     * 訂閱撞牆後改用備援 API Key
     *
     * @param string $text
     * @param string $prompt
     * @param array  $context
     * @return array|null
     */
    private function resolveByFallback($text, $prompt, array $context)
    {
        if (!$this->canUseFallback()) {
            return null;
        }

        $apiKey = $this->appSettingService->get(AppSettingService::KEY_FALLBACK_API_KEY);

        Log::info('自動回覆改用備援 API Key');

        return $this->execute($text, $prompt, $context, [
            'source' => config('constants.AUTO_REPLY.SOURCE.FALLBACK'),
            'model'  => $this->appSettingService->get(AppSettingService::KEY_FALLBACK_MODEL, 'haiku'),
            'env'    => $this->buildEnv([
                'ANTHROPIC_API_KEY'       => $apiKey,
                // 同上，兩把憑證不要同時帶進去
                'CLAUDE_CODE_OAUTH_TOKEN' => false,
            ]),
        ]);
    }

    /**
     * 備援是否可用
     *
     * 開關、金鑰、每日上限三個條件都要過。每日上限是防呆 ——
     * 訂閱掛掉一整天不該無聲燒掉一筆 API 費用。
     *
     * @return bool
     */
    private function canUseFallback()
    {
        if (!$this->appSettingService->getBool(AppSettingService::KEY_FALLBACK_ENABLED)) {
            return false;
        }

        if (!$this->appSettingService->has(AppSettingService::KEY_FALLBACK_API_KEY)) {
            Log::warning('備援已開啟但未設定 API Key');

            return false;
        }

        $limit = $this->appSettingService->getInt(AppSettingService::KEY_FALLBACK_DAILY_LIMIT);

        if ($limit <= 0) {
            return true;
        }

        $used = $this->llmUsageRepository->countByDateAndSource(
            now()->format('Y-m-d'),
            config('constants.AUTO_REPLY.SOURCE.FALLBACK')
        );

        if ($used >= $limit) {
            Log::warning('備援已達每日上限，不再呼叫', ['limit' => $limit, 'used' => $used]);

            return false;
        }

        return true;
    }

    /**
     * 執行一次 CLI 並解析結果
     *
     * @param string $text    客人的話
     * @param string $prompt  system prompt（含整份題庫）
     * @param array  $context
     * @param array  $options source / model / env
     * @return array|null
     */
    private function execute($text, $prompt, array $context, array $options)
    {
        $startedAt = microtime(true);
        $process = $this->buildProcess($text, $prompt, $context, $options);

        try {
            $process->run();
        } catch (\Exception $e) {
            Log::error('Claude CLI 執行失敗', ['error' => $e->getMessage(), 'source' => $options['source']]);
            $this->recordUsage($options, $context, $startedAt, true, false, []);

            return null;
        }

        $output = $process->getOutput();
        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $envelope = json_decode($output, true);
        $usage = is_array($envelope) && isset($envelope['usage']) ? $envelope['usage'] : [];

        // ⚠️ CLI 失敗時**不一定是非 0 結束**：未登入、額度用盡這類情況它會以 exit 0 結束，
        // 錯誤只寫在 JSON 的 is_error / result 欄位裡。只看 exit code 會把失敗當成功。
        $failedByEnvelope = is_array($envelope) && !empty($envelope['is_error']);

        if (!$process->isSuccessful() || $failedByEnvelope) {
            $detail = $process->getErrorOutput() . ' ' . $this->envelopeMessage($envelope) . ' ' . $output;
            $rateLimited = $this->looksRateLimited($detail);

            Log::error('Claude CLI 回傳失敗', [
                'source'       => $options['source'],
                'exit_code'    => $process->getExitCode(),
                'rate_limited' => $rateLimited,
                'duration_ms'  => $duration,
                'reason'       => mb_substr(trim($this->envelopeMessage($envelope)), 0, 200),
                'stderr'       => mb_substr($process->getErrorOutput(), 0, 300),
            ]);

            $this->recordUsage($options, $context, $startedAt, true, $rateLimited, $usage);

            return null;
        }

        $parsed = $this->parseResult($envelope);

        if (!filled($parsed)) {
            Log::error('Claude CLI 輸出無法解析', [
                'source' => $options['source'],
                'output' => mb_substr((string) $output, 0, 500),
            ]);

            $this->recordUsage($options, $context, $startedAt, true, false, $usage);

            return null;
        }

        $this->recordUsage($options, $context, $startedAt, false, false, $usage);
        $parsed['source'] = $options['source'];

        return $parsed;
    }

    /**
     * 組出 CLI 指令
     *
     * 用陣列傳參數（不經 shell），所以題庫與客人的話裡有引號、換行都不會出事。
     *
     * @param string $text
     * @param string $prompt
     * @param array  $context
     * @param array  $options
     * @return Process
     */
    private function buildProcess($text, $prompt, array $context, array $options)
    {
        $command = [
            config('auto_reply.cli_path'),
            '-p', $this->buildUserPrompt($text, $context),
            '--model', $options['model'],
            '--effort', config('auto_reply.effort'),
            '--output-format', 'json',
            '--json-schema', $this->buildSchema(),
            '--system-prompt', $prompt,
            // 這個任務不需要任何工具，也不要載入專案的 skill 與設定
            '--allowedTools', '',
            '--disable-slash-commands',
            '--setting-sources', '',
        ];

        // 工作目錄刻意避開專案根目錄：那裡有 CLAUDE.md，會被讀進脈絡造成干擾
        $process = new Process($command, storage_path('app'), $options['env'], null, (float) config('auto_reply.timeout'));

        return $process;
    }

    /**
     * 解析 CLI 的輸出
     *
     * `--output-format json` 回的是一層 envelope，真正的內容在 result 裡；
     * 依版本可能是物件也可能是 JSON 字串，兩種都吃。
     *
     * @param mixed $envelope
     * @return array|null item_id / confidence / candidate_ids
     */
    private function parseResult($envelope)
    {
        if (!is_array($envelope)) {
            return null;
        }

        $result = isset($envelope['result']) ? $envelope['result'] : null;

        if (is_string($result)) {
            $result = json_decode($result, true);
        }

        if (!is_array($result) || !array_key_exists('item_id', $result)) {
            return null;
        }

        $candidates = [];
        if (isset($result['candidate_ids']) && is_array($result['candidate_ids'])) {
            $candidates = array_values(array_filter(array_map('intval', $result['candidate_ids'])));
        }

        return [
            'item_id'       => filled($result['item_id']) ? (int) $result['item_id'] : null,
            'confidence'    => isset($result['confidence']) ? (string) $result['confidence'] : config('constants.AUTO_REPLY.CONFIDENCE.LOW'),
            'candidate_ids' => $candidates,
        ];
    }

    /**
     * 強制輸出格式的 JSON Schema
     *
     * 這是「只准挑、不准寫」能成立的技術基礎 —— 不靠提示詞拜託模型守規矩。
     *
     * @return string
     */
    private function buildSchema()
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'item_id' => [
                    'type'        => ['integer', 'null'],
                    'description' => '最相符的題目 id；題庫裡沒有對應答案時為 null',
                ],
                'confidence' => [
                    'type'        => 'string',
                    'enum'        => [
                        config('constants.AUTO_REPLY.CONFIDENCE.HIGH'),
                        config('constants.AUTO_REPLY.CONFIDENCE.LOW'),
                    ],
                    'description' => '有把握就 high；不確定是哪一題就 low',
                ],
                'candidate_ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'confidence 為 low 時，列出 2-3 個可能的題目 id',
                ],
            ],
            'required'             => ['item_id', 'confidence', 'candidate_ids'],
            'additionalProperties' => false,
        ];

        return json_encode($schema, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 組 system prompt：挑選規則 + 整份題庫
     *
     * 題庫每則訊息都要用，但很少變動，所以整份快取；
     * 題庫或同義設定異動時由 QuickReplyService 主動清掉。
     *
     * @return string
     */
    private function buildSystemPrompt()
    {
        $repository = $this->quickReplyRepository;

        return Cache::remember(config('auto_reply.prompt_cache_key'), (int) config('auto_reply.prompt_cache_seconds'), function () use ($repository) {
            $items = $repository->getActiveItemsForMatch();

            if ($items->isEmpty()) {
                return '';
            }

            $lines = [];
            foreach ($items as $item) {
                $lines[] = "[id:{$item->id}]（{$item->category_label}）";
                $lines[] = "問題：{$item->label}";
                $lines[] = "答案：{$item->answer}";
                $lines[] = '';
            }

            $knowledge = implode("\n", $lines);

            return implode("\n", [
                '你是客服題庫的比對助手。下面是完整題庫，你的工作是判斷客人的問題對應到哪一則。',
                '',
                '規則：',
                '1. 你**只能**從題庫裡挑一則，回傳它的 id。絕對不要自己寫答案，也不要改寫題庫內容。',
                '2. 判斷時要同時看「問題」與「答案」—— 答案裡常寫著問題標題沒提到的資訊。',
                '3. 有把握時 confidence 填 high；不確定是哪一題時填 low，並在 candidate_ids 列出 2-3 個可能的 id。',
                '4. 題庫裡真的沒有對應的答案時，item_id 填 null，不要勉強挑一個相近的。',
                '5. 客人一次問兩件事、或問題太模糊時，一律算不確定（low）。',
                '6. 只輸出符合 schema 的 JSON，不要有任何其他文字，也不要使用任何工具。',
                '',
                '題庫：',
                '',
                $knowledge,
            ]);
        });
    }

    /**
     * 組 user prompt
     *
     * 反問之後的第二輪要把上一輪的候選帶進來，
     * 否則客人回「比較像第一個」時模型沒有脈絡可判斷。
     *
     * @param string $text
     * @param array  $context
     * @return string
     */
    private function buildUserPrompt($text, array $context)
    {
        $candidates = isset($context['candidate_ids']) ? $context['candidate_ids'] : [];

        if (empty($candidates)) {
            return $text;
        }

        $ids = implode(', ', $candidates);

        return implode("\n", [
            "（上一輪已經反問過客人，當時提供的候選題目 id 是：{$ids}）",
            '（以下是客人的回覆，請據此判斷他指的是哪一題）',
            '',
            $text,
        ]);
    }

    /**
     * 組出執行 CLI 用的環境變數
     *
     * 除了憑證之外一定要指定 HOME —— 網頁請求是以 www-data 執行的，
     * 它的 HOME 是專案的父層（/var/www），放著不管 CLI 會在那裡建設定目錄；
     * 而佇列 worker 又是另一個使用者、另一個 HOME，行為會不一致。
     * 統一指到 storage 底下，兩邊才是同一份狀態。
     *
     * @param array $credentials
     * @return array
     */
    private function buildEnv(array $credentials)
    {
        return array_merge($credentials, ['HOME' => $this->claudeHome()]);
    }

    /**
     * CLI 的家目錄，不存在就建
     *
     * @return string
     */
    private function claudeHome()
    {
        $path = storage_path('app/claude-home');

        if (!is_dir($path)) {
            // 併發時可能有人先建好了，第二個 mkdir 會失敗 —— 那不是問題
            @mkdir($path, 0775, true);
        }

        return $path;
    }

    /**
     * 輸出看起來像不像撞到額度上限
     *
     * CLI 的錯誤訊息文字可能隨版本調整，所以用關鍵字寬鬆比對，
     * 判斷錯了最多只是多試一次備援或少試一次，不會造成錯誤回覆。
     *
     * @param string $output
     * @return bool
     */
    private function looksRateLimited($output)
    {
        $lower = mb_strtolower((string) $output);

        foreach (self::RATE_LIMIT_HINTS as $hint) {
            if (mb_strpos($lower, $hint) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 記錄這次呼叫
     *
     * 只要有呼叫到 Claude 就記 —— 成功、失敗、撞限額、逾時都算，
     * 因為它們都消耗了額度或至少嘗試消耗。
     *
     * @param array $options
     * @param array $context
     * @param float $startedAt     microtime(true)
     * @param bool  $isError
     * @param bool  $isRateLimited
     * @param array $usage         CLI 回傳的 usage
     * @return void
     */
    private function recordUsage(array $options, array $context, $startedAt, $isError, $isRateLimited, array $usage)
    {
        try {
            $this->llmUsageRepository->create([
                'used_on'           => now()->format('Y-m-d'),
                'telegram_group_id' => isset($context['group_id']) ? $context['group_id'] : null,
                'source'            => $options['source'],
                'model'             => $options['model'],
                'duration_ms'       => (int) round((microtime(true) - $startedAt) * 1000),
                'is_error'          => $isError,
                'is_rate_limited'   => $isRateLimited,
                'input_tokens'      => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0,
                'output_tokens'     => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0,
                'created_at'        => now(),
            ]);
        } catch (\Exception $e) {
            // 統計寫不進去不該影響客人收不收得到回覆
            Log::error('LLM 用量紀錄寫入失敗', ['error' => $e->getMessage()]);
        }
    }
}
