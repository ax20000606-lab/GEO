<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\ArticleAiQualityHealthService;
use Illuminate\Console\Command;

class ArticleAiQualityHealthCommand extends Command
{
    protected $signature = 'geoflow:ai-quality-health {--json} {--probe} {--wait=10}';

    protected $description = 'Check article AI quality queue readiness and optionally run a front-queue probe';

    public function handle(ArticleAiQualityHealthService $health): int
    {
        $probe = $this->option('probe')
            ? $health->probe((int) $this->option('wait'))
            : null;
        $snapshot = $health->snapshot(recordTransition: true);
        if ($probe !== null) {
            $snapshot['probe'] = $probe;
            if (! $snapshot['probe']['passed']) {
                $snapshot['status'] = 'unavailable';
                $snapshot['issues'][] = (string) $snapshot['probe']['error'];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf('AI quality service: %s', $snapshot['status']));
            foreach ($snapshot['issues'] as $issue) {
                $this->warn((string) $issue);
            }
        }

        // 【本地修复 A2】原实现把 degraded 和 unavailable 混为一谈：
        //     return $snapshot['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
        // 于是 backfill lane 抖动 1.2 秒（worker 自回收的空窗）就会把整个健康检查打成
        // ERROR，历史上 9/10 次失败都是这一个原因。
        //
        // front lane 才是业务主链路，backfill 只是 reconcile 兜底；它少一个 worker
        // 不应该触发 ERROR 级告警。因此按三档处理：
        //   healthy   -> 0
        //   degraded  -> 0（软告警：issues 仍会打印，ai_quality_health_changed WARNING 仍会写）
        //   unavailable / 其它任何未知状态 -> 1（最严格，避免未来新增状态悄悄放行）
        // 注意：exit code 是给监控用的，日志是给人看的——degraded 不再 exit(1) 之后
        // ArticleAiQualityHealthService::recordTransition() 里的 WARNING 必须保留。
        return match ($snapshot['status']) {
            'healthy' => self::SUCCESS,
            'degraded' => self::SUCCESS,
            default => self::FAILURE,
        };
    }
}
