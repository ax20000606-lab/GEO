<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\TitleGeneratorAgent;
use App\Data\Ai\TitleGenerationExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Title;
use App\Models\TitleGenerationRun;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Support\GeoFlow\AiModelFailoverDecider;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Throwable;

/**
 * 标题 AI 生成服务。
 *
 * 该服务负责：
 * 1. 基于 ai_models 配置发起真实模型调用；
 * 2. 将模型结果和失败原因转换为稳定的领域结果；
 * 3. 记录成功调用及已发起的失败调用，执行每日额度保护。
 */
class TitleAiGenerationService
{
    /**
     * 复用统一 API Key 解密组件，避免标题生成链路与其他 AI 链路出现差异。
     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiModelFailoverDecider $failoverDecider,
        private readonly AiModelInvocationLock $invocationLocks,
        private readonly TitleGenerationAiExecutionGuard $executionGuard,
        private readonly AiModelUsageAttemptFactory $usageAttempts,
    ) {}

    /**
     * 生成标题列表。
     *
     * @param  list<string>  $keywords
     */
    public function generateTitles(
        AiModel $aiModel,
        array $keywords,
        int $count,
        string $style,
        string $customPrompt = '',
        ?TitleGenerationExecutionContext $executionContext = null,
        int $candidateOrdinal = 1,
    ): TitleGenerationOutcome {
        $reservation = null;
        $invocationLock = null;
        $usageAttempt = null;
        $providerReturned = false;
        $providerUsage = null;

        try {
            try {
                $invocationLock = $this->invocationLocks->acquireForInvocation(
                    (int) $aiModel->getKey(),
                    $this->providerTimeoutSeconds() + 60,
                );
                $currentModel = AiModel::query()->find((int) $aiModel->getKey());
                if (! $currentModel instanceof AiModel) {
                    return TitleGenerationOutcome::failed('ai_model_unavailable');
                }
                if ($executionContext instanceof TitleGenerationExecutionContext) {
                    $this->executionGuard->assertCurrent($executionContext, $currentModel);
                }

                $request = $this->prepareRequest($currentModel, $keywords, $count, $style, $customPrompt);
                $reservation = $this->usageQuota->reserveModel($currentModel);
                if ($reservation === null) {
                    $available = AiModel::query()
                        ->whereKey($currentModel->getKey())
                        ->where('status', 'active')
                        ->exists();

                    return $available
                        ? TitleGenerationOutcome::quotaExhausted()
                        : TitleGenerationOutcome::failed('ai_model_unavailable');
                }
                if ($executionContext instanceof TitleGenerationExecutionContext) {
                    $this->executionGuard->assertCurrent($executionContext, $currentModel);
                    $usageAttempt = $this->beginUsageAttempt(
                        $executionContext,
                        $currentModel,
                        $candidateOrdinal,
                        $request['user_prompt'],
                    );
                }

                $response = (new TitleGeneratorAgent($request['system_prompt'], $request['output_token_limit']))->prompt(
                    prompt: $request['user_prompt'],
                    attachments: [],
                    provider: $request['provider_name'],
                    model: (string) ($currentModel->model_id ?? ''),
                    timeout: $this->providerTimeoutSeconds(),
                );
                $providerReturned = true;
                $providerUsage = $response->usage ?? null;
                $content = $this->normalizeProviderContent((string) ($response->text ?? ''), $count);
                $titles = $this->parseGeneratedTitles($content);
                // 【本地扩展】风格硬校验：prompt 只是软约束，模型经常不守字数、把品牌名写进标题、
                // 或者用「震惊/必看」这类词。这里再兜一道，过不了的直接丢，宁可少几条也不要脏数据。
                $titles = $this->filterTitlesByStyle(
                    $titles,
                    (string) ($request['title_style'] ?? ''),
                    (int) ($request['keyword_library_id'] ?? 0)
                );
                if ($titles !== []) {
                    if ($executionContext instanceof TitleGenerationExecutionContext
                        && $usageAttempt instanceof AiModelUsageAttempt
                        && $invocationLock !== null) {
                        $delivery = new TitleGenerationUsageDelivery(
                            usageAttempt: $usageAttempt,
                            providerUsage: $providerUsage,
                            quotaReservation: $reservation,
                            usageQuota: $this->usageQuota,
                            invocationLocks: $this->invocationLocks,
                            invocationLock: $invocationLock,
                        );
                        $invocationLock = null;

                        return TitleGenerationOutcome::success($titles, $delivery);
                    }

                    $this->recordSuccess($reservation);

                    return TitleGenerationOutcome::success($titles);
                }
            } catch (AiModelAccessException $exception) {
                if ($reservation instanceof AiUsageReservation) {
                    $this->releaseReservation($reservation);
                }

                throw $exception;
            } catch (Throwable $exception) {
                if ($reservation instanceof AiUsageReservation) {
                    $this->recordAttempt($reservation);
                }
                if ($usageAttempt instanceof AiModelUsageAttempt) {
                    $providerReturned
                        ? $usageAttempt->discarded($this->safeFailureCode($exception), $providerUsage)
                        : $usageAttempt->failed($this->safeFailureCode($exception));
                }

                return TitleGenerationOutcome::failed(
                    $this->safeFailureCode($exception),
                    retryable: $this->failoverDecider->shouldFailover($exception),
                );
            }

            if ($reservation instanceof AiUsageReservation) {
                $this->recordAttempt($reservation);
            }
            $usageAttempt?->discarded('empty_result', $providerUsage);

            return TitleGenerationOutcome::failed('empty_result', retryable: true);
        } finally {
            $this->invocationLocks->release($invocationLock);
        }
    }

    /**
     * @param  list<string>  $keywords
     * @return array{provider_name:string,system_prompt:string,user_prompt:string,output_token_limit:int}
     */
    private function prepareRequest(
        AiModel $aiModel,
        array $keywords,
        int $count,
        string $style,
        string $customPrompt,
    ): array {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new \RuntimeException('ai_url_missing');
        }

        $apiKey = $this->decryptApiKey((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('ai_key_missing');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('title_ai', $driver, $providerUrl, $apiKey);

        // 【本地改造】原来按"专业严谨/吸引眼球/SEO/创意/疑问式"这些抽象形容词分，
        // 模型理解不一致，而且用户最终是按平台发的。
        // 改成按平台给具体约束（字数、语气、结构），模型更好执行，也更贴近实际用途。
        // 旧值保留映射，避免历史请求报错。
        $styleMap = [
            'xiaohongshu' => '小红书风格：口语化、第一人称，可以用数字和具体场景制造代入感，'
                .'允许少量 emoji 但别滥用；标题 15–25 字，不要写成新闻稿',
            'zhihu' => '知乎风格：理性、信息量大，常用「为什么 / 怎么 / 有哪些 / 值不值得」这类结构，'
                .'不用表情和感叹号，不喊口号；标题 15–30 字',
            'wechat' => '微信公众号风格：专业但不生硬，要说清读者能得到什么；标题 15–28 字',
            'toutiao' => '今日头条风格：通俗直白、信息密度高、短句为主，讲清具体利益点；标题 15–30 字',
            'baijiahao' => '百家号风格：客观科普口吻，重视信息完整性，不用夸张词；标题 15–30 字',
            'douyin_script' => '抖音口播风格：短句、口语，前 3 秒就要抓住人；标题 12–20 字',
            'professional' => '专业严谨的',
            'attractive' => '吸引眼球的',
            'seo' => 'SEO优化的',
            'creative' => '创意新颖的',
            'question' => '疑问式的',
        ];
        $styleDescription = $styleMap[$style] ?? '通用风格';
        $keywordsText = implode('、', $keywords);

        $systemPrompt = '你是内容标题专家，只输出标题本身，不要解释、不要编号。';
        $userPrompt = "请基于以下关键词，生成 {$count} 个符合下面要求的文章标题：\n\n"
            ."关键词：{$keywordsText}\n\n"
            ."标题要求：{$styleDescription}\n\n";
        if ($customPrompt !== '') {
            $userPrompt .= "额外要求：{$customPrompt}\n\n";
        }
        $userPrompt .= "通用要求：\n"
            ."1. 每个标题独占一行\n"
            ."2. 标题要具体，说清读者能获得什么，不要空泛口号\n"
            ."3. 不要标题党、不要夸大、不要用「震惊」「必看」这类词\n"
            ."4. 标题里不要出现品牌名（正文里再提）\n"
            ."5. 不要添加序号或其他标记，直接输出标题内容";

        $configuredMaxTokens = (int) ($aiModel->max_tokens ?? 0);
        // 【本地调整】token 给足，避免"输出被截断 → 供应商报错"：
        // 每条标题按 256 token 预算，整体上限 8192（DeepSeek 单次输出上限），下限 2048。
        // 原来 max(512, count*64) 在 6 条以上就不够，配合"标题要具体"的约束会截断。
        $outputTokenLimit = max(2048, min(8192, $count * 256));
        if ($configuredMaxTokens > 0) {
            $outputTokenLimit = min($outputTokenLimit, $configuredMaxTokens);
        }

        return [
            'provider_name' => $providerName,
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
            'output_token_limit' => $outputTokenLimit,
        ];
    }

    /**
     * 将供应商响应限制为标题解析器可接受的安全文本。
     */
    private function normalizeProviderContent(string $rawContent, int $count): string
    {
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);

        $maxCharacters = max(2000, $count * 600);
        $maxLines = max(10, $count * 3);
        if (mb_strlen($content, 'UTF-8') > $maxCharacters
            || count(preg_split('/\R/u', $content) ?: []) > $maxLines) {
            throw new \RuntimeException('ai_title_response_too_large');
        }

        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new \RuntimeException('ai_empty_stream_content');
            }

            throw new \RuntimeException('ai_empty_content');
        }

        return $content;
    }

    public function providerTimeoutSeconds(): int
    {
        return (int) config('geoflow.title_ai_request_timeout_seconds', 90);
    }

    private function beginUsageAttempt(
        TitleGenerationExecutionContext $context,
        AiModel $model,
        int $candidateOrdinal,
        string $requestPayload,
    ): AiModelUsageAttempt {
        return $this->usageAttempts->beginForAdmin(
            model: $model,
            executionAdminId: $context->modelAccessAdminId,
            accessVersion: $context->aiConfigAccessVersion,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
            modelSource: $this->usageAttempts->sourceFor($model, $context->modelAccessAdminId),
            requestId: $context->leaseToken(),
            requestPayload: $requestPayload,
            callKey: sprintf(
                'b%d.a%d.c%d.p1',
                $context->batchSequence,
                $context->batchAttemptCount,
                max(1, $candidateOrdinal),
            ),
            operation: 'title_generation.generate',
            businessSource: 'title_generation',
            sourceType: TitleGenerationRun::class,
            sourceId: $context->runId,
        );
    }

    /**
     * 解析模型输出文本为标题列表。
     *
     * @return list<string>
     */
    private function parseGeneratedTitles(string $content): array
    {
        $lines = array_values(array_filter(
            preg_split('/\R/u', $content) ?: [],
            static fn (string $line): bool => preg_match('/^\s*```/u', $line) !== 1,
        ));
        $candidate = trim(implode("\n", $lines));
        $decoded = json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                return [];
            }
            $lines = array_map(
                static fn (mixed $title): string => is_string($title) || is_numeric($title)
                    ? (string) $title
                    : '',
                $decoded,
            );
        }

        $titles = [];
        foreach ($lines as $line) {
            $title = self::normalizeTitle($line);
            if ($title === '') {
                continue;
            }
            $titles[] = $title;
        }

        return array_values(array_unique($titles));
    }

    /**
     * 【本地扩展】按平台/风格做硬校验。
     *
     * 为什么需要：提示词里写了"标题 15-25 字""不要出现品牌名"，但模型不一定遵守。
     * 面试演示时冒出一个 40 字的小红书标题或者带品牌名的标题，很掉分。
     *
     * @param  list<string>  $titles
     * @return list<string>
     */
    private function filterTitlesByStyle(array $titles, string $style, int $keywordLibraryId): array
    {
        // 各平台的字数区间（和提示词里写的一致）
        $ranges = [
            'xiaohongshu' => [12, 28],
            'zhihu' => [14, 32],
            'wechat' => [14, 30],
            'toutiao' => [14, 32],
            'baijiahao' => [14, 32],
            'douyin_script' => [10, 24],
        ];
        $range = $ranges[$style] ?? null;
        $min = $range[0] ?? 10;
        $max = $range[1] ?? 40;

        // 品牌名：从关联的关键词库名「品牌｜类」里取
        $brand = '';
        if ($keywordLibraryId > 0) {
            try {
                $libName = (string) (\Illuminate\Support\Facades\DB::table('keyword_libraries')
                    ->where('id', $keywordLibraryId)->value('name') ?? '');
                if ($libName !== '') {
                    $prefix = trim(explode('｜', $libName)[0] ?? '');
                    if ($prefix !== '' && $prefix !== $libName && mb_strlen($prefix, 'UTF-8') >= 2) {
                        $brand = $prefix;
                    }
                }
            } catch (\Throwable) {
            }
        }

        // 标题党 / 绝对化用语（广告法也管这个）
        $banned = '/震惊|必看|惊呆|史上最|全网最|第一品牌|绝对|100%|永久/u';

        $out = [];
        foreach ($titles as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $len = mb_strlen($t, 'UTF-8');
            if ($len < $min || $len > $max) {
                continue;
            }
            if ($brand !== '' && mb_strpos($t, $brand, 0, 'UTF-8') !== false) {
                continue;
            }
            if (preg_match($banned, $t) === 1) {
                continue;
            }
            $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    public static function normalizeTitle(string $title): string
    {
        $normalized = Title::normalizeText($title);
        if (preg_match('/^(?:```|#{1,6}\s|(?:以下|下面).{0,20}(?:标题|列表)|(?:here\s+are|titles?\s*:)|(?:aqui\s+est[aã]o|t[ií]tulos?\s*:))/iu', $normalized) === 1) {
            return '';
        }

        $normalized = preg_replace(
            '/^(?:\d+(?:(?:\.(?!\d)|[)）、])\s*|\s*[-:：]\s+(?!\d))|\s+|[-*•]\s*)/u',
            '',
            $normalized,
        );

        return Title::normalizeText((string) $normalized);
    }

    /**
     * 解密 ai_models 中的 API Key（兼容旧系统 enc:v1 格式）。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    private function safeFailureCode(Throwable $exception): string
    {
        $code = trim($exception->getMessage());

        return in_array($code, [
            'ai_url_missing',
            'ai_key_missing',
            'ai_model_unavailable',
            'ai_provider_request_failed',
            'ai_title_response_too_large',
            'ai_empty_stream_content',
            'ai_empty_content',
        ], true) ? $code : 'ai_provider_request_failed';
    }

    private function recordAttempt(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->recordModelAttempt($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function recordSuccess(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->recordModelSuccess($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function releaseReservation(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->releaseModel($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
