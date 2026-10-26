<?php

/**
 * Web 路由：前台与 Blade 管理后台（路径见 config/geoflow.admin_base_path，默认 geo_admin）。
 */

use App\Http\Controllers\Admin\AdminAccountController;
use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminRecentActivityController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWelcomeController;
use App\Http\Controllers\Admin\AiModelController;
use App\Http\Controllers\Admin\AiPromptController;
use App\Http\Controllers\Admin\AiSourceProviderController;
use App\Http\Controllers\Admin\AiSpecialPromptController;
use App\Http\Controllers\Admin\AiVisibilityAnalyticsController;
use App\Http\Controllers\Admin\AiWorkspaceApiController;
use App\Http\Controllers\Admin\AiWorkspaceController;
use App\Http\Controllers\Admin\AiWorkspaceKnowledgeMediaController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\ArticleAiOptimizationController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\ArticleEditorAssetController;
use App\Http\Controllers\Admin\ArticleEditorAssistantController;
use App\Http\Controllers\Admin\AuthorController;
use App\Http\Controllers\Admin\BrowserClientController;
use App\Http\Controllers\Admin\BrowserConnectionApprovalController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContentAnalyticsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DistributionAnalyticsController;
use App\Http\Controllers\Admin\DistributionController;
use App\Http\Controllers\Admin\EnterpriseKnowledgeController;
use App\Http\Controllers\Admin\HostedSiteController;
use App\Http\Controllers\Admin\ImageLibraryController;
use App\Http\Controllers\Admin\KeywordLibraryController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use App\Http\Controllers\Admin\KnowledgeBaseMediaController;
use App\Http\Controllers\Admin\KnowledgeFactController;
use App\Http\Controllers\Admin\KnowledgeFactGenerationController;
use App\Http\Controllers\Admin\LeadAnalyticsController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Admin\LeadFormController;
use App\Http\Controllers\Admin\LegacyController;
use App\Http\Controllers\Admin\LegacySystemUpdateHistoryController;
use App\Http\Controllers\Admin\ManualPublicationController;
use App\Http\Controllers\Admin\ManualPublicationSettingsController;
use App\Http\Controllers\Admin\MaterialsController;
use App\Http\Controllers\Admin\SecuritySettingsController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\Admin\SiteThemePackageController;
use App\Http\Controllers\Admin\SiteThemePreviewController;
use App\Http\Controllers\Admin\SiteThemeReplicationController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\SystemUpdaterOperationController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TitleLibraryController;
use App\Http\Controllers\Admin\TrafficAnalyticsController;
use App\Http\Controllers\Admin\UrlImportController;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\ArchiveController;
use App\Http\Controllers\Site\ArticleController as SiteArticleController;
use App\Http\Controllers\Site\CategoryController as SiteCategoryController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\HostedAssetController;
use App\Http\Controllers\Site\LeadFormController as SiteLeadFormController;
use App\Http\Controllers\Site\SiteDiscoveryController;
use App\Support\AdminUiRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// ==========================================================================
// 【本地扩展】品牌上下文切换（多产品独立数据中心的开关）
//
// 为什么要加这段：
//   GEOFlow 官方的 AI 可见性统计，品牌名和自有域名是从全局配置读的
//   （geoflow.site_name / site_full_name / site_url），也就是说整个系统
//   同时只能按一个品牌算。但实际业务里一个团队会管多个产品，每个产品
//   都要有自己的提及率 / 前三率曲线。
//
// 做法：
//   品牌档案放在 storage/app/geo-brands.json（不需要额外挂载，storage 已共享）。
//   任一后台页面带 ?brand=<slug> 访问，就在路由加载阶段把三个配置项覆盖成
//   该品牌的值，于是 AnalyticsService 里的 brandAliases()/ownedHosts()
//   自动按这个品牌算，KPI 也就跟着变了。
//
// 注意：
//   - 只影响当前请求，不写 .env、不重建容器；
//   - 读文件失败或 slug 不存在时静默回退到 .env 的原配置，不影响主流程；
//   - GEOFlow 官方硬编码了 'GEOFlow' 作为别名，这个去不掉（除非改源码），
//     所以搜索结果里出现 "GEOFlow" 字样时会被误算成品牌命中。概率极低，
//     但如果客户品牌名正好含 GEO，要留意。
// ==========================================================================
try {
    $geoBrandFile = storage_path('app/geo-brands.json');
    if (is_file($geoBrandFile)) {
        $geoBrandData = json_decode((string) file_get_contents($geoBrandFile), true);
        if (is_array($geoBrandData) && ! empty($geoBrandData['brands'])) {
            // 优先 URL 参数，其次 cookie（记住上次选择），最后用默认品牌
            $geoWanted = trim((string) ($_GET['brand'] ?? $_COOKIE['geo_brand'] ?? ''));
            if ($geoWanted === '') {
                $geoWanted = (string) ($geoBrandData['default'] ?? '');
            }

            foreach ((array) $geoBrandData['brands'] as $geoBrand) {
                if (! is_array($geoBrand) || (string) ($geoBrand['slug'] ?? '') !== $geoWanted) {
                    continue;
                }
                $geoName = trim((string) ($geoBrand['name'] ?? ''));
                $geoFull = trim((string) ($geoBrand['full_name'] ?? ''));

                config([
                    'geoflow.site_name' => $geoName !== '' ? $geoName : (string) config('geoflow.site_name'),
                    'geoflow.site_full_name' => $geoFull !== '' ? $geoFull : ($geoName !== '' ? $geoName : (string) config('geoflow.site_full_name')),
                    'geoflow.site_url' => trim((string) ($geoBrand['site_url'] ?? '')),
                ]);

                // 记住选择，后续页面不带参数也按这个品牌算
                if (trim((string) ($_GET['brand'] ?? '')) !== '' && ! headers_sent()) {
                    @setcookie('geo_brand', $geoWanted, [
                        'expires' => time() + 86400 * 30,
                        'path' => '/',
                        'samesite' => 'Lax',
                    ]);
                }
                break;
            }
        }
    }
} catch (\Throwable) {
    // 品牌档案异常时回退 .env 配置，绝不阻断请求
}

Route::get('/favicon.ico', HostedAssetController::class)->name('site.asset.favicon');
Route::get('/{assetPath}', HostedAssetController::class)
    ->where('assetPath', '(?:(?:assets|js|storage)/[a-zA-Z0-9._/-]+|themes/[a-zA-Z0-9_-]+/[^\x00-\x1F\x7F]+|build/assets/[a-zA-Z0-9._-]+)')
    ->name('site.asset');

Route::get('/app', function () {
    return Auth::guard('admin')->check()
        ? redirect()->route('admin.dashboard')
        : redirect()->route('admin.login');
})->name('pwa.launch');

Route::middleware(['site.locale', 'site.view_log'])->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('site.home');
    Route::get('/about', [AboutController::class, 'index'])->name('site.about');
    Route::get('/robots.txt', [SiteDiscoveryController::class, 'robots'])->name('site.robots');
    Route::get('/sitemap.xml', [SiteDiscoveryController::class, 'sitemap'])->name('site.sitemap');
    Route::get('/sitemaps/pages-{page}.xml', [SiteDiscoveryController::class, 'sitemapShard'])
        ->whereNumber('page')
        ->name('site.sitemap.shard');
    Route::get('/archive', [ArchiveController::class, 'index'])->name('site.archive');
    Route::get('/archive/{year}/{month}', [ArchiveController::class, 'month'])
        ->name('site.archive.month')
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}']);
    Route::get('/category/{slug}', [SiteCategoryController::class, 'show'])->name('site.category');
    Route::get('/article/{slug}', [SiteArticleController::class, 'show'])->name('site.article');
    Route::get('/forms/{slug}', [SiteLeadFormController::class, 'show'])->name('site.lead-forms.show');
    Route::post('/forms/{slug}/submissions', [SiteLeadFormController::class, 'submit'])
        ->middleware('throttle:site-lead-submission')
        ->name('site.lead-forms.submit');
});

$adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale'])->group(function () {
    // 通用入口与语言切换
    Route::get('locale/{locale}', [AdminAuthController::class, 'switchLocale'])->name('locale.switch');

    Route::get('/', function () {
        return Auth::guard('admin')->check()
            ? redirect()->route('admin.dashboard')
            : redirect()->route('admin.login');
    })->name('entry');

    // 访客认证路由
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminAuthController::class, 'showLoginForm'])->name('login');
        Route::post('login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:admin-login')
            ->name('login.attempt');
    });

    // 后台受保护路由
    Route::middleware(['admin.auth', 'admin.activity', 'admin.recent'])->group(function () {
        // 会话与首页
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::post('welcome/dismiss', [AdminWelcomeController::class, 'dismiss'])->name('welcome.dismiss');
        Route::middleware('admin.ui-v3')->group(function (): void {
            Route::get('recent', AdminRecentActivityController::class)
                ->middleware('throttle:admin-recent-read')
                ->name('recent.index');
            Route::get('ai-workspace', AiWorkspaceController::class)->name('ai-workspace');
            Route::prefix('ai-workspace')->name('ai-workspace.')->group(function (): void {
                Route::middleware('throttle:ai-workspace-read')->group(function (): void {
                    Route::get('conversations', [AiWorkspaceApiController::class, 'conversations'])->name('conversations.index');
                    Route::get('conversations/{conversation}', [AiWorkspaceApiController::class, 'showConversation'])->name('conversations.show');
                    Route::get('media/{mediaAsset}', AiWorkspaceKnowledgeMediaController::class)
                        ->whereNumber('mediaAsset')
                        ->name('media.show');
                });
                Route::post('conversations/{conversation}/archive', [AiWorkspaceApiController::class, 'archiveConversation'])->middleware('throttle:ai-workspace')->name('conversations.archive');
                Route::patch('conversations/{conversation}', [AiWorkspaceApiController::class, 'renameConversation'])->middleware('throttle:ai-workspace')->name('conversations.update');
                Route::post('conversations', [AiWorkspaceApiController::class, 'storeConversation'])->middleware('throttle:ai-workspace')->name('conversations.store');
                Route::post('conversations/{conversation}/messages', [AiWorkspaceApiController::class, 'sendMessage'])->middleware('throttle:ai-workspace-messages')->name('messages.store');
            });
            Route::prefix('account')->name('account.')->group(function (): void {
                Route::get('/', [AdminAccountController::class, 'show'])->name('show');
                Route::put('profile', [AdminAccountController::class, 'updateProfile'])->name('profile.update');
                Route::put('password', [AdminAccountController::class, 'updatePassword'])
                    ->middleware('throttle:admin-sensitive')
                    ->name('password.update');
            });
        });
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::prefix('account/browser-clients')->name('account.browser-clients.')->group(function (): void {
            Route::get('/', [BrowserClientController::class, 'index'])->name('index');
            Route::delete('{tokenId}', [BrowserClientController::class, 'destroy'])
                ->middleware('throttle:admin-sensitive')
                ->whereNumber('tokenId')
                ->name('destroy');
        });
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');
        Route::prefix('analytics')->name('analytics.')->group(function (): void {
            Route::get('content', ContentAnalyticsController::class)->name('content');
            Route::get('traffic', TrafficAnalyticsController::class)->name('traffic');
            Route::get('ai-visibility', AiVisibilityAnalyticsController::class)->name('ai-visibility');
            Route::post('ai-visibility/collect', [AiVisibilityAnalyticsController::class, 'collect'])
                ->middleware(['admin.super', 'throttle:admin-sensitive'])
                ->name('ai-visibility.collect');
            Route::post('ai-visibility/competitors', [AiVisibilityAnalyticsController::class, 'storeCompetitor'])
                ->middleware(['admin.super', 'throttle:admin-sensitive'])
                ->name('ai-visibility.competitors.store');
            Route::post('ai-visibility/competitors/detect', [AiVisibilityAnalyticsController::class, 'detectCompetitors'])
                ->middleware(['admin.super', 'throttle:admin-sensitive'])
                ->name('ai-visibility.competitors.detect');
            Route::delete('ai-visibility/competitors/{competitor}/delete', [AiVisibilityAnalyticsController::class, 'destroyCompetitor'])
                ->whereNumber('competitor')
                ->middleware(['admin.super', 'throttle:admin-sensitive'])
                ->name('ai-visibility.competitors.destroy');
            Route::get('leads', LeadAnalyticsController::class)->name('leads');
            Route::get('distribution', DistributionAnalyticsController::class)
                ->middleware('admin.super')
                ->name('distribution');
        });

        Route::prefix('system-updates')->name('system-updates.')->middleware('admin.super')->group(function () {
            Route::get('/', [SystemUpdateController::class, 'index'])->name('index');
            Route::post('updater/prepare', [SystemUpdaterOperationController::class, 'prepare'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.prepare');
            Route::get('updater/download', [SystemUpdaterOperationController::class, 'download'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.download');
            Route::post('updater/plan', [SystemUpdaterOperationController::class, 'preview'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.plan');
            Route::post('updater/switch-back', [SystemUpdaterOperationController::class, 'switchBack'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.switch-back');
            Route::post('updater/update', [SystemUpdaterOperationController::class, 'update'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.update');
            Route::post('updater/backup', [SystemUpdaterOperationController::class, 'backup'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.backup');
            Route::post('updater/rollback', [SystemUpdaterOperationController::class, 'rollback'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.rollback');
            Route::post('updater/verify', [SystemUpdaterOperationController::class, 'verify'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.verify');
            Route::post('check', [SystemUpdateController::class, 'check'])
                ->middleware('throttle:admin-sensitive')
                ->name('check');
            Route::get('runs/{runUuid}', [LegacySystemUpdateHistoryController::class, 'run'])->name('runs.show');
            Route::get('backups/{backupUuid}', [LegacySystemUpdateHistoryController::class, 'backup'])->name('backups.show');
        });

        Route::prefix('lead-forms')->name('lead-forms.')->group(function () {
            Route::get('/', [LeadFormController::class, 'index'])->name('index');
            Route::get('create', [LeadFormController::class, 'create'])->name('create');
            Route::post('/', [LeadFormController::class, 'store'])->name('store');
            Route::get('{formId}/edit', [LeadFormController::class, 'edit'])->name('edit')->whereNumber('formId');
            Route::put('{formId}', [LeadFormController::class, 'update'])->name('update')->whereNumber('formId');
            Route::post('{formId}/toggle-status', [LeadFormController::class, 'toggleStatus'])->name('toggle-status')->whereNumber('formId');
            Route::post('{formId}/delete', [LeadFormController::class, 'destroy'])->name('delete')->whereNumber('formId');
        });
        Route::prefix('leads')->name('leads.')->group(function () {
            Route::get('/', [LeadController::class, 'index'])->name('index');
            Route::get('export', [LeadController::class, 'export'])->name('export');
            Route::get('{submissionId}', [LeadController::class, 'show'])->name('show')->whereNumber('submissionId');
            Route::put('{submissionId}', [LeadController::class, 'update'])->name('update')->whereNumber('submissionId');
        });

        // 任务管理（Blade 新路径）
        Route::prefix('tasks')->name('tasks.')->group(function () {
            Route::get('/', [TaskController::class, 'index'])->name('index');
            Route::get('workers', [TaskController::class, 'workers'])->name('workers');
            Route::get('jobs', [TaskController::class, 'jobs'])->name('jobs');
            Route::post('title-readiness', [TaskController::class, 'titleReadiness'])->name('title-readiness');
            Route::post('{taskId}/toggle-status', [TaskController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('{taskId}/delete', [TaskController::class, 'destroyTask'])->name('delete');
            Route::post('{taskId}/restore', [TaskController::class, 'restoreTask'])->whereNumber('taskId')->name('restore');
            Route::get('create', [TaskController::class, 'create'])->name('create');
            Route::post('create', [TaskController::class, 'store'])->name('store');
            Route::get('{taskId}/edit', [TaskController::class, 'edit'])->name('edit');
            Route::put('{taskId}', [TaskController::class, 'update'])->name('update');
            Route::get('health-check', [TaskController::class, 'healthCheck'])->name('health');
            Route::post('batch/start', [TaskController::class, 'batchAction'])->name('batch');
        });

        // 分发管理：集中管理外部站点 Agent 与文章分发队列
        Route::prefix('distribution')->name('distribution.')->middleware('admin.super')->group(function () {
            Route::get('/', [DistributionController::class, 'index'])->name('index');
            Route::get('create', [DistributionController::class, 'create'])->name('create');
            Route::post('create', [DistributionController::class, 'store'])->name('store');
            Route::prefix('hosted-sites')->name('hosted-sites.')->middleware('hosted-sites.enabled')->scopeBindings()->group(function (): void {
                Route::get('/', [HostedSiteController::class, 'index'])->name('index');
                Route::get('create', [HostedSiteController::class, 'create'])->name('create');
                Route::post('/', [HostedSiteController::class, 'store'])->name('store');
                Route::get('{hostedSite}', [HostedSiteController::class, 'show'])->name('show');
                Route::get('{hostedSite}/edit', [HostedSiteController::class, 'edit'])->name('edit');
                Route::put('{hostedSite}', [HostedSiteController::class, 'update'])->name('update');
                Route::post('{hostedSite}/preflight', [HostedSiteController::class, 'preflight'])->name('preflight');
                Route::post('{hostedSite}/activate', [HostedSiteController::class, 'activate'])->name('activate');
                Route::post('{hostedSite}/pause', [HostedSiteController::class, 'pause'])->name('pause');
                Route::post('{hostedSite}/maintenance', [HostedSiteController::class, 'maintenance'])->name('maintenance');
                Route::post('{hostedSite}/indexing', [HostedSiteController::class, 'indexing'])->name('indexing');
                Route::post('{hostedSite}/archive', [HostedSiteController::class, 'archive'])->name('archive');
                Route::post('{hostedSite}/articles', [HostedSiteController::class, 'assignArticle'])->name('articles.assign');
            })->whereNumber('hostedSite');
            Route::get('jobs', [DistributionController::class, 'jobs'])->name('jobs');
            Route::get('sync-settings-all/preview', [DistributionController::class, 'previewSyncSettingsAll'])->name('sync-settings-all.preview');
            Route::post('sync-settings-all', [DistributionController::class, 'syncSettingsAll'])->name('sync-settings-all');
            Route::post('sync-settings-selected/preview', [DistributionController::class, 'previewSyncSettingsSelected'])->name('sync-settings-selected.preview');
            Route::post('sync-settings-selected', [DistributionController::class, 'syncSettingsSelected'])->name('sync-settings-selected');
            Route::get('jobs/{distributionId}/edit', [DistributionController::class, 'editArticle'])->name('article.edit')->whereNumber('distributionId');
            Route::put('jobs/{distributionId}', [DistributionController::class, 'updateArticle'])->name('article.update')->whereNumber('distributionId');
            Route::post('jobs/{distributionId}/delete', [DistributionController::class, 'deleteArticle'])->name('article.delete')->whereNumber('distributionId');
            Route::post('jobs/{distributionId}/retry', [DistributionController::class, 'retry'])->name('retry')->whereNumber('distributionId');
            Route::get('{channelId}/delete', [DistributionController::class, 'deletePreview'])->middleware('admin.super')->name('delete')->whereNumber('channelId');
            Route::post('{channelId}/delete/prepare', [DistributionController::class, 'prepareDelete'])->middleware('admin.super')->name('delete.prepare')->whereNumber('channelId');
            Route::post('{channelId}/delete/cancel', [DistributionController::class, 'cancelDelete'])->middleware('admin.super')->name('delete.cancel')->whereNumber('channelId');
            Route::delete('{channelId}', [DistributionController::class, 'destroy'])->middleware(['admin.super', 'throttle:admin-sensitive'])->name('destroy')->whereNumber('channelId');
            Route::get('{channelId}/edit', [DistributionController::class, 'edit'])->name('edit')->whereNumber('channelId');
            Route::put('{channelId}', [DistributionController::class, 'update'])->name('update')->whereNumber('channelId');
            Route::post('{channelId}/pause', [DistributionController::class, 'pause'])->name('pause')->whereNumber('channelId');
            Route::post('{channelId}/activate', [DistributionController::class, 'activate'])->name('activate')->whereNumber('channelId');
            Route::post('{channelId}/rotate-secret', [DistributionController::class, 'rotateSecret'])->name('rotate-secret')->whereNumber('channelId');
            Route::post('{channelId}/reveal-secret', [DistributionController::class, 'revealSecret'])->name('reveal-secret')->whereNumber('channelId');
            Route::post('{channelId}/download-package', [DistributionController::class, 'downloadPackage'])->name('download-package')->whereNumber('channelId');
            Route::post('{channelId}/frontend-capabilities/refresh', [DistributionController::class, 'refreshFrontendCapabilities'])->name('frontend-capabilities.refresh')->whereNumber('channelId');
            Route::get('{channelId}/sync-settings/preview', [DistributionController::class, 'previewSyncSettings'])->name('sync-settings.preview')->whereNumber('channelId');
            Route::post('{channelId}/sync-settings', [DistributionController::class, 'syncSettings'])->name('sync-settings')->whereNumber('channelId');
            Route::get('{channelId}', [DistributionController::class, 'show'])->name('show')->whereNumber('channelId');
            Route::post('{channelId}/health', [DistributionController::class, 'health'])->name('health')->whereNumber('channelId');
        });

        // 文章管理（Blade 新路径）
        Route::prefix('articles')->name('articles.')->group(function () {
            Route::get('/', [ArticleController::class, 'index'])->name('index');
            Route::post('batch/update-status', [ArticleController::class, 'batchUpdateStatus'])->name('batch.update-status');
            Route::post('batch/update-review', [ArticleController::class, 'batchUpdateReview'])->name('batch.update-review');
            Route::post('batch/delete', [ArticleController::class, 'batchDelete'])->name('batch.delete');
            Route::post('batch/export-markdown/prepare', [ArticleController::class, 'prepareMarkdownExport'])
                ->middleware('throttle:article-markdown-export-prepare')
                ->name('batch.export-markdown.prepare');
            Route::get('batch/export-markdown/download/{exportToken}', [ArticleController::class, 'downloadMarkdownExport'])
                ->middleware(['signed:relative', 'throttle:article-markdown-export-download'])
                ->where('exportToken', '[A-Za-z0-9]{40}')
                ->name('batch.export-markdown.download');
            Route::post('batch/restore', [ArticleController::class, 'batchRestore'])->name('batch.restore');
            Route::post('batch/force-delete', [ArticleController::class, 'batchForceDelete'])
                ->middleware('throttle:admin-sensitive')
                ->name('batch.force-delete');
            Route::post('trash/empty', [ArticleController::class, 'emptyTrash'])
                ->middleware('throttle:admin-sensitive')
                ->name('trash.empty');
            Route::post('editor/wechat-html', [ArticleEditorAssetController::class, 'exportWeChatHtml'])->name('editor.wechat-html');
            Route::get('editor/titles', [ArticleEditorAssistantController::class, 'titles'])->name('editor.titles');
            Route::post('editor/generate', [ArticleEditorAssistantController::class, 'generate'])->middleware('throttle:10,1')->name('editor.generate');
            Route::get('create', [ArticleController::class, 'create'])->name('create');
            Route::post('create', [ArticleController::class, 'store'])->name('store');
            Route::post('{articleId}/restore', [ArticleController::class, 'restore'])->name('restore')->whereNumber('articleId');
            Route::post('{articleId}/force-delete', [ArticleController::class, 'forceDelete'])
                ->middleware('throttle:admin-sensitive')
                ->name('force-delete')
                ->whereNumber('articleId');
            Route::get('{articleId}/edit', [ArticleController::class, 'edit'])->name('edit');
            Route::post('{articleId}/risk-scan', [ArticleController::class, 'recheckRisk'])->name('risk-scan')->whereNumber('articleId');
            Route::post('{articleId}/ai-quality/recheck', [ArticleController::class, 'recheckAiQuality'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.recheck')
                ->whereNumber('articleId');
            Route::get('{articleId}/ai-quality/status', [ArticleController::class, 'aiQualityStatus'])
                ->middleware('throttle:120,1')
                ->name('ai-quality.status')
                ->whereNumber('articleId');
            Route::post('{articleId}/ai-quality/optimization', [ArticleAiOptimizationController::class, 'store'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.optimization.store')
                ->whereNumber('articleId');
            Route::get('{articleId}/ai-quality/optimization/{runId}/candidate', [ArticleAiOptimizationController::class, 'candidate'])
                ->middleware('throttle:120,1')
                ->name('ai-quality.optimization.candidate')
                ->whereNumber(['articleId', 'runId']);
            Route::post('{articleId}/ai-quality/optimization/{runId}/apply', [ArticleAiOptimizationController::class, 'apply'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.optimization.apply')
                ->whereNumber(['articleId', 'runId']);
            Route::post('{articleId}/ai-quality/optimization/{runId}/cancel', [ArticleAiOptimizationController::class, 'cancel'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.optimization.cancel')
                ->whereNumber(['articleId', 'runId']);
            Route::post('{articleId}/ai-quality/optimization/{runId}/rollback', [ArticleAiOptimizationController::class, 'rollback'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.optimization.rollback')
                ->whereNumber(['articleId', 'runId']);
            Route::post('{articleId}/ai-quality/workflow-retry', [ArticleController::class, 'retryAiQualityWorkflow'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.workflow-retry')
                ->whereNumber('articleId');
            Route::post('{articleId}/ai-quality/override', [ArticleController::class, 'overrideAiQuality'])->name('ai-quality.override')->whereNumber('articleId');
            Route::post('{articleId}/editor/images/upload', [ArticleEditorAssetController::class, 'uploadImage'])->name('editor.images.upload')->whereNumber('articleId');
            Route::put('{articleId}', [ArticleController::class, 'update'])->name('update');
        });

        Route::prefix('manual-publications')->name('manual-publications.')->group(function () {
            Route::get('browser-connect', [BrowserConnectionApprovalController::class, 'show'])->name('browser-connect.show');
            Route::post('browser-connect/decision', [BrowserConnectionApprovalController::class, 'decision'])
                ->middleware('throttle:admin-sensitive')
                ->name('browser-connect.decision');
            Route::get('/', [ManualPublicationController::class, 'index'])->name('index');
            Route::get('export', [ManualPublicationController::class, 'export'])->name('export');
            Route::get('create', [ManualPublicationController::class, 'create'])->name('create');
            Route::post('/', [ManualPublicationController::class, 'store'])->name('store');
            Route::middleware('admin.super')->prefix('settings')->name('settings.')->group(function () {
                Route::get('/', [ManualPublicationSettingsController::class, 'index'])->name('index');
                Route::post('personas', [ManualPublicationSettingsController::class, 'storePersona'])->name('personas.store');
                Route::put('personas/{personaId}', [ManualPublicationSettingsController::class, 'updatePersona'])->name('personas.update')->whereNumber('personaId');
                Route::post('accounts', [ManualPublicationSettingsController::class, 'storeAccount'])->name('accounts.store');
                Route::put('accounts/{accountId}', [ManualPublicationSettingsController::class, 'updateAccount'])->name('accounts.update')->whereNumber('accountId');
            });
            Route::get('{manualPublicationId}', [ManualPublicationController::class, 'show'])->name('show')->whereNumber('manualPublicationId');
            Route::get('{manualPublicationId}/edit', [ManualPublicationController::class, 'edit'])->name('edit')->whereNumber('manualPublicationId');
            Route::put('{manualPublicationId}', [ManualPublicationController::class, 'update'])->name('update')->whereNumber('manualPublicationId');
            Route::post('{manualPublicationId}/transition', [ManualPublicationController::class, 'transition'])->name('transition')->whereNumber('manualPublicationId');
        });

        // 栏目管理（保持 geo_admin/categories 路径语义）
        Route::prefix('categories')->name('categories.')->group(function () {
            Route::get('/', [CategoryController::class, 'index'])->name('index');
            Route::get('create', [CategoryController::class, 'create'])->name('create');
            Route::post('create', [CategoryController::class, 'store'])->name('store');
            Route::get('{categoryId}/edit', [CategoryController::class, 'edit'])->name('edit');
            Route::put('{categoryId}', [CategoryController::class, 'update'])->name('update');
            Route::post('{categoryId}/delete', [CategoryController::class, 'destroy'])->name('delete');
        });

        // 素材管理：作者管理
        Route::prefix('authors')
            ->name('authors.')
            ->where(['authorId' => '[1-9][0-9]{0,17}'])
            ->group(function () {
                Route::get('/', [AuthorController::class, 'index'])->name('index');
                Route::get('create', [AuthorController::class, 'create'])->name('create');
                Route::post('create', [AuthorController::class, 'store'])->name('store');
                Route::get('{authorId}/edit', [AuthorController::class, 'edit'])->name('edit');
                Route::get('{authorId}/detail', [AuthorController::class, 'detail'])->name('detail');
                Route::put('{authorId}', [AuthorController::class, 'update'])->name('update');
                Route::post('{authorId}/delete', [AuthorController::class, 'destroy'])->name('delete');
            });

        // 素材管理：关键词库管理
        Route::prefix('keyword-libraries')
            ->name('keyword-libraries.')
            ->where(['libraryId' => '[1-9][0-9]{0,17}'])
            ->group(function () {
                Route::get('/', [KeywordLibraryController::class, 'index'])->name('index');
                Route::get('create', [KeywordLibraryController::class, 'create'])->name('create');
                Route::post('create', [KeywordLibraryController::class, 'store'])->name('store');
                Route::get('{libraryId}/edit', [KeywordLibraryController::class, 'edit'])->name('edit');
                Route::get('{libraryId}/detail', [KeywordLibraryController::class, 'detail'])->name('detail');
                Route::get('{libraryId}/keywords/create', [KeywordLibraryController::class, 'createKeyword'])->name('keywords.create');
                Route::post('{libraryId}/keywords', [KeywordLibraryController::class, 'storeKeyword'])->name('keywords.store');
                Route::post('{libraryId}/keywords/delete', [KeywordLibraryController::class, 'destroyKeywords'])->name('keywords.delete');
                Route::get('{libraryId}/import', [KeywordLibraryController::class, 'createImport'])->name('import.create');
                Route::post('{libraryId}/import', [KeywordLibraryController::class, 'importKeywords'])->name('import');
                Route::put('{libraryId}/detail', [KeywordLibraryController::class, 'updateFromDetail'])->name('detail.update');
                Route::put('{libraryId}', [KeywordLibraryController::class, 'update'])->name('update');
                Route::post('{libraryId}/delete', [KeywordLibraryController::class, 'destroy'])->name('delete');
            });

        // 素材管理：标题库管理
        Route::prefix('title-libraries')
            ->name('title-libraries.')
            ->where([
                'libraryId' => '[1-9][0-9]{0,17}',
                'runId' => '[1-9][0-9]{0,17}',
            ])
            ->group(function () {
                Route::get('/', [TitleLibraryController::class, 'index'])->name('index');
                Route::get('create', [TitleLibraryController::class, 'create'])->name('create');
                Route::post('create', [TitleLibraryController::class, 'store'])->name('store');
                Route::get('{libraryId}/edit', [TitleLibraryController::class, 'edit'])->name('edit');
                Route::get('{libraryId}/detail', [TitleLibraryController::class, 'detail'])->name('detail');
                Route::get('{libraryId}/titles/create', [TitleLibraryController::class, 'createTitle'])->name('titles.create');
                Route::post('{libraryId}/titles', [TitleLibraryController::class, 'storeTitle'])->name('titles.store');
                Route::post('{libraryId}/titles/delete', [TitleLibraryController::class, 'destroyTitles'])->name('titles.delete');
                Route::get('{libraryId}/import', [TitleLibraryController::class, 'createImport'])->name('import.create');
                Route::post('{libraryId}/import', [TitleLibraryController::class, 'importTitles'])->name('import');
                Route::get('{libraryId}/ai-generate', [TitleLibraryController::class, 'aiGenerate'])->name('ai-generate');
                Route::post('{libraryId}/ai-generate', [TitleLibraryController::class, 'generateWithAi'])
                    ->middleware('throttle:title-generation-submissions')
                    ->name('ai-generate.submit');
                Route::get('{libraryId}/ai-generation-runs/{runId}/status', [TitleLibraryController::class, 'generationStatus'])
                    ->name('ai-generate.status');
                Route::post('{libraryId}/ai-generation-runs/{runId}/retry', [TitleLibraryController::class, 'retryGeneration'])
                    ->middleware('throttle:title-generation-submissions')
                    ->name('ai-generate.retry');
                Route::post('{libraryId}/ai-generation-runs/{runId}/cancel', [TitleLibraryController::class, 'cancelGeneration'])
                    ->name('ai-generate.cancel');
                Route::put('{libraryId}', [TitleLibraryController::class, 'update'])->name('update');
                Route::post('{libraryId}/delete', [TitleLibraryController::class, 'destroy'])->name('delete');
            });

        // 素材管理：图片库管理
        Route::prefix('image-libraries')
            ->name('image-libraries.')
            ->where(['libraryId' => '[1-9][0-9]{0,17}'])
            ->group(function () {
                Route::get('/', [ImageLibraryController::class, 'index'])->name('index');
                Route::get('create', [ImageLibraryController::class, 'create'])->name('create');
                Route::post('create', [ImageLibraryController::class, 'store'])->name('store');
                Route::get('{libraryId}/edit', [ImageLibraryController::class, 'edit'])->name('edit');
                Route::get('{libraryId}/detail', [ImageLibraryController::class, 'detail'])->name('detail');
                Route::get('{libraryId}/images/upload', [ImageLibraryController::class, 'createImageUpload'])->name('images.create');
                Route::post('{libraryId}/images/upload', [ImageLibraryController::class, 'uploadImages'])->name('images.upload');
                Route::post('{libraryId}/images/delete', [ImageLibraryController::class, 'destroyImages'])->name('images.delete');
                Route::put('{libraryId}/detail', [ImageLibraryController::class, 'updateFromDetail'])->name('detail.update');
                Route::put('{libraryId}', [ImageLibraryController::class, 'update'])->name('update');
                Route::post('{libraryId}/delete', [ImageLibraryController::class, 'destroy'])->name('delete');
            });

        // 素材管理：知识库管理
        Route::prefix('knowledge-bases')->name('knowledge-bases.')->group(function () {
            Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
            Route::get('create', [KnowledgeBaseController::class, 'create'])->name('create');
            Route::post('create', [KnowledgeBaseController::class, 'store'])->name('store');
            Route::get('{knowledgeBaseId}/edit', [KnowledgeBaseController::class, 'edit'])->name('edit');
            Route::get('{knowledgeBaseId}/detail', [KnowledgeBaseController::class, 'detail'])->name('detail');
            Route::get('{knowledgeBaseId}/chunks', [KnowledgeBaseController::class, 'chunks'])
                ->name('chunks.index')->whereNumber('knowledgeBaseId');
            Route::post('upload', [KnowledgeBaseController::class, 'uploadFile'])->name('upload');
            Route::post('{knowledgeBaseId}/chunks/refresh', [KnowledgeBaseController::class, 'refreshChunks'])->name('chunks.refresh');
            Route::post('{knowledgeBaseId}/revisions/{revisionId}/restore', [KnowledgeBaseController::class, 'restoreRevision'])
                ->name('revisions.restore')
                ->whereNumber(['knowledgeBaseId', 'revisionId']);
            Route::post('{knowledgeBaseId}/official/adopt', [KnowledgeBaseController::class, 'adoptOfficial'])
                ->name('official.adopt')
                ->whereNumber('knowledgeBaseId');
            Route::post('{knowledgeBaseId}/media', [KnowledgeBaseMediaController::class, 'store'])
                ->name('media.store')->whereNumber('knowledgeBaseId');
            Route::put('{knowledgeBaseId}/media/{mediaAsset}', [KnowledgeBaseMediaController::class, 'update'])
                ->name('media.update')->whereNumber(['knowledgeBaseId', 'mediaAsset']);
            Route::post('{knowledgeBaseId}/media/{mediaAsset}/replace', [KnowledgeBaseMediaController::class, 'replace'])
                ->name('media.replace')->whereNumber(['knowledgeBaseId', 'mediaAsset']);
            Route::post('{knowledgeBaseId}/media/{mediaAsset}/toggle', [KnowledgeBaseMediaController::class, 'toggle'])
                ->name('media.toggle')->whereNumber(['knowledgeBaseId', 'mediaAsset']);
            Route::get('{knowledgeBaseId}/facts', [KnowledgeFactController::class, 'index'])->name('facts.index')->whereNumber('knowledgeBaseId');
            Route::post('{knowledgeBaseId}/facts', [KnowledgeFactController::class, 'store'])->name('facts.store')->whereNumber('knowledgeBaseId');
            Route::put('{knowledgeBaseId}/facts/{factId}', [KnowledgeFactController::class, 'update'])->name('facts.update')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/review', [KnowledgeFactController::class, 'review'])->name('facts.review')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/archive', [KnowledgeFactController::class, 'archive'])->name('facts.archive')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/values', [KnowledgeFactController::class, 'storeValue'])->name('fact-values.store')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::put('{knowledgeBaseId}/fact-values/{valueId}', [KnowledgeFactController::class, 'updateValue'])->name('fact-values.update')->whereNumber(['knowledgeBaseId', 'valueId']);
            Route::post('{knowledgeBaseId}/fact-values/{valueId}/archive', [KnowledgeFactController::class, 'archiveValue'])->name('fact-values.archive')->whereNumber(['knowledgeBaseId', 'valueId']);
            Route::post('{knowledgeBaseId}/fact-values/{valueId}/evidences', [KnowledgeFactController::class, 'storeEvidence'])->name('fact-evidences.store')->whereNumber(['knowledgeBaseId', 'valueId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/merge', [KnowledgeFactController::class, 'merge'])->name('facts.merge')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/split', [KnowledgeFactController::class, 'split'])->name('facts.split')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/publish', [KnowledgeFactController::class, 'publish'])->name('facts.publish')->whereNumber('knowledgeBaseId');
            Route::post('{knowledgeBaseId}/fact-revisions/{revisionId}/restore', [KnowledgeFactController::class, 'restore'])->name('fact-revisions.restore')->whereNumber(['knowledgeBaseId', 'revisionId']);
            Route::post('{knowledgeBaseId}/fact-generation', [KnowledgeFactGenerationController::class, 'store'])->name('fact-generation.store')->whereNumber('knowledgeBaseId')->middleware('throttle:admin-sensitive');
            Route::get('{knowledgeBaseId}/fact-generation/{runId}', [KnowledgeFactGenerationController::class, 'show'])->name('fact-generation.show')->whereNumber(['knowledgeBaseId', 'runId']);
            Route::post('{knowledgeBaseId}/fact-generation/{runId}/cancel', [KnowledgeFactGenerationController::class, 'cancel'])->name('fact-generation.cancel')->whereNumber(['knowledgeBaseId', 'runId'])->middleware('throttle:admin-sensitive');
            Route::post('{knowledgeBaseId}/fact-generation/{runId}/resolve', [KnowledgeFactGenerationController::class, 'resolve'])->name('fact-generation.resolve')->whereNumber(['knowledgeBaseId', 'runId'])->middleware('throttle:admin-sensitive');
            Route::put('{knowledgeBaseId}/detail', [KnowledgeBaseController::class, 'updateFromDetail'])->name('detail.update');
            Route::put('{knowledgeBaseId}', [KnowledgeBaseController::class, 'update'])->name('update');
            Route::post('{knowledgeBaseId}/delete', [KnowledgeBaseController::class, 'destroy'])->name('delete');
        });

        Route::prefix('enterprise-knowledge')->name('enterprise-knowledge.')->group(function () {
            Route::get('/', [EnterpriseKnowledgeController::class, 'index'])->name('index');
            Route::get('create', [EnterpriseKnowledgeController::class, 'create'])->name('create');
            Route::post('create', [EnterpriseKnowledgeController::class, 'store'])->name('store');
            Route::get('{projectId}/status', [EnterpriseKnowledgeController::class, 'status'])->name('status')->whereNumber('projectId');
            Route::post('{projectId}/editor/images/upload', [EnterpriseKnowledgeController::class, 'uploadImage'])
                ->name('editor.images.upload')
                ->whereNumber('projectId');
            Route::get('{projectId}', [EnterpriseKnowledgeController::class, 'show'])->name('show')->whereNumber('projectId');
            Route::post('{projectId}/autosave', [EnterpriseKnowledgeController::class, 'autosave'])->name('autosave')->whereNumber('projectId');
            Route::post('{projectId}/validate', [EnterpriseKnowledgeController::class, 'validateDraft'])->name('validate')->whereNumber('projectId');
            Route::post('{projectId}/revisions/{revisionId}/restore', [EnterpriseKnowledgeController::class, 'restoreRevision'])
                ->name('revisions.restore')
                ->whereNumber(['projectId', 'revisionId']);
            Route::post('{projectId}/publish', [EnterpriseKnowledgeController::class, 'publish'])->name('publish')->whereNumber('projectId');
            Route::post('{projectId}/delete', [EnterpriseKnowledgeController::class, 'destroy'])->name('delete')->whereNumber('projectId');
        });

        // 业务页面
        Route::get('materials', [MaterialsController::class, 'index'])->name('materials.index');
        Route::middleware('admin.super')->group(function () {
            Route::get('url-import', [UrlImportController::class, 'index'])->name('url-import');
            Route::post('url-import', [UrlImportController::class, 'store'])->name('url-import.store');
            Route::get('url-import/history', [UrlImportController::class, 'history'])->name('url-import.history');
            Route::post('url-import/{jobId}/run', [UrlImportController::class, 'run'])
                ->name('url-import.run')
                ->whereNumber('jobId');
            Route::get('url-import/{jobId}/status', [UrlImportController::class, 'status'])
                ->name('url-import.status')
                ->whereNumber('jobId');
            Route::post('url-import/{jobId}/commit', [UrlImportController::class, 'commit'])
                ->name('url-import.commit')
                ->whereNumber('jobId');
            Route::get('url-import/{jobId}', [UrlImportController::class, 'show'])
                ->name('url-import.show')
                ->whereNumber('jobId');
        });

        // AI 配置模块（配置器 / 模型 / 提示词）
        Route::group([], function () {
            Route::get('ai-configurator', [LegacyController::class, 'aiConfigurator'])->name('ai.configurator');
            Route::prefix('ai-models')->name('ai-models.')->group(function () {
                Route::get('/', [AiModelController::class, 'index'])->name('index');
                Route::get('create', [AiModelController::class, 'create'])->name('create');
                Route::post('create', [AiModelController::class, 'store'])->name('store');
                Route::post('personal-defaults', [AiModelController::class, 'updatePersonalDefaults'])->name('personal-defaults');
                Route::get('{modelId}/edit', [AiModelController::class, 'edit'])->name('edit')->whereNumber('modelId');
                Route::put('{modelId}', [AiModelController::class, 'update'])->name('update')->whereNumber('modelId');
                Route::post('{modelId}/test', [AiModelController::class, 'testConnection'])
                    ->middleware('throttle:admin-sensitive')
                    ->name('test')
                    ->whereNumber('modelId');
                Route::post('{modelId}/delete', [AiModelController::class, 'destroy'])->name('delete')->whereNumber('modelId');
                Route::post('default-embedding', [AiModelController::class, 'updateDefaultEmbedding'])->middleware('admin.super')->name('default-embedding');
                Route::post('chunking-config', [AiModelController::class, 'updateChunkingConfig'])->middleware('admin.super')->name('chunking-config');
            });
            Route::prefix('ai-source-providers')
                ->name('ai-source-providers.')
                ->middleware('admin.super')
                ->where(['providerId' => '[1-9][0-9]{0,17}'])
                ->group(function () {
                    Route::get('/', [AiSourceProviderController::class, 'index'])->name('index');
                    Route::get('create', [AiSourceProviderController::class, 'create'])->name('create');
                    Route::post('/', [AiSourceProviderController::class, 'store'])->name('store');
                    Route::get('{providerId}/edit', [AiSourceProviderController::class, 'edit'])->name('edit');
                    Route::put('{providerId}', [AiSourceProviderController::class, 'update'])->name('update');
                    Route::post('{providerId}/test', [AiSourceProviderController::class, 'testProvider'])
                        ->middleware('throttle:admin-sensitive')
                        ->name('test');
                    Route::post('{providerId}/delete', [AiSourceProviderController::class, 'destroy'])->name('delete');
                    Route::post('model-bindings', [AiSourceProviderController::class, 'updateModelBindings'])->name('model-bindings');
                    Route::post('model-bindings/upsert-api', [AiSourceProviderController::class, 'upsertModelApi'])->name('model-bindings.upsert-api');
                    Route::post('model-bindings/test', [AiSourceProviderController::class, 'testModelBinding'])
                        ->middleware('throttle:admin-sensitive')
                        ->name('model-bindings.test');
                });
            Route::get('ai-prompts', [AiPromptController::class, 'index'])->name('ai-prompts');
            Route::get('ai-prompts/create', [AiPromptController::class, 'create'])->name('ai-prompts.create');
            Route::post('ai-prompts/create', [AiPromptController::class, 'store'])->name('ai-prompts.store');
            Route::get('ai-prompts/{promptId}/edit', [AiPromptController::class, 'edit'])->name('ai-prompts.edit')->whereNumber('promptId');
            Route::post('ai-prompts/{promptId}/copy', [AiPromptController::class, 'copy'])->name('ai-prompts.copy')->whereNumber('promptId');
            Route::put('ai-prompts/{promptId}', [AiPromptController::class, 'update'])->name('ai-prompts.update')->whereNumber('promptId');
            Route::post('ai-prompts/{promptId}/delete', [AiPromptController::class, 'destroy'])->name('ai-prompts.delete')->whereNumber('promptId');
            Route::get('ai-special-prompts', [AiSpecialPromptController::class, 'index'])->name('ai-special-prompts');
            Route::post('ai-special-prompts/keyword', [AiSpecialPromptController::class, 'updateKeyword'])->name('ai-special-prompts.keyword');
            Route::post('ai-special-prompts/description', [AiSpecialPromptController::class, 'updateDescription'])->name('ai-special-prompts.description');
        });

        Route::prefix('site-settings')->name('site-settings.')->group(function () {
            Route::get('/', [SiteSettingsController::class, 'index'])->name('index');
            Route::post('/', [SiteSettingsController::class, 'update'])->name('update');
            Route::post('theme', [SiteSettingsController::class, 'updateTheme'])->name('theme');
            Route::get('homepage-modules', [SiteSettingsController::class, 'editHomepageModules'])->name('homepage-modules.edit');
            Route::post('homepage-modules', [SiteSettingsController::class, 'updateHomepageModules'])->name('homepage-modules');
            Route::post('homepage-modules/preset', [SiteSettingsController::class, 'applyHomepageModulePreset'])->name('homepage-modules.preset');
            Route::post('homepage-modules/import', [SiteSettingsController::class, 'importHomepageModuleDesign'])->name('homepage-modules.import');
            Route::middleware('admin.super')->group(function () {
                Route::prefix('theme-packages')->name('theme-packages.')->group(function (): void {
                    Route::post('exports', [SiteThemePackageController::class, 'export'])->middleware('throttle:admin-sensitive')->name('exports.store');
                    Route::get('exports/{token}', [SiteThemePackageController::class, 'download'])->where('token', '[A-Za-z0-9]{40}')->name('exports.download');
                    Route::get('imports/create', [SiteThemePackageController::class, 'create'])->name('imports.create');
                    Route::post('imports', [SiteThemePackageController::class, 'upload'])->middleware('throttle:admin-sensitive')->name('imports.store');
                    Route::get('imports/{token}', [SiteThemePackageController::class, 'inspection'])->where('token', '[A-Za-z0-9]{40}')->name('imports.show');
                    Route::get('imports/{token}/files/{fileIndex}', [SiteThemePackageController::class, 'file'])->where('token', '[A-Za-z0-9]{40}')->where('fileIndex', '[0-9]{1,9}')->name('imports.file');
                    Route::post('imports/{token}/install', [SiteThemePackageController::class, 'install'])->middleware('throttle:admin-sensitive')->where('token', '[A-Za-z0-9]{40}')->name('imports.install');
                    Route::get('installed/{themeId}/preview/frame/{sitePath?}', [SiteThemePreviewController::class, 'frame'])->middleware('site.locale')->where(['themeId' => '[A-Za-z0-9_-]{1,80}', 'sitePath' => '.*'])->name('preview.frame');
                    Route::get('installed/{themeId}/preview/{page?}', [SiteThemePreviewController::class, 'show'])->where(['themeId' => '[A-Za-z0-9_-]{1,80}', 'page' => 'home|category|article|about|archive-index|archive-month'])->name('preview');
                });
                Route::get('theme-replications/create', [SiteThemeReplicationController::class, 'create'])->name('theme-replications.create');
                Route::post('theme-replications', [SiteThemeReplicationController::class, 'store'])->name('theme-replications.store');
                Route::get('theme-replications/{replicationId}', [SiteThemeReplicationController::class, 'show'])
                    ->name('theme-replications.show')
                    ->whereNumber('replicationId');
                Route::get('theme-replications/{replicationId}/status', [SiteThemeReplicationController::class, 'status'])
                    ->name('theme-replications.status')
                    ->whereNumber('replicationId');
                Route::get('theme-replications/{replicationId}/preview/{page}', [SiteThemeReplicationController::class, 'preview'])
                    ->name('theme-replications.preview')
                    ->whereNumber('replicationId')
                    ->whereIn('page', ['home', 'category', 'article']);
                Route::post('theme-replications/{replicationId}/retry', [SiteThemeReplicationController::class, 'retry'])
                    ->name('theme-replications.retry')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/iterate', [SiteThemeReplicationController::class, 'iterate'])
                    ->name('theme-replications.iterate')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/publish', [SiteThemeReplicationController::class, 'publish'])
                    ->name('theme-replications.publish')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/copy', [SiteThemeReplicationController::class, 'copy'])
                    ->name('theme-replications.copy')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/archive', [SiteThemeReplicationController::class, 'archive'])
                    ->name('theme-replications.archive')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/drafts/delete', [SiteThemeReplicationController::class, 'deleteDrafts'])
                    ->name('theme-replications.delete-drafts')
                    ->whereNumber('replicationId');
                Route::get('theme-replications/{replicationId}/package', [SiteThemeReplicationController::class, 'downloadPackage'])
                    ->name('theme-replications.package')
                    ->whereNumber('replicationId');
            });
            Route::post('article-detail-ads', [SiteSettingsController::class, 'updateArticleDetailAds'])->name('ads');
            Route::post('article-detail-text-ads', [SiteSettingsController::class, 'updateArticleDetailTextAds'])->name('text-ads');
            Route::get('sensitive-words', [SecuritySettingsController::class, 'index'])->name('sensitive-words');
            Route::post('sensitive-words', [SecuritySettingsController::class, 'storeSensitiveWords'])
                ->middleware('admin.super')
                ->name('sensitive-words.store');
            Route::put('sensitive-words/{wordId}', [SecuritySettingsController::class, 'updateSensitiveWord'])
                ->middleware('admin.super')
                ->name('sensitive-words.update')
                ->whereNumber('wordId');
            Route::post('sensitive-words/{wordId}/delete', [SecuritySettingsController::class, 'destroySensitiveWord'])
                ->middleware('admin.super')
                ->name('sensitive-words.delete')
                ->whereNumber('wordId');
        });
        Route::prefix('security-settings')->name('security-settings.')->group(function () {
            Route::get('/', fn () => redirect()->route('admin.site-settings.sensitive-words'))->name('index');
            Route::post('sensitive-words', [SecuritySettingsController::class, 'storeSensitiveWords'])
                ->middleware('admin.super')
                ->name('words.store');
            Route::post('sensitive-words/{wordId}/delete', [SecuritySettingsController::class, 'destroySensitiveWord'])
                ->middleware('admin.super')
                ->name('words.delete');
            Route::post('password', [SecuritySettingsController::class, 'updatePassword'])->name('password.update');
        });

        // 超级管理员功能
        Route::middleware('admin.super')->group(function () {
            Route::prefix('admin-users')
                ->name('admin-users.')
                ->where(['adminId' => '[1-9][0-9]{0,17}'])
                ->group(function () {
                    Route::get('/', [AdminUserController::class, 'index'])->name('index');
                    Route::get('create', [AdminUserController::class, 'create'])->name('create');
                    Route::post('create', [AdminUserController::class, 'store'])->name('store');
                    Route::get('{adminId}/edit', [AdminUserController::class, 'edit'])->name('edit');
                    Route::post('{adminId}/update', [AdminUserController::class, 'update'])->name('update');
                    Route::post('{adminId}/toggle-status', [AdminUserController::class, 'toggleStatus'])->name('toggle-status');
                    Route::post('{adminId}/delete', [AdminUserController::class, 'destroy'])->name('delete');
                });
            Route::get('admin-activity-logs', [AdminActivityLogController::class, 'index'])->name('admin-activity-logs');
            Route::prefix('api-tokens')->name('api-tokens.')->group(function () {
                Route::get('/', [ApiTokenController::class, 'index'])->name('index');
                Route::post('/', [ApiTokenController::class, 'store'])->middleware('throttle:admin-sensitive')->name('store');
                Route::post('{tokenId}/revoke', [ApiTokenController::class, 'revoke'])->name('revoke');
            });
        });
    });
});

$adminUiRegistry = app(AdminUiRegistry::class);
foreach (Route::getRoutes() as $adminRoute) {
    $routeName = $adminRoute->getName();
    if (is_string($routeName)
        && in_array('GET', $adminRoute->methods(), true)
        && $adminUiRegistry->shouldRememberRoute($routeName)) {
        $adminRoute->block(30, 30);
    }
}

// ==========================================================================
// 官网诊断（本地扩展 · 追加路由，非 GEOFlow 官方功能）
// 访问：/{admin}/geo-audit?url=https://example.com&auto=1
// ==========================================================================
// 预构建镜像的 autoload 已固化，这里显式加载本地扩展类
require_once __DIR__.'/../app/Support/GeoAudit/GeoAuditor.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoAuditReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoAuditAdvisor.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoAuditSchema.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoPlanGenerator.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoPlanReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoStore.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoUi.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoTaskBridge.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoHistoryReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoKbBuilder.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoKbReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoKeywordMiner.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoKeywordReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoModelPicker.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoChat.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoCitationConfirm.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoArticleGenReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoArticleAiInject.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoCheckReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoTitleGen.php';

// 【本地扩展】把官方「内容管理」改名叫「内容工坊」。
// 用 addLines 动态覆盖，不碰 lang 文件（那文件几千行，改了以后维护成本高）。
// 既然不再跟官方升级，这个覆盖风险可控。
try {
    $__t = app('translator');
    // 必须先 load 再 addLines。
    // 直接 addLines 会把 'admin' 这个组标记成"已加载（只含我加的这几个 key）"，
    // 之后真正的 zh_CN/admin.php 永远不会被读，所有 key 退回到 en —— 表现就是页面变英文。
    $__t->load('*', 'admin', 'zh_CN');
    $__t->addLines([
        'admin.nav.articles' => '内容工坊',
        'admin.articles.page_title' => '内容工坊',
        'admin.articles.topbar_title' => '内容工坊',
        'admin.button.create_article' => '新建文章',
        // 本地扩展的侧边栏项：必须走 __() + 双语 key，
        // 否则切换语言时这些项不会变（官方自带项能变，是因为它们都有语言包）。
        'admin.nav.geo_plan' => 'GEO 方案',
        'admin.nav.geo_audit' => '官网诊断',
        'admin.nav.geo_coverage' => '覆盖核查',
        'admin.nav.geo_sov' => '声量对标',
        'admin.nav.geo_studio' => '内容工坊',
        'admin.nav.geo_publish' => '发布追踪',
        'admin.nav.geo_citation' => '引用样本录入',
    ], 'zh_CN');
} catch (\Throwable) {
}

// 英文侧：同样的 key 也要有值，否则切到 English 时这几项会显示成原始 key
try {
    $__te = app('translator');
    $__te->load('*', 'admin', 'en');
    $__te->addLines([
        'admin.nav.geo_plan' => 'GEO Plan',
        'admin.nav.geo_audit' => 'Site Audit',
        'admin.nav.geo_coverage' => 'Coverage Check',
        'admin.nav.geo_sov' => 'Share of Voice',
        'admin.nav.geo_studio' => 'Content Studio',
        'admin.nav.geo_publish' => 'Publishing Tracker',
        'admin.nav.geo_citation' => 'Citation Samples',
    ], 'en');
} catch (\Throwable) {
}
require_once __DIR__.'/../app/Support/GeoAudit/GeoCoverageCheck.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoCoverageReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoPlatforms.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoContentStudio.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoContentStudioReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoPublish.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoPublishReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoScorer.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoShareOfVoice.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoShareOfVoiceReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoKeywordGen.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoKeywordGenReport.php';
require_once __DIR__.'/../app/Support/GeoAudit/GeoCitationRecord.php';

Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale', 'admin.auth'])->group(function (): void {
    Route::match(['get', 'post'], 'geo-audit', function (\Illuminate\Http\Request $request) {
        $adminBasePath = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $toolView = static fn (string $html, string $title = '官网诊断') => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_audit',
            'toolHtml' => $html,
        ]);

        // 记录操作：删除 / 下载 / 查看，均在 官网诊断页内完成（不跳独立记录中心）
        // 顺序很关键：del → download → view。
        // download 用的也是 ?id=，必须排在 view 之前，否则下载请求会被当成"查看"。
        //
        // 【安全】删除必须走 POST + CSRF：GET 链接容易被浏览器预取、被第三方页面
        // 用 <img src="...?del=56"> 触发，等于给删库留了个无鉴权的入口。
        // 这里只认 POST 的 _action=delete + id，不再接受 ?del= 这种 GET 参数。
        if ($request->isMethod('post') && (string) $request->input('_action', '') === 'delete') {
            $delId = (int) $request->input('id', 0);
            if ($delId > 0) {
                (new \App\Support\GeoAudit\GeoStore())->delete($delId);

                return redirect($adminBasePath.'/geo-audit');
            }
        }

        // 下载已存档的 Markdown：直接取 geo_ai_outputs.content，绝不重跑诊断
        // （重跑要 20 秒，而且下载历史记录的结果和当时存档的内容可能已经不一样了）
        $dlId = (int) ($request->query('id') ?? 0);
        if ((string) $request->query('download', '') === '1' && $dlId > 0) {
            $row = (new \App\Support\GeoAudit\GeoStore())->find($dlId);
            if ($row !== null) {
                $kind = (string) ($row->kind ?: 'record');

                return response((string) ($row->content ?? ''), 200, [
                    'Content-Type' => 'text/markdown; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="geo-'.$kind.'-'.$dlId.'.md"',
                ]);
            }
            // 记录不存在就当作普通访问往下走，不要抛 500
        }

        // 兼容两种查看参数：?view= 是新写法，?id= 是旧链接（老邮件/收藏里可能还在用）
        $viewId = (int) ($request->query('view') ?? $request->query('id') ?? 0);
        if ($viewId > 0) {
            $store = new \App\Support\GeoAudit\GeoStore();
            $row = $store->find($viewId);
            if ($row !== null) {
                $prev = (string) $row->kind === 'audit' ? $store->previousAudit((string) $row->url, (int) $row->id) : null;

                return $toolView(\App\Support\GeoAudit\GeoHistoryReport::detailPage($row, $prev), 'GEO 记录');
            }
        }

        $url = trim((string) ($request->query('url') ?? ''));
        $auditor = new \App\Support\GeoAudit\GeoAuditor();

        if ((string) $request->query('format', '') === 'md' && $url !== '') {
            $result = $auditor->run($url);

            return response(\App\Support\GeoAudit\GeoAuditReport::markdown($result), 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="geo-audit-report.md"',
            ]);
        }

        $auto = (string) $request->query('auto', '') === '1';
        if ($url !== '' && $auto) {
            $result = $auditor->run($url);
            $top = '';
            if (($result['ok'] ?? false) === true) {
                $store = new \App\Support\GeoAudit\GeoStore();
                // 先取上一次快照（不含本次），再存档本次
                $prev = $store->previousAudit((string) $result['final_url']);
                $savedId = $store->save('audit', null, (string) $result['final_url'],
                    '页面诊断 · '.$result['total'].' 分', \App\Support\GeoAudit\GeoAuditReport::markdown($result),
                    [
                        'total' => $result['total'],
                        'grade' => $result['grade'],
                        'dimension_scores' => $result['dimension_scores'],
                        'modules' => array_map(static fn (array $m): array => ['name' => $m['name'], 'score' => $m['score']], $result['modules']),
                    ],
                    (int) (auth('admin')->id() ?? 0)
                );
                $top = \App\Support\GeoAudit\GeoHistoryReport::compareBanner($result, $prev);
            }

            return $toolView(\App\Support\GeoAudit\GeoAuditReport::reportPage($result, $top));
        }

        return $toolView(\App\Support\GeoAudit\GeoAuditReport::formPage($url));
    })->name('geo-audit');

    // AI 解读：规则诊断结果 → 模型生成可执行建议（explain/content/answers/plan）
    Route::get('geo-audit/advise', function (\Illuminate\Http\Request $request) {
        $toolView = static fn (string $html, string $title = 'AI 解读') => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_audit',
            'toolHtml' => $html,
        ]);

        $url = trim((string) ($request->query('url') ?? ''));
        $type = (string) ($request->query('type') ?? 'explain');
        if ($url === '') {
            return response(\App\Support\GeoAudit\GeoAuditReport::formPage('', '请先输入要诊断的网址'));
        }

        // 【下载 Markdown】优先取已存那份，命中就直接返回。
        // 必须放在诊断之前：否则点一次下载会重跑「抓页面 + 24 项诊断 + 模型解读」，
        // 用户等 20 秒看不到任何反馈，会以为按钮坏了。
        if ((string) $request->query('format', '') === 'md') {
            $cached = (new \App\Support\GeoAudit\GeoStore())->latestContent('advise', $type, $url);
            if ($cached !== null && trim((string) $cached->content) !== '') {
                return response((string) $cached->content, 200, [
                    'Content-Type' => 'text/markdown; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="geo-advise-'.$type.'.md"',
                ]);
            }
            // 没缓存就往下走，生成完再下载（这种情况是用户直接粘链接访问）
        }

        $audit = (new \App\Support\GeoAudit\GeoAuditor())->run($url);
        if (! ($audit['ok'] ?? false)) {
            return response(\App\Support\GeoAudit\GeoAuditReport::formPage($url, (string) ($audit['error'] ?? '诊断失败')));
        }
        try {
            $result = (new \App\Support\GeoAudit\GeoAuditAdvisor())->advise($audit, $type);
            $store = new \App\Support\GeoAudit\GeoStore();
            $savedId = $store->save('advise', $result['type'], (string) ($result['url'] ?? ''),
                (string) ($result['type_label'] ?? 'AI 解读').' · '.(int) ($result['total'] ?? 0).' 分',
                (string) $result['markdown'],
                [
                    'total' => $result['total'] ?? null,
                    'grade' => $result['grade'] ?? null,
                    'model' => $result['model'] ?? null,
                    'elapsed_ms' => $result['elapsed_ms'] ?? null,
                    'usage' => $result['usage'] ?? [],
                ],
                (int) (auth('admin')->id() ?? 0)
            );
            $result['saved_id'] = $savedId;
            if ((string) $request->query('format', '') === 'md') {
                return response($result['markdown'], 200, [
                    'Content-Type' => 'text/markdown; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="geo-advise-'.$result['type'].'.md"',
                ]);
            }

            return $toolView(\App\Support\GeoAudit\GeoAuditReport::advisePage($result, $audit), (string) ($result['type_label'] ?? 'AI 解读'));
        } catch (\Throwable $e) {
            return $toolView(\App\Support\GeoAudit\GeoAuditReport::adviseErrorPage($url, $type, $e->getMessage(), $audit), 'AI 解读失败');
        }
    })->name('geo-audit.advise');

    // GEO 执行方案生成器（表单 → 规则诊断取证 → 模型生成 30/60/90 方案）
    Route::match(['get', 'post'], 'geo-plan', function (\Illuminate\Http\Request $request) {
        $geoPlanView = static fn (string $html) => response()->view('admin.geo-plan.index', [
            'pageTitle' => 'GEO 方案',
            'activeMenu' => 'geo_plan',
            'planHtml' => $html,
        ]);

        // 下载上一次生成的方案（存在 session，避免重复调模型）
        if ((string) $request->query('download', '') === '1') {
            $last = $request->hasSession() ? $request->session()->get('geo_plan_last') : null;
            if (! is_array($last) || empty($last['markdown'])) {
                return response('还没有生成过方案，请先填写表单生成一次。', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
            }

            return response((string) $last['markdown'], 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="geo-plan-'.date('Ymd-His').'.md"',
            ]);
        }

        $input = $request->isMethod('post')
            ? (array) $request->except(['_token'])
            : (array) $request->query();
        unset($input['generate'], $input['download']);

        // GET 也可以生成（避免 CSRF 失效导致 419；表单用 GET 提交）
        if ($request->isMethod('post') || (string) $request->query('generate', '') === '1') {
            try {
                $result = (new \App\Support\GeoAudit\GeoPlanGenerator())->generate($input);
                $store = new \App\Support\GeoAudit\GeoStore();
                $result['saved_id'] = $store->save('plan', null, (string) ($result['url'] ?? ''),
                    '执行方案 · '.(string) ($result['brand'] ?? '').' · '.(int) ($result['timeline'] ?? 0).'天',
                    (string) $result['markdown'],
                    [
                        'total' => $result['audit_total'] ?? null,
                        'grade' => $result['audit_grade'] ?? null,
                        'model' => $result['model'] ?? null,
                        'elapsed_ms' => $result['elapsed_ms'] ?? null,
                        'usage' => $result['usage'] ?? [],
                        'brand' => $result['brand'] ?? null,
                        'timeline' => $result['timeline'] ?? null,
                        'tasks' => $result['tasks'] ?? [],
                    ],
                    (int) (auth('admin')->id() ?? 0)
                );
                if ($request->hasSession()) {
                    $request->session()->put('geo_plan_last', $result);
                }

                return $geoPlanView(\App\Support\GeoAudit\GeoPlanReport::resultPage($result));
            } catch (\Throwable $e) {
                return $geoPlanView(\App\Support\GeoAudit\GeoPlanReport::errorPage($input, $e->getMessage()));
            }
        }

        return $geoPlanView(\App\Support\GeoAudit\GeoPlanReport::formPage($input));
    })->name('geo-plan');
});


// ==========================================================================
// GEO 记录中心 + 方案→任务 桥接（本地扩展）
// ==========================================================================
Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale', 'admin.auth'])->group(function (): void {
    // 记录中心已并入 官网诊断页（?view= / ?del=），不再提供独立页面


    // 方案 → 创建任务草稿（可勾选方案里的任务清单，批量生成 paused 任务）
    Route::get('geo-plan/run-task', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $toolView = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_plan',
            'toolHtml' => '<style>'.\App\Support\GeoAudit\GeoUi::css().'</style>'
                .'<div class="geo-ui" style="max-width:920px;margin:0 auto;padding:4px 4px 48px;font:14px/1.7 var(--g-font);color:#111827">'
                .$html.'</div>',
        ]);

        $id = (int) ($request->query('id') ?? 0);
        $row = (new \App\Support\GeoAudit\GeoStore())->find($id);
        $admin = auth('admin')->user();

        if ($row === null || $admin === null) {
            return $toolView('<div class="g-card"><h3>方案记录不存在</h3>'
                .'<p class="g-cap">请先到「GEO 方案」生成一份方案，再回到这里创建任务。</p>'
                .'<a class="g-btn g-btn--primary" href="'.$adminBase.'/geo-plan">去生成方案</a></div>', '创建任务草稿');
        }

        $meta = json_decode((string) ($row->meta ?? '{}'), true) ?: [];
        $tasks = is_array($meta['tasks'] ?? null) ? $meta['tasks'] : [];
        $planCtx = [
            'brand' => (string) ($meta['brand'] ?? ''),
            'url' => (string) $row->url,
            'timeline' => (int) ($meta['timeline'] ?? 90),
        ];
        $bridge = new \App\Support\GeoAudit\GeoTaskBridge();
        $picked = $request->query('picked');

        // 阶段一：方案里没有结构化任务清单 → 退化为单个总任务
        if ($tasks === []) {
            $result = $bridge->createDraft($planCtx, $admin);
            $inner = $result['ok']
                ? '<div class="g-card"><h3>任务草稿已创建</h3><p class="g-cap">'.htmlspecialchars((string) ($result['name'] ?? ''), ENT_QUOTES, 'UTF-8').'</p>'
                    .'<div class="g-row"><a class="g-btn g-btn--primary" href="'.$adminBase.'/tasks/'.(int) $result['task_id'].'/edit">去补充参数并启动</a>'
                    .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/tasks">任务列表</a></div></div>'
                : '<div class="g-card"><h3>未能自动创建</h3><div class="g-err">'.htmlspecialchars((string) ($result['error'] ?? ''), ENT_QUOTES, 'UTF-8').'</div>'
                    .(! empty($result['fallback_url']) ? '<a class="g-btn g-btn--primary" href="'.htmlspecialchars((string) $result['fallback_url'], ENT_QUOTES, 'UTF-8').'">打开预填好的任务表单</a>' : '')
                    .'</div>';

            return $toolView($inner, '创建任务草稿');
        }

        // 阶段二：展示可勾选清单
        if ($picked === null) {
            $rows = '';
            foreach ($tasks as $i => $t) {
                $pri = (string) ($t['priority'] ?? 'P1');
                $priColor = $pri === 'P0' ? '#b91c1c' : ($pri === 'P1' ? '#b45309' : '#4b5563');
                $rows .= '<tr>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 10px;width:34px;text-align:center">'
                    .'<input type="checkbox" class="geo-task-pick" value="'.$i.'" checked style="accent-color:#4f46e5"></td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 10px">'.htmlspecialchars((string) $t['name'], ENT_QUOTES, 'UTF-8').'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 10px;white-space:nowrap;color:'.$priColor.';font-weight:700">'.$pri.'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 10px;white-space:nowrap">'.htmlspecialchars((string) ($t['owner'] ?? ''), ENT_QUOTES, 'UTF-8').'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 10px;white-space:nowrap">'.(int) ($t['estimate_hours'] ?? 0).' h</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 10px;color:#6b7280">'.htmlspecialchars((string) ($t['depends_on'] ?? ''), ENT_QUOTES, 'UTF-8').'</td>'
                    .'</tr>';
            }

            $inner = '<div class="g-card"><h3>按方案创建任务草稿</h3>'
                .'<p class="g-cap">筛选你要执行的任务，系统会为每条任务建一个「暂停」状态的 GEOFlow 任务，参数可再进去微调</p>'
                .'<table style="width:100%;border-collapse:collapse;font-size:12.5px"><thead><tr>'
                .'<th style="border:1px solid #e5e7eb;padding:9px 10px;background:#f9fafb"><input type="checkbox" id="geo-task-all" checked style="accent-color:#4f46e5"></th>'
                .'<th style="border:1px solid #e5e7eb;padding:9px 10px;background:#f9fafb;text-align:left">任务</th>'
                .'<th style="border:1px solid #e5e7eb;padding:9px 10px;background:#f9fafb;text-align:left">优先级</th>'
                .'<th style="border:1px solid #e5e7eb;padding:9px 10px;background:#f9fafb;text-align:left">负责角色</th>'
                .'<th style="border:1px solid #e5e7eb;padding:9px 10px;background:#f9fafb;text-align:left">工时</th>'
                .'<th style="border:1px solid #e5e7eb;padding:9px 10px;background:#f9fafb;text-align:left">依赖</th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table>'
                .'<div class="g-row" style="margin-top:16px">'
                .'<a class="g-btn g-btn--primary" id="geo-task-create" href="'.$adminBase.'/geo-plan/run-task?id='.$id.'&picked=all">按勾选创建任务草稿</a>'
                .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/geo-plan-run-task-back" style="display:none"></a>'
                .'<span class="g-cap" style="margin:0">创建后均为 paused 状态，不会自动开始生产</span></div>'
                .'<script>(function(){'
                .'var all=document.getElementById("geo-task-all");var boxes=document.querySelectorAll(".geo-task-pick");'
                .'var btn=document.getElementById("geo-task-create");'
                .'function sync(){var ids=[];for(var i=0;i<boxes.length;i++){if(boxes[i].checked){ids.push(boxes[i].value);}}'
                .'btn.setAttribute("href","'.$adminBase.'/geo-plan/run-task?id='.$id.'&picked="+(ids.join(",")||"none"));'
                .'btn.style.opacity=ids.length?"1":".5";}'
                .'if(all){all.addEventListener("change",function(){for(var i=0;i<boxes.length;i++){boxes[i].checked=all.checked;}sync();});}'
                .'for(var i=0;i<boxes.length;i++){boxes[i].addEventListener("change",sync);}'
                .'sync();})();</script></div>';

            return $toolView($inner, '创建任务草稿');
        }

        // 阶段三：按勾选批量创建
        $selected = [];
        if ((string) $picked !== 'none') {
            foreach (explode(',', (string) $picked) as $rawIndex) {
                $idx = (int) trim($rawIndex);
                if (isset($tasks[$idx])) {
                    $selected[] = $tasks[$idx];
                }
            }
        }

        if ($selected === []) {
            return $toolView('<div class="g-card"><h3>没有选中任何任务</h3>'
                .'<p class="g-cap">请返回上一步勾选要执行的任务。</p>'
                .'<a class="g-btn g-btn--primary" href="'.$adminBase.'/geo-plan/run-task?id='.$id.'">返回选择</a></div>', '创建任务草稿');
        }

        $batch = $bridge->createFromTasks($selected, $planCtx, $admin);

        $okRows = '';
        foreach ($batch['created'] as $c) {
            $okRows .= '<tr><td style="border:1px solid #e5e7eb;padding:9px 10px">'.htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 10px;white-space:nowrap">'.$c['priority'].'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 10px"><a href="'.$adminBase.'/tasks/'.$c['task_id'].'/edit">任务 #'.$c['task_id'].'</a></td></tr>';
        }
        $failRows = '';
        foreach ($batch['failed'] as $f) {
            $failRows .= '<li>'.htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8').'：'.htmlspecialchars($f['error'], ENT_QUOTES, 'UTF-8').'</li>';
        }

        $inner = '<div class="g-card"><h3>已创建 '.count($batch['created']).' 个任务草稿</h3>'
            .'<p class="g-cap">全部为 paused 状态，进入任务页确认参数后再启动</p>'
            .($okRows !== '' ? '<table style="width:100%;border-collapse:collapse;font-size:12.5px"><thead><tr>'
                .'<th style="border:1px solid #e5e7eb;padding:9px 10px;background:#f9fafb;text-align:left">任务</th>'
                .'<th style="border:1px solid #e5e7eb;padding:9px 10px;background:#f9fafb;text-align:left">优先级</th>'
                .'<th style="border:1px solid #e5e7eb;padding:9px 10px;background:#f9fafb;text-align:left">GEOFlow 任务</th>'
                .'</tr></thead><tbody>'.$okRows.'</tbody></table>' : '')
            .($failRows !== '' ? '<div class="g-err" style="margin-top:14px"><b>以下任务未创建：</b><ul style="margin:6px 0 0;padding-left:18px">'.$failRows.'</ul></div>' : '')
            .'<div class="g-row" style="margin-top:16px">'
            .'<a class="g-btn g-btn--primary" href="'.$adminBase.'/tasks">去任务列表</a>'
            .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/geo-plan/run-task?id='.$id.'">再次选择</a>'
            .'<a class="g-btn g-btn--soft" href="'.$adminBase.'/geo-audit?view='.$id.'">回到方案</a></div></div>';

        return $toolView($inner, '创建任务草稿');
    })->name('geo-plan.run-task');
});

// ==========================================================================
// 知识库批量导入（本地扩展）：多 URL → 抓取清洗合并 → 下载 md → 走官方知识库入口
// ==========================================================================
Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale', 'admin.auth'])->group(function (): void {
    Route::get('kb-import', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $toolView = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_audit',
            'toolHtml' => $html,
        ]);

        // 下载上一次合并结果
        if ((string) $request->query('download', '') === '1') {
            $last = $request->hasSession() ? $request->session()->get('geo_kb_last') : null;
            if (! is_array($last) || empty($last['markdown'])) {
                return response('还没有可下载的资料，请先抓取一次。', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
            }

            return response((string) $last['markdown'], 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="geo-kb-'.date('Ymd-His').'.md"',
            ]);
        }

        $old = [
            'urls' => (string) ($request->query('urls') ?? ''),
            'title' => (string) ($request->query('title') ?? ''),
        ];

        if ((string) $request->query('run', '') !== '1') {
            return $toolView(\App\Support\GeoAudit\GeoKbReport::formPage($old), '批量导入资料');
        }

        $urls = preg_split('/[\r\n]+/u', $old['urls']) ?: [];
        $urls = array_values(array_filter(array_map('trim', $urls), static fn (string $u): bool => $u !== ''));
        if ($urls === []) {
            return $toolView(\App\Support\GeoAudit\GeoKbReport::formPage($old, '请至少填写一个网址'), '批量导入资料');
        }

        try {
            $result = (new \App\Support\GeoAudit\GeoKbBuilder())->build($urls, ['title' => $old['title']]);
            if (! ($result['ok'] ?? false)) {
                return $toolView(\App\Support\GeoAudit\GeoKbReport::formPage($old, '全部抓取失败：可能是纯 JS 渲染页面，或站点拒绝抓取'), '批量导入资料');
            }
            if ($request->hasSession()) {
                $request->session()->put('geo_kb_last', $result);
            }

            return $toolView(\App\Support\GeoAudit\GeoKbReport::resultPage($result, $adminBase), '批量导入资料');
        } catch (\Throwable $e) {
            return $toolView(\App\Support\GeoAudit\GeoKbReport::formPage($old, '抓取异常：'.$e->getMessage()), '批量导入资料');
        }
    })->name('kb-import');
});

// ==========================================================================
// 关键词挖掘（本地扩展）：AI 意图挖掘 + 百度联想词 → 写入关键词库
// ==========================================================================
Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale', 'admin.auth'])->group(function (): void {
    Route::get('keyword-miner', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $toolView = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_audit',
            'toolHtml' => $html,
        ]);
        $libraries = \Illuminate\Support\Facades\DB::table('keyword_libraries')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->all();

        // ① 写入关键词库
        if ((string) $request->query('save', '') === '1') {
            $last = $request->hasSession() ? $request->session()->get('geo_kw_last') : null;
            $picked = (string) ($request->query('picked') ?? 'none');
            $libId = (int) ($request->query('lib') ?? 0);
            if (! is_array($last) || empty($last['flat']) || $libId <= 0) {
                return $toolView(\App\Support\GeoAudit\GeoKeywordReport::formPage([], $libraries, '会话已过期或未选择词库，请重新挖掘'), '关键词挖掘');
            }
            $flat = (array) $last['flat'];
            $words = [];
            if ($picked !== 'none') {
                foreach (explode(',', $picked) as $raw) {
                    $i = (int) trim($raw);
                    if (isset($flat[$i])) {
                        $words[] = (string) $flat[$i];
                    }
                }
            }
            if ($words === []) {
                return $toolView(\App\Support\GeoAudit\GeoKeywordReport::formPage([], $libraries, '没有选中任何关键词'), '关键词挖掘');
            }

            $rows = [];
            foreach ($words as $w) {
                $rows[] = ['library_id' => $libId, 'keyword' => mb_substr($w, 0, 200, 'UTF-8'), 'created_at' => now()];
            }
            $inserted = \Illuminate\Support\Facades\DB::table('keywords')->insertOrIgnore($rows);
            $libName = (string) (\Illuminate\Support\Facades\DB::table('keyword_libraries')->where('id', $libId)->value('name') ?? '');

            return $toolView(\App\Support\GeoAudit\GeoKeywordReport::savedPage($words, (int) $inserted, count($words) - (int) $inserted, $libName, $adminBase), '关键词已入库');
        }

        $old = [
            'industry' => (string) ($request->query('industry') ?? ''),
            'brand' => (string) ($request->query('brand') ?? ''),
            'audience' => (string) ($request->query('audience') ?? ''),
            'seed' => (string) ($request->query('seed') ?? ''),
            'collect' => (string) ($request->query('collect') ?? '1'),
        ];

        // ② 执行挖掘
        if ((string) $request->query('run', '') !== '1') {
            return $toolView(\App\Support\GeoAudit\GeoKeywordReport::formPage($old, $libraries), '关键词挖掘');
        }

        try {
            $miner = new \App\Support\GeoAudit\GeoKeywordMiner();
            $result = $miner->expand($old);
            $suggests = [];
            if ($old['collect'] === '1' && $result['seeds'] !== []) {
                $suggests = $miner->baiduSuggest($result['seeds']);
            }

            // 合并去重：问题在前，联想词在后
            $flat = [];
            $seen = [];
            foreach ($result['questions'] as $q) {
                $w = trim($q['question']);
                if ($w !== '' && ! isset($seen[$w])) {
                    $seen[$w] = true;
                    $flat[] = $w;
                }
            }
            foreach ($suggests as $words) {
                foreach ($words as $w) {
                    $w = trim((string) $w);
                    if ($w !== '' && ! isset($seen[$w])) {
                        $seen[$w] = true;
                        $flat[] = $w;
                    }
                }
            }

            $payload = $result + ['suggests' => $suggests, 'flat' => array_slice($flat, 0, 120)];
            if ($request->hasSession()) {
                $request->session()->put('geo_kw_last', $payload);
            }

            return $toolView(\App\Support\GeoAudit\GeoKeywordReport::resultPage($payload, $libraries, $adminBase), '关键词挖掘结果');
        } catch (\Throwable $e) {
            return $toolView(\App\Support\GeoAudit\GeoKeywordReport::formPage($old, $libraries, $e->getMessage()), '关键词挖掘');
        }
    })->name('keyword-miner');
});

// ==========================================================================
// 覆盖核查（本地扩展）：问题 → AI 逐题作答 → 规则判定有没有你 → 未覆盖问题建任务
//
// 分四段请求，不是一个请求干完，原因：
//   12 题串行问模型要 60–100 秒。拆成「清单页 + 逐题 fetch(?probe=1)」后，
//   单次请求最长 8 秒左右，既不撞超时，用户也能一题一题看到结果出来。
// ==========================================================================
Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale', 'admin.auth'])->group(function (): void {
    Route::get('coverage-check', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $toolView = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_coverage',
            'toolHtml' => $html,
        ]);
        $sess = $request->hasSession() ? $request->session() : null;

        // ---------- ① 逐题核查（AJAX，返回 JSON）----------
        if ((string) $request->query('probe', '') === '1') {
            $ctx = $sess?->get('geo_cov_ctx');
            $qs = $sess?->get('geo_cov_questions');
            $i = (int) ($request->query('i') ?? -1);

            if (! is_array($ctx) || ! is_array($qs) || ! isset($qs[$i])) {
                return response()->json(['error' => '会话已过期，请返回重新发起核查', 'covered' => false]);
            }

            $row = (new \App\Support\GeoAudit\GeoCoverageCheck())->probe(
                (string) $qs[$i],
                (array) ($ctx['brands'] ?? []),
                (array) ($ctx['domains'] ?? []),
                (array) ($ctx['competitors'] ?? []),
                (int) ($ctx['rounds'] ?? 1),
            );

            $results = (array) ($sess?->get('geo_cov_results') ?? []);
            $results[$i] = $row;
            $sess?->put('geo_cov_results', $results);

            return response()->json(array_merge($row, ['i' => $i]));
        }

        // ---------- ② 未覆盖问题 → 任务草稿 ----------
        if ((string) $request->query('to-tasks', '') === '1') {
            $ctx = (array) ($sess?->get('geo_cov_ctx') ?? []);
            $results = (array) ($sess?->get('geo_cov_results') ?? []);
            $admin = auth('admin')->user();
            if ($ctx === [] || $results === [] || $admin === null) {
                return $toolView(\App\Support\GeoAudit\GeoCoverageReport::formPage([], [], '会话已过期，请重新核查'), '覆盖核查');
            }

            // 报告页里 checkbox 的 value 是「内容缺口清单」内的序号，
            // 顺序必须与 GeoCoverageReport::reportPage 完全一致。
            // 单一来源：GeoCoverageReport::todoList()，两边都调它，不再各写一份排序逻辑。
            // 第二个参数是当前品牌：用来把题面回 keywords 表判类别，把 A 类挡在内容任务外面。
            $coverageBrand = (string) ($ctx['brand'] ?? '');
            $todo = \App\Support\GeoAudit\GeoCoverageReport::todoList($results, $coverageBrand);

            // ---- picked 解析：只认纯数字索引，all 表示全选 ----
            // 不能直接用 (int) 转：`abc`、`0x`、`1x` 都会被转成 0，
            // 于是"手抖多打一个字符"就静默建出第 1 题的任务草稿（垃圾数据）。
            // 非法 token 一律丢弃但<b>记下来</b>，事后在结果页告诉用户忽略了什么；
            // 一个合法 token 都没有就走"没有选中"的分支，不写任何记录。
            $pickedRaw = (string) ($request->query('picked', 'none'));
            $pickedIndexes = [];
            $pickAll = false;
            $invalidTokens = [];
            foreach (explode(',', $pickedRaw) as $raw) {
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }
                // 静态兜底 href 用的就是 picked=all，这里必须显式支持，否则它会落成 0
                if (strtolower($raw) === 'all') {
                    $pickAll = true;
                    continue;
                }
                if (preg_match('/^\d+$/', $raw) === 1) {
                    $pickedIndexes[] = (int) $raw;
                } else {
                    $invalidTokens[] = $raw;
                }
            }
            $pickedIndexes = array_values(array_unique($pickedIndexes));

            $selected = [];
            $outOfRange = [];
            if ($pickAll) {
                foreach ($todo as $row) {
                    $selected[] = (string) $row['question'];
                }
            } else {
                foreach ($pickedIndexes as $n) {
                    if (isset($todo[$n])) {
                        $selected[] = (string) $todo[$n]['question'];
                    } else {
                        // 格式合法但清单里没这个序号（例如 picked=99），同样要告诉用户
                        $outOfRange[] = (string) $n;
                    }
                }
            }

            // ---- 防御：A 类（品牌认知）不进内容任务 ----
            // 正常情况下 todoList() 已经用同一个判定过滤过，这里走不到。
            // 它兜的是两种"清单和勾选时看到的不一样"的情况：
            //   ① 页面渲染之后、点按钮之前，这道题在关键词库里被改标成 A 类；
            //   ② 将来有人改 todoList() 时不小心把这类过滤去掉或改了条件。
            // 一旦错位到 A 类，静默产出一篇"抢媒体位"的软文任务，比多一条提示糟糕得多——
            // 用户从结果页看不出来写错了方向（标题看着都通顺）。
            // 注意顺序：索引到题目的映射已经在上面完成，这里只按题面过滤，
            // 不会挪动 picked 的编号语义。
            $authoritySkippedQuestions = [];
            $selectedForTask = [];
            foreach ($selected as $questionText) {
                if (\App\Support\GeoAudit\GeoCoverageReport::isAuthorityQuestion($questionText, $coverageBrand)) {
                    $authoritySkippedQuestions[] = $questionText;
                    continue;
                }
                $selectedForTask[] = $questionText;
            }
            $selected = $selectedForTask;

            // A 类被挡下时单独的说明：这不是"题号错了"，是"这类题不该写文章"
            $authorityNotice = '';
            if ($authoritySkippedQuestions !== []) {
                $authorityNotice = count($authoritySkippedQuestions).' 道题属于品牌认知类（A 类），已跳过、没有生成内容任务：'
                    .implode('、', array_map(
                        static fn ($t): string => htmlspecialchars(mb_substr((string) $t, 0, 24, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        array_slice($authoritySkippedQuestions, 0, 3)
                    )).(count($authoritySkippedQuestions) > 3 ? ' 等' : '')
                    .'。这类题缺位时抢走位置的是媒体和百科（不是竞品），要补的是权威信源，'
                    .'不是按这个标题写软文——请到报告页的「信源缺口」看。';
            }

            if ($selected === []) {
                $hint = ($pickedRaw !== '' && $pickedRaw !== 'none')
                    ? '<p class="g-cap">链接里的 picked 参数没有解析出任何有效题号'
                        .'（只接受待补清单的数字序号，或 all 表示全选；收到的是 '
                        .htmlspecialchars($pickedRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'），'
                        .'<b>没有创建任何记录</b>。</p>'
                    : '';

                return $toolView('<div class="g-card"><h3>没有选中任何问题</h3>'
                    .($authorityNotice !== ''
                        ? '<div class="g-err" style="background:#fffbeb;border-color:#fde68a;color:#b45309">'.$authorityNotice.'</div>'
                        : '')
                    .$hint
                    .'<p class="g-cap">请回报告页勾选要生成内容的待补问题。</p>'
                    .'<a class="g-btn g-btn--primary" href="'.$adminBase.'/coverage-check?report=1">返回核查报告</a></div>', '生成任务草稿');
            }

            // 部分 token 被丢弃时必须说清楚，否则用户"选了 3 题怎么只建了 2 题"无从得知。
            // 两种丢弃要分开讲，因为对用户意味着不同的事：
            //   · abc / 1x → 格式非法，根本解析不出数字（多半是手抖打错）
            //   · 99       → 格式合法但清单里没有这个序号（多半是记错题号）
            // 合并成"无法识别"会让他以为自己打错了，其实是他记错了。
            $pickedNotice = '';
            if (($invalidTokens !== [] || $outOfRange !== []) && ! $pickAll) {
                $tokenText = static function (array $tokens): string {
                    $shown = array_slice($tokens, 0, 3);

                    return implode('、', array_map(
                        static fn ($t): string => htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        $shown
                    )).(count($tokens) > 3 ? ' 等' : '');
                };

                if ($invalidTokens !== [] && $outOfRange !== []) {
                    $pickedNotice = '链接里有 '.(count($invalidTokens) + count($outOfRange)).' 个题号被忽略：'
                        .count($invalidTokens).' 个无法识别（'.$tokenText($invalidTokens).'），'
                        .count($outOfRange).' 个超出清单范围（'.$tokenText($outOfRange).'）。';
                } elseif ($invalidTokens !== []) {
                    $pickedNotice = '链接里有 '.count($invalidTokens).' 个无法识别的题号（'
                        .$tokenText($invalidTokens).'），已忽略。';
                } else {
                    $pickedNotice = '链接里有 '.count($outOfRange).' 个题号超出本次清单范围（'
                        .$tokenText($outOfRange).'），已忽略。';
                }

                $pickedNotice .= '下面只处理了 '.count($selected).' 个有效题号。';
            }

            // A 类被挡下的说明放最前面：它改变的是"这批任务对不对"，比题号笔误更重要
            if ($authorityNotice !== '') {
                $pickedNotice = $authorityNotice.($pickedNotice !== '' ? ' '.$pickedNotice : '');
            }

            $result = (new \App\Support\GeoAudit\GeoTaskBridge())->createCoverageTask($selected, [
                'brand' => (string) ($ctx['brand'] ?? ''),
                'url' => (string) ($ctx['url'] ?? ''),
            ], $admin);

            return $toolView(\App\Support\GeoAudit\GeoCoverageReport::taskDonePage($result, $adminBase, $pickedNotice), '生成任务草稿');
        }

        // ---------- ③ 报告页 ----------
        if ((string) $request->query('report', '') === '1') {
            $ctx = (array) ($sess?->get('geo_cov_ctx') ?? []);
            $qs = (array) ($sess?->get('geo_cov_questions') ?? []);
            $results = (array) ($sess?->get('geo_cov_results') ?? []);
            if ($ctx === [] || $qs === []) {
                return $toolView(\App\Support\GeoAudit\GeoCoverageReport::formPage([], [], '会话已过期，请重新发起核查'), '覆盖核查');
            }
            // 有题没跑（中途关页面/刷新）→ 补一条"未核查"记录，
            // 不能直接忽略：否则报告里的"核查题数"会小于用户以为跑过的题数，属于误导。
            $rows = [];
            foreach ($qs as $i => $q) {
                $rows[$i] = $results[$i] ?? [
                    'question' => (string) $q,
                    'covered' => false,
                    'error' => '未核查（页面中途关闭或刷新导致中断）',
                ];
            }
            ksort($rows);

            return $toolView(\App\Support\GeoAudit\GeoCoverageReport::reportPage($ctx, array_values($rows), $adminBase), '覆盖核查报告');
        }

        // ---------- ④ 发起核查：整理清单 ----------
        $old = [
            'brand' => (string) ($request->query('brand') ?? ''),
            'url' => (string) ($request->query('url') ?? ''),
            'competitors' => (string) ($request->query('competitors') ?? ''),
            'questions' => (string) ($request->query('questions') ?? ''),
            'lib' => (int) ($request->query('lib') ?? 0),
            'limit' => (int) ($request->query('limit') ?? 12),
            'rounds' => (int) ($request->query('rounds') ?? 3),
        ];
        $libraries = \Illuminate\Support\Facades\DB::table('keyword_libraries')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->all();

        if ((string) $request->query('plan', '') !== '1') {
            return $toolView(\App\Support\GeoAudit\GeoCoverageReport::formPage($old, $libraries), '覆盖核查');
        }

        $brands = \App\Support\GeoAudit\GeoCoverageCheck::parseList($old['brand']);
        if ($brands === []) {
            return $toolView(\App\Support\GeoAudit\GeoCoverageReport::formPage($old, $libraries, '请填写品牌或产品名'), '覆盖核查');
        }

        // 检索层必须知道「哪个域名算你自己的」，否则无法认领推荐位
        $domain = \App\Support\GeoAudit\GeoCoverageCheck::domainOf($old['url']);
        if (($domain['keys'] ?? []) === []) {
            return $toolView(\App\Support\GeoAudit\GeoCoverageReport::formPage($old, $libraries, '请填写自有站点 URL（例如 https://www.binsis.cn/），否则无法判断推荐位里哪条是你的'), '覆盖核查');
        }

        $limit = max(4, min(20, (int) $old['limit']));
        $raw = [];

        // 词库取词：问句优先（覆盖核查要的是"用户会怎么问"，不是词根）
        if ((int) $old['lib'] > 0) {
            $words = \Illuminate\Support\Facades\DB::table('keywords')
                ->where('library_id', (int) $old['lib'])
                ->orderBy('id')
                ->limit(600)
                ->pluck('keyword')
                ->all();
            $ask = [];
            $rest = [];
            foreach ($words as $w) {
                $w = trim((string) $w);
                if ($w === '') {
                    continue;
                }
                if (preg_match('/[?？]|怎么|怎样|如何|哪个|哪种|哪家|多少|为什么|能不能|可不可以|是否|有没有|推荐|适合|值得/u', $w)) {
                    $ask[] = $w;
                } else {
                    $rest[] = $w;
                }
            }
            $raw = array_merge($ask, $rest);
        }

        foreach (preg_split('/[\r\n]+/u', $old['questions']) ?: [] as $line) {
            $raw[] = $line;
        }

        $questions = array_slice(\App\Support\GeoAudit\GeoCoverageCheck::normalizeQuestions($raw), 0, $limit);
        if ($questions === []) {
            return $toolView(\App\Support\GeoAudit\GeoCoverageReport::formPage($old, $libraries, '没有可核查的问题：请选一个关键词库，或在下面手填问题'), '覆盖核查');
        }

        $ctx = [
            'brand' => $old['brand'],
            'brands' => $brands,
            'url' => $old['url'],
            'domains' => $domain['keys'],
            'rounds' => in_array((int) $old['rounds'], [1, 3, 5], true) ? (int) $old['rounds'] : 3,
            'competitors' => \App\Support\GeoAudit\GeoCoverageCheck::parseList($old['competitors']),
        ];
        $sess?->put('geo_cov_ctx', $ctx);
        $sess?->put('geo_cov_questions', $questions);
        $sess?->put('geo_cov_results', []);

        return $toolView(\App\Support\GeoAudit\GeoCoverageReport::runPage($ctx, $questions, $adminBase), '覆盖核查进行中');
    })->name('coverage-check');
});

// ==========================================================================
// 内容工坊（本地扩展）：旧文改造 + 科普生成
//
// 两个 P0 Skill 合一：yao-geo-content-refiner / yao-geo-explainer-builder
// 表单用 POST 而不是 GET —— 原文可能有几千字，GET 会撞 nginx 的 URL 长度上限。
// ==========================================================================
Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale', 'admin.auth'])->group(function (): void {
    Route::match(['get', 'post'], 'content-studio', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $toolView = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_studio',
            'toolHtml' => $html,
        ]);
        $sess = $request->hasSession() ? $request->session() : null;

        $mode = (string) ($request->input('mode') ?? 'refine');
        if (! isset(\App\Support\GeoAudit\GeoContentStudio::MODES[$mode])) {
            $mode = 'refine';
        }

        // ---------- ① 下载指定平台版本 ----------
        if ((string) $request->query('download', '') === '1') {
            $slug = trim((string) ($request->query('p') ?? ''));
            $batch = (array) ($sess?->get('geo_cs_batch_results') ?? []);
            $item = null;

            if ($slug !== '' && isset($batch[$slug]) && is_array($batch[$slug])) {
                $item = $batch[$slug];
            } elseif (is_array($sess?->get('geo_cs_last'))) {
                $item = $sess->get('geo_cs_last');
                $slug = (string) ($item['platform']['slug'] ?? 'content');
            }

            if (! is_array($item) || trim((string) ($item['markdown'] ?? '')) === '') {
                return $toolView(
                    \App\Support\GeoAudit\GeoContentStudioReport::formPage(['mode' => $mode], '还没有生成过内容，请先运行一次'),
                    '内容工坊'
                );
            }

            $safe = preg_replace('/[^a-z0-9_]/', '', $slug) ?: 'content';
            $filename = 'geo-'.$safe.'-'.date('Ymd-His').'.md';

            return response((string) $item['markdown'], 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        // ---------- ② 逐平台生成（AJAX，返回 JSON）----------
        if ((string) $request->query('one', '') === '1') {
            $ctx = $sess?->get('geo_cs_batch_ctx');
            $queue = $sess?->get('geo_cs_batch_queue');
            $i = (int) ($request->query('p') ?? -1);

            if (! is_array($ctx) || ! is_array($queue) || ! isset($queue[$i])) {
                return response()->json(['error' => '会话已过期，请返回重新提交']);
            }

            $slug = (string) ($queue[$i]['slug'] ?? '');
            $name = (string) ($queue[$i]['name'] ?? $slug);

            $opts = $ctx;
            $opts['platform'] = $slug;
            unset($opts['platforms']);

            try {
                $studio = new \App\Support\GeoAudit\GeoContentStudio();
                $result = ($ctx['mode'] ?? 'refine') === 'refine'
                    ? $studio->refine((string) ($ctx['raw'] ?? ''), $opts)
                    : $studio->explain((string) ($ctx['topic'] ?? ''), $opts);

                $results = (array) ($sess?->get('geo_cs_batch_results') ?? []);
                $results[$slug] = $result;
                $sess?->put('geo_cs_batch_results', $results);

                // 【发布追踪】产出自动进内容资产台账，否则关了页面就没东西可追踪了
                $batchId = (string) ($sess?->get('geo_cs_batch_id') ?? '');
                if ($batchId === '') {
                    $batchId = date('YmdHis');
                    $sess?->put('geo_cs_batch_id', $batchId);
                }
                $assetId = 0;
                try {
                    $assetId = (new \App\Support\GeoAudit\GeoPublish())->saveAsset($result, $ctx, $batchId);
                    $result['asset_id'] = $assetId;
                    $results[$slug]['asset_id'] = $assetId;
                    $sess?->put('geo_cs_batch_results', $results);
                } catch (\Throwable) {
                    // 落库失败不影响生成结果
                }

                $errs = (array) ($sess?->get('geo_cs_batch_errors') ?? []);
                unset($errs[$slug]);
                $sess?->put('geo_cs_batch_errors', $errs);
                $sess?->put('geo_cs_last', $result);

                return response()->json([
                    'ok' => true,
                    'slug' => $slug,
                    'name' => $name,
                    'asset_id' => $assetId,
                    'platform_check' => $result['platform_check'] ?? [],
                    'scoring_total' => $result['scoring']['total'] ?? null,
                    'scoring_grade' => $result['scoring']['grade'] ?? '',
                    'stats' => $result['stats'] ?? [],
                ]);
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 200, 'UTF-8');
                $errs = (array) ($sess?->get('geo_cs_batch_errors') ?? []);
                $errs[$slug] = $msg;
                $sess?->put('geo_cs_batch_errors', $errs);

                return response()->json(['error' => $msg]);
            }
        }

        // ---------- ③ 批量结果页（tab 切换各平台版本）----------
        if ((string) $request->query('result', '') === '1') {
            $ctx = (array) ($sess?->get('geo_cs_batch_ctx') ?? []);
            $queue = (array) ($sess?->get('geo_cs_batch_queue') ?? []);
            $results = (array) ($sess?->get('geo_cs_batch_results') ?? []);
            $errors = (array) ($sess?->get('geo_cs_batch_errors') ?? []);

            if ($ctx === [] || $queue === []) {
                return $toolView(
                    \App\Support\GeoAudit\GeoContentStudioReport::formPage(['mode' => $mode], '会话已过期，请重新提交'),
                    '内容工坊'
                );
            }
            if ($results === [] && $errors === []) {
                return $toolView(
                    \App\Support\GeoAudit\GeoContentStudioReport::formPage(['mode' => $mode], '还没有任何结果，请先运行一次'),
                    '内容工坊'
                );
            }

            return $toolView(
                \App\Support\GeoAudit\GeoContentStudioReport::batchReportPage($ctx, $queue, $results, $errors, $adminBase),
                '一文多发结果'
            );
        }

        // ---------- ④ 提交：整理生成队列 ----------
        $old = [
            'mode' => $mode,
            'platforms' => array_values((array) ($request->input('platforms') ?? [])),
            'platform' => (string) ($request->input('platform') ?? ''),
            'model_id' => (int) ($request->input('model_id') ?? 0),
            'brand' => (string) ($request->input('brand') ?? ''),
            'industry' => (string) ($request->input('industry') ?? ''),
            'audience' => (string) ($request->input('audience') ?? ''),
            'angle' => (string) ($request->input('angle') ?? ''),
            'topic' => (string) ($request->input('topic') ?? ''),
            'raw' => (string) ($request->input('raw') ?? ''),
        ];

        // 这一步是"提交生成请求"的分界线。
        // 同时认 plan=1 和 run=1：前者是四段式改造后的新参数，后者是旧表单遗留，
        // 只认一个的话，表单提交会静默退回表单页（页面看着"没反应"）。
        $isSubmit = (string) $request->input('plan', '') === '1'
            || (string) $request->input('run', '') === '1';

        if (! $isSubmit) {
            return $toolView(\App\Support\GeoAudit\GeoContentStudioReport::formPage($old), '内容工坊');
        }

        $platforms = \App\Support\GeoAudit\GeoPlatforms::all();
        $picked = [];
        foreach ($old['platforms'] as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '' && isset($platforms[$slug])) {
                $picked[$slug] = (string) ($platforms[$slug]['name'] ?? $slug);
            }
        }

        if ($picked === []) {
            return $toolView(
                \App\Support\GeoAudit\GeoContentStudioReport::formPage($old, '请至少勾选一个目标平台'),
                '内容工坊'
            );
        }

        if ($mode === 'refine' && mb_strlen(trim($old['raw']), 'UTF-8') < 120) {
            return $toolView(
                \App\Support\GeoAudit\GeoContentStudioReport::formPage($old, '原文太短（少于 120 字），没有改造价值'),
                '内容工坊'
            );
        }
        if ($mode === 'explain' && mb_strlen(trim($old['topic']), 'UTF-8') < 4) {
            return $toolView(
                \App\Support\GeoAudit\GeoContentStudioReport::formPage($old, '请填写主题或用户问题（至少 4 个字）'),
                '内容工坊'
            );
        }

        $queue = [];
        foreach ($picked as $slug => $name) {
            $queue[] = ['slug' => $slug, 'name' => $name];
        }

        $sess?->put('geo_cs_batch_ctx', $old);
        $sess?->put('geo_cs_batch_queue', $queue);
        $sess?->put('geo_cs_batch_results', []);
        $sess?->put('geo_cs_batch_errors', []);

        return $toolView(
            \App\Support\GeoAudit\GeoContentStudioReport::batchRunPage($old, $queue, $adminBase),
            '批量生成中'
        );
    })->name('content-studio');

    // =========================================================================
    // 【本地扩展】一键建库：一次给品牌建齐 A/B/C/D 四个问题库
    // 官方 store 只建一个库，每加一个客户要重复建四次，太啰嗦。
    // =========================================================================
    Route::post('keyword-libraries/batch-create', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

        $brand = trim((string) $request->input('brand', ''));
        $choice = trim((string) $request->input('category', 'ALL'));
        $labels = ['A' => '品牌认知', 'B' => '品类场景', 'C' => '产品深挖', 'D' => '竞品对比'];

        if ($brand === '') {
            return redirect($adminBase.'/keyword-libraries/create')->with('message', '请先选择品牌');
        }

        // ALL = 一次建四个；具体字母 = 只建那一个
        $cats = strtoupper($choice) === 'ALL' ? ['A', 'B', 'C', 'D'] : [strtoupper($choice)];
        $cats = array_values(array_filter($cats, static fn ($c) => isset($labels[$c])));
        if ($cats === []) {
            return redirect($adminBase.'/keyword-libraries/create')->with('message', '请选择要创建的问题类别');
        }

        $created = [];
        $skipped = [];
        try {
            foreach ($cats as $c) {
                $name = $brand.'｜'.$c.' '.$labels[$c];
                $exists = \Illuminate\Support\Facades\DB::table('keyword_libraries')->where('name', $name)->first();
                if ($exists !== null) {
                    $skipped[] = $name;
                    continue;
                }
                \Illuminate\Support\Facades\DB::table('keyword_libraries')->insert([
                    'name' => $name,
                    'description' => $brand.' 的'.$labels[$c].'问题库（用户在 AI 里会这样问）',
                    'keyword_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created[] = $name;
            }
        } catch (\Throwable $e) {
            return redirect($adminBase.'/keyword-libraries')->with('message', '创建失败：'.mb_substr($e->getMessage(), 0, 80, 'UTF-8'));
        }

        $msg = $created === []
            ? '这些库都已经存在了（'.$brand.' 的 A/B/C/D）'
            : '已创建 '.count($created).' 个库：'.implode('、', $created)
                .($skipped !== [] ? '；已存在跳过 '.count($skipped).' 个' : '');

        return redirect($adminBase.'/keyword-libraries')->with('message', $msg);
    })->name('keyword-libraries.batch-create');

    // 在新建/编辑文章页注入「目标平台 + 按平台生成」区块（不改官方 1600 行的模板）
    \Illuminate\Support\Facades\Event::listen(
        \Illuminate\Foundation\Http\Events\RequestHandled::class,
        static function ($event): void {
            try {
                $req = $event->request;
                $res = $event->response;
                if (! $req->isMethod('get') || ! method_exists($res, 'getContent')) {
                    return;
                }
                $html = $res->getContent();
                if (! is_string($html) || $html === '') {
                    return;
                }
                if (! \App\Support\GeoAudit\GeoArticleAiInject::shouldInject($req->path(), $html)) {
                    return;
                }
                $aid = 0;
                if (preg_match('#/articles/(\d+)/edit$#', $req->path(), $mm)) {
                    $aid = (int) $mm[1];
                }
                // 新建页 aid 保持 0 → 注入评分卡；编辑页 aid>0 → 注入侧边条
                $res->setContent(\App\Support\GeoAudit\GeoArticleAiInject::injectInto($html, $aid));
            } catch (\Throwable) {
            }
        }
    );

    // 文章编辑页里的「按平台生成」：生成一篇草稿并返回它的编辑页地址。
    // 为什么不原地填编辑器：Vditor 实例在官方模板的闭包里，外部拿不到 setValue；
    // 但正文在 hidden textarea 里，所以跳到自己这篇的编辑页，打开时内容已经在。
    Route::post('articles/ai-inline', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $title = trim((string) $request->input('title', ''));
        $platform = trim((string) $request->input('platform', ''));

        if ($title === '') {
            return response()->json(['ok' => false, 'error' => '标题不能为空']);
        }

        $authorId = (int) (\Illuminate\Support\Facades\Auth::id() ?? 1);
        $r = (new \App\Support\GeoAudit\GeoArticleGenReport())->generateOne($title, $platform, 0, $authorId);

        if (! $r['ok']) {
            return response()->json(['ok' => false, 'error' => $r['error']]);
        }

        return response()->json([
            'ok' => true,
            'article_id' => $r['article_id'],
            'chars' => $r['chars'],
            'edit_url' => $adminBase.'/articles/'.$r['article_id'].'/edit',
        ]);
    })->name('articles.ai-inline');

    // 按标题库认品牌 → 返回该品牌的全部问题（供"从问题库选"用）
    // 关键：必须按品牌过滤。标题库叫「瑞贝卡网站标题库」，就不能把冰溪的护肤问题混进来。
    Route::get('title-libraries/{libraryId}/brand-keywords', function (int $libraryId) {
        try {
            $lib = \Illuminate\Support\Facades\DB::table('title_libraries')->where('id', $libraryId)->first(['id', 'name']);
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'error' => '读不到标题库']);
        }
        if ($lib === null) {
            return response()->json(['ok' => false, 'error' => '标题库不存在']);
        }

        $libName = (string) $lib->name;
        $brandName = '';
        foreach (\App\Support\GeoAudit\GeoShareOfVoice::brands() as $b) {
            $n = (string) ($b['name'] ?? '');
            if ($n !== '' && str_contains($libName, $n)) {
                $brandName = $n;
                break;
            }
        }
        // 兜底：品牌没进 geo-brands 时，从问题库的「品牌｜类」前缀里认
        // （新客户往往先建关键词库、后补品牌档案，不能因此用不了这个功能）
        if ($brandName === '') {
            try {
                $prefixes = \Illuminate\Support\Facades\DB::table('keyword_libraries')
                    ->pluck('name')->all();
                foreach ($prefixes as $fullName) {
                    $prefix = trim(explode('｜', (string) $fullName)[0] ?? '');
                    if ($prefix !== '' && $prefix !== $fullName && str_contains($libName, $prefix)) {
                        $brandName = $prefix;
                        break;
                    }
                }
            } catch (\Throwable) {
            }
        }

        if ($brandName === '') {
            return response()->json([
                'ok' => false,
                'error' => '标题库名「'.$libName.'」里没认到品牌。请把库名改成「品牌名 + 用途」（例如「瑞贝卡网站标题库」），或者先给这个品牌建关键词库。',
            ]);
        }

        try {
            $kLibs = \Illuminate\Support\Facades\DB::table('keyword_libraries')
                ->where('name', 'like', $brandName.'%')
                ->orderBy('id')->get(['id', 'name']);
            $kIds = $kLibs->pluck('id')->map(static fn ($v) => (int) $v)->all();

            $rows = $kIds === [] ? collect() : \Illuminate\Support\Facades\DB::table('keywords')
                ->whereIn('library_id', $kIds)
                ->orderBy('library_id')->orderBy('id')
                ->get(['keyword', 'library_id']);
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'error' => '查询问题失败']);
        }

        $byLib = [];
        foreach ($kLibs as $kl) {
            $byLib[(int) $kl->id] = ['name' => (string) $kl->name, 'items' => []];
        }
        foreach ($rows as $r) {
            $lid = (int) $r->library_id;
            if (isset($byLib[$lid])) {
                $byLib[$lid]['items'][] = (string) $r->keyword;
            }
        }

        return response()->json([
            'ok' => true,
            'brand' => $brandName,
            'groups' => array_values(array_filter($byLib, static fn ($g) => $g['items'] !== [])),
            'total' => count($rows),
        ]);
    })->where('libraryId', '[0-9]+')->name('title-libraries.brand-keywords');

    // 声量对标 → 为某道缺口题生成文章标题，直接进标题库
    Route::post('share-of-voice/gen-titles', function (\Illuminate\Http\Request $request) {
        $keyword = trim((string) $request->input('keyword', ''));
        $platform = trim((string) $request->input('platform', ''));

        $r = (new \App\Support\GeoAudit\GeoTitleGen())->generate($keyword, $platform, 0);

        if (! $r['ok']) {
            return response()->json(['ok' => false, 'error' => $r['error']]);
        }

        return response()->json([
            'ok' => true,
            'titles' => $r['titles'],
            'library_id' => $r['library_id'],
            'model' => $r['model'],
        ]);
    })->name('share-of-voice.gen-titles');

    // 单篇文章的 GEO 十维体检页
    Route::get('articles/{articleId}/geo-check', function (int $articleId) {
        $html = \App\Support\GeoAudit\GeoCheckReport::page($articleId);

        return response()->view('admin.geo-tools.index', [
            'pageTitle' => 'GEO 体检',
            'activeMenu' => 'articles',
            'toolHtml' => $html,
        ]);
    })->where('articleId', '[0-9]+')->name('articles.geo-check');

    // 新建文章页的实时 GEO 评分：把编辑器里的内容丢进来，纯本地算十维，不调模型
    Route::post('articles/geo-score-preview', function (\Illuminate\Http\Request $request) {
        $content = (string) $request->input('content', '');
        if (trim(strip_tags($content)) === '') {
            return response()->json(['ok' => false, 'error' => '内容为空']);
        }

        try {
            $scoring = \App\Support\GeoAudit\GeoScorer::score($content);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 80, 'UTF-8')]);
        }

        $dims = [];
        foreach ((array) ($scoring['dimensions'] ?? []) as $d) {
            $dims[] = [
                'name' => (string) ($d['name'] ?? ''),
                'score' => (int) ($d['score'] ?? 0),
                'color' => (string) ($d['color'] ?? '#9ca3af'),
                'hits' => (int) ($d['hits'] ?? 0),
                'weight' => (int) ($d['weight'] ?? 0),
                'mark' => (string) ($d['mark'] ?? ''),
                'desc' => (string) ($d['desc'] ?? ''),
                'examples' => array_slice(array_map('strval', (array) ($d['examples'] ?? [])), 0, 6),
            ];
        }

        return response()->json([
            'ok' => true,
            'total' => (float) ($scoring['total'] ?? 0),
            'grade' => (string) ($scoring['grade'] ?? ''),
            'grade_color' => (string) ($scoring['grade_color'] ?? '#6b7280'),
            'dims' => $dims,
            'chars' => mb_strlen(trim(strip_tags($content)), 'UTF-8'),
        ]);
    })->name('articles.geo-score-preview');

    // 记录"本文风格"（官方生成的文章不会自己记，这里让人补标）
    Route::post('articles/{articleId}/set-platform', function (int $articleId, \Illuminate\Http\Request $request) {
        $platform = trim((string) $request->input('platform', ''));
        try {
            \Illuminate\Support\Facades\DB::table('articles')->where('id', $articleId)
                ->update(['geo_platform' => mb_substr($platform, 0, 30, 'UTF-8'), 'updated_at' => now()]);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 80, 'UTF-8')]);
        }
    })->where('articleId', '[0-9]+')->name('articles.set-platform');

    // 对现有文章跑一次体检（纯本地正则分析，不调模型）
    Route::post('articles/{articleId}/geo-check/run', function (int $articleId) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $r = \App\Support\GeoAudit\GeoCheckReport::run($articleId);
        $msg = $r['ok']
            ? '体检完成：'.number_format($r['total'], 1).' 分（'.$r['grade'].'）'
            : '体检失败：'.$r['error'];

        return redirect($adminBase.'/articles/'.$articleId.'/geo-check')->with('message', $msg);
    })->where('articleId', '[0-9]+')->name('articles.geo-check.run');

    // =========================================================================
    // 【本地扩展】内容工坊 → AI 生成文章
    // 取代原来独立的「内容工坊」模块：在内容管理里选题选平台，生成即草稿。
    // =========================================================================
    Route::match(['get', 'post'], 'articles/ai-generate', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $view = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title, 'activeMenu' => 'articles', 'toolHtml' => $html,
        ]);

        $platforms = \App\Support\GeoAudit\GeoPlatforms::all();
        $platList = [];
        foreach ($platforms as $slug => $p) {
            $platList[] = [
                'slug' => (string) $slug,
                'name' => (string) ($p['name'] ?? $slug),
                'words' => (array) ($p['words'] ?? [0, 0]),
            ];
        }
        $categories = \Illuminate\Support\Facades\DB::table('categories')->orderBy('id')->get(['id', 'name']);
        $categories = $categories->map(static function ($c) {
            $c->id = (int) $c->id;
            $c->name = (string) $c->name;

            return $c;
        });

        if (! $request->isMethod('post')) {
            return $view(\App\Support\GeoAudit\GeoArticleGenReport::formPage($platList, $categories), 'AI 生成文章');
        }

        $topic = trim((string) $request->input('topic', ''));
        $picked = array_values(array_filter(array_map('strval', (array) $request->input('platforms', []))));
        $catId = (int) $request->input('category_id', 0);
        $authorId = (int) (\Illuminate\Support\Facades\Auth::id() ?? 1);

        if ($topic === '' || $picked === []) {
            return $view(
                \App\Support\GeoAudit\GeoArticleGenReport::formPage($platList, $categories,
                    ['topic' => $topic, 'platforms' => $picked, 'category_id' => $catId],
                    '主题和平台都要填'),
                'AI 生成文章'
            );
        }

        // 一次最多 3 个平台：再多会超出网关等待时间（每个约 30-45 秒）
        $picked = array_slice($picked, 0, 3);
        $gen = new \App\Support\GeoAudit\GeoArticleGenReport();
        $ok = 0;
        $fail = [];
        foreach ($picked as $slug) {
            $r = $gen->generateOne($topic, $slug, $catId, $authorId);
            if ($r['ok']) {
                $ok++;
            } else {
                $fail[] = $r['platform'].'：'.$r['error'];
            }
        }

        $msg = '已生成 '.$ok.' 篇草稿'.($fail !== [] ? '；失败 '.count($fail).' 篇 —— '.implode(' / ', array_slice($fail, 0, 2)) : '');

        return redirect($adminBase.'/articles')->with('message', $msg);
    })->name('articles.ai-generate');

    // =========================================================================
    // 【本地扩展】AI 关键词生成：按 A/B/C/D 四类规则生成 → 筛选 → 导入词库
    // =========================================================================
    Route::match(['get', 'post'], 'keyword-gen', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $base = $adminBase.'/keyword-gen';
        $toolView = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            // 归属到「内容资产 → 关键词库」：这个功能本质是往词库里加东西，
            // 挂在 官网诊断下面（之前用 geo_audit）会让侧边栏高亮跑到别的模块去。
            'activeMenu' => 'keyword_libraries',
            'toolHtml' => $html,
        ]);

        $sess = $request->hasSession() ? $request->session() : null;
        $gen = new \App\Support\GeoAudit\GeoKeywordGen();
        $categories = \App\Support\GeoAudit\GeoKeywordGen::categories();
        $libraries = \Illuminate\Support\Facades\DB::table('keyword_libraries')->orderByDesc('id')->get();

        // ---------- ① 生成 ----------
        if ($request->isMethod('post') && (string) $request->input('gen', '') === '1') {
            // 首次使用自动补 category 列（不给权限就返回 false，页面会提示）
            $gen->ensureCategoryColumn();

            $old = [
                'brand' => trim((string) $request->input('brand', '')),
                'industry' => trim((string) $request->input('industry', '')),
                'audience' => trim((string) $request->input('audience', '')),
                'products' => trim((string) $request->input('products', '')),
                'competitors' => trim((string) $request->input('competitors', '')),
                'cats' => array_values(array_filter(array_map('strval', (array) $request->input('cats', [])))),
                'lib' => (int) $request->input('lib', 0),
                'source' => 'baidu',
                'limit' => max(3, min(30, (int) $request->input('limit', 10))),
            ];

            $genCtx = [
                'industry' => $old['industry'],
                'audience' => $old['audience'],
                'products' => $old['products'],
                'competitors' => $old['competitors'],
            ];

            // 两种数据源：
            //   ai    = 纯 AI 按四类规则出题（快，但用户未必真在搜）
            //   baidu = 百度联想词（真实搜索）→ AI 筛选归类（慢，但真实且干净）
            // 后者其实就是把原来「关键词挖掘」的能力合并进来了。
            $limitPerCat = max(3, min(30, (int) $request->input('limit', 10)));

            // 目标库里已经有的词交给生成器排除，这样连跑几次也不会堆重复
            $exclude = [];
            if ($old['lib'] > 0) {
                try {
                    $exclude = \Illuminate\Support\Facades\DB::table('keywords')
                        ->where('library_id', $old['lib'])
                        ->pluck('keyword')->all();
                } catch (\Throwable) {
                }
            }

            $res = $gen->generateFromBaidu($old['brand'], $old['cats'], $genCtx, $limitPerCat, $exclude);

            if (! $res['ok']) {
                return $toolView(
                    \App\Support\GeoAudit\GeoKeywordGenReport::formPage(
                        $old, $libraries, $categories, $adminBase, $res['error'], $gen->hasCategoryColumn()
                    ),
                    'AI 关键词生成'
                );
            }

            $sess?->put('geo_kg_items', $res['items']);
            $sess?->put('geo_kg_old', $old);

            $metaLine = '模型 '.$res['model'].' · 耗时 '.number_format($res['elapsed_ms'] / 1000, 1).' 秒';
            if (isset($res['stats'])) {
                // 百度模式要让人看到"拉了多少、筛掉多少"，否则不知道这道筛选有没有干活
                $st = $res['stats'];
                $metaLine = '百度联想 '.$st['raw'].' 条 → 筛掉 '.$st['dropped'].' 条 → 保留 '.$st['kept'].' 条 · '.$metaLine;
            } elseif (isset($res['usage'])) {
                $metaLine .= ' · tokens '.(int) $res['usage'];
            }
            $sess?->put('geo_kg_meta', $metaLine);

            return redirect($base.'?result=1');
        }

        // ---------- ② 结果页 ----------
        if ((string) $request->query('result', '') === '1') {
            $items = (array) ($sess?->get('geo_kg_items') ?? []);
            $old = (array) ($sess?->get('geo_kg_old') ?? []);
            $meta = (string) ($sess?->get('geo_kg_meta') ?? '');

            if ($items === []) {
                return redirect($base);
            }

            return $toolView(
                \App\Support\GeoAudit\GeoKeywordGenReport::resultPage(
                    $items, $old, $libraries, $categories, $adminBase,
                    (string) ($old['brand'] ?? ''), $meta
                ),
                'AI 关键词生成'
            );
        }

        // ---------- ③ 导入 ----------
        if ($request->isMethod('post') && (string) $request->input('do_import', '') === '1') {
            $rows = [];
            foreach ((array) $request->input('picked', []) as $v) {
                $v = (string) $v;
                if ($v === '') {
                    continue;
                }
                $pos = strpos($v, '|');
                if ($pos === false) {
                    $rows[] = ['category' => '', 'keyword' => $v];
                } else {
                    $rows[] = [
                        'category' => substr($v, 0, $pos),
                        'keyword' => substr($v, $pos + 1),
                    ];
                }
            }

            $libChoice = (string) $request->input('lib', '');
            $libId = 0;
            $res = ['added' => 0, 'skipped' => 0, 'total' => count($rows), 'error' => ''];

            if ($libChoice === 'auto') {
                // 按类别自动分配：每道题放进「品牌｜类别」对应的库。
                // 生成时是多类一起出的，自动分配才能保证 A/B/C/D 不混库。
                $brand = trim((string) $request->input('brand_for_auto', ''));
                if ($brand === '') {
                    // 兜底：从已选中的词里反推——取第一个词所在的库名前缀
                    $brand = trim((string) ($request->input('lib_brand', '')));
                }

                $byCat = [];
                foreach ($rows as $row) {
                    $c = strtoupper((string) ($row['category'] ?? ''));
                    if ($c === '') {
                        continue;
                    }
                    $byCat[$c][] = $row;
                }

                if ($byCat === []) {
                    $res['error'] = '选中的问题没有类别标记，无法自动分配';
                } else {
                    try {
                        foreach ($byCat as $c => $group) {
                            // 按「品牌｜类」找库；品牌名从库名反查不到时，用当前选中的库所属品牌
                            $target = null;
                            $candidates = \Illuminate\Support\Facades\DB::table('keyword_libraries')
                                ->where('name', 'like', '%｜'.$c.' %')
                                ->get();
                            foreach ($candidates as $cd) {
                                if ($brand !== '' && str_contains((string) $cd->name, $brand)) {
                                    $target = $cd;
                                    break;
                                }
                            }
                            if ($target === null && $candidates->count() === 1) {
                                $target = $candidates->first();
                            }
                            if ($target === null) {
                                $res['error'] = '找不到「'.$c.'」对应的词库，请先在关键词库建好该品牌的四个库';
                                break;
                            }
                            $one = $gen->import((int) $target->id, $group);
                            $res['added'] += (int) $one['added'];
                            $res['skipped'] += (int) $one['skipped'];
                            $libId = $libId === 0 ? (int) $target->id : $libId;
                        }
                    } catch (\Throwable $e) {
                        $res['error'] = '自动分配失败：'.mb_substr($e->getMessage(), 0, 60, 'UTF-8');
                    }
                }
            } else {
                $libId = (int) $libChoice;
                $res = $gen->import($libId, $rows);
            }

            $sess?->put('geo_kg_done', $res);
            $sess?->put('geo_kg_done_lib', $libId);
            $sess?->forget('geo_kg_items');

            return redirect($base.'?done=1');
        }

        // ---------- ④ 导入完成 ----------
        if ((string) $request->query('done', '') === '1') {
            $res = (array) ($sess?->get('geo_kg_done') ?? ['added' => 0, 'skipped' => 0, 'total' => 0, 'error' => '没有导入记录']);
            $libId = (int) ($sess?->get('geo_kg_done_lib') ?? 0);

            $libName = '（未知词库）';
            try {
                $row = \Illuminate\Support\Facades\DB::table('keyword_libraries')->where('id', $libId)->first(['name']);
                if ($row !== null) {
                    $libName = (string) $row->name;
                }
            } catch (\Throwable) {
            }

            return $toolView(
                \App\Support\GeoAudit\GeoKeywordGenReport::donePage(
                    $res, $gen->libraryStats($libId), $libName, $libId, $categories, $adminBase
                ),
                '导入完成'
            );
        }

        // ---------- ⑤ 表单 ----------
        return $toolView(
            \App\Support\GeoAudit\GeoKeywordGenReport::formPage(
                [], $libraries, $categories, $adminBase, '', $gen->hasCategoryColumn()
            ),
            'AI 关键词生成'
        );
    })->name('keyword-gen');

    // =========================================================================
    // 【本地扩展】声量对标：竞品 SOV ＋ 引用来源结构
    // 零新增采集，全部基于已有 sources 聚合
    // =========================================================================
    Route::get('share-of-voice', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $toolView = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_sov',
            'toolHtml' => $html,
        ]);

        $engine = new \App\Support\GeoAudit\GeoShareOfVoice();
        $slug = \App\Support\GeoAudit\GeoShareOfVoice::resolveSlug((string) ($request->query('brand') ?? ''));

        // 引用确认：手动触发，绝不自动跑。
        // 每题多一次模型调用（约 8-10 秒 / 0.01 元），是花成本的决策，必须由人来点。
        if ((string) $request->query('confirm', '') !== '') {
            $howMany = max(1, min(20, (int) $request->query('confirm')));
            $brandName = (string) (\App\Support\GeoAudit\GeoShareOfVoice::brand($slug)['name'] ?? '');
            $done = 0;
            try {
                $libIds = \Illuminate\Support\Facades\DB::table('keyword_libraries')
                    ->where('name', 'like', $brandName.'%')->pluck('id')->all();
                $confirm = new \App\Support\GeoAudit\GeoCitationConfirm();
                $runs = \Illuminate\Support\Facades\DB::table('ai_visibility_runs as r')
                    ->join('keywords as k', 'k.keyword', '=', 'r.keyword')
                    ->whereIn('k.library_id', $libIds)
                    ->whereIn('k.category', ['B', 'C', 'D'])
                    ->whereNotNull('r.raw_response_json')
                    ->select('r.id', 'r.keyword', 'r.raw_response_json', 'r.analysis_json')
                    ->orderBy('r.id')->limit(300)->get();
                foreach ($runs as $run) {
                    if ($done >= $howMany) {
                        break;
                    }
                    $aj = json_decode((string) ($run->analysis_json ?? ''), true);
                    if (is_array($aj) && isset($aj['cited_urls'])) {
                        continue;
                    }
                    $one = $confirm->confirmRun($run);
                    if ($one['ok']) {
                        $done++;
                    }
                }
            } catch (\Throwable $e) {
                return redirect($adminBase.'/share-of-voice?brand='.urlencode($slug))
                    ->with('message', '引用确认失败：'.mb_substr($e->getMessage(), 0, 60, 'UTF-8'));
            }

            return redirect($adminBase.'/share-of-voice?brand='.urlencode($slug))
                ->with('message', $done > 0
                    ? '已完成 '.$done.' 道题的引用确认（每题约 8 秒）'
                    : '没有待确认的题了——这个品牌下能确认的都已经跑过');
        }
        $brand = \App\Support\GeoAudit\GeoShareOfVoice::brand($slug);

        // 只留一个「看哪批问题」的选择器，它的值同时决定库 / 类 / 单题三个粒度：
        //   ""            全部问题
        //   lib:3         整个词库
        //   cat:3:B       词库里的某一类
        //   kw:敏感肌推荐   具体一道题
        // 之前拆成「数据源 + 分类」两个下拉，等于让用户选两遍同一件事。
        $scopeRaw = trim((string) ($request->query('scope') ?? ''));
        $opts = [
            'days' => (int) ($request->query('days') ?? 0),
            'keyword' => '',
            'category' => '',
            'library_id' => 0,
            'scope_raw' => $scopeRaw,
        ];
        if (str_starts_with($scopeRaw, 'lib:')) {
            $opts['library_id'] = (int) substr($scopeRaw, 4);
        } elseif (str_starts_with($scopeRaw, 'cat:')) {
            $parts = explode(':', substr($scopeRaw, 4));
            $opts['library_id'] = (int) ($parts[0] ?? 0);
            $opts['category'] = (string) ($parts[1] ?? '');
        } elseif (str_starts_with($scopeRaw, 'kw:')) {
            $opts['keyword'] = substr($scopeRaw, 3);
        }

        $sov = $engine->sov($slug, $opts);
        $src = $engine->sourceStructure($slug, $opts);
        // 网页级明细：哪些具体页面在占位置、哪些页面上有我、我能不能动手
        $pageLevel = $engine->pageLevel($slug, $opts);

        // 按承诺分级的达成情况（必赢/争夺/待定），给页面出"交付表"
        $opts['grade_stats'] = $engine->gradeStats($slug, 0);

        // 竞品内容地图：每个品牌占了哪些具体页面
        $compMap = $engine->competitorMap($slug, $opts);

        // 一句话诊断：是"真占位"还是"在自己名字周边自说自话"
        $selfTalk = $engine->selfTalkDiagnosis($slug, $opts);

        // 跨口径对比改成 B（品类场景）vs D（竞品对比）。
        // 原来比的是"品牌词 vs 品类词"，但品牌认知(A)已归到覆盖核查，
        // 这里只比赛道内的两种场景：用户说需求时你在不在，用户做对比时你排第几。
        // 跨口径始终看 B / D 两类的整体对比，不跟"看哪批问题"走——
        // 选了一道具体题时，谈"品类场景 vs 竞品对比"没有意义。
        $crossBase = [
            'days' => $opts['days'],
        ];
        $cross = [
            'b' => $engine->sov($slug, $crossBase + ['category' => 'B']),
            'd' => $engine->sov($slug, $crossBase + ['category' => 'D']),
        ];

        return $toolView(
            \App\Support\GeoAudit\GeoShareOfVoiceReport::page(
                $sov,
                $src,
                $engine->keywords(),
                $engine->librariesWithData(),
                $pageLevel,
                $compMap,
                $selfTalk,
                $opts,
                $adminBase,
                (string) ($brand['name'] ?? ''),
                $slug,
                $cross,
            ),
            '声量对标'
        );
    })->name('share-of-voice');

    // =========================================================================
    // 【本地扩展】引用样本录入：把 artisan geoflow:citation:record 搬到界面上
    // GET  打开页面（可带 ?query=&source= 查看某一批的明细统计）
    // POST 保存一批（_action=save）/ 删除一批（_action=delete）
    // =========================================================================
    Route::match(['get', 'post'], 'citation-record', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $base = $adminBase.'/citation-record';
        $toolView = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_citation',
            'toolHtml' => $html,
        ]);

        $notice = '';
        $isError = false;
        $pendingRows = [];

        if ($request->isMethod('post')) {
            $action = trim((string) $request->input('_action', 'save'));
            $postQuery = trim((string) $request->input('query', ''));
            $postSource = trim((string) $request->input('source_type', 'chat'));

            try {
                if ($action === 'delete') {
                    $deleted = \App\Support\GeoAudit\GeoCitationRecord::deleteBatch($postQuery, $postSource);

                    // 删到 0 条时不 flash：页面主体会显示红条「没有找到可删除的记录」，
                    // 再弹一条绿色「已删除 0 条」就是同一屏一红一绿互相打脸。
                    return $deleted > 0
                        ? redirect($base.'?'.http_build_query(['deleted' => $deleted]))
                            ->with('message', '已删除 '.$deleted.' 条引用样本')
                        : redirect($base.'?'.http_build_query(['deleted' => $deleted]));
                }

                $submitted = (array) $request->input('rows', []);
                $result = \App\Support\GeoAudit\GeoCitationRecord::save($postQuery, $postSource, $submitted);

                $savedMsg = '已保存 '.$result['inserted'].' 条引用样本';
                $meta = [
                    'source' => $postSource,
                    'saved' => $result['inserted'],
                    'skipped' => $result['skipped'],
                ];

                // 保存后带着问题回跳，正好落在这批数据的统计上，方便核对刚录的对不对。
                // query 必须用 $result['query']（实际落库值）：超过 500 字会被截断，
                // 用截断前的 $postQuery 回跳会查不到数据，还会跟「已保存 N 条」自相矛盾。
                $jumpUrl = $base.'?'.http_build_query(['query' => $result['query']] + $meta);

                // 但问题很长时不能把它塞进 URL：中文 1 字百分号编码约占 9 字节，
                // 500 字 → Location 头 4.6KB，超过 nginx 的 fastcgi 响应头缓冲（4KB），
                // 直接变成 502（web 容器日志：upstream sent too big header）。
                // 所以超阈值时改走 session 把 query 带过去，URL 只留 source/saved/skipped。
                if (strlen($jumpUrl) > 1800) {
                    return redirect($base.'?'.http_build_query($meta))
                        ->with('message', $savedMsg)
                        ->with('citation_query', $result['query'])
                        ->with('citation_source', $postSource);
                }

                return redirect($jumpUrl)->with('message', $savedMsg);
            } catch (\RuntimeException $e) {
                // 校验失败：不跳转，把填的内容原样带回表单，只在上方加一条红条
                $notice = $e->getMessage();
                $isError = true;
                $pendingRows = (array) $request->input('rows', []);
            }
        }

        $query = trim((string) ($request->query('query') ?? ''));
        $source = trim((string) ($request->query('source') ?? ''));

        if ($request->isMethod('post')) {
            // 校验失败回显时沿用 POST 上来的内容
            if ($query === '') {
                $query = trim((string) $request->input('query', ''));
            }
            if ($source === '') {
                $source = trim((string) $request->input('source_type', ''));
            }
        } elseif ($query === '') {
            // 刚保存的问题太长、URL 带不动时，query 是通过 flash 传过来的（见上方 502 说明）
            $query = trim((string) session('citation_query', ''));
            if ($query !== '') {
                $source = trim((string) session('citation_source', $source));
            }
        }

        if (! $isError) {
            $saved = (int) ($request->query('saved') ?? 0);
            $skipped = (int) ($request->query('skipped') ?? 0);
            $deleted = (int) ($request->query('deleted') ?? 0);
            if ($request->query->has('deleted')) {
                // 删除 0 条也要说话：运营连点两次删除时，静默会让人以为按钮坏了
                if ($deleted > 0) {
                    $notice = '已删除 '.$deleted.' 条引用样本';
                } else {
                    $notice = '没有找到可删除的记录——这一批可能已经被删掉了，看下面的列表确认一下。';
                    $isError = true;
                }
            } elseif ($saved > 0) {
                $notice = '已保存 '.$saved.' 条引用样本'
                    .($skipped > 0 ? '（跳过 '.$skipped.' 条没有标题的行）' : '');
            }
        }

        return $toolView(
            \App\Support\GeoAudit\GeoCitationRecord::page($adminBase, $query, $source, $notice, $isError, $pendingRows),
            '引用样本录入'
        );
    })->name('citation-record');

    // =========================================================================
    // 【本地扩展】发布追踪（阶段 3）：内容资产台账 → 发布记录 → 收录归因
    // 与 AI 可见性共用采集数据，不新增任何采集成本
    // =========================================================================
    Route::match(['get', 'post'], 'publish-track', function (\Illuminate\Http\Request $request) {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $base = $adminBase.'/publish-track';
        $toolView = static fn (string $html, string $title) => response()->view('admin.geo-tools.index', [
            'pageTitle' => $title,
            'activeMenu' => 'geo_publish',
            'toolHtml' => $html,
        ]);

        $pub = new \App\Support\GeoAudit\GeoPublish();
        $pub->ensureTables();

        $assetId = (int) ($request->query('asset') ?? 0);

        // ---------- ① 下载当前资产的 Markdown ----------
        $dl = (int) ($request->query('download') ?? 0);
        if ($dl > 0) {
            $a = $pub->findAsset($dl);
            if ($a === null) {
                return redirect($base.'?msg='.urlencode('找不到这条资产'));
            }
            $safe = preg_replace('/[^a-z0-9_]/', '', (string) $a->platform) ?: 'content';

            return response((string) $a->markdown, 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="geo-'.$safe.'-'.$dl.'.md"',
            ]);
        }

        // ---------- ② 删除发布记录 ----------
        $delRec = (int) ($request->query('delrec') ?? 0);
        if ($delRec > 0) {
            $pub->deletePublish($delRec);

            return redirect($base.'?asset='.$assetId.'&msg='.urlencode('发布记录已删除'));
        }

        // ---------- ③ 删除内容资产 ----------
        $delAsset = (int) ($request->query('delasset') ?? 0);
        if ($delAsset > 0) {
            $pub->deleteAsset($delAsset);

            return redirect($base.'?msg='.urlencode('内容资产及其发布记录已删除'));
        }

        // ---------- ④ 登记一条发布记录 ----------
        if ((string) $request->query('add', '') === '1') {
            $res = $pub->registerPublish(
                $assetId,
                (string) ($request->query('url') ?? ''),
                null,
                (string) ($request->query('published') ?? ''),
                (string) ($request->query('notes') ?? ''),
            );

            return redirect($base.'?asset='.$assetId
                .'&msg='.urlencode($res['msg'])
                .($res['ok'] ? '' : '&t=err'));
        }

        // ---------- ⑤ 轮询收录状态 ----------
        if ((string) $request->query('poll', '') === '1') {
            $probe = (string) $request->query('probe', '') === '1';
            $r = $pub->pollAll($probe);

            return redirect($base.'?polled=1'
                .'&chk='.$r['checked']
                .'&cited='.$r['cited']
                .'&pending='.$r['pending']
                .'&changed='.$r['changed']
                .($assetId > 0 ? '&asset='.$assetId : ''));
        }

        // ---------- ⑥ 批量解析（只做匹配，不落库）----------
        if ((string) $request->query('bulk', '') === '1' && (string) $request->query('parse', '') === '1') {
            $raw = trim((string) ($request->query('urls') ?? ''));
            $lines = preg_split('/[\r\n]+/', $raw) ?: [];
            $lines = array_values(array_filter(array_map('trim', $lines), static fn ($v) => $v !== ''));
            $lines = array_slice($lines, 0, 20);

            $plan = [];
            $used = [];
            foreach ($lines as $line) {
                $url = $line;
                if (! preg_match('#^https?://#i', $url)) {
                    $url = 'https://'.$url;
                }
                $p = \App\Support\GeoAudit\GeoPublish::platformOfUrl($url);
                $slug = (string) $p['slug'];

                // 同平台里挑一条还没登记过的（按生成时间倒序，先发的先占）
                $pick = 0;
                $title = '';
                $cands = $pub->listAssets(['platform' => $slug], 60);
                $fallback = 0;
                foreach ($cands as $c) {
                    if (isset($used[(int) $c->id])) {
                        continue;
                    }
                    if ($fallback === 0) {
                        $fallback = (int) $c->id;
                    }
                    if ((int) ($c->rec_count ?? 0) === 0) {
                        $pick = (int) $c->id;
                        $title = (string) ($c->title ?: $c->topic);
                        break;
                    }
                }
                if ($pick === 0 && $fallback > 0) {
                    $pick = $fallback;
                    foreach ($cands as $c) {
                        if ((int) $c->id === $pick) {
                            $title = (string) ($c->title ?: $c->topic);
                            break;
                        }
                    }
                }
                if ($pick > 0) {
                    $used[$pick] = true;
                }

                $plan[] = [
                    'url' => $url,
                    'slug' => $slug,
                    'name' => (string) $p['name'],
                    'asset_id' => $pick,
                    'asset_title' => $title,
                    'ok' => $pick > 0,
                    'reason' => $pick > 0 ? '' : '这个平台还没有内容资产，请先在内容工坊生成对应平台的版本',
                ];
            }

            return $toolView(
                \App\Support\GeoAudit\GeoPublishReport::bulkPage($plan, $pub->listAssets([], 100), $adminBase, '', $raw),
                '发布追踪 · 批量登记'
            );
        }

        // ---------- ⑦ 批量提交 ----------
        if ((string) $request->query('bulkrun', '') === '1') {
            $urls = (array) ($request->query('u') ?? []);
            $binds = (array) ($request->query('bind') ?? []);

            $ok = 0;
            $fail = 0;
            $msgs = [];
            foreach ($urls as $i => $url) {
                $aid = (int) ($binds[$i] ?? 0);
                if ($aid <= 0) {
                    $fail++;
                    continue;
                }
                $res = $pub->registerPublish($aid, (string) $url);
                if ($res['ok']) {
                    $ok++;
                } else {
                    $fail++;
                    $msgs[] = $res['msg'];
                }
            }

            $msg = '已登记 '.$ok.' 条'.($fail > 0 ? '，'.$fail.' 条失败'.($msgs !== [] ? '（'.implode('；', array_slice(array_unique($msgs), 0, 2)).'）' : '') : '');

            return redirect($base.'?msg='.urlencode($msg).($fail > 0 ? '&t=err' : ''));
        }

        // ---------- ⑧ 批量登记表单 ----------
        if ((string) $request->query('bulk', '') === '1') {
            return $toolView(
                \App\Support\GeoAudit\GeoPublishReport::bulkPage([], $pub->listAssets([], 100), $adminBase),
                '发布追踪 · 批量登记'
            );
        }

        // ---------- ⑨ 单篇详情 ----------
        if ($assetId > 0) {
            $asset = $pub->findAsset($assetId);
            if ($asset === null) {
                return redirect($base.'?msg='.urlencode('找不到这条资产'));
            }
            $records = $pub->recordsOf($assetId);
            $attrs = [];
            foreach ($records as $rec) {
                $attrs[(int) $rec->id] = $pub->attribution((int) $rec->id);
            }

            $msg = (string) ($request->query('msg') ?? '');
            $type = (string) ($request->query('t') ?? 'ok');

            return $toolView(
                \App\Support\GeoAudit\GeoPublishReport::detailPage($asset, $records, $attrs, $adminBase, $msg, $type),
                '发布追踪'
            );
        }

        // ---------- ⑩ 台账主页 ----------
        $filter = [
            'platform' => (string) ($request->query('platform') ?? ''),
            'status' => (string) ($request->query('status') ?? ''),
            'q' => (string) ($request->query('q') ?? ''),
        ];

        $notice = (string) ($request->query('msg') ?? '');
        if ((string) $request->query('polled', '') === '1') {
            $notice = '收录状态已刷新：共核对 '.(int) ($request->query('chk') ?? 0).' 条记录，'
                .'已引用 '.(int) ($request->query('cited') ?? 0).' 条，'
                .'待收录 '.(int) ($request->query('pending') ?? 0).' 条';
        }

        $body = \App\Support\GeoAudit\GeoPublishReport::indexPage(
            $pub->overview(),
            $pub->platformStats(),
            $pub->listAssets($filter, 200),
            $pub->staleRecords(7, 10),
            $filter,
            $pub->collectionSpan(),
            $adminBase,
        );

        if ($notice !== '') {
            $t = (string) ($request->query('t') ?? 'ok');
            $bg = $t === 'err' ? '#fef2f2' : '#ecfdf5';
            $bd = $t === 'err' ? '#b91c1c' : '#047857';
            $body = '<div style="background:'.$bg.';border:1px solid '.$bd.';border-radius:10px;padding:10px 14px;'
                .'font-size:13px;color:'.$bd.';margin-bottom:14px">'
                .e($notice).'</div>'.$body;
        }

        return $toolView($body, '发布追踪');
    })->name('publish-track');
});
