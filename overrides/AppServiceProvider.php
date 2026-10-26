<?php

namespace App\Providers;

use App\Contracts\Admin\AiModelWriteLock;
use App\Contracts\AiWorkspace\AdminHelpResponder;
use App\Contracts\ArticleAiOptimizationRefiner;
use App\Contracts\ArticleAiQualityReviewer;
use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Contracts\SystemUpdater\AgentClient;
use App\Http\ApiAuthContext;
use App\Http\Requests\Admin\AiWorkspace\SendMessageRequest;
use App\Jobs\GenerateKnowledgeFactBatchJob;
use App\Jobs\ProcessTitleGenerationBatchJob;
use App\Models\Admin;
use App\Models\KnowledgeFactGenerationRun;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\Admin\DatabaseAiModelWriteLock;
use App\Services\AiWorkspace\AiWorkspaceModelRuntime;
use App\Services\AiWorkspace\TaskCreationFlow;
use App\Services\GeoFlow\AnonymousUsageTelemetry;
use App\Services\GeoFlow\ArticleAiQualityWorkerLiveness;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\HorizonMetricsAdapter;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\LaravelArticleAiOptimizationRefiner;
use App\Services\GeoFlow\LaravelArticleAiQualityReviewer;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Services\Outbound\FinalOutboundSecurityPolicy;
use App\Services\Outbound\LaravelPinnedOutboundTransport;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Outbound\SystemHostResolver;
use App\Services\Site\HostedSiteResolver;
use App\Services\SystemUpdater\UnixSocketAgentClient;
use App\Support\AdminUiRegistry;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteThemePreviewContext;
use App\View\Composers\SiteLayoutComposer;
use Closure;
use GuzzleHttp\Utils;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
// 保留本 import 是刻意的：下面 A1 修复里被注释掉的 WorkerStopping 监听仍需要它，
// 将来若要把"停止即摘除心跳"恢复，只需取消注释即可（`use` 已就位）。
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $fixedContextCapability = new \stdClass;
        $trustedTerminal = Closure::fromCallable(Utils::chooseHandler());

        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->bind(ArticleAiQualityReviewer::class, LaravelArticleAiQualityReviewer::class);
        $this->app->bind(ArticleAiOptimizationRefiner::class, LaravelArticleAiOptimizationRefiner::class);
        $this->app->bind(AgentClient::class, UnixSocketAgentClient::class);
        $this->app->bind(AiModelWriteLock::class, DatabaseAiModelWriteLock::class);
        $this->app->singleton(FinalOutboundSecurityPolicy::class);
        $this->app->bind(OutboundTransport::class, function () use ($fixedContextCapability): LaravelPinnedOutboundTransport {
            return new LaravelPinnedOutboundTransport($fixedContextCapability);
        });
        $this->app->singleton(HttpFactory::class, function ($app) use ($fixedContextCapability, $trustedTerminal): SecureHttpFactory {
            $resolver = Closure::fromCallable(
                fn (string $url) => $app->make(SafeOutboundHttpClient::class)->resolveTarget($url)
            );

            return new SecureHttpFactory(
                $app->make('events'),
                $app->make(FinalOutboundSecurityPolicy::class),
                $resolver,
                $trustedTerminal,
                $fixedContextCapability,
            );
        });
        $this->app->singleton(JobQueueService::class);
        $this->app->singleton(HorizonMetricsAdapter::class);
        $this->app->singleton(TaskMonitoringQueryService::class);
        $this->app->singleton(TaskLifecycleService::class);
        $this->app->singleton(ArticleGeoFlowService::class);
        $this->app->scoped(CurrentSite::class);
        $this->app->scoped(SiteThemePreviewContext::class);
        $this->app->singleton(HostedSiteResolver::class);
        $this->app->singleton(AiWorkspaceModelRuntime::class);
        $this->app->alias(AiWorkspaceModelRuntime::class, AdminHelpResponder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertHostedSiteConfiguration();
        Event::listen(WorkerStarting::class, function (WorkerStarting $event): void {
            app(ArticleAiQualityWorkerLiveness::class)->record((string) $event->connectionName, (string) $event->queue);
        });
        Event::listen(Looping::class, function (Looping $event): void {
            app(ArticleAiQualityWorkerLiveness::class)->record((string) $event->connectionName, (string) $event->queue);
        });
        // 【本地修复 A1】WorkerStopping 时**故意不再删除**心跳行（原实现调用
        // ArticleAiQualityWorkerLiveness::removeCurrentProcess()）。
        //
        // 原因：AI 质检 worker 会周期性自回收——
        //   ai-quality        : queue:work --max-jobs=100 --max-time=3600
        //   ai-quality-backfill: queue:work --max-jobs=25  --max-time=1800
        // backfill 队列上 ReconcileArticleAiQualityJob 约每分钟 1 个，所以 worker 每约
        // 25 分钟就会因 --max-jobs=25 优雅退出一次。退出时若立刻删行，会产生约 1.2 秒的
        // "该 lane 无消费者"空窗；而 geoflow:ai-quality-health 每分钟定点采样（第 04-09 秒）
        // 且 degraded 即 exit(1)，采样撞进空窗就会误报 backfill_consumer_missing 并打出
        // ERROR，实测每天约 9 次假警报。
        //
        // 取舍（有意为之，不是漏改）：
        //   1. graceful stop 与 crash stop 现在被一视同仁——都靠
        //      geoflow.ai_quality_worker_stale_seconds（当前 300s）自然过期。
        //      代价是 worker 真挂时故障发现最多延后 5 分钟；对 backfill 这种 reconcile
        //      兜底 lane 完全可接受，front lane 的主链路探针仍由 --probe 覆盖。
        //   2. 行不会永久堆积：pruneStale() 仍按 last_seen_at 超过 max(3600s, stale*10)
        //      清理陈旧行，本条修改没有动它。
        //   3. 容器被 recreate（hostname 变化）时旧行会残留最多 300s，期间该 lane 的
        //      worker 计数可能短暂为 2；freshCounts() 只做 `< expected` 判断，多计数
        //      不会造成误报。
        // removeCurrentProcess() 本体保留：它仍是语义正确的公开方法，未来若真的需要
        // 立即摘除心跳（例如下线某个 lane）可直接复用。
        // Event::listen(WorkerStopping::class, function (): void {
        //     app(ArticleAiQualityWorkerLiveness::class)->removeCurrentProcess();
        // });
        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(30)->by('admin-login-ip:'.$request->ip());
        });
        RateLimiter::for('admin-sensitive', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(5)->by('admin-sensitive:admin:'.$adminId),
                Limit::perMinute(5)->by('admin-sensitive:admin-ip:'.$adminId.'|'.$request->ip()),
            ];
        });
        RateLimiter::for('api-ai-quality-manual', function (Request $request): array {
            $auth = $request->attributes->get('api_auth');
            $tokenId = $auth instanceof ApiAuthContext ? (int) ($auth->token['id'] ?? 0) : 0;
            $articleId = (int) $request->route('article');

            return [
                Limit::perMinute(5)->by('api-ai-quality:token:'.$tokenId),
                Limit::perHour(20)->by('api-ai-quality:token-hour:'.$tokenId),
                Limit::perHour(6)->by('api-ai-quality:article:'.$tokenId.'|'.$articleId),
                Limit::perHour(12)->by('api-ai-quality:article-global:'.$articleId),
                Limit::perMinute(10)->by('api-ai-quality:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('article-markdown-export-prepare', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perHour(12)->by('article-export-prepare:admin:'.$adminId),
                Limit::perHour(120)->by('article-export-prepare:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('article-markdown-export-download', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);
            $tokenHash = hash('sha256', (string) $request->route('exportToken'));

            return [
                Limit::perMinutes(10, 4)->by('article-export-download:token:'.$adminId.'|'.$tokenHash),
                Limit::perMinutes(10, 12)->by('article-export-download:admin:'.$adminId),
                Limit::perMinutes(10, 30)->by('article-export-download:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('ai-workspace', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(30)->by('ai-workspace:admin:'.$adminId),
                Limit::perMinute(60)->by('ai-workspace:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('ai-workspace-read', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(120)->by('ai-workspace-read:admin:'.$adminId),
                Limit::perMinute(240)->by('ai-workspace-read:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('admin-recent-read', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(60)->by('admin-recent-read:admin:'.$adminId),
                Limit::perMinute(120)->by('admin-recent-read:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('ai-workspace-messages', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);
            $validator = Validator::make($request->only(array_keys((new SendMessageRequest)->rules())), (new SendMessageRequest)->rules());
            $localControl = $validator->passes() && app(TaskCreationFlow::class)->isLocalControlRequest((string) $request->input('prompt'), $validator->validated());
            $scope = $localControl ? 'ai-workspace-controls' : 'ai-workspace-messages';

            return [
                Limit::perMinute($localControl ? 60 : 6)->by($scope.':admin:'.$adminId),
                Limit::perMinute($localControl ? 120 : 12)->by($scope.':ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('site-lead-submission', function (Request $request): Limit {
            $siteId = app(CurrentSite::class)->profileId() ?? 0;

            return Limit::perMinute(10)->by('site-lead:'.$siteId.'|'.$request->ip());
        });
        RateLimiter::for('title-generation', function (ProcessTitleGenerationBatchJob $job): Limit {
            return Limit::perMinute((int) config('geoflow.title_ai_rate_per_minute', 30))
                ->by('title-generation:model:'.$job->aiModelId);
        });
        RateLimiter::for('knowledge-fact-generation', function (GenerateKnowledgeFactBatchJob $job): Limit {
            $run = KnowledgeFactGenerationRun::query()
                ->whereKey($job->runId)
                ->first(['id', 'status', 'model_access_admin_id', 'execution_attempt', 'batch_claims_json']);
            $claim = (array) data_get($run?->batch_claims_json, (string) $job->sequence, []);
            $validClaim = $run instanceof KnowledgeFactGenerationRun
                && $run->isActive()
                && (int) $run->execution_attempt === $job->executionAttempt
                && (int) data_get($claim, 'execution_attempt') === $job->executionAttempt
                && hash_equals((string) data_get($claim, 'input_hash'), $job->inputHash)
                && (string) data_get($claim, 'dispatch_token') !== ''
                && $job->claimToken !== ''
                && hash_equals((string) data_get($claim, 'dispatch_token'), $job->claimToken);

            $key = 'knowledge-fact-generation:invalid';
            if ($validClaim) {
                $key = 'knowledge-fact-generation:admin:'.(int) $run->model_access_admin_id;
            }

            return Limit::perMinute((int) config('geoflow.knowledge_fact_generation_rate_per_minute', 10))
                ->by($key);
        });
        RateLimiter::for('title-generation-submissions', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute((int) config('geoflow.title_ai_submit_rate_per_minute', 6))
                    ->by('title-generation-submit:admin:'.$adminId),
                Limit::perMinute((int) config('geoflow.title_ai_submit_ip_rate_per_minute', 12))
                    ->by('title-generation-submit:ip:'.$request->ip()),
            ];
        });

        $adminGuard = Auth::guard('admin');
        if (method_exists($adminGuard, 'setRememberDuration')) {
            $adminGuard->setRememberDuration(
                max(1, (int) config('geoflow.admin_remember_minutes', 43200))
            );
        }
        View::composer(['site.layout', 'theme.*.layout'], SiteLayoutComposer::class);

        View::composer('admin.layouts.app', function ($view): void {
            $admin = auth('admin')->user();
            $view->with(
                'adminWelcomeModalPayload',
                $admin instanceof Admin ? app(AdminWelcomeModalService::class)->buildModalPayload($admin) : null
            );
            $view->with(
                'adminUpdateNotificationPayload',
                $admin instanceof Admin ? app(AdminUpdateMetadataService::class)->buildNotificationPayload() : null
            );
            $view->with(
                'anonymousUsageTelemetryPayload',
                $admin instanceof Admin ? app(AnonymousUsageTelemetry::class)->payload($admin) : null
            );
            if ((bool) config('geoflow.admin_ui_v3_enabled', false) && $admin instanceof Admin) {
                $registry = app(AdminUiRegistry::class);
                $viewData = $view->getData();
                $routeName = request()->route()?->getName();
                $view->with('adminUiV3', [
                    'navigation' => $registry->navigation($admin),
                    'current' => $registry->currentPage(
                        $admin,
                        $routeName,
                        (string) ($viewData['activeMenu'] ?? '')
                    ),
                    'page_identity' => $registry->pageIdentity($routeName),
                    'settings_navigation' => $registry->settingsNavigation($admin, $routeName),
                    'show_settings_navigation' => $registry->activeKey($routeName) === 'site_settings'
                        && ! request()->routeIs('admin.account.*'),
                    'ai_configurator_navigation' => $registry->aiConfiguratorNavigation($admin, $routeName),
                    'show_ai_configurator_navigation' => $registry->activeKey($routeName) === 'ai_config',
                    'site_url' => (string) config('geoflow.site_url', config('app.url')),
                ]);
            }
        });
    }

    private function assertHostedSiteConfiguration(): void
    {
        if (! $this->app->environment('production')
            || ! config('geoflow.hosted_sites.enabled', false)) {
            return;
        }

        $errors = array_values(array_map(
            'strval',
            (array) config('geoflow.hosted_sites.configuration_errors', [])
        ));
        $primaryHosts = (array) config('geoflow.hosted_sites.primary_hosts', []);
        $rootDomains = (array) config('geoflow.hosted_sites.root_domains', []);
        if ($primaryHosts === []) {
            $errors[] = 'At least one exact primary host is required.';
        }
        if (count($rootDomains) !== 1) {
            $errors[] = 'Phase one requires exactly one hosted root domain.';
        }
        if (! in_array(config('session.domain'), [null, ''], true)) {
            $errors[] = 'SESSION_DOMAIN must be null so sessions remain Host-only.';
        }

        $appUrl = (string) config('app.url');
        $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $appScheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));
        $appPort = (int) (parse_url($appUrl, PHP_URL_PORT) ?: ($appScheme === 'https' ? 443 : 80));
        if ($appScheme !== 'https' || $appPort !== 443) {
            $errors[] = 'Phase one hosted sites require APP_URL to use HTTPS on port 443.';
        }
        if (! in_array($appHost, $primaryHosts, true)) {
            $errors[] = 'APP_URL host must be present in GEOFLOW_PRIMARY_HOSTS.';
        }
        if ((string) config('geoflow.hosted_sites.nginx_primary_host') !== $appHost) {
            $errors[] = 'GEOFLOW_NGINX_PRIMARY_HOST must match the APP_URL host.';
        }
        if ((string) config('geoflow.hosted_sites.nginx_root_domain') !== (string) ($rootDomains[0] ?? '')) {
            $errors[] = 'GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN must match the hosted root domain.';
        }
        if ((string) config('geoflow.hosted_sites.nginx_public_scheme') !== $appScheme
            || (int) config('geoflow.hosted_sites.nginx_public_port') !== $appPort) {
            $errors[] = 'Nginx public scheme and port must match APP_URL.';
        }
        if (! config('geoflow.hosted_sites.network_preflight_enabled', false)) {
            $errors[] = 'Hosted site network preflight must be enabled in production.';
        }
        if (blank(config('trustedproxy.proxies'))) {
            $errors[] = 'TRUSTED_PROXIES must trust the immediate Nginx proxy.';
        }

        $reverbApp = (array) config('reverb.apps.apps.0', []);
        $reverbOptions = (array) ($reverbApp['options'] ?? []);
        $allowedOrigins = array_values(array_map('strval', (array) ($reverbApp['allowed_origins'] ?? [])));
        if ($allowedOrigins === []
            || in_array('*', $allowedOrigins, true)
            || ! in_array($appHost, $allowedOrigins, true)
            || array_diff($allowedOrigins, $primaryHosts) !== []
            || array_diff($primaryHosts, $allowedOrigins) !== []) {
            $errors[] = 'Reverb allowed origins must exactly match all primary hostnames.';
        }
        if (strtolower((string) ($reverbOptions['host'] ?? '')) !== $appHost
            || strtolower((string) ($reverbOptions['scheme'] ?? '')) !== $appScheme
            || (int) ($reverbOptions['port'] ?? 0) !== $appPort) {
            $errors[] = 'Reverb public host, scheme and port must match APP_URL.';
        }
        if ((int) config('reverb.servers.reverb.port') !== 18080
            || '/'.trim((string) config('reverb.servers.reverb.path'), '/') !== '/reverb') {
            $errors[] = 'Bundled Nginx requires Reverb server port 18080 and path /reverb.';
        }

        if ($errors !== []) {
            throw new LogicException('Invalid hosted site configuration: '.implode(' ', array_unique($errors)));
        }
    }
}
