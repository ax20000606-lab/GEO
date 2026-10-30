<?php

namespace App\Support\GeoAudit;

/**
 * 标题反推提问 —— 页面渲染（表单页 + 报告页）。
 *
 * 为什么渲染层是「PHP 拼 HTML 字符串」而不是 Blade：
 *   与隔壁 GeoKbReport / GeoCheckReport / GeoShareOfVoiceReport 完全一致。
 *   好处是这些类可以纯 PHP 单测（tinker 里直接 call 一次、grep 关键字），
 *   不需要起 HTTP、不需要登录态。这个仓库里所有 geo_* 页面都这么写。
 *
 * 依赖边界：
 *   · 只读 GeoTitleQuestionStore / Industry / Rules / Slots，**不写库**
 *   · 不调用模型（生成器是 T03 的事，本文件只负责展示）
 *   · 不新增 DB 列：资质提示框是纯静态文案，计数来自已有 content_type
 */
final class GeoTitleQuestionReport
{
    /** 报告页主清单/副清单的分页上限（超出的折叠，避免一屏几百行） */
    private const LIST_SOFT_LIMIT = 40;

    // =========================================================================
    // 表单页
    // =========================================================================

    /**
     * 表单页：输入标题 + 历史批次。
     *
     * 生成走 POST（T03 已接），提交后同步跑模型（20–40 秒）再跳报告页。
     * 表单带 CSRF —— 这是一个会花钱、会写库的操作，不能靠 GET 触发。
     *
     * @param  string  $brandSlug  当前口径品牌
     * @param  int  $currentBatchId  已选中的批次（0 = 未选）
     */
    public static function formPage(string $brandSlug, int $currentBatchId = 0): string
    {
        $brand = GeoShareOfVoice::brand($brandSlug);
        $brandName = (string) ($brand['name'] ?? $brandSlug);
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

        // 校验失败（标题为空 / 模型失败）回到表单页时把原因显示出来。
        // 不显示的话用户只会看到"点了没反应"，然后重复提交 —— 每次都在花钱。
        $errorHtml = '';
        try {
            $bag = session('errors');
            $first = is_object($bag) && method_exists($bag, 'first') ? (string) $bag->first() : '';
            if ($first !== '') {
                $errorHtml = '<div class="g-err">'.self::e($first).'</div>';
            }
        } catch (\Throwable) {
            // session 不可用（比如 CLI 渲染预览）时忽略，不要让页面挂掉
        }

        $history = GeoTitleQuestionStore::recentTitleGroups($brandSlug, 20);
        $historyHtml = '';
        if ($history === []) {
            $historyHtml = '<p class="g-cap" style="margin:0">这个品牌还没有推导记录。'
                .'生成器接入后，跑过的标题会出现在这里，点进去看每一次的结论与差异。</p>';
        } else {
            $historyHtml = '<div style="display:flex;flex-direction:column;gap:8px">';
            foreach ($history as $g) {
                $runs = (int) ($g['runs'] ?? 0);
                $historyHtml .= '<div style="display:flex;align-items:center;gap:10px;'
                    .'padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff">'
                    .'<div style="flex:1;min-width:0">'
                    .'<div style="font-size:13.5px;font-weight:600;color:#111827;white-space:nowrap;'
                    .'overflow:hidden;text-overflow:ellipsis">'.self::e((string) ($g['title'] ?? '')).'</div>'
                    .'<div class="g-cap" style="margin:2px 0 0">'
                    .self::e((string) ($g['last_at'] ?? '')).' · 共 '.$runs.' 次'
                    .'</div></div>'
                    .'<span class="g-pill">'.$runs.' 次</span>'
                    .'</div>';
            }
            $historyHtml .= '</div>';
        }

        $body = '<div class="g-card"><h3>标题反推 AI 提问</h3>'
            .'<p class="g-cap">输入一个标题，得到「这个标题想抢哪些 AI 提问」的清单，用来给运营派活。'
            .'<b>只做推导，不接引用可行性</b> —— AI 现在引用谁、抢位的是竞品还是媒体，'
            .'那些在「覆盖核查」「声量对标」里看。</p>'
            .$errorHtml
            .'<form method="post" action="'.$adminBase.'/title-question/generate" id="tq-form">'
            .'<input type="hidden" name="_token" value="'.self::e(csrf_token()).'">'
            .'<input type="hidden" name="brand" value="'.self::e($brandSlug).'">'
            .'<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:12px">'
            .'<label style="flex:1;min-width:320px;display:block;font-size:12.5px;font-weight:600;color:#374151">'
            .'标题原文'
            .'<input type="text" name="title" required maxlength="200" '
            .'value="'.self::e((string) session('tq_last_title')).'" '
            .'placeholder="例如：冰溪舒缓修护霜测评：烂脸期真的能用吗" '
            .'style="width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;'
            .'font-size:13.5px"></label>'
            .'<button type="submit" class="g-btn g-btn--primary" id="tq-submit">开始推导</button></div>'
            .'</form>'
            .'<p class="g-cap" style="margin:10px 0 0">'
            .'一次会调用模型生成 10–12 条候选，再筛成 5–8 条主清单，通常 20–40 秒。'
            .'同一标题重复跑会各自留一份批次，历史里能对比。</p>'
            .'<p class="g-cap" style="margin:6px 0 0">当前品牌：<b>'.self::e($brandName).'</b>'
            .'（切换品牌用页面顶部的品牌 chip）</p></div>'

            .'<div class="g-card"><h3>历史批次</h3>'.$historyHtml.'</div>'

            .'<div class="g-card"><h3>这份清单怎么用</h3>'
            .'<ol style="margin:0;padding-left:20px;font-size:12.5px;color:#4b5563;line-height:1.9">'
            .'<li><b>主清单</b>是这篇能直接覆盖的问题，按「投入产出比」排序（S3b &gt; S3a &gt; S2 &gt; S5 &gt; S1 &gt; S4）</li>'
            .'<li><b>副清单</b>是「顺带能覆盖」的，默认折叠 —— 展开后会忍不住把它当目标写</li>'
            .'<li>每条都带<b>预期答案</b>和<b>内容类型</b>：前者是验收口径，后者决定派给谁写</li>'
            .'<li><b>转信源建设</b>的题不要写成软文，它们靠品牌词条/百科/资质页去抢</li>'
            .'</ol></div>';

        // 提交后禁用按钮：一次生成要 20–40 秒，用户很容易连点，
        // 而每点一次就是一次真实的模型调用（真花钱）。
        $body .= '<script>(function(){var f=document.getElementById("tq-form");if(!f)return;'
            .'f.addEventListener("submit",function(){var b=document.getElementById("tq-submit");'
            .'if(b){b.disabled=true;b.style.opacity=".65";b.textContent="推导中…";}'
            .'if(window.geoBusy){window.geoBusy("正在推导 AI 提问…","一次模型调用，通常 20–40 秒，请勿关闭页面");}});})();</script>';

        return self::shell($body, '标题反推提问');
    }

    // =========================================================================
    // 报告页
    // =========================================================================

    /**
     * 报告页（只读）。
     *
     * @param  int  $batchId  0 或不存在时回落到「最近一批」
     */
    public static function reportPage(int $batchId, string $brandSlug = ''): string
    {
        if ($batchId <= 0) {
            $recent = GeoTitleQuestionStore::recentBatches($brandSlug, 1);
            $batchId = (int) ($recent[0]['id'] ?? 0);
        }

        if ($batchId <= 0) {
            return self::shell(
                '<div class="g-card"><h3>还没有可看的报告</h3>'
                .'<p class="g-cap" style="margin:0">先跑一次推导（生成器接入后可用），'
                .'或者用 ?batch=N 指定一个已有批次。</p></div>',
                '标题反推提问 · 报告'
            );
        }

        $batch = GeoTitleQuestionStore::findBatch($batchId);
        if ($batch === null) {
            return self::shell(
                '<div class="g-card"><h3>批次不存在</h3>'
                .'<p class="g-cap" style="margin:0">batch='.(int) $batchId.' 查不到，可能已被清理。</p></div>',
                '标题反推提问 · 报告'
            );
        }

        $questions = GeoTitleQuestionStore::questionsOfBatch($batchId);
        $primary = [];
        $marginal = [];
        foreach ($questions as $row) {
            if ((string) ($row['list_kind'] ?? '') === 'marginal') {
                $marginal[] = $row;
            } else {
                $primary[] = $row;
            }
        }

        $body = self::headerCard($batch)
            .self::statCards($batch, $primary, $marginal)
            .self::prospectusNotice($primary)
            .self::warningsCard($batch)
            .self::questionCard('主清单', '这篇能直接覆盖的问题', $primary, false)
            .self::questionCard('副清单', '顺带能覆盖的，不作为本篇目标', $marginal, true)
            .self::skippedCard($batch)
            .self::rejectedCard($batch)
            .self::declarationCard((string) ($batch['declaration'] ?? GeoTitleQuestionStore::DECLARATION));

        return self::shell($body, '标题反推提问 · 报告');
    }

    /**
     * 页头：标题 / 品牌 / 版本三要素 / 时间。
     *
     * 版本三要素（prompt_version + model + seed）为什么要显示：
     * 规则改了、换模型了、seed 变了，同标题的结果就不可直接比。
     * 不显示的话运营看到两次结果不同会以为系统不稳定。
     *
     * @param  array<string,mixed>  $batch
     */
    private static function headerCard(array $batch): string
    {
        $status = (string) ($batch['status'] ?? '');
        $statusPill = match ($status) {
            'completed' => '<span class="g-pill g-pill--ok">完成</span>',
            'degraded' => '<span class="g-pill g-pill--warn">有告警</span>',
            'failed' => '<span class="g-pill g-pill--bad">失败</span>',
            default => '<span class="g-pill">'.self::e($status).'</span>',
        };

        $seed = $batch['seed'] ?? null;
        $seedText = $seed === null ? '不支持（该模型接口无 seed 参数）' : (string) $seed;
        $model = trim((string) ($batch['model_name'] ?? ''));
        if ($model === '') {
            $model = trim((string) ($batch['model_id'] ?? ''));
        }

        return '<div class="g-card"><div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">'
            .'<div style="flex:1;min-width:280px">'
            .'<h3 style="margin:0 0 4px">'.self::e((string) ($batch['title'] ?? '')).'</h3>'
            .'<p class="g-cap" style="margin:0">'
            .self::e((string) ($batch['brand_name'] ?? $batch['brand_slug'] ?? '')).' · '
            .self::e((string) ($batch['created_at'] ?? '')).'</p></div>'
            .'<div style="text-align:right">'.$statusPill.'</div></div>'

            .'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-top:14px">'
            .self::kv('规则版本', (string) ($batch['prompt_version'] ?? ''))
            .self::kv('行业模板', (string) ($batch['industry_template'] ?? ''))
            .self::kv('模型', $model === '' ? '—' : $model)
            .self::kv('seed', $seedText)
            .self::kv('temperature', (string) ($batch['temperature'] ?? ''))
            .self::kv('单条标题哈希', mb_substr((string) ($batch['title_hash'] ?? ''), 0, 12).'…')
            .'</div></div>';
    }

    /**
     * 统计卡：条数 / 射程 / 角度 / 阶段 / 类分布。
     *
     * 「射程」为什么单独一张卡：主清单条数含 A 类转信源建设的题，
     * 那些不计入"本篇能抢"。两个数放一起运营才看得懂差在哪。
     *
     * @param  array<string,mixed>  $batch
     * @param  list<array<string,mixed>>  $primary
     * @param  list<array<string,mixed>>  $marginal
     */
    private static function statCards(array $batch, array $primary, array $marginal): string
    {
        $classes = (array) ($batch['class_counts'] ?? []);
        $classText = 'A'.(int) ($classes['A'] ?? 0).' / B'.(int) ($classes['B'] ?? 0)
            .' / C'.(int) ($classes['C'] ?? 0).' / D'.(int) ($classes['D'] ?? 0);

        // 角度/内容类型分布直接从行里算（不落库，随时可重算且不受历史影响）
        $angleDist = [];
        $ctypeDist = [];
        foreach ($primary as $row) {
            $a = (string) ($row['angle'] ?? '');
            $c = (string) ($row['content_type'] ?? '');
            if ($a !== '') {
                $angleDist[$a] = ($angleDist[$a] ?? 0) + 1;
            }
            if ($c !== '') {
                $ctypeDist[$c] = ($ctypeDist[$c] ?? 0) + 1;
            }
        }
        $angleText = self::distText($angleDist, static fn (string $k): string => GeoTitleQuestionIndustry::angleLabel($k));
        $ctypeText = self::distText($ctypeDist, static fn (string $k): string => GeoTitleQuestionIndustry::contentTypeLabel($k));

        $touched = GeoTitleQuestionStore::statsByTouched((int) ($batch['id'] ?? 0));
        $touchedText = self::distText($touched, static fn (string $k): string => GeoTitleQuestionIndustry::angleLabel($k));

        return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:14px">'
            .self::stat('主清单', (string) count($primary), '条')
            .self::stat('本篇射程', (string) (int) ($batch['scoped_count'] ?? 0), '条', '排除转信源建设')
            .self::stat('副清单', (string) count($marginal), '条', '顺带覆盖')
            .self::stat('角度覆盖', (string) (int) ($batch['angle_count'] ?? 0), '个', $angleText)
            .self::stat('阶段覆盖', (string) (int) ($batch['stage_count'] ?? 0), '个')
            .self::stat('类分布', $classText, '', 'A≤2 只给自有信源页')
            .self::stat('内容类型', (string) count($ctypeDist), '种', $ctypeText)
            .self::stat('角度触及', (string) array_sum($touched), '次', $touchedText.'（生成时快照）')
            .'</div>';
    }

    /**
     * 资质公示与售后政策提示框（合并成一个、N>0 才渲染）。
     *
     * 为什么必须合并成一个框、且只在有行时出现：
     *   · 分成两个框（行级 notice + 静态说明）会重复，运营会跳过不看
     *   · 常驻说明会被无视；只有「本批真有这类题」时才值得打断视线
     *
     * 为什么必须有这段提醒：这一格的答案是官方资质与政策，没有页面就不存在
     * 可被引用的信源。只出问题不提示，运营会照软文写 —— 海洋至尊 A 类 11 次
     * 缺位全是媒体占位，写软文一次都没抢到。
     *
     * @param  list<array<string,mixed>>  $primary
     */
    private static function prospectusNotice(array $primary): string
    {
        $n = 0;
        foreach ($primary as $row) {
            if ((string) ($row['content_type'] ?? '') === 'prospectus_and_policy') {
                $n++;
            }
        }
        if ($n <= 0) {
            return '';
        }

        return '<div class="g-card" style="border-color:#f59e0b;background:#fffbeb">'
            .'<h3 style="color:#92400e;margin:0 0 6px">这批有 '.$n.' 条「资质公示与售后政策」</h3>'
            .'<p class="g-cap" style="margin:0 0 8px;color:#92400e">'
            .self::e(GeoTitleQuestionIndustry::PROSPECTUS_HINT).'</p>'
            .'<p style="margin:0 0 6px;font-size:12.5px;font-weight:600;color:#92400e">正文必须含三块：</p>'
            .'<ol style="margin:0;padding-left:20px;font-size:12.5px;color:#78350f;line-height:1.9">'
            .'<li>主体资质（营业执照 / 生产许可 / 备案号，<b>可点开验证</b>）</li>'
            .'<li>授权与防伪（官方授权链 / 防伪查询入口）</li>'
            .'<li>退换与售后条款（退换条件、时效、责任划分、投诉渠道）</li>'
            .'</ol>'
            .'<p style="margin:10px 0 0;font-size:12.5px;color:#b45309">'
            .'⚠ 不要写成「品牌介绍」，要写成可验证的事实清单。</p></div>';
    }

    /**
     * warnings 卡片（黄条）。
     *
     * @param  array<string,mixed>  $batch
     */
    private static function warningsCard(array $batch): string
    {
        $warnings = (array) ($batch['warnings'] ?? []);
        if ($warnings === []) {
            return '';
        }

        $items = '';
        foreach ($warnings as $w) {
            $text = (string) $w;
            // MATRIX_GAP 要单独高亮：它是「我方矩阵缺格」，处置与「标题窄」相反
            $isMatrixGap = str_starts_with($text, 'MATRIX_GAP');
            $items .= '<li style="margin-bottom:6px;'.($isMatrixGap ? 'color:#b91c1c;font-weight:600' : '').'">'
                .self::e($text).'</li>';
        }

        return '<div class="g-card" style="border-color:#fcd34d;background:#fffbeb">'
            .'<h3 style="color:#92400e;margin:0 0 8px">'.count($warnings).' 条告警</h3>'
            .'<ul style="margin:0;padding-left:20px;font-size:12.5px;color:#78350f;line-height:1.7">'
            .$items.'</ul></div>';
    }

    /**
     * 清单表格。
     *
     * @param  list<array<string,mixed>>  $rows
     */
    private static function questionCard(string $title, string $caption, array $rows, bool $collapsed): string
    {
        if ($rows === []) {
            // 主清单为空通常意味着三判据把候选全砍了；那种情况 warnings 里会有说明，
            // 这里不重复，只给一句指向性文案。
            $emptyText = $collapsed ? '没有「顺带覆盖」的候选。' : '没有通过三判据的候选，看上面的告警。';

            return '<div class="g-card"><h3>'.$title.'</h3>'
                .'<p class="g-cap" style="margin:0">'.$emptyText.'</p></div>';
        }

        $shown = 0;
        $trs = '';
        foreach ($rows as $row) {
            if ($shown >= self::LIST_SOFT_LIMIT) {
                break;
            }
            $shown++;
            $trs .= self::questionRow($row);
        }

        $more = count($rows) > $shown
            ? '<p class="g-cap" style="margin:8px 0 0">还有 '.(count($rows) - $shown).' 条未显示（本页最多 '.self::LIST_SOFT_LIMIT.' 条）。</p>'
            : '';

        // 7 列在 <1100px 视口里会被压扁（问题列挤到 1 字一行），
        // 所以套一层横向滚动容器 + 给表格一个 min-width。
        $table = '<div style="overflow-x:auto;margin-top:10px">'
            .'<table style="width:100%;min-width:1040px;border-collapse:collapse;font-size:12.5px">'
            .'<thead><tr>'
            .self::th('#', '40px')
            .self::th('问题', '')
            .self::th('阶段 / 角度', '150px')
            .self::th('类 / 意图', '110px')
            .self::th('内容类型', '160px')
            .self::th('顾虑', '90px')
            .self::th('预期答案', '200px')
            .self::th('怎么用', '130px')
            .'</tr></thead><tbody>'.$trs.'</tbody></table></div>'.$more;

        if ($collapsed) {
            // 副清单默认折叠：展开会让运营把「顺带覆盖」当目标写
            return '<details class="g-card"><summary style="cursor:pointer;font-weight:600;font-size:14px">'
                .$title.'（'.count($rows).' 条，默认折叠）</summary>'
                .'<p class="g-cap" style="margin:8px 0 0">'.$caption.'</p>'.$table.'</details>';
        }

        return '<div class="g-card"><h3>'.$title.'（'.count($rows).' 条）</h3>'
            .'<p class="g-cap" style="margin:0">'.$caption.'</p>'.$table.'</div>';
    }

    /**
     * 单行。
     *
     * @param  array<string,mixed>  $row
     */
    private static function questionRow(array $row): string
    {
        $stage = (string) ($row['stage'] ?? '');
        $angle = (string) ($row['angle'] ?? '');
        $class = (string) ($row['class'] ?? '');
        $intent = (string) ($row['intent'] ?? '');
        $route = (string) ($row['route'] ?? '');
        $ctype = (string) ($row['content_type'] ?? '');
        $concern = (string) ($row['concern'] ?? '');
        $concernAlt = (string) ($row['concern_alt'] ?? '');
        $expected = (string) ($row['expected'] ?? '');
        $why = (string) ($row['why'] ?? '');

        $routePill = match ($route) {
            'direct' => '<span class="g-pill g-pill--ok">本篇覆盖</span>',
            'sidelong' => '<span class="g-pill">顺带覆盖</span>',
            'own_source' => '<span class="g-pill g-pill--warn">自有信源页</span>',
            'source_build' => '<span class="g-pill g-pill--bad">转信源建设</span>',
            default => '<span class="g-pill">'.self::e($route).'</span>',
        };

        $concernText = self::e($concern);
        if ($concernAlt !== '') {
            $concernText .= '<span class="g-cap" style="display:block;margin:0">+ '.self::e($concernAlt).'</span>';
        }

        return '<tr>'
            .self::td((string) (int) ($row['position'] ?? 0), 'color:#9ca3af')
            .self::td('<div style="font-weight:600;color:#111827;word-break:break-word">'
                .self::e((string) ($row['question'] ?? '')).'</div>'
                .'<div class="g-cap" style="margin:4px 0 0;word-break:break-word">'.self::e($why).'</div>')
            .self::td(self::e(GeoTitleQuestionIndustry::stageLabel($stage)).'<br>'
                .'<span class="g-cap" style="margin:0">'.self::e(GeoTitleQuestionIndustry::angleLabel($angle)).'</span>')
            .self::td('<b>'.$class.'</b> '.self::e($class === '' ? '' : GeoTitleQuestionIndustry::classLabel($class))
                .'<br><span class="g-cap" style="margin:0">'.self::e(GeoTitleQuestionIndustry::intentLabel($intent)).'</span>')
            .self::td(self::e(GeoTitleQuestionIndustry::contentTypeLabel($ctype))
                .'<br><span class="g-cap" style="margin:0">'.self::e($ctype).'</span>')
            .self::td($concernText)
            .self::td(self::e($expected))
            .self::td($routePill)
            .'</tr>';
    }

    /**
     * 空转体检：哪些格本该出题却没出。
     *
     * 这个块的价值在于「矩阵退化成只有 B 类」这件事必须可见 ——
     * 没有它，运营只会觉得"这次出的题有点单调"，看不出是档案缺竞品。
     *
     * @param  array<string,mixed>  $batch
     */
    private static function skippedCard(array $batch): string
    {
        $skipped = (array) ($batch['skipped_cells'] ?? []);
        if ($skipped === []) {
            return '';
        }

        $trs = '';
        foreach ($skipped as $s) {
            if (! is_array($s)) {
                continue;
            }
            $cell = (string) ($s['cell'] ?? '');
            $reason = (string) ($s['detail'] ?? ($s['reason'] ?? ''));
            $code = (string) ($s['code'] ?? '');
            $label = $cell !== '' ? GeoTitleQuestionIndustry::angleLabel((string) strtok($cell, ':')).':'.$cell : '—';
            $trs .= '<tr>'
                .self::td(self::e($cell), 'font-family:ui-monospace,monospace;color:#6b7280')
                .self::td(self::e($reason) . ($code !== '' ? ' <span class="g-cap">['.self::e($code).']</span>' : ''))
                .'</tr>';
        }

        return '<details class="g-card"><summary style="cursor:pointer;font-weight:600;font-size:14px">'
            .'空转体检（'.count($skipped).' 个格没出题）</summary>'
            .'<p class="g-cap" style="margin:8px 0 0">'
            .'「缺占位符」= 品牌档案里没有竞品/产品，补档案就能开；'
            .'「未承诺该角度」= 标题没这个动作词，改标题才有用。</p>'
            .'<div style="overflow-x:auto;margin-top:10px"><table style="width:100%;min-width:640px;'
            .'border-collapse:collapse;font-size:12.5px">'
            .'<thead><tr>'.self::th('格子', '200px').self::th('原因', '').'</tr></thead>'
            .'<tbody>'.$trs.'</tbody></table></div></details>';
    }

    /**
     * 被淘汰的候选（折叠）。
     *
     * 规则上「抢不到」不输出、不进任何清单，所以它们不在问题表里。
     * 但完全不给人看就无法复盘「为什么这条被淘汰」，所以按批次留档在这里。
     *
     * @param  array<string,mixed>  $batch
     */
    private static function rejectedCard(array $batch): string
    {
        $rejected = (array) ($batch['rejected'] ?? []);
        if ($rejected === []) {
            return '';
        }

        $trs = '';
        foreach ($rejected as $r) {
            if (! is_array($r)) {
                continue;
            }
            $trs .= '<tr>'
                .self::td(self::e((string) ($r['drop'] ?? '')), 'font-family:ui-monospace,monospace;color:#b91c1c')
                .self::td(self::e((string) ($r['q'] ?? '')))
                .self::td(self::e((string) ($r['detail'] ?? '')), 'color:#6b7280')
                .'</tr>';
        }

        return '<details class="g-card"><summary style="cursor:pointer;font-weight:600;font-size:14px">'
            .'被淘汰的候选（'.count($rejected).' 条，按规则不输出）</summary>'
            .'<p class="g-cap" style="margin:8px 0 0">'
            .'规则原文是「抢不到：不输出，也不出现在任何清单里」。这里只是留档，用来复盘为什么被淘汰。</p>'
            .'<div style="overflow-x:auto;margin-top:10px"><table style="width:100%;min-width:720px;'
            .'border-collapse:collapse;font-size:12.5px">'
            .'<thead><tr>'.self::th('原因', '90px').self::th('问题', '').self::th('详情', '320px').'</tr></thead>'
            .'<tbody>'.$trs.'</tbody></table></div></details>';
    }

    /** 固定免责声明（不可关闭、固定位置） */
    private static function declarationCard(string $declaration): string
    {
        $text = trim($declaration) !== '' ? $declaration : GeoTitleQuestionStore::DECLARATION;

        return '<div class="g-card" style="background:#f9fafb">'
            .'<p style="margin:0;font-size:12.5px;color:#4b5563;line-height:1.8">'
            .self::e($text).'</p>'
            .'<p class="g-cap" style="margin:6px 0 0">'
            .'这句话是固定的，不可关闭 —— 推导结果不等于实测结果，'
            .'别把它当成「AI 真的这样问过」。</p></div>';
    }

    // =========================================================================
    // 小工具
    // =========================================================================

    /** 键值小卡 */
    private static function kv(string $k, string $v): string
    {
        return '<div style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb">'
            .'<div class="g-cap" style="margin:0">'.self::e($k).'</div>'
            .'<div style="font-size:12.5px;font-weight:600;color:#111827;word-break:break-all">'
            .self::e($v).'</div></div>';
    }

    /** 统计卡 */
    private static function stat(string $label, string $value, string $unit, string $hint = ''): string
    {
        $hintHtml = $hint !== ''
            ? '<div class="g-cap" style="margin:4px 0 0;white-space:normal">'.self::e($hint).'</div>'
            : '';

        return '<div style="padding:12px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#fff">'
            .'<div class="g-cap" style="margin:0">'.self::e($label).'</div>'
            .'<div style="font-size:20px;font-weight:700;color:#111827;line-height:1.3">'.self::e($value)
            .'<span style="font-size:12px;font-weight:500;color:#6b7280;margin-left:3px">'.self::e($unit).'</span></div>'
            .$hintHtml.'</div>';
    }

    /**
     * 分布 → 一行文字（"评测 4 / 信任 2"）。
     *
     * @param  array<string,int>  $dist
     * @param  callable(string):string  $labeler
     */
    private static function distText(array $dist, callable $labeler): string
    {
        if ($dist === []) {
            return '';
        }
        arsort($dist);
        $parts = [];
        foreach ($dist as $k => $n) {
            $parts[] = $labeler((string) $k).' '.$n;
        }

        return implode(' / ', $parts);
    }

    private static function th(string $text, string $width): string
    {
        $w = $width !== '' ? 'width:'.$width.';' : '';

        return '<th style="'.$w.'text-align:left;padding:8px 10px;border-bottom:1px solid #e5e7eb;'
            .'font-size:12px;color:#6b7280;font-weight:600;white-space:nowrap">'.self::e($text).'</th>';
    }

    private static function td(string $html, string $style = ''): string
    {
        $s = $style !== '' ? $style.';' : '';

        return '<td style="'.$s.'vertical-align:top;padding:8px 10px;border-bottom:1px solid #f3f4f6">'
            .$html.'</td>';
    }

    private static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 与其它 geo 报告页一致的页面外壳 */
    private static function shell(string $body, string $title): string
    {
        return '<style>'.GeoUi::css().'</style>'
            .'<div class="geo-ui" data-geo-title="'.self::e($title).'" '
            .'style="max-width:1240px;margin:0 auto;padding:4px 4px 48px;font:14px/1.7 var(--g-font);color:#111827">'
            .$body.'</div>'.GeoUi::busyOverlay();
    }
}
