<?php

namespace App\Support\GeoAudit;

use App\Models\Admin;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Support\Facades\DB;

/**
 * 方案 → 任务草稿 桥接
 *
 * 用 GEOFlow 官方 TaskLifecycleService 建任务（不直接插库），保证与后台表单创建的
 * 结果完全一致；缺参数时返回 fallback_url，引导到预填好的任务创建页。
 */
final class GeoTaskBridge
{
    /**
     * @param  array<string,mixed>  $planResult
     * @return array{ok:bool,task_id?:int,name?:string,error?:string,fallback_url?:string}
     */
    public function createDraft(array $planResult, Admin $admin, ?string $nameOverride = null): array
    {
        $brand = trim((string) ($planResult['brand'] ?? ''));
        $url = trim((string) ($planResult['url'] ?? ''));
        $name = $nameOverride !== null && trim($nameOverride) !== ''
            ? trim($nameOverride)
            : 'GEO｜'.($brand !== '' ? $brand : ($url !== '' ? parse_url($url, PHP_URL_HOST) : '新站点')).'｜'.
                ((int) ($planResult['timeline'] ?? 90)).'天计划';

        $defaults = $this->catalogDefaults();
        $missing = [];
        foreach (['title_library_id', 'prompt_id', 'ai_model_id', 'fixed_category_id', 'author_id'] as $f) {
            if (empty($defaults[$f])) {
                $missing[] = $f;
            }
        }

        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $query = http_build_query(array_filter([
            'name' => $name,
            'article_limit' => 5,
            'knowledge_base_id' => $defaults['knowledge_base_id'] ?? null,
            'ai_model_id' => $defaults['ai_model_id'] ?? null,
            'prompt_id' => $defaults['prompt_id'] ?? null,
            'title_library_id' => $defaults['title_library_id'] ?? null,
            'category_id' => $defaults['fixed_category_id'] ?? null,
        ], static fn ($v): bool => $v !== null && $v !== ''));
        $fallback = $adminBase.'/tasks/create'.($query !== '' ? '?'.$query : '');

        if ($missing !== []) {
            return ['ok' => false, 'error' => '缺少必要配置：'.implode('、', $missing).'。已为你打开预填好的任务表单。', 'fallback_url' => $fallback];
        }

        try {
            $task = app(TaskLifecycleService::class)->createTask([
                'name' => $name,
                'article_limit' => 5,
                'title_library_id' => (int) $defaults['title_library_id'],
                'prompt_id' => (int) $defaults['prompt_id'],
                'ai_model_id' => (int) $defaults['ai_model_id'],
                'fixed_category_id' => (int) $defaults['fixed_category_id'],
                'knowledge_base_ids' => $defaults['knowledge_base_id'] ? [(int) $defaults['knowledge_base_id']] : [],
                'knowledge_base_id' => $defaults['knowledge_base_id'] ? (int) $defaults['knowledge_base_id'] : null,
                'author_id' => (int) $defaults['author_id'],
                'image_library_id' => null,
                'image_count' => 0,
                'publish_interval' => 3600,
                'publish_interval_minutes' => 60,
                'need_review' => 1,
                // 关键：草稿态，不自动开跑
                'status' => 'paused',
                'publish_scope' => 'local_only',
                'is_loop' => 0,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
                'draft_limit' => 5,
                'auto_keywords' => 1,
                'auto_description' => 1,
                'ai_quality_enabled' => false,
            ], (int) $admin->getKey(), null, $admin);

            return [
                'ok' => true,
                'task_id' => (int) ($task['id'] ?? 0),
                'name' => $name,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => '创建失败：'.mb_substr($e->getMessage(), 0, 160).'。已为你打开预填好的任务表单。',
                'fallback_url' => $fallback,
            ];
        }
    }

    /**
     * 按方案里的任务清单批量建草稿（每个任务一条 GEOFlow 任务，均 paused）。
     *
     * @param  list<array<string,mixed>>  $tasks
     * @param  array<string,mixed>  $planMeta
     * @return array{created:list<array{name:string,task_id:int}>,failed:list<array{name:string,error:string}>,fallback_url:?string}
     */
    public function createFromTasks(array $tasks, array $planMeta, Admin $admin): array
    {
        $created = [];
        $failed = [];
        $fallback = null;

        $brand = trim((string) ($planMeta['brand'] ?? ''));
        $prefix = 'GEO｜'.($brand !== '' ? $brand : '站点').'｜';

        foreach ($tasks as $task) {
            $name = trim((string) ($task['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $priority = (string) ($task['priority'] ?? 'P1');
            $result = $this->createDraft([
                'brand' => $brand,
                'url' => (string) ($planMeta['url'] ?? ''),
                'timeline' => (int) ($planMeta['timeline'] ?? 90),
            ], $admin, $prefix.$name.'（'.$priority.'）');

            if ($result['ok']) {
                $created[] = ['name' => $name, 'task_id' => (int) $result['task_id'], 'priority' => $priority];
            } else {
                $failed[] = ['name' => $name, 'error' => (string) ($result['error'] ?? '')];
                $fallback = $fallback ?? ($result['fallback_url'] ?? null);
            }
        }

        return ['created' => $created, 'failed' => $failed, 'fallback_url' => $fallback];
    }

    /**
     * 覆盖核查 → 任务草稿：把"未覆盖的问题"变成一篇篇定向内容。
     *
     * 与 createFromTasks 的区别：
     *   createFromTasks 是"一个任务名一条任务"，标题仍来自通用标题库；
     *   这里是"问题本身就是标题"——所以要先建一个专属标题库，把问题写进 titles，
     *   任务引用这个库、article_limit = 问题数，这样每篇文章才会真的围着那个问题写。
     *
     * @param  list<string>  $questions  未覆盖问题（将作为文章标题）
     * @param  array{brand?:string,url?:string,label?:string}  $meta
     * @return array<string,mixed>
     */
    public function createCoverageTask(array $questions, array $meta, Admin $admin): array
    {
        $questions = array_values(array_filter(array_map(
            static fn ($q): string => trim((string) $q),
            $questions,
        ), static fn (string $q): bool => $q !== ''));

        if ($questions === []) {
            return ['ok' => false, 'error' => '没有选中任何问题'];
        }

        $brand = trim((string) ($meta['brand'] ?? ''));
        if ($brand === '') {
            $brand = '站点';
        }
        $count = count($questions);
        $stamp = date('Y-m-d H:i');

        $defaults = $this->catalogDefaults();
        $missing = [];
        foreach (['prompt_id', 'ai_model_id', 'fixed_category_id', 'author_id'] as $f) {
            if (empty($defaults[$f])) {
                $missing[] = $f;
            }
        }

        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        if ($missing !== []) {
            $query = http_build_query(array_filter([
                'name' => 'GEO｜'.$brand.'｜未覆盖问题 '.$count.' 篇',
                'article_limit' => $count,
                'ai_model_id' => $defaults['ai_model_id'] ?? null,
                'prompt_id' => $defaults['prompt_id'] ?? null,
                'title_library_id' => $defaults['title_library_id'] ?? null,
                'category_id' => $defaults['fixed_category_id'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== ''));

            return [
                'ok' => false,
                'error' => '缺少必要配置：'.implode('、', $missing).'。请先在后台补齐内容提示词、聊天模型、分类与作者。',
                'fallback_url' => $adminBase.'/tasks/create?'.$query,
            ];
        }

        try {
            // ① 专属标题库：名字带品牌与时间，方便日后按批次找到
            $library = TitleLibrary::create([
                'name' => mb_substr('GEO未覆盖｜'.$brand.'｜'.$stamp, 0, 100, 'UTF-8'),
                'description' => '由「覆盖核查」生成：'.count($questions).' 个 AI 未提及的问题，逐条作为文章标题定向补齐。',
                'title_count' => 0,
                'generation_type' => 'manual',
                'is_ai_generated' => 0,
            ]);

            // ② 问题写进 titles（keyword 也填问题原文，auto_keywords 会据此生成关键词）
            foreach ($questions as $q) {
                Title::create([
                    'library_id' => (int) $library->getKey(),
                    'title' => mb_substr($q, 0, 500, 'UTF-8'),
                    'keyword' => mb_substr($q, 0, 200, 'UTF-8'),
                    'is_ai_generated' => false,
                ]);
            }
            TitleLibrary::whereKey($library->getKey())->update(['title_count' => $count]);

            // ③ 建任务：引用该标题库，篇数 = 问题数，草稿态不开跑
            $name = mb_substr('GEO｜'.$brand.'｜未覆盖问题 '.$count.' 篇（'.$stamp.'）', 0, 200, 'UTF-8');
            $kbId = $defaults['knowledge_base_id'] ?? null;

            $task = app(TaskLifecycleService::class)->createTask([
                'name' => $name,
                'article_limit' => $count,
                'title_library_id' => (int) $library->getKey(),
                'prompt_id' => (int) $defaults['prompt_id'],
                'ai_model_id' => (int) $defaults['ai_model_id'],
                'fixed_category_id' => (int) $defaults['fixed_category_id'],
                'knowledge_base_ids' => $kbId ? [(int) $kbId] : [],
                'knowledge_base_id' => $kbId ? (int) $kbId : null,
                'author_id' => (int) $defaults['author_id'],
                'image_library_id' => null,
                'image_count' => 0,
                'publish_interval' => 3600,
                'publish_interval_minutes' => 60,
                'need_review' => 1,
                'status' => 'paused',
                'publish_scope' => 'local_only',
                'is_loop' => 0,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
                'draft_limit' => $count,
                'auto_keywords' => 1,
                'auto_description' => 1,
                'ai_quality_enabled' => false,
            ], (int) $admin->getKey(), null, $admin);

            return [
                'ok' => true,
                'library_id' => (int) $library->getKey(),
                'task_id' => (int) ($task['id'] ?? 0),
                'name' => $name,
                'titles' => $questions,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => '创建失败：'.mb_substr($e->getMessage(), 0, 200, 'UTF-8'),
                'fallback_url' => $adminBase.'/tasks/create',
            ];
        }
    }

    /** @return array<string,int|null> */
    private function catalogDefaults(): array
    {
        try {
            return [
                'title_library_id' => (int) DB::table('title_libraries')->orderBy('id')->value('id') ?: null,
                'prompt_id' => (int) DB::table('prompts')->where('type', 'content')->orderBy('id')->value('id') ?: null,
                'ai_model_id' => (int) DB::table('ai_models')->where('model_type', 'chat')->where('status', 'active')->orderBy('id')->value('id') ?: null,
                'fixed_category_id' => (int) DB::table('categories')->orderBy('id')->value('id') ?: null,
                'author_id' => (int) DB::table('authors')->orderBy('id')->value('id') ?: null,
                'knowledge_base_id' => (int) DB::table('knowledge_bases')->orderBy('id')->value('id') ?: null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
