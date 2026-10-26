<?php

namespace App\Support\GeoAudit;

/**
 * 官网诊断报告渲染（HTML / Markdown）
 * 与服务端逻辑分离，便于单独调整版式。
 */
final class GeoAuditReport
{
    public static function formPage(string $url = '', string $error = ''): string
    {
        $err = $error !== '' ? '<div class="err">'.self::e($error).'</div>' : '';
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

        // 最近记录（只在本页内展示，不做独立记录中心；最多保留 10 条）
        $recentRows = '';
        try {
            foreach ((new GeoStore())->recent(10) as $row) {
                $meta = json_decode((string) ($row->meta ?? '{}'), true) ?: [];
                $kindLabel = ['audit' => '诊断', 'advise' => '解读', 'plan' => '方案'][$row->kind] ?? $row->kind;
                $score = isset($meta['total']) ? (int) $meta['total'].' 分' : '—';
                $recentRows .= '<tr>'
                    .'<td style="white-space:nowrap;color:#6b7280;border:1px solid #e5e7eb;padding:8px 10px">'.self::e((string) $row->created_at).'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 10px">'.$kindLabel.'</td>'
                    .'<td style="max-width:320px;word-break:break-all;border:1px solid #e5e7eb;padding:8px 10px">'.self::e((string) $row->url).'</td>'
                    .'<td style="white-space:nowrap;border:1px solid #e5e7eb;padding:8px 10px">'.$score.'</td>'
                    .'<td style="white-space:nowrap;border:1px solid #e5e7eb;padding:8px 10px">'
                    // 三个操作：查看详情 / 下载已存档的 Markdown / 删除这条记录
                    // 下载走 ?download=1&id=，由路由直接从 geo_ai_outputs.content 取，不重跑诊断
                    .'<a class="g-btn g-btn--soft" style="font-size:11.5px;padding:5px 10px;margin-right:6px" '
                    .'href="?view='.(int) $row->id.'">查看</a>'
                    .'<a class="g-btn g-btn--ghost" style="font-size:11.5px;padding:5px 10px;margin-right:6px" '
                    .'href="?download=1&id='.(int) $row->id.'">下载 Markdown</a>'
                    // 删除必须是 POST + CSRF：GET 链接会被预取或第三方 <img src> 触发误删
                    .'<form method="POST" action="'.self::e($adminBase.'/geo-audit').'" style="display:inline" '
                    .'onsubmit="return confirm(\'确定删除这条记录？删除后不可恢复。\')">'
                    .'<input type="hidden" name="_token" value="'.self::e((string) csrf_token()).'">'
                    .'<input type="hidden" name="_action" value="delete">'
                    .'<input type="hidden" name="id" value="'.(int) $row->id.'">'
                    .'<button type="submit" class="g-btn g-btn--ghost" '
                    .'style="font-size:11.5px;padding:5px 10px;color:#b91c1c;border-color:#fecaca">删除</button>'
                    .'</form>'
                    .'</td></tr>';
            }
        } catch (\Throwable $e) {
            // 【本地修复】原来完全静默：GeoStore 一旦出问题，整张「最近的 GEO 记录」表会凭空消失，
            // 页面不报错、日志也不记，排查时只会觉得"记录没了"却找不到原因。
            \Illuminate\Support\Facades\Log::warning(
                'geo_audit.recent_records_failed: '.mb_substr($e->getMessage(), 0, 300, 'UTF-8')
            );
            $recentRows = '';
        }
        $recentBlock = $recentRows === '' ? '' : '<div class="card" style="margin-top:18px"><h3>最近的 GEO 记录</h3>'
            .'<p class="cap">每次诊断、AI 解读、方案生成会自动存档，最多保留最近 10 条</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:12.5px">'
            .'<thead><tr>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">时间</th>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">类型</th>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">网址</th>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">分数</th>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">操作</th>'
            .'</tr></thead><tbody>'.$recentRows.'</tbody></table></div>';

        $body = <<<HTML
<div class="hero">
  <h2>输入网址，生成官网诊断报告</h2>
  <p class="sub">检查这个页面<strong>能不能被 AI 抓到、读懂、并愿意引用</strong>——抓取入口、预览元信息、结构化语义、内容组织、答案表达、引用可信，六个维度共 24 项。</p>
  {$err}
  <form method="get" action="">
    <input type="text" name="url" value="__URL__" placeholder="例如：https://www.haiyangzhizun.com" autofocus>
    <input type="hidden" name="auto" value="1">
    <button type="submit">开始诊断</button>
  </form>
  <div class="hint">支持 https/http；只读取页面 HTML，不提交任何数据到第三方。耗时通常 1–3 秒。</div>
</div>
HTML;
        $body = str_replace(['__URL__', '__ADMINBASE__'], [self::e($url), $adminBase], $body);
        $body .= $recentBlock;

        return self::page($body, '官网诊断');
    }

    /** @param array<string,mixed> $r */
    public static function reportPage(array $r, ?string $topHtml = null): string
    {
        if (! ($r['ok'] ?? false)) {
            $body = '<div class="hero"><h2>诊断失败</h2>'
                .'<div class="err">目标：'.self::e((string) ($r['url'] ?? '')).'<br>原因：'.self::e((string) ($r['error'] ?? '')).'</div>'
                .'<form method="get" action=""><input type="text" name="url" value="'.self::e((string) ($r['url'] ?? '')).'">'
                .'<input type="hidden" name="auto" value="1"><button type="submit">重试</button></form>'
                .'<div class="hint">常见原因：站点不可达 / 需要登录 / 拒绝非浏览器 UA / 只在特定网络可访问。</div></div>';

            return self::page($body, '诊断失败');
        }

        $s = $r['stats'];
        $dims = '';
        foreach ($r['dimension_scores'] as $k => $v) {
            $dims .= '<div class="dim"><div class="n">'.self::e((string) $k).'</div>'
                .'<div class="v" style="color:'.self::color($v).'">'.$v.'</div>'
                .'<div><div class="bar"><i style="width:'.$v.'%;background:'.self::color($v).'"></i></div></div></div>';
        }

        $funnel = '';
        foreach ($r['funnel'] as [$name, $score]) {
            $funnel .= '<div class="fstep"><div class="t">'.self::e((string) $name).'</div>'
                .'<div class="s" style="color:'.self::color($score).'">'.$score.'%</div></div>';
        }

        $chapters = [];
        foreach ($r['modules'] as $m) {
            $chapters[$m['chapter']][] = $m;
        }
        $blocks = '';
        foreach ($chapters as $chap => $mods) {
            $cards = '';
            foreach ($mods as $m) {
                $cards .= '<div class="mod"><div class="top"><span class="nm">'.self::e($m['name']).'</span>'
                    .'<span class="sc" style="color:'.self::color($m['score']).'">'.$m['score'].'</span></div>'
                    .'<div class="ev">'.self::e($m['evidence']).'</div>'
                    .'<div class="sg">'.self::e($m['suggestion']).'</div></div>';
            }
            $blocks .= '<div class="chap">'.self::e((string) $chap).'</div><div class="grid">'.$cards.'</div>';
        }

        $found = '';
        foreach ($r['schema_found'] as $t) {
            $found .= '<span class="chip ok">'.self::e($t).'</span>';
        }
        $found = $found !== '' ? $found : '<span class="chip">无</span>';
        $missing = '';
        foreach ($r['schema_missing'] as $t) {
            $missing .= '<span class="chip miss">'.self::e($t).'</span>';
        }
        $missing = $missing !== '' ? $missing : '<span class="chip">无</span>';

        $phases = '';
        $labels = ['P0' => 'P0 · 立即修复', 'P1' => 'P1 · 本轮补齐', 'P2' => 'P2 · 持续优化'];
        foreach ($labels as $ph => $label) {
            $items = $r['roadmap'][$ph] ?? [];
            if ($items === []) {
                continue;
            }
            $lis = '';
            foreach ($items as $i) {
                $lis .= '<li><b>'.self::e($i['chapter'].' / '.$i['name']).'</b>（'.$i['score'].' 分）→ '.self::e($i['suggestion']).'</li>';
            }
            $phases .= '<div class="phase"><b>'.$label.'</b><ul>'.$lis.'</ul></div>';
        }

        $pillClass = $r['total'] >= 85 ? 'pass' : ($r['total'] >= 70 ? 'warn' : 'fail');

        // P1：一句话结论（内容侧 vs 机器侧），比裸分数更好懂
        $dimRaw = (array) ($r['dimension_scores'] ?? []);
        $contentSide = (int) round((((int) ($dimRaw['四、内容组织'] ?? 0)) + ((int) ($dimRaw['五、答案表达'] ?? 0))) / 2);
        $machineSide = (int) round((((int) ($dimRaw['一、抓取入口'] ?? 0)) + ((int) ($dimRaw['三、结构化语义'] ?? 0))) / 2);
        $readability = match (true) {
            $machineSide < 40 && $contentSide >= 60 => '内容能读懂，机器读不懂（结构几乎没打开）',
            $machineSide < 40 && $contentSide < 60 => '机器入口基本没打开，内容也偏薄，两头都要补',
            $machineSide >= 70 && $contentSide < 55 => '结构齐全，但内容偏薄（有入口没干货）',
            $contentSide >= 70 && $machineSide >= 70 => '基础均衡，机会在长尾覆盖与内容深度',
            default => '有基础，但内容侧与机器侧各有一处明显短板',
        };
        $readabilityHtml = '<p style="font-size:12.5px;color:#4b5563;margin:10px 0 0">'
            .'<b>AI 可读性</b>：'.$readability
            .' <span class="tag" style="margin-left:6px">内容侧 '.$contentSide.'</span>'
            .' <span class="tag">机器侧 '.$machineSide.'</span></p>';

        // llms.txt 检测结果 + 可直接交付的模板
        $llms = (array) ($r['llms'] ?? []);
        $llmsTpl = '';
        try {
            $llmsTpl = (new GeoAuditSchema())->llmsTemplate($r);
        } catch (\Throwable $e) {
            // 降级：模板生成失败就不展示，但留一条日志，别让"模板突然没了"变成无头案
            \Illuminate\Support\Facades\Log::warning(
                'geo_audit.llms_status_failed: '.mb_substr($e->getMessage(), 0, 300, 'UTF-8')
            );
        }
        $llmsStatusHtml = ((bool) ($llms['exists'] ?? false))
            ? '<span style="color:#047857;font-weight:700">已存在</span>（'.number_format(((int) ($llms['bytes'] ?? 0)) / 1024, 1).' KB，'.((int) ($llms['links'] ?? 0)).' 个链接）'
            : '<span style="color:#b45309;font-weight:700">未检测到</span>'
                .'（HTTP '.((int) ($llms['status'] ?? 0)).'）—— 建议由客户 IT 在域名根目录放一个，模板见下';

        // ---- 诊断总述（规则生成，无需模型）----
        $sorted = $r['modules'];
        usort($sorted, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $strengths = array_slice(array_values(array_filter($sorted, static fn (array $m): bool => $m['score'] >= 90)), 0, 3);
        $gaps = array_slice(array_values(array_filter(array_reverse($sorted), static fn (array $m): bool => $m['score'] < 70)), 0, 3);
        $topActions = array_slice($r['roadmap']['P0'] ?? [], 0, 2);

        $conclusion = match (true) {
            $r['total'] >= 85 => '这个站点已经具备较好的 GEO 基础，主要机会在于把已有优势做深、把长尾结构补齐。',
            $r['total'] >= 70 => '基础框架在，但关键结构仍有缺口。优先修掉 P0 项，通常能带来最明显的提升。',
            $r['total'] >= 55 => '机器可读性基础偏弱：内容人看得懂，但 AI 抓取与抽取时会丢信息。建议先补齐基础标签，再谈内容深度。',
            default => '机器可读性基础明显缺失（常见于老式建站）。这不代表内容不好，而是"没有把内容翻译成机器能懂的结构"。',
        };

        $liStrengths = '';
        foreach ($strengths as $m) {
            $liStrengths .= '<li>'.self::e($m['name']).'（'.$m['score'].'）</li>';
        }
        if ($liStrengths === '') {
            $liStrengths = '<li>暂无明显优势项，先把基础项补起来</li>';
        }
        $liGaps = '';
        foreach ($gaps as $m) {
            $liGaps .= '<li>'.self::e($m['name']).'（'.$m['score'].'）</li>';
        }
        $liActions = '';
        foreach ($topActions as $a) {
            $liActions .= '<li><b>'.self::e($a['name']).'</b> → '.self::e($a['suggestion']).'</li>';
        }

        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $uEnc = urlencode((string) $r['final_url']);
        $adviseBase = $adminBase.'/geo-audit/advise?url='.$uEnc;
        // 方案表单带上诊断结果，避免用户重新填一遍背景
        $planUrl = $adminBase.'/geo-plan?url='.$uEnc.'&from_audit=1&score='.(int) $r['total'].'&grade='.urlencode((string) $r['grade']);
        $explainHtml = '<div class="card" id="explain"><h3>诊断总述</h3><p class="cap">一句话看懂这份报告</p>'
            .'<p style="font-size:13.5px;margin:0 0 16px">'.self::e($conclusion).'</p>'
            .'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">'
            .'<div><p style="font-size:12.5px;font-weight:700;margin:0 0 6px">优先动作</p><ul style="margin:0;padding-left:18px;font-size:12.5px;color:#374151">'.$liActions.'</ul></div>'
            .'<div><p style="font-size:12.5px;font-weight:700;margin:0 0 6px">已具备优势</p><ul style="margin:0;padding-left:18px;font-size:12.5px;color:#047857">'.$liStrengths.'</ul></div>'
            .'<div><p style="font-size:12.5px;font-weight:700;margin:0 0 6px">主要缺口</p><ul style="margin:0;padding-left:18px;font-size:12.5px;color:#b91c1c">'.$liGaps.'</ul></div>'
            .'</div></div>';
        $mdUrl = '?format=md&url='.urlencode((string) $r['final_url']);

        $body = <<<HTML
<div class="hero">
  <form method="get" action="">
    <input type="text" name="url" value="__FINAL__">
    <input type="hidden" name="auto" value="1">
    <button type="submit">重新诊断</button>
  </form>
  <div class="hint">目标：__FINAL__ · 抓取 __MS__ ms · HTTP __STATUS__ · 正文约 __TEXT__ 字 · Schema 命中 __SCHEMA__ 类</div>
</div>

<div class="card">
  <div class="score-row">
    <div class="score-big">__TOTAL__<small> / 100</small></div>
    <div><span class="pill __PILL__">GEO 评分：__GRADE__</span>__READABILITY__</div>
    <div style="flex:1;min-width:220px"><a class="dl" href="__MDURL__">下载 Markdown 报告</a></div>
  </div>
</div>

__EXPLAIN__

<div class="card"><h3>AI 解读与分析</h3><p class="cap">基于上面这份诊断事实由模型生成（约 10–20 秒），事实部分仍来自规则引擎</p>
<div style="display:flex;gap:10px;flex-wrap:wrap">
  <a class="ai-btn g-btn g-btn--primary" href="__ADVISE__&type=explain">诊断报告解读</a>
  <a class="ai-btn g-btn g-btn--primary" href="__ADVISE__&type=content">内容结构优化</a>
  <a class="ai-btn g-btn g-btn--primary" href="__ADVISE__&type=answers">品牌可见性问答</a>
  <a class="ai-btn g-btn g-btn--primary" href="__PLANURL__">行动方案（生成完整方案）</a>
</div></div>

<div class="card"><h3>六维能力</h3><p class="cap">从抓取入口到权威引用的就绪度</p>__DIMS__</div>

<div class="card"><h3>GEO 就绪漏斗</h3><p class="cap">页面从被抓取到被引用的推进链</p><div class="funnel">__FUNNEL__</div></div>

<div class="card" id="llms"><h3>llms.txt（给 AI 看的站点说明书）</h3>
<p class="cap">放在域名根目录的纯文本文件，用来说明站点结构与核心页面。属社区提案，非官方标准，建议项而非必做项</p>
<p style="font-size:13px;margin:0 0 8px"><b>检测结果</b>：__LLMS_STATUS__</p>
<details style="font-size:12.5px;color:#4b5563"><summary style="cursor:pointer;font-weight:650;color:#374151">展开可复制的 llms.txt 模板（交给客户 IT 放到根目录）</summary>
<pre style="margin-top:10px">__LLMS_TPL__</pre></details></div>

<div class="card" id="method"><h3>评分怎么算的（口径说明）</h3>
<details style="font-size:12.5px;color:#4b5563;line-height:1.75">
  <summary style="cursor:pointer;font-weight:650;color:#374151">展开查看计分规则与局限</summary>
  <ul style="margin:10px 0 0;padding-left:18px">
    <li><b>计分</b>：24 项模块各自 0–100 分，总分为<b>等权平均</b>；六维分＝该维度 4 项的均值。</li>
    <li><b>等级</b>：≥85 优秀 / ≥70 良好 / ≥55 待优化 / &lt;55 亟需整改。</li>
    <li><b>事实来源</b>：直接读取页面 HTML 源码做静态检测，<b>不调用大模型</b>，同一页面结果可复现；不执行 JS，纯前端渲染站点可能被低估。</li>
    <li><b>没有行业基准</b>：分数用于纵向对比（改前 vs 改后），不能横向断言"比同行差"。</li>
    <li><b>首页 ≠ 文章页</b>：首段直答、阅读时长等规则更适合内容页，用在品牌首页会偏严。</li>
  </ul>
</details></div>

<div class="card" id="content"><h3>24 项诊断模块</h3><p class="cap">6 个章节 × 4 个模块，逐项打分与修复建议</p>__BLOCKS__</div>

<div class="card"><h3>结构化标记检测</h3><p class="cap">从页面 JSON-LD 提取 @type</p>
<p style="font-size:12.5px;margin:0 0 8px"><b>已检测到</b></p><div class="chips">__FOUND__</div>
<p style="font-size:12.5px;margin:14px 0 8px"><b>建议补充</b></p><div class="chips">__MISSING__</div></div>

<div class="card" id="roadmap"><h3>行动路线</h3><p class="cap">按失分排序自动分档</p>__PHASES__</div>
HTML;

        $body = (string) $topHtml . $body;

        $body = str_replace(
            ['__ADVISE__', '__PLANURL__', '__READABILITY__', '__LLMS_STATUS__', '__LLMS_TPL__', '__EXPLAIN__', '__FINAL__', '__MS__', '__STATUS__', '__TEXT__', '__SCHEMA__', '__TOTAL__', '__PILL__', '__GRADE__', '__MDURL__', '__DIMS__', '__FUNNEL__', '__BLOCKS__', '__FOUND__', '__MISSING__', '__PHASES__'],
            [
                self::e($adviseBase), self::e($planUrl), $readabilityHtml, $llmsStatusHtml, self::e($llmsTpl), $explainHtml, self::e((string) $r['final_url']), (string) $r['elapsed_ms'], (string) $r['http_status'],
                (string) $s['text_len'], (string) count($r['schema_found']), (string) $r['total'],
                $pillClass, self::e((string) $r['grade']), self::e($mdUrl), $dims, $funnel, $blocks, $found, $missing, $phases,
            ],
            $body
        );

        // 点击 4 个 AI 按钮时调用统一遮罩（GeoUi::busyOverlay 提供 window.geoBusy）
        $body .= <<<'HTML'
<script>
(function(){
  document.addEventListener('click',function(e){
    var el=e.target;
    while(el && el!==document){
      if(el.classList && (el.classList.contains('ai-btn') || el.classList.contains('gbtn-switch'))){
        if(window.geoBusy){ window.geoBusy(el.textContent.trim()+' 生成中…','正在调用模型生成内容，约 10–20 秒，请勿关闭页面'); }
        var btns=document.querySelectorAll('.ai-btn');
        for(var i=0;i<btns.length;i++){btns[i].style.pointerEvents='none';btns[i].style.opacity='.55';}
        return;
      }
      el=el.parentElement;
    }
  },true);
})();
</script>
HTML;

        return self::page($body, '官网诊断 · '.$r['total'].' 分 · '.(string) $r['final_url']);
    }

    /** @param array<string,mixed> $r */
    public static function markdown(array $r): string
    {
        if (! ($r['ok'] ?? false)) {
            return "# 官网诊断失败\n\n- 目标：".($r['url'] ?? '')."\n- 原因：".($r['error'] ?? '')."\n";
        }
        $lines = [];
        $lines[] = '# GEO 页面诊断报告：'.$r['final_url'];
        $lines[] = '';
        $lines[] = '**GEO 总分：'.$r['total'].' / 100（'.$r['grade'].'）**  ';
        $lines[] = '抓取耗时 '.$r['elapsed_ms'].' ms · HTTP '.$r['http_status'].' · 正文约 '.$r['stats']['text_len'].' 字';
        $lines[] = '';
        $lines[] = '## 一、六维得分';
        $lines[] = '';
        $lines[] = '| 维度 | 得分 |';
        $lines[] = '|---|---|';
        foreach ($r['dimension_scores'] as $k => $v) {
            $lines[] = '| '.$k.' | '.$v.' |';
        }
        $lines[] = '';
        $lines[] = '## 二、GEO 就绪漏斗';
        $lines[] = '';
        foreach ($r['funnel'] as [$name, $score]) {
            $lines[] = '- **'.$name.'** '.$score.'% '.str_repeat('█', max(1, (int) ($score / 5)));
        }
        $lines[] = '';
        $lines[] = '## 三、24 项模块明细';
        $cur = '';
        foreach ($r['modules'] as $m) {
            if ($m['chapter'] !== $cur) {
                $cur = $m['chapter'];
                $lines[] = '';
                $lines[] = '### '.$cur;
                $lines[] = '';
                $lines[] = '| 模块 | 得分 | 状态 | 证据 | 建议 |';
                $lines[] = '|---|---|---|---|---|';
            }
            $mark = $m['score'] >= 90 ? '✅' : ($m['score'] >= 70 ? '🟡' : '🔴');
            $lines[] = '| '.$m['name'].' | '.$m['score'].' | '.$mark.' | '.$m['evidence'].' | '.$m['suggestion'].' |';
        }
        $lines[] = '';
        $lines[] = '## 四、结构化标记检测';
        $lines[] = '';
        $lines[] = '- 已检测到：'.($r['schema_found'] !== [] ? implode(', ', $r['schema_found']) : '无');
        $lines[] = '- 建议补充：'.($r['schema_missing'] !== [] ? implode(', ', $r['schema_missing']) : '无');
        $lines[] = '';
        $lines[] = '## 五、行动路线';
        $lines[] = '';
        foreach (['P0', 'P1', 'P2'] as $ph) {
            $items = $r['roadmap'][$ph] ?? [];
            if ($items === []) {
                continue;
            }
            $lines[] = '### '.$ph;
            $lines[] = '';
            foreach ($items as $i) {
                $lines[] = '- **'.$i['chapter'].' / '.$i['name'].'**（'.$i['score'].' 分）→ '.$i['suggestion'];
            }
            $lines[] = '';
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '> 本报告由纯规则引擎生成（无大模型参与），结果可复现。';

        return implode("\n", $lines);
    }

    /** AI 解读结果页 @param array<string,mixed> $result @param array<string,mixed> $audit */
    public static function advisePage(array $result, array $audit): string
    {
        $md = (string) ($result['markdown'] ?? '');
        $html = self::markdownToHtml($md);

        $usage = $result['usage'] ?? [];
        $badges = '<span class="ai-badge">AI 生成</span>'
            .'<span class="ai-meta">模型 '.self::e((string) ($result['model'] ?? '')).'</span>'
            .'<span class="ai-meta">耗时 '.number_format(((int) ($result['elapsed_ms'] ?? 0)) / 1000, 1).' 秒</span>'
            .'<span class="ai-meta">tokens '.((int) ($usage['total_tokens'] ?? 0)).'</span>';

        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $urlQ = urlencode((string) ($result['url'] ?? ''));
        $typeNow = (string) ($result['type'] ?? 'explain');

        $dl = '?format=md&type='.urlencode($typeNow).'&url='.$urlQ;
        // 返回诊断报告：带上原网址，回去即可复现同一份报告（规则诊断 0.3 秒可重算，不会丢）
        $back = $adminBase.'/geo-audit?url='.$urlQ.'&auto=1';
        $answersLink = '';

        // 顶部工具条：返回 + 切换到其他 3 个功能
        $typeLabels = ['explain' => '诊断报告解读', 'content' => '内容结构优化', 'answers' => '品牌可见性问答', 'plan' => '行动方案拆解'];
        $switchers = '';
        foreach ($typeLabels as $k => $label) {
            if ($k === $typeNow) {
                continue;
            }
            $switchers .= '<a class="dl gbtn-switch" href="'.$adminBase.'/geo-audit/advise?type='.$k.'&url='.$urlQ.'">'.$label.'</a>';
        }
        $toolbar = '<div class="gabar"><a class="gbtn-back" href="'.self::e($back).'">← 返回诊断报告</a>'
            .'<span class="gsep">换个功能继续：</span>'.$switchers.'</div>';

        $schemaCards = '';
        if (($result['type'] ?? '') === 'content') {
            $drafts = (new GeoAuditSchema())->drafts($audit);
            foreach ($drafts as $d) {
                $code = (string) ($d['code'] ?? '');
                $schemaCards .= '<div style="margin:18px 0 0;padding-top:14px;border-top:1px dashed #e5e7eb">'
                    .'<p style="font-size:13px;font-weight:700;margin:0 0 4px">'.self::e((string) $d['label']).' <span class="ai-meta">'.self::e((string) $d['type']).'</span></p>'
                    .'<p style="font-size:12px;color:#6b7280;margin:0 0 8px">'.self::e((string) $d['note']).'</p>'
                    .($code !== '' ? '<pre>'.self::e($code).'</pre>' : '')
                    .'</div>';
            }
            $schemaCards = '<div class="card" style="margin-top:18px"><h3>可直接使用的 JSON-LD 骨架</h3>'
                .'<p class="cap">由规则引擎生成并已通过 JSON 语法校验（字段值来自本页真实抓取），AI 不参与生成，避免编造字段</p>'
                .$schemaCards.'</div>';
        }

        $body = $toolbar
            .'<div class="hero">'
            .'<h2>'.self::e((string) ($result['type_label'] ?? '诊断解读')).'</h2>'
            .'<p class="sub">基于规则诊断的真实事实（'.((int) ($result['total'] ?? 0)).' 分 / '.self::e((string) ($result['grade'] ?? '')).'）由模型生成可执行建议：'
            .self::e((string) ($result['url'] ?? '')).'</p>'
            .'<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">'.$badges.'</div>'
            .'<div style="margin-top:14px"><a class="dl" id="geo-dl-md" href="'.self::e($dl).'">下载 Markdown</a>'
            .'<a class="dl" href="'.self::e($adminBase.'/geo-audit/advise?type='.$typeNow.'&url='.$urlQ.'&retry=1').'" style="margin-left:8px">重新生成</a>'
            .'<a class="dl" href="'.self::e($back).'" style="margin-left:8px">返回诊断报告</a>'.$answersLink.'</div>'
            .'</div>'
            .'<div class="card md-body" style="font-size:14px;line-height:1.78">'.$html.'</div>'
            .$schemaCards
            .'<div class="gabar gabar--bottom"><a class="gbtn-back" href="'.self::e($back).'">← 返回诊断报告</a>'
            .'<span class="gsep">换个功能继续：</span>'.$switchers.'</div>'
            .'<div class="foot">本页建议由 AI 生成，事实来自规则诊断器；请结合业务判断后再落地。</div>'
            // 下载反馈：正常情况下直接命中已存内容（毫秒级），
            // 只有在"直接粘链接访问、还没有任何历史"时才需要现场生成，此时给出提示
            .'<script>(function(){var a=document.getElementById("geo-dl-md");if(!a)return;'
            .'a.addEventListener("click",function(){if(window.geoBusy){'
            .'window.geoBusy("正在准备下载","优先使用已生成的内容；若还没有历史记录，会现场生成一份，约 20 秒");}'
            .'setTimeout(function(){var b=document.getElementById("geo-busy");if(b)b.classList.remove("is-on");},1200);});})();</script>';
        // 规则生成的 JSON-LD 附在正文之后
        // （见上方 $schemaCards）

        return self::page($body, (string) ($result['type_label'] ?? 'AI 解读'));
    }

    /** @param array<string,mixed> $audit */
    public static function adviseErrorPage(string $url, string $type, string $message, array $audit): string
    {
        $body = '<div class="hero"><h2>AI 解读失败</h2>'
            .'<div class="err">目标：'.self::e($url).'<br>原因：'.self::e($message).'</div>'
            .'<p class="hint">规则诊断本身是成功的（'.((int) ($audit['total'] ?? 0)).' 分），只是模型调用没成功。</p>'
            .'<div style="margin-top:14px"><a class="dl" href="?type='.self::e($type).'&url='.urlencode($url).'">重试</a>'
            .'<a class="dl" href="/?url='.urlencode($url).'&auto=1" style="margin-left:8px">只看规则诊断报告</a></div></div>';

        return self::page($body, 'AI 解读失败');
    }

    private static function markdownToHtml(string $md): string
    {
        if (method_exists(\Illuminate\Support\Str::class, 'markdown')) {
            try {
                return (string) \Illuminate\Support\Str::markdown($md, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]);
            } catch (\Throwable $e) {
                // 落到简易渲染，但要留痕：markdown 扩展异常会让排版突然退化，没有日志很难定位
                \Illuminate\Support\Facades\Log::warning(
                    'geo_audit.markdown_render_failed: '.mb_substr($e->getMessage(), 0, 300, 'UTF-8')
                );
            }
        }
        $html = self::e($md);
        $html = preg_replace('/^### (.+)$/mu', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/mu', '<h3>$1</h3>', $html);
        $html = preg_replace('/^# (.+)$/mu', '<h2>$1</h2>', $html);
        $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html);
        $html = preg_replace('/^- (.+)$/mu', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*?<\/li>\n?)+/us', '<ul>$0</ul>', $html);

        return nl2br($html);
    }

    /** 渲染为片段，由 admin 布局包裹（保留侧边栏/顶栏） */
    private static function page(string $body, string $title): string
    {
        return '<style>'.GeoUi::css().'</style>'
            .'<style>'.self::css().'</style>'
            .'<div class="geo-ui geo-audit-wrap" data-geo-audit-title="'.self::e($title).'" '
            .'style="max-width:1080px;margin:0 auto;padding:4px 4px 48px;font:14px/1.7 var(--g-font);color:var(--g-txt)">'
            .$body
            .'</div>'.GeoUi::busyOverlay();
    }

    private static function color(int $score): string
    {
        return $score >= 90 ? '#10b981' : ($score >= 70 ? '#f59e0b' : '#ef4444');
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<'CSS'
.geo-audit-wrap{--bg:#f7f8fa;--card:#fff;--line:#e5e7eb;--txt:#111827;--muted:#6b7280;--brand:#4f46e5}
.geo-audit-wrap *{box-sizing:border-box}
.geo-audit-wrap{margin:0;background:var(--bg);color:var(--txt);font:14px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.geo-audit-wrap .wrap{max-width:1080px;margin:0 auto;padding:28px 20px 64px}
.geo-audit-wrap .head{display:flex;align-items:center;gap:10px;margin-bottom:22px}
.geo-audit-wrap .logo{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,#4f46e5,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px}
.geo-audit-wrap .head h1{font-size:17px;margin:0;font-weight:650}
.geo-audit-wrap .head .badge{font-size:11px;color:#6366f1;background:#eef2ff;border:1px solid #e0e7ff;padding:2px 8px;border-radius:99px;font-weight:600}
.geo-audit-wrap .hero{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:30px;margin-bottom:20px}
.geo-audit-wrap .hero h2{margin:0 0 8px;font-size:22px;letter-spacing:-.3px}
.geo-audit-wrap .hero p.sub{margin:0 0 20px;color:var(--muted);font-size:13px}
.geo-audit-wrap form{display:flex;gap:10px;flex-wrap:wrap}
.geo-audit-wrap input[type=text]{flex:1;min-width:260px;padding:13px 15px;border:1px solid #d1d5db;border-radius:11px;font-size:14px;outline:none}
.geo-audit-wrap input:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(79,70,229,.12)}
.geo-audit-wrap button{padding:13px 22px;border:0;border-radius:11px;background:var(--brand);color:#fff;font-size:14px;font-weight:600;cursor:pointer}
.geo-audit-wrap button:hover{background:#4338ca}
.geo-audit-wrap .hint{margin-top:12px;font-size:12px;color:var(--muted);word-break:break-all}
.geo-audit-wrap .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px 24px;margin-bottom:18px}
.geo-audit-wrap .card h3{margin:0 0 4px;font-size:15px;font-weight:650}
.geo-audit-wrap .card .cap{color:var(--muted);font-size:12px;margin:0 0 16px}
.geo-audit-wrap .score-row{display:flex;gap:26px;align-items:center;flex-wrap:wrap}
.geo-audit-wrap .score-big{font-size:52px;font-weight:750;letter-spacing:-2px;line-height:1}
.geo-audit-wrap .score-big small{font-size:16px;color:var(--muted);font-weight:500;letter-spacing:0}
.geo-audit-wrap .pill{display:inline-block;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:700}
.geo-audit-wrap .pill.pass{background:#ecfdf5;color:#047857}.pill.warn{background:#fffbeb;color:#b45309}.pill.fail{background:#fef2f2;color:#b91c1c}
.geo-audit-wrap .bar{height:9px;border-radius:99px;background:#eef0f3;overflow:hidden;margin-top:7px}
.geo-audit-wrap .bar>i{display:block;height:100%;border-radius:99px}
.geo-audit-wrap .dim{display:grid;grid-template-columns:150px 46px 1fr;gap:12px;align-items:center;margin-bottom:13px}
.geo-audit-wrap .dim .n{font-size:13px;color:#374151}.dim .v{font-size:13px;font-weight:700;text-align:right}
.geo-audit-wrap .funnel{display:flex;gap:8px;flex-wrap:wrap}
.geo-audit-wrap .fstep{flex:1;min-width:150px;border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:#fcfcfd}
.geo-audit-wrap .fstep .t{font-size:12px;color:var(--muted)}.fstep .s{font-size:20px;font-weight:750;margin-top:2px}
.geo-audit-wrap .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(232px,1fr));gap:12px}
.geo-audit-wrap .mod{border:1px solid var(--line);border-radius:12px;padding:13px 14px}
.geo-audit-wrap .mod .top{display:flex;justify-content:space-between;align-items:center;gap:8px}
.geo-audit-wrap .mod .nm{font-size:13px;font-weight:600}.mod .sc{font-size:13px;font-weight:750}
.geo-audit-wrap .mod .ev{font-size:11.5px;color:var(--muted);margin-top:6px;word-break:break-all}
.geo-audit-wrap .mod .sg{font-size:11.5px;color:#4b5563;margin-top:6px;padding-top:6px;border-top:1px dashed #eef0f3}
.geo-audit-wrap .chap{margin:22px 0 10px;font-size:13px;font-weight:700;color:#374151;display:flex;align-items:center;gap:8px}
.geo-audit-wrap .chap:before{content:"";width:3px;height:14px;border-radius:2px;background:var(--brand)}
.geo-audit-wrap .chips{display:flex;gap:7px;flex-wrap:wrap}
.geo-audit-wrap .chip{font-size:12px;padding:4px 10px;border-radius:99px;background:#f3f4f6;color:#374151;font-family:ui-monospace,monospace}
.geo-audit-wrap .chip.ok{background:#ecfdf5;color:#047857}.chip.miss{background:#fef2f2;color:#b91c1c}
.geo-audit-wrap .phase{border-left:3px solid var(--brand);padding-left:14px;margin:14px 0}
.geo-audit-wrap .phase b{font-size:13px}.phase ul{margin:8px 0 0;padding-left:18px}
.geo-audit-wrap .phase li{font-size:12.5px;color:#374151;margin-bottom:5px}
.geo-audit-wrap .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:14px 16px;font-size:13px;margin-bottom:18px}
.geo-audit-wrap .dl{display:inline-block;margin-top:6px;font-size:12.5px;color:var(--brand);text-decoration:none;border:1px solid #e0e7ff;background:#eef2ff;padding:7px 13px;border-radius:9px;font-weight:600}
.geo-audit-wrap .foot{color:var(--muted);font-size:12px;text-align:center;margin-top:26px}
.geo-audit-wrap .gabar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 16px;margin-bottom:16px}
.geo-audit-wrap .gabar--bottom{margin-top:22px;margin-bottom:0}
.geo-audit-wrap .gbtn-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:#111827;text-decoration:none;background:#f3f4f6;border:1px solid #d1d5db;padding:8px 14px;border-radius:9px}
.geo-audit-wrap .gbtn-back:hover{background:#e5e7eb}
.geo-audit-wrap .gsep{font-size:12px;color:var(--muted);margin-left:6px}
.geo-audit-wrap .ai-btn{display:inline-block;text-decoration:none;font-size:13px;font-weight:650;color:#fff;background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:10px 16px;border-radius:10px}
.geo-audit-wrap .ai-btn:hover{filter:brightness(1.08)}
.geo-audit-wrap .ai-badge{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px}
.geo-audit-wrap .ai-meta{font-size:11.5px;color:#6b7280;background:#f3f4f6;border-radius:99px;padding:3px 10px}
.geo-audit-wrap .md-body h2{font-size:19px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eef0f3}
.geo-audit-wrap .md-body h3{font-size:16px;margin:18px 0 8px}
.geo-audit-wrap .md-body h4{font-size:14.5px;margin:14px 0 6px;color:#374151}
.geo-audit-wrap .md-body table{width:100%;border-collapse:collapse;margin:12px 0;font-size:12.5px}
.geo-audit-wrap .md-body th, .geo-audit-wrap .md-body td{border:1px solid #e5e7eb;padding:8px 10px;text-align:left;vertical-align:top}
.geo-audit-wrap .md-body th{background:#f9fafb;font-weight:650}
.geo-audit-wrap .md-body ul, .geo-audit-wrap .md-body ol{padding-left:20px;margin:10px 0}
.geo-audit-wrap .md-body li{margin:4px 0}
.geo-audit-wrap .md-body p{margin:10px 0}
.geo-audit-wrap .md-body code{background:#f3f4f6;padding:1px 5px;border-radius:4px;font-size:12.5px}
.geo-audit-wrap .md-body pre{background:#0f172a;color:#e5e7eb;padding:14px;border-radius:10px;overflow:auto;font-size:12.5px}
.geo-audit-wrap .md-body blockquote{border-left:3px solid #e0e7ff;margin:12px 0;padding:2px 14px;color:#4b5563}
@media(max-width:640px){.geo-audit-wrap .dim{grid-template-columns:110px 40px 1fr}.geo-audit-wrap .score-big{font-size:40px}}
CSS;
    }
}
