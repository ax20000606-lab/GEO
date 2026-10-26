<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\Article;
use App\Services\GeoFlow\ArticleAiQualityBackfillGuard;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileArticleAiQualityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 70;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $minimumArticleId = 0,
        public readonly int $maximumArticleId = 0,
        public readonly int $limit = 100,
        /** @var list<int> */
        public readonly array $articleIds = [],
    ) {}

    public function handle(ArticleAiQualityInspectionService $inspection): void
    {
        $reconciliation = app(ArticleAiQualityReconciliationService::class);
        if ($this->articleIds === []) {
            $reconciliation->recoverCompletedWorkflows($this->limit);
        } else {
            $reconciliation->recoverCompletedWorkflowsForArticles($this->articleIds, $this->limit);
        }
        $guard = app(ArticleAiQualityBackfillGuard::class);
        $ruleVersion = (string) ($inspection->rules()['version'] ?? '');
        $ruleVersionExpression = $this->ruleVersionExpression();
        $batchLimit = max(1, min(25, $this->limit));
        $articles = Article::query()
            ->with('latestAiQualityCheck')
            ->when($this->articleIds !== [], fn ($query) => $query->whereIn('id', $this->articleIds))
            ->when($this->minimumArticleId > 0, fn ($query) => $query->where('id', '>=', $this->minimumArticleId))
            ->when($this->maximumArticleId > 0, fn ($query) => $query->where('id', '<=', $this->maximumArticleId))
            ->whereIn('status', ['draft', 'private', 'published'])
            ->where(function ($query): void {
                $query->where('ai_quality_required_at_creation', true)
                    ->orWhereHas('task', fn ($task) => $task->where('ai_quality_enabled', true));
            })
            ->where(function ($query) use ($ruleVersion, $ruleVersionExpression): void {
                $query->whereDoesntHave('aiQualityChecks')
                    ->orWhereHas('latestAiQualityCheck', function ($check) use ($ruleVersion, $ruleVersionExpression): void {
                        $check->where(function ($candidate) use ($ruleVersion, $ruleVersionExpression): void {
                            $candidate->whereIn('status', ['stale', 'cancelled'])
                                ->orWhereRaw("COALESCE({$ruleVersionExpression}, '') <> ?", [$ruleVersion]);
                        });
                    });
            })
            ->orderBy('id')
            ->limit($batchLimit)
            ->get();

        foreach ($articles as $article) {
            try {
                $policy = app(ArticleAiQualityPolicyResolver::class)->resolve($article);
                $model = ($policy['model'] ?? null) instanceof AiModel ? $policy['model'] : null;
                if ($guard->pauseReason($model) !== null) {
                    if ($this->isFullBackfill()) {
                        $guard->preserveCursor((int) $article->id);
                    }

                    return;
                }
                $inspection->createOrReuse($article, trigger: 'reconcile', force: true);
            } catch (Throwable $exception) {
                if ($this->isConfigurationError($exception)) {
                    // 配置类异常：任务/文章的 prompt、model、知识库没配好属于"数据问题"。
                    // 本 job 由 everyMinute 驱动，同一行脏配置每分钟复现一次，
                    // 若按 ERROR 上报会被放大成日志风暴（2026-10-26 单日 2117 条）。
                    // 处理方式：降级为 warning，并按 (article_id, 错误 token) 做小时级去重。
                    $articleId = (int) $article->id;
                    $token = mb_substr($exception->getMessage(), 0, 120, 'UTF-8');
                    $cacheKey = 'ai_quality_config_error:'.$articleId.':'.md5($token);
                    if (Cache::add($cacheKey, 1, now()->addHour())) {
                        Log::warning('ai_quality.config_error: '.$token, [
                            'article_id' => $articleId,
                            'task_id' => $article->task_id,
                            'note' => '配置类异常，已降级并做小时级去重；一小时内同一篇同一错只记这一次',
                        ]);
                    }
                } else {
                    // 非配置类异常（代码 bug、DB 故障、超时等）保持原样上报，不能掩盖真问题。
                    report($exception);
                }
            }
        }

        $lastArticleId = (int) ($articles->last()?->id ?? 0);
        if ($this->articleIds === []
            && $articles->count() === $batchLimit
            && $lastArticleId > 0
            && ($this->maximumArticleId === 0 || $lastArticleId < $this->maximumArticleId)) {
            self::dispatch($lastArticleId + 1, $this->maximumArticleId, $batchLimit)
                ->onConnection('redis')
                ->onQueue((string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill'));
            if ($this->isFullBackfill()) {
                $guard->preserveCursor($lastArticleId + 1);
            }
        } elseif ($this->isFullBackfill()) {
            $guard->clearCursor();
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-quality', 'ai-quality-reconcile'];
    }

    private function ruleVersionExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "(article_ai_quality_checks.advertising_rules_snapshot::jsonb ->> 'version')",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(article_ai_quality_checks.advertising_rules_snapshot, '$.version'))",
            'sqlsrv' => "JSON_VALUE(article_ai_quality_checks.advertising_rules_snapshot, '$.version')",
            default => "json_extract(article_ai_quality_checks.advertising_rules_snapshot, '$.version')",
        };
    }

    private function isFullBackfill(): bool
    {
        return $this->articleIds === [] && $this->maximumArticleId === 0;
    }

    /**
     * 判断异常是否属于"配置类"异常。
     *
     * 配置类异常指：文章的 prompt / model / 知识库 / 阈值 / 检索方式没有配好，
     * 属于数据问题而不是代码 bug。这类异常在 everyMinute 的调度下会每分钟复现一次，
     * 按 ERROR 记会把日志刷爆，因此降级为 warning + 小时级去重。
     *
     * 其余异常（代码 bug、DB 故障、超时、HTTP 5xx 等）必须保持 report()，不能掩盖真问题。
     *
     * 匹配规则：精确相等，或以 "<token>:" 为前缀（允许下游附加诊断信息）。
     * 刻意不使用 str_contains —— 否则任何"消息里带了这些字样"的无关异常都会被误判降级。
     */
    private function isConfigurationError(Throwable $e): bool
    {
        $message = $e->getMessage();
        $knownTokens = [
            'ai_quality_prompt_unavailable',
            'ai_quality_model_unavailable',
            'ai_quality_knowledge_unavailable',
            'ai_quality_thresholds_invalid',
            'ai_quality_retrieval_mode_invalid',
            // 与 retrieval_mode_invalid 同源：知识库没有可用的质检方式（PolicyResolver:297），
            // 同样处在 resolve() 链路上，同样会被 everyMinute 放大成日志风暴。
            'ai_quality_retrieval_mode_unavailable',
        ];

        foreach ($knownTokens as $known) {
            if ($message === $known || str_starts_with($message, $known.':')) {
                return true;
            }
        }

        return false;
    }
}
