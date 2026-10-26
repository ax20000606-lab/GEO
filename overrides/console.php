<?php

/**
 * Artisan 自定义命令注册（闭包命令或后续类命令）。
 */

use App\Data\Ai\SystemAiIdentity;
use App\Models\KnowledgeFactGenerationRun;
use App\Services\GeoFlow\ArticleMarkdownExportService;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'geoflow:recover-knowledge-syncs {--stale=600} {--limit=50}',
    function (KnowledgeChunkSyncCoordinator $coordinator): int {
        $recovered = $coordinator->recoverStale(
            SystemAiIdentity::knowledgeIndex(),
            max(60, (int) $this->option('stale')),
            max(1, min(200, (int) $this->option('limit'))),
        );
        $this->info(sprintf('Recovered stale knowledge syncs: %d', $recovered));

        return 0;
    }
)->purpose('Requeue knowledge chunk sync pipelines that stopped making progress');

Artisan::command('geoflow:prune-expired-cache {--limit=5000}', function (): int {
    $store = (string) config('cache.limiter', config('cache.default'));
    $storeConfig = (array) config('cache.stores.'.$store, []);
    if (($storeConfig['driver'] ?? null) !== 'database') {
        $this->info('Limiter cache does not use the database store.');

        return 0;
    }

    $connection = $storeConfig['connection'] ?? null;
    $table = (string) ($storeConfig['table'] ?? 'cache');
    $keys = DB::connection($connection)
        ->table($table)
        ->where('expiration', '<=', now()->getTimestamp())
        ->orderBy('expiration')
        ->limit(max(1, min(20000, (int) $this->option('limit'))))
        ->pluck('key');
    $deleted = $keys->isEmpty()
        ? 0
        : DB::connection($connection)->table($table)->whereIn('key', $keys->all())->delete();
    $this->info(sprintf('Pruned expired cache rows: %d', $deleted));

    return 0;
})->purpose('Delete expired database cache rows used by rate limiters');

Artisan::command('geoflow:prune-article-exports', function (ArticleMarkdownExportService $exports): int {
    $deleted = $exports->pruneExpired();
    $this->info(sprintf('Pruned article export artifacts: %d', $deleted));

    return 0;
})->purpose('Delete expired Markdown article export files');

Artisan::command('geoflow:prune-knowledge-fact-generations {--limit=200} {--dry-run}', function (): int {
    $cutoff = now()->subDays((int) config('geoflow.knowledge_fact_generation_retention_days', 90));
    $pruned = 0;
    KnowledgeFactGenerationRun::query()->whereIn('status', ['completed', 'partial', 'failed', 'cancelled', 'obsolete'])
        ->whereNull('diagnostic_payload_pruned_at')->where('updated_at', '<', $cutoff)->orderBy('id')
        ->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id')->each(function (int $runId) use (&$pruned): void {
            DB::transaction(function () use ($runId, &$pruned): void {
                $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
                if (! $run || $run->diagnostic_payload_pruned_at !== null || ! in_array($run->status, ['completed', 'partial', 'failed', 'cancelled', 'obsolete'], true)) {
                    return;
                }
                $result = (array) $run->result_json;
                if ((array) ($result['conflicts'] ?? []) !== []) {
                    return;
                }
                $pruned++;
                if ((bool) $this->option('dry-run')) {
                    return;
                }
                $summary = ['summary' => ['candidate_count' => count((array) ($result['candidates'] ?? [])), 'batch_count' => count((array) ($result['batches'] ?? []))]];
                $run->forceFill([
                    'result_json' => $summary,
                    'result_hash' => hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                    'batch_meta_json' => null, 'coverage_json' => null, 'usage_json' => null, 'error_message' => null,
                    'diagnostic_payload_pruned_at' => now(),
                ])->save();
            }, 3);
        });
    $verb = (bool) $this->option('dry-run') ? 'Eligible' : 'Pruned';
    $this->info("{$verb} knowledge fact generation diagnostics: {$pruned}");

    return 0;
})->purpose('Prune old knowledge fact generation diagnostics without unresolved conflicts');

/**
 * Horizon 监控快照：用于沉淀队列吞吐、等待等时序指标。
 */
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

/**
 * GeoFlow 任务调度：每分钟扫描一次可执行任务并入队（对齐 bak cron 逻辑）。
 */
Schedule::command('geoflow:schedule-tasks')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-knowledge-syncs')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-ai-workspace')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-url-imports')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:recover-enterprise-knowledge-drafts')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:recover-title-generations')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-knowledge-fact-generations')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:reconcile-ai-usage-attempts --older-than=900')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:reconcile-ai-quality')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:reconcile-ai-optimization')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:converge-ai-quality')
    ->everyFiveSeconds()
    ->onOneServer()
    ->withoutOverlapping(1);

Schedule::command('geoflow:ai-quality-health --json')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(1);

Schedule::command('geoflow:prune-expired-cache')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:prune-article-exports')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:prune-ai-workspace')
    ->dailyAt('02:30')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('geoflow:prune-knowledge-fact-generations')
    ->dailyAt('02:45')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('geoflow:prune-task-trash')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('hosted-sites:reconcile', [
    '--limit' => (int) config('geoflow.hosted_sites.reconcile_limit', 500),
])
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);


/**
 * 【本地扩展】录入人工采集的 AI 引用样本（豆包对话口径）。
 *
 * 为什么单独一个命令：
 *   「豆包 App/网页的对话」和「豆包搜索 API」是两套不同的信源池——
 *   同一个问题，对话里 10 条引用全是抖音，搜索 API 返回的是什么值得买 / 百科 / 微博。
 *   对话的联网检索不开放 API，没法程序化采集，只能人工跑完再录入。
 *
 * 用法：
 *   php artisan geoflow:citation:record \
 *     --query="秋天皮肤干燥 推荐一个国产护肤品牌" \
 *     --source=chat \
 *     --file=/tmp/citations.json \
 *     [--replace]
 *
 * JSON 格式：
 *   {"sources":[{"rank":1,"domain":"douyin.com","title":"标题","topics":["护肤","面霜"]}]}
 */
Artisan::command(
    'geoflow:citation:record {--query=} {--source=chat} {--file=} {--replace}',
    function (): int {
        $query = trim((string) $this->option('query'));
        $source = trim((string) $this->option('source')) ?: 'chat';
        $file = trim((string) $this->option('file'));

        if ($query === '') {
            $this->error('缺少 --query');
            return self::FAILURE;
        }
        if (! in_array($source, ['chat', 'api'], true)) {
            $this->error('--source 只能是 chat 或 api');
            return self::FAILURE;
        }
        if ($file === '' || ! is_file($file) || ! is_readable($file)) {
            $this->error('--file 不存在或不可读：'.$file);
            return self::FAILURE;
        }

        $payload = json_decode((string) @file_get_contents($file), true);
        if (! is_array($payload)) {
            $this->error('JSON 解析失败：'.json_last_error_msg());
            return self::FAILURE;
        }
        $sources = $payload['sources'] ?? $payload;
        if (! is_array($sources) || $sources === []) {
            $this->error('JSON 里没有 sources 数组');
            return self::FAILURE;
        }

        if ($this->option('replace')) {
            $deleted = DB::table('geo_citation_samples')
                ->where('query', $query)->where('source_type', $source)->delete();
            $this->line("已删除旧记录：{$deleted} 条");
        }

        $inserted = 0;
        $skipped = 0;
        foreach (array_values($sources) as $i => $row) {
            if (! is_array($row)) { $skipped++; continue; }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') { $skipped++; continue; }

            $topics = $row['topics'] ?? [];
            if (is_string($topics)) {
                $topics = preg_split('/[,，、]+/u', $topics) ?: [];
            }
            $topics = array_values(array_unique(array_filter(array_map(
                static fn ($t): string => trim((string) $t),
                is_array($topics) ? $topics : []
            ), static fn (string $t): bool => $t !== '')));

            try {
                DB::table('geo_citation_samples')->insert([
                    'query' => mb_substr($query, 0, 500, 'UTF-8'),
                    'source_type' => $source,
                    'rank' => (int) ($row['rank'] ?? ($i + 1)),
                    'domain' => mb_substr(trim((string) ($row['domain'] ?? '')), 0, 240, 'UTF-8'),
                    'title' => mb_substr($title, 0, 1000, 'UTF-8'),
                    'topics' => $topics === [] ? null : '{'.implode(',', array_map(
                        static fn (string $t): string => '"'.str_replace('"', '\\"', $t).'"',
                        $topics
                    )).'}',
                    'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 500, 'UTF-8'),
                    'created_at' => now(),
                ]);
                $inserted++;
            } catch (\Throwable $e) {
                $this->warn('第 '.($i + 1).' 条失败：'.mb_substr($e->getMessage(), 0, 100, 'UTF-8'));
                $skipped++;
            }
        }

        $this->info("录入完成：source={$source} 写入 {$inserted} 条，跳过 {$skipped} 条");
        return $inserted > 0 ? self::SUCCESS : self::FAILURE;
    }
)->purpose('录入人工采集的 AI 引用样本（豆包对话口径），写入 geo_citation_samples');
