<?php

namespace App\Support\GeoAudit;

/**
 * 内容工坊 —— 表单页 / 结果页
 *
 * 两个模式共用一套页面：
 *   refine  旧文改造：粘贴已有稿子，重排成 AI 愿意引用的结构（保留原观点）
 *   explain 科普生成：给一个主题，从零写一篇结构化科普
 */
final class GeoContentStudioReport
{
    /**
     * @param  array<string,mixed>  $old
     */
    public static function formPage(array $old = [], string $error = ''): string
    {
        $mode = (string) ($old['mode'] ?? 'refine');
        if (! isset(GeoContentStudio::MODES[$mode])) {
            $mode = 'refine';
        }

        $brand = self::e((string) ($old['brand'] ?? ''));
        $industry = self::e((string) ($old['industry'] ?? ''));
        $audience = self::e((string) ($old['audience'] ?? ''));
        $angle = self::e((string) ($old['angle'] ?? ''));
        $topic = self::e((string) ($old['topic'] ?? ''));
        $raw = self::e((string) ($old['raw'] ?? ''));

        // 平台与模型（平台改成多选：选 1 个 = 单篇，选 N 个 = 一文多发）
        $platforms = GeoPlatforms::all();

        // 兼容旧的单选参数 ?platform=xxx
        $curPlatforms = [];
        if (isset($old['platforms']) && is_array($old['platforms'])) {
            $curPlatforms = array_values(array_filter(array_map('strval', $old['platforms']), static fn (string $s): bool => isset($platforms[$s])));
        }
        if ($curPlatforms === []) {
            $legacy = (string) ($old['platform'] ?? '');
            $curPlatforms = isset($platforms[$legacy]) ? [$legacy] : [GeoPlatforms::defaultSlug()];
        }

        $curModel = (int) ($old['model_id'] ?? 0);

        $platformChecks = '';
        foreach ($platforms as $slug => $p) {
            $w = (array) ($p['words'] ?? [0, 0]);
            $on = in_array($slug, $curPlatforms, true);
            $platformChecks .= '<label style="display:flex;align-items:flex-start;gap:9px;padding:10px 12px;border:1px solid '
                .($on ? '#4f46e5' : '#d1d5db').';border-radius:10px;cursor:pointer;background:'
                .($on ? '#f5f3ff' : '#fff').'">'
                .'<input type="checkbox" name="platforms[]" value="'.self::e($slug).'"'.($on ? ' checked' : '').' class="cs-plat" style="margin-top:3px;accent-color:#4f46e5">'
                .'<span style="line-height:1.45"><b style="font-size:13px">'.self::e((string) ($p['name'] ?? $slug)).'</b>'
                .'<br><span style="font-size:11.5px;color:#6b7280">'.(int) ($w[0] ?? 0).'–'.(int) ($w[1] ?? 0).' 字</span></span>'
                .'</label>';
        }

        $modelOptions = '<option value="0">默认模型</option>';
        foreach (GeoChat::chatModels() as $m) {
            $modelOptions .= '<option value="'.$m['id'].'"'.($m['id'] === $curModel ? ' selected' : '').'>'
                .self::e($m['name'].'（'.$m['model_id'].'）').'</option>';
        }

        $inputStyle = 'width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13.5px';
        $labelStyle = 'font-size:12.5px;font-weight:600;color:#374151';
        $err = $error !== '' ? '<div class="g-err">'.self::e($error).'</div>' : '';

        // tab
        // tab 切换只带 mode：原文可能有几千字，拼进 URL 会超长度限制
        $tabs = '<div class="g-row" style="margin-bottom:16px">';
        foreach (GeoContentStudio::MODES as $key => $label) {
            $on = $key === $mode;
            $tabs .= '<a class="g-btn '.($on ? 'g-btn--primary' : 'g-btn--ghost').'" href="?mode='.$key.'">'.$label.'</a>';
        }
        $tabs .= '</div>';

        $ctxFields = '<div style="margin-top:16px;padding:14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px">'
            .'<div style="font-size:12.5px;font-weight:600;color:#374151;margin-bottom:9px">目标平台（可多选）'
            .'<span style="font-weight:400;color:#6b7280">—— 选 1 个 = 单篇；选多个 = 一文多发，逐平台原生生成</span></div>'
            .'<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:9px">'.$platformChecks.'</div>'
            .'<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px">'
            .'<label style="'.$labelStyle.'">生成模型'
            .'<select name="model_id" style="'.$inputStyle.'">'.$modelOptions.'</select></label>'
            .'<label style="'.$labelStyle.'">品牌（可选）'
            .'<input type="text" name="brand" value="'.$brand.'" placeholder="例如：冰溪" style="'.$inputStyle.'"></label>'
            .'<label style="'.$labelStyle.'">品类（可选）'
            .'<input type="text" name="industry" value="'.$industry.'" placeholder="例如：敏感肌护肤品" style="'.$inputStyle.'"></label>'
            .'<label style="'.$labelStyle.'">目标人群（可选）'
            .'<input type="text" name="audience" value="'.$audience.'" placeholder="例如：25–35 岁敏感肌女性" style="'.$inputStyle.'"></label>'
            .'<label style="'.$labelStyle.'">切入角度（可选）'
            .'<input type="text" name="angle" value="'.$angle.'" placeholder="例如：从成分安全切入，不谈功效" style="'.$inputStyle.'"></label>'
            .'</div></div>';

        // 用 POST：原文可能有几千字，走 GET 会撞 URL 长度上限（nginx 默认 8K）
        $csrf = '';
        try {
            $token = csrf_token();
            if (is_string($token) && $token !== '') {
                $csrf = '<input type="hidden" name="_token" value="'.self::e($token).'">';
            }
        } catch (\Throwable) {
            // CLI / 无 session 环境取不到 token，跳过（正常浏览器访问一定有）
        }

        if ($mode === 'refine') {
            $form = '<form method="post" action="" id="cs-form">'
                .$csrf
                .'<input type="hidden" name="plan" value="1">'
                .'<input type="hidden" name="mode" value="refine">'
                .'<label style="display:block;'.$labelStyle.'">原文（粘贴已有文章 / 公众号稿 / SEO 文）'
                .'<textarea name="raw" rows="12" placeholder="把原稿整篇粘进来。系统会保留原观点，只重排结构、补 FAQ、标证据边界，不会编造原文没有的数据。" style="'.$inputStyle.';resize:vertical;font-family:inherit;line-height:1.7">'.$raw.'</textarea></label>'
                .'<p class="g-cap" style="margin:8px 0 0">建议 500 字以上。少于 120 字没有改造价值。</p>'
                .$ctxFields
                .'<div class="g-row" style="margin-top:18px">'
                .'<button type="submit" class="g-btn g-btn--primary" id="cs-submit">开始生成</button>'
                .'<span class="g-cap" style="margin:0" id="cs-eta">只重排结构，不新增事实</span></div>'
                .'</form>';
        } else {
            $form = '<form method="post" action="" id="cs-form">'
                .$csrf
                .'<input type="hidden" name="plan" value="1">'
                .'<input type="hidden" name="mode" value="explain">'
                .'<label style="display:block;'.$labelStyle.'">主题 / 用户问题'
                .'<input type="text" name="topic" value="'.$topic.'" placeholder="例如：敏感肌能不能用酸　或　换季泛红怎么护理" style="'.$inputStyle.'"></label>'
                .'<p class="g-cap" style="margin:8px 0 0">直接写用户会搜的那句话，命中率比写"某某知识科普"高得多。</p>'
                .$ctxFields
                .'<div class="g-row" style="margin-top:18px">'
                .'<button type="submit" class="g-btn g-btn--primary" id="cs-submit">开始生成</button>'
                .'<span class="g-cap" style="margin:0" id="cs-eta">按所选平台的字数档位生成，含 FAQ 与 JSON-LD</span></div>'
                .'</form>';
        }

        $body = '<div class="g-card"><h3>内容工坊</h3>'
            .'<p class="g-cap">两条路都能产出「AI 愿意引用」的结构：直答首段 → H2 分章 → 列表承载 → FAQ → 证据边界 → JSON-LD</p>'
            .$err.$tabs.$form.'</div>'
            .'<div class="g-card"><h3>为什么还要约束模型</h3>'
            .'<p class="g-cap">DeepSeek 搜索明确会过滤低质 AIGC。<b>随便生成一堆文章发出去，反而被判低质</b>——'
            .'所以这两个功能的 prompt 有一半篇幅是约束，不是"写好看点"：</p>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr><th style="width:200px">约束</th><th>原因</th></tr></thead><tbody>'
            .'<tr><td>不许编造研究数据 / 专家姓名 / 认证 / 销量 / 排名</td><td>编造内容一旦被抓，品牌信用损失远大于排名收益</td></tr>'
            .'<tr><td>原文没依据的结论要标「未提供依据」</td><td>改造不等于镀金，把含糊表述洗成肯定句就是造假</td></tr>'
            .'<tr><td>不写产品功效承诺、不用绝对化表述</td><td>广告法风险，且绝对化表述会被搜索引擎降权</td></tr>'
            .'<tr><td>必须写「证据边界」段</td><td>AI 更愿意引用边界清晰的段落，因为可核验</td></tr>'
            .'<tr><td>不贬低竞品，只做同口径参数对比</td><td>合规要求，也是可信度来源</td></tr>'
            .'</tbody></table></div>'
            .'<script>(function(){var f=document.getElementById("cs-form");if(!f)return;'
            .'var plats=f.querySelectorAll(".cs-plat");'
            .'function picked(){var n=0;for(var i=0;i<plats.length;i++){if(plats[i].checked)n++;}return n;}'
            .'function syncEta(){var n=picked(),b=document.getElementById("cs-submit"),e=document.getElementById("cs-eta");'
            .'for(var i=0;i<plats.length;i++){var lb=plats[i].closest("label");if(lb){'
            .'lb.style.borderColor=plats[i].checked?"#4f46e5":"#d1d5db";lb.style.background=plats[i].checked?"#f5f3ff":"#fff";}}'
            .'if(b){b.textContent=n>1?("批量生成 "+n+" 个平台"):"开始生成";}'
            .'if(e){e.textContent=n===0?"请至少选一个平台":(n>1?("约 "+(n*35)+" 秒，逐平台生成，第一个出来就能先看"):"约 25–45 秒");}}'
            .'for(var i=0;i<plats.length;i++){plats[i].addEventListener("change",syncEta);}syncEta();'
            .'f.addEventListener("submit",function(ev){var b=document.getElementById("cs-submit");'
            .'var raw=f.querySelector("textarea[name=raw]"),tp=f.querySelector("input[name=topic]");'
            .'if(picked()===0){ev.preventDefault();'
            .'if(window.geoBusy){window.geoBusy("没有选平台","至少勾选一个目标平台，不同平台的写法完全不同");'
            .'setTimeout(function(){var x=document.getElementById("geo-busy");if(x)x.classList.remove("is-on");},2200);}return;}'
            .'if(raw&&raw.value.trim().length<120){ev.preventDefault();raw.focus();raw.style.borderColor="#ef4444";'
            .'if(window.geoBusy){window.geoBusy("原文太短","至少 120 字才值得改造，请粘贴完整文章");'
            .'setTimeout(function(){var x=document.getElementById("geo-busy");if(x)x.classList.remove("is-on");},2200);}return;}'
            .'if(tp&&!tp.value.trim()){ev.preventDefault();tp.focus();tp.style.borderColor="#ef4444";return;}'
            .'if(b){b.disabled=true;b.style.opacity=".65";b.textContent="准备中…";}'
            .'if(window.geoBusy){window.geoBusy("正在生成","模型在按平台结构重排并做事实边界检查，请勿关闭页面");}});})();</script>';

        return self::shell($body, '内容工坊');
    }

    /**
     * 单平台结果页（保留：直接访问 / 单平台流程用）
     *
     * @param  array<string,mixed>  $result
     */
    public static function resultPage(array $result, string $adminBase, string $mode): string
    {
        $label = (string) ($result['label'] ?? '');
        $slug = (string) ($result['platform']['slug'] ?? '');
        $platformName = (string) ($result['platform']['name'] ?? '');

        $body = '<div class="g-toolbar">'
            .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/content-studio?mode='.self::e($mode).'">← 再来一篇</a>'
            .'<a class="g-btn g-btn--primary" href="'.$adminBase.'/content-studio?download=1&p='.self::e($slug).'">下载 Markdown</a>'
            .'<span class="g-sep">'.self::e($label).' · <b>'.self::e($platformName ?: '未指定平台').'</b> · '
            .self::e((string) ($result['model'] ?? '')).' · 耗时 '
            .number_format(((int) ($result['elapsed_ms'] ?? 0)) / 1000, 1).' 秒 · tokens '.((int) ($result['usage']['total_tokens'] ?? 0)).'</span>'
            .'</div>'
            .self::resultBlock($result, $adminBase, $slug !== '' ? $slug : 'one');

        return self::shell($body, '内容工坊 · '.$label);
    }

    /**
     * 结果主体：统计卡 + 平台合规检查 + 正文 + JSON-LD + 核对清单。
     * 单平台页与批量 tab 共用；$uid 用来给 DOM id 加后缀，避免多 tab 时 id 冲突。
     *
     * @param  array<string,mixed>  $result
     */
    private static function resultBlock(array $result, string $adminBase, string $uid): string
    {
        $md = (string) ($result['markdown'] ?? '');
        $schema = (string) ($result['schema'] ?? '');
        $stats = (array) ($result['stats'] ?? []);
        $title = (string) ($result['title'] ?? '');
        $platformName = (string) ($result['platform']['name'] ?? '');

        $stat = static function (string $k, string $v, string $color = '#111827'): string {
            return '<div style="flex:1;min-width:92px;border:1px solid #e5e7eb;border-radius:12px;padding:10px 13px">'
                .'<div style="font-size:11px;color:#6b7280">'.self::e($k).'</div>'
                .'<div style="font-size:19px;font-weight:750;color:'.$color.';line-height:1.35">'.self::e($v).'</div></div>';
        };

        $chars = (int) ($stats['chars'] ?? 0);
        $statCards = '<div style="display:flex;gap:9px;flex-wrap:wrap;margin:14px 0">'
            .$stat('字数', (string) $chars, $chars >= 1500 ? '#047857' : '#b45309')
            .$stat('阅读', (($stats['minutes'] ?? 0)).' 分钟')
            .$stat('H2 章节', (string) ((int) ($stats['h2'] ?? 0)))
            .$stat('列表条数', (string) ((int) ($stats['lists'] ?? 0)))
            .$stat('问句', (string) ((int) ($stats['questions'] ?? 0)))
            .$stat('标签', (string) ((int) ($stats['hashtags'] ?? 0)))
            .$stat('JSON-LD', $schema !== ''
                ? ((string) ($result['schema_source'] ?? 'model') === 'fallback' ? '骨架' : '有')
                : '无',
                $schema === '' ? '#b91c1c' : ((string) ($result['schema_source'] ?? 'model') === 'fallback' ? '#b45309' : '#047857'))
            .'</div>';

        // ---------- 平台合规检查 ----------
        $pc = (array) ($result['platform_check'] ?? []);
        $pcItems = (array) ($pc['items'] ?? []);
        $pcPass = (int) ($pc['pass'] ?? 0);
        $pcTotal = (int) ($pc['total'] ?? 0);
        $pcBlock = '';
        if ($pcItems !== []) {
            $rows = '';
            foreach ($pcItems as $it) {
                $ok = ! empty($it['ok']);
                $soft = ! empty($it['soft']);
                $mark = $ok ? '✅' : ($soft ? '⚠️' : '❌');
                $color = $ok ? '#047857' : ($soft ? '#b45309' : '#b91c1c');
                $rows .= '<tr>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px;width:32px;text-align:center">'.$mark.'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px;width:180px">'.self::e((string) ($it['label'] ?? '')).'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px;color:'.$color.'">'.self::e((string) ($it['detail'] ?? '')).'</td>'
                    .'</tr>';
            }

            $allOk = $pcPass === $pcTotal && $pcTotal > 0;
            $pcBlock = '<div class="g-card"><h3>平台合规检查：'.$pcPass.' / '.$pcTotal.' 通过'
                .' <span class="g-pill '.($allOk ? 'g-pill--ok' : 'g-pill--warn').'" style="font-size:11px">'
                .($allOk ? '可以直接发' : '有项没过').'</span></h3>'
                .'<p class="g-cap">按【'.self::e($platformName ?: '当前平台').'】的档案规则做机器校验，纯规则、可复现。'
                .'❌ 是硬性不合规，⚠️ 是软性建议</p>'
                .'<table class="g-table" style="font-size:12.5px"><tbody>'.$rows.'</tbody></table>'
                .($allOk ? '' : '<p class="g-cap" style="margin:12px 0 0;color:#b45309">'
                    .'没过硬性项就重跑或手改。<b>禁用词是广告法风险，必须改掉。</b></p>')
                .'</div>';
        }

        // ---------- JSON-LD ----------
        $schemaBlock = '';
        if ($schema !== '') {
            $isFallback = (string) ($result['schema_source'] ?? 'model') === 'fallback';
            $note = $isFallback
                ? '<p class="g-cap" style="color:#b45309;margin:0 0 10px"><b>⚠ 这份是规则兜底生成的骨架</b>——'
                    .'模型这次没输出 JSON-LD（通常是正文写超长被截断）。里面只有 Article，缺 FAQPage，'
                    .'括号里的字段值必须自己补。</p>'
                : '<p class="g-cap" style="margin:0 0 10px">复制到页面的 <code>&lt;script type="application/ld+json"&gt;</code> 里。'
                    .'注意把里面的 URL、日期、作者改成真实值，别直接照抄。</p>';

            $schemaBlock = '<div class="g-card"><h3>JSON-LD 结构化数据</h3>'
                .$note
                .'<div style="position:relative">'
                .'<pre id="cs-schema-'.$uid.'" style="background:#0f172a;color:#e5e7eb;padding:14px;border-radius:10px;overflow:auto;max-height:340px;font-size:12px;line-height:1.6">'.self::e($schema).'</pre>'
                .'<button type="button" data-copy="cs-schema-'.$uid.'" class="g-btn g-btn--soft cs-copy" style="position:absolute;top:10px;right:10px;font-size:12px;padding:6px 12px">复制</button>'
                .'</div></div>';
        }

        // 截断预警：正文明显短于平台下限，且 token 接近上限 → 大概率是被截断了
        $truncWarn = '';
        if (! empty($result['truncated_risk'])) {
            $truncWarn = '<div class="g-err" style="background:#fffbeb;border-color:#fcd34d;color:#92400e">'
                .'<b>⚠ 输出可能被截断</b>：本次用满了 token 上限（'.((int) ($result['usage']['completion_tokens'] ?? 0)).' 个输出 token）。'
                .'如果正文字数明显低于平台要求，说明模型把额度耗在了别处（常见是把全文塞进 JSON-LD）。'
                .'建议重跑一次，或把主题写具体一点。</div>';
        }

        // ---------- 内容质量十维评分（纯规则，标注式）----------
        $sc = (array) ($result['scoring'] ?? []);
        $scoreBlock = '';
        if (! empty($sc['dimensions'])) {
            $scTotal = (float) ($sc['total'] ?? 0);
            $scGrade = (string) ($sc['grade'] ?? '');
            $scColor = (string) ($sc['grade_color'] ?? '#6b7280');

            // 图例：颜色点 + 圈数字 + 名称 + 权重
            $legend = '';
            foreach ($sc['dimensions'] as $d) {
                $legend .= '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;'
                    .'border-radius:99px;background:'.$d['color'].'14;font-size:11.5px;margin:0 6px 6px 0">'
                    .'<b style="color:'.$d['color'].'">'.$d['mark'].'</b>'
                    .self::e((string) $d['name'])
                    .'<span style="color:#9ca3af">'.(int) $d['weight'].'%</span></span>';
            }

            // 条形：分数的可视化 + 命中次数（标的是前 2 处）
            $bars = '';
            foreach ($sc['dimensions'] as $d) {
                $s = (int) $d['score'];
                $col = $s >= 85 ? '#047857' : ($s >= 60 ? '#b45309' : '#b91c1c');
                $ex = (array) ($d['examples'] ?? []);
                $tip = $ex !== [] ? self::e(implode(' / ', array_slice($ex, 0, 3))) : '这一项没有任何命中';
                $bars .= '<div style="display:flex;align-items:center;gap:9px;margin:5px 0" title="'.$tip.'">'
                    .'<span style="width:15px;color:'.$d['color'].';font-weight:700;font-size:13px">'.$d['mark'].'</span>'
                    .'<span style="width:74px;font-size:12px;color:#374151">'.self::e((string) $d['name']).'</span>'
                    .'<span style="width:32px;font-size:11px;color:#9ca3af;text-align:right">'.(int) $d['weight'].'%</span>'
                    .'<span style="flex:1;height:7px;background:#f3f4f6;border-radius:99px;overflow:hidden">'
                    .'<span style="display:block;height:100%;width:'.$s.'%;background:'.$d['color'].';border-radius:99px"></span></span>'
                    .'<span style="width:34px;text-align:right;font-size:12px;font-weight:700;color:'.$col.'">'.$s.'</span>'
                    .'<span style="width:60px;font-size:11px;color:#9ca3af;text-align:right">命中 '.(int) $d['hits'].'</span>'
                    .'</div>';
            }

            // 最该补：按"权重 × 未得分比例"排序，这才是投入产出最高的三项
            $gapBlock = '';
            if (! empty($sc['gaps'])) {
                $chips = '';
                foreach ($sc['gaps'] as $g) {
                    $chips .= '<span style="display:inline-flex;align-items:center;gap:6px;padding:5px 11px;'
                        .'border-radius:99px;background:#fef2f2;color:#b91c1c;font-size:12px;margin:0 6px 6px 0">'
                        .'<b>'.self::e((string) $g['name']).'</b><span style="color:#dc2626">丢 '.$g['loss'].' 分</span></span>';
                }
                $gapBlock = '<div style="margin-top:14px;padding-top:12px;border-top:1px dashed #e5e7eb">'
                    .'<div style="font-size:12px;color:#6b7280;margin-bottom:7px">'
                    .'<b style="color:#111827">最该补的三项</b>（按"权重 × 未得分比例"排，补这些分数涨得最快）</div>'
                    .$chips.'</div>';
            }

            $scoreBlock = '<div class="g-card"><h3>内容质量十维评分 '
                .'<span style="font-size:22px;font-weight:800;color:'.$scColor.'">'.number_format($scTotal, 1).'</span>'
                .' <span class="g-pill" style="background:'.$scColor.'14;color:'.$scColor.';font-size:11px">'.$scGrade.'</span>'
                .'</h3>'
                .'<p class="g-cap">合格线 70，优秀线 85。<b>十项权重来自「单篇标注页」的评分规则</b>，'
                .'判定全部用规则、不调模型 —— 同一篇跑两次分数必须一样，否则没法用来对比改前改后。<br>'
                .'正文里的圈数字就是标注点，颜色对应下面的图例；<b>每项最多标 2 处</b>（标全篇等于没标），'
                .'但"命中 N"是真实命中总数。</p>'
                .'<div style="margin:12px 0 4px">'.$legend.'</div>'
                .'<div style="margin-top:10px">'.$bars.'</div>'
                .$gapBlock
                .'<p class="g-cap" style="margin:12px 0 0">'
                .'<b>两项 0 分是正常的</b>：AI 直接生成的稿子通常没有真实数据来源，「统计数据」「溯源信息」天生缺失。'
                .'这两项加起来 24% 权重，是这类稿件最大的扣分项 —— 也是 GEO 里最该人工补的部分。'
                .'鼠标悬停每一行能看到命中的具体词。</p>'
                .'</div>';
        }

        return '<div class="g-card"><h3>'.($title !== '' ? self::e($title) : '生成结果').'</h3>'
            .'<p class="g-cap">下面是通用结构指标。<b>平台是否达标看下一张卡</b>——不同平台的合格线不一样。</p>'
            .$truncWarn
            .$statCards.'</div>'
            .$scoreBlock
            .$pcBlock
            .'<div class="g-card"><h3>正文预览<span style="font-size:11px;color:#9ca3af;font-weight:400"> · 高亮句＝标注点，上标圈数字对应上方图例</span></h3>'
            .'<div class="g-md" style="max-height:620px;overflow:auto;padding-right:8px">'
            .GeoScorer::render($md, (array) ($sc['annotations'] ?? []))
            .'</div></div>'
            .$schemaBlock
            .'<div class="g-card"><h3>发布前必须自己过一遍</h3>'
            .'<ul class="g-md"><li>核对每一个数字、认证、研究结论。<b>模型可能把原文的含糊表述"洗"成肯定句</b>，这是最大的风险点。</li>'
            .'<li><b>看十维评分的「统计数据」和「溯源信息」是不是 0 分</b>。是的话说明全文没有可核验的数据和出处，'
            .'这两项占 24% 权重，也是 AI 判断"这篇能不能引用"的关键。人工补上来源再发。</li>'
            .'<li>检查「证据边界」段是否真的写了，有没有被模型省略。</li>'
            .'<li>把 JSON-LD 里的域名、日期、作者改成真实值。</li>'
            .'<li>改完先跑一遍 <a href="'.$adminBase.'/geo-audit" style="color:#4f46e5">官网诊断</a>，确认结构项都过。</li></ul></div>'
            .'<script>(function(){function fallback(t,done){var ta=document.createElement("textarea");ta.value=t;'
            .'document.body.appendChild(ta);ta.select();try{document.execCommand("copy");done();}catch(e){}document.body.removeChild(ta);}'
            .'var btns=document.querySelectorAll(".cs-copy");'
            .'for(var i=0;i<btns.length;i++){(function(b){'
            .'b.addEventListener("click",function(){var p=document.getElementById(b.getAttribute("data-copy"));if(!p)return;'
            .'var t=p.textContent||"";var done=function(){b.textContent="已复制";setTimeout(function(){b.textContent="复制";},1600);};'
            .'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done).catch(function(){fallback(t,done);});}'
            .'else{fallback(t,done);}});})(btns[i]);}})();</script>';
    }

    /**
     * 批量生成进度页：逐平台 fetch，一个平台一个平台出结果。
     *
     * 为什么不用一个请求跑完：5 个平台串行要 100–200 秒，页面会长时间空白，
     * 而且中途失败一个全批作废。拆开后单请求 25–45 秒，出错的平台可单独重试。
     *
     * @param  array<string,mixed>  $ctx
     * @param  list<array{slug:string,name:string}>  $queue
     */
    public static function batchRunPage(array $ctx, array $queue, string $adminBase): string
    {
        $total = count($queue);
        $mode = (string) ($ctx['mode'] ?? 'refine');
        $modeLabel = GeoContentStudio::MODES[$mode] ?? $mode;
        $topic = trim((string) ($ctx['topic'] ?? ''));
        $headline = $topic !== '' ? mb_substr($topic, 0, 40, 'UTF-8') : '原文改造';

        $rows = '';
        foreach ($queue as $i => $q) {
            $rows .= '<tr id="bq-row-'.$i.'">'
                .'<td style="border:1px solid #e5e7eb;padding:10px 12px;width:34px;text-align:center;color:#9ca3af">'.($i + 1).'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:10px 12px;width:150px"><b>'.self::e($q['name']).'</b></td>'
                .'<td style="border:1px solid #e5e7eb;padding:10px 12px" id="bq-st-'.$i.'"><span style="color:#9ca3af">等待</span></td>'
                .'<td style="border:1px solid #e5e7eb;padding:10px 12px;width:110px" id="bq-pc-'.$i.'"><span style="color:#9ca3af">—</span></td>'
                .'</tr>';
        }

        $queueJson = json_encode($queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        $body = '<div class="g-card"><h3>正在生成 '.$total.' 个平台版本</h3>'
            .'<p class="g-cap">'.self::e($modeLabel).' · '.self::e($headline)
            .'　—— 逐平台原生生成，第一个出来就能先看</p>'
            .'<p class="g-cap" id="bq-state">0 / '.$total.' 完成</p>'
            .'<div style="height:8px;background:#eef0f3;border-radius:99px;overflow:hidden;margin:0 0 16px">'
            .'<div id="bq-bar" style="height:100%;width:0;background:linear-gradient(90deg,#4f46e5,#7c3aed);transition:width .3s"></div></div>'
            .'<table class="g-table" style="font-size:13px"><thead><tr>'
            .'<th style="width:34px">#</th><th style="width:150px">平台</th>'
            .'<th>状态</th><th style="width:110px">合规</th>'
            .'</tr></thead><tbody id="bq-body">'.$rows.'</tbody></table>'
            .'<div class="g-row" style="margin-top:18px">'
            .'<a class="g-btn g-btn--primary" id="bq-goto" href="'.$adminBase.'/content-studio?result=1" style="display:none">查看全部结果</a>'
            .'<span class="g-cap" style="margin:0" id="bq-hint">请勿关闭页面，逐平台生成中…</span></div></div>'
            .'<script>(function(){'
            .'var Q='.$queueJson.';var BASE="'.$adminBase.'/content-studio";var STATE=new Array(Q.length);'
            .'var bar=document.getElementById("bq-bar"),stateEl=document.getElementById("bq-state"),'
            .'btn=document.getElementById("bq-goto"),hint=document.getElementById("bq-hint"),'
            .'bodyEl=document.getElementById("bq-body");'
            .'function el(id){return document.getElementById(id);}'
            .'function pill(t,c){return "<span class=\\"g-pill "+c+"\\">"+t+"</span>";}'
            .'function render(i){var s=STATE[i],st=el("bq-st-"+i),pc=el("bq-pc-"+i);if(!st)return;'
            .'if(s===undefined){st.innerHTML="<span style=\\"color:#9ca3af\\">等待</span>";return;}'
            .'if(s==="busy"){st.innerHTML="<span style=\\"color:#4f46e5\\">生成中…（约 25–45 秒）</span>";return;}'
            .'if(s.error){st.innerHTML=pill("失败","g-pill--bad")+" <a href=\\"#\\" data-retry=\\""+i+"\\" style=\\"font-size:11px;color:#4f46e5\\">重试</a>";'
            .'if(pc)pc.innerHTML="<span style=\\"color:#9ca3af\\">—</span>";return;}'
            .'var p=s.platform_check||{};var pass=p.pass||0,tot=p.total||0;'
            .'st.innerHTML=pill("已生成","g-pill--ok");'
            .'if(pc){var sc=(s.scoring_total==null?"":"<div style=\\"font-size:10.5px;color:#6b7280\\">十维 "+s.scoring_total+"</div>");'
            .'pc.innerHTML="<b style=\\"color:"+(tot&&pass===tot?"#047857":"#b45309")+"\\">"+pass+"/"+tot+"</b>"+sc;}}'
            .'function counters(){var d=0,ok=0,fail=0;for(var i=0;i<Q.length;i++){var s=STATE[i];'
            .'if(s===undefined||s==="busy")continue;d++;if(s.error){fail++;}else{ok++;}}'
            .'return{d:d,ok:ok,fail:fail};}'
            .'function tick(){var c=counters();bar.style.width=(c.d/Q.length*100)+"%";'
            .'stateEl.textContent=c.d+" / "+Q.length+" 完成　成功 "+c.ok+(c.fail?"　失败 "+c.fail:"");'
            .'if(c.d>=Q.length){if(hint)hint.textContent="全部完成 —— 成功 "+c.ok+(c.fail?"，失败 "+c.fail+"（可点该行「重试」）":"")+"，点右边查看各平台版本";'
            .'if(btn)btn.style.display="inline-flex";}}'
            .'async function step(i){STATE[i]="busy";render(i);'
            .'try{var r=await fetch(BASE+"?one=1&p="+i,{credentials:"same-origin",headers:{"Accept":"application/json"}});'
            .'var j=await r.json();if(j&&j.error){STATE[i]={error:j.error};}else{STATE[i]=j;}}'
            .'catch(e){STATE[i]={error:"请求失败，可点重试"};}'
            .'render(i);tick();}'
            .'if(bodyEl){bodyEl.addEventListener("click",function(ev){var t=ev.target;'
            .'while(t&&t!==bodyEl){if(t.getAttribute&&t.getAttribute("data-retry")!==null){ev.preventDefault();'
            .'var i=parseInt(t.getAttribute("data-retry"),10);if(!isNaN(i)){step(i);}return;}t=t.parentNode;}});}'
            .'(async function(){for(var i=0;i<Q.length;i++){await step(i);}})();'
            .'})();</script>';

        return self::shell($body, '批量生成中');
    }

    /**
     * 批量结果页：tab 切换各平台版本。
     *
     * @param  array<string,mixed>  $ctx
     * @param  list<array{slug:string,name:string}>  $queue
     * @param  array<string,array<string,mixed>|null>  $results  slug => result（null = 失败）
     * @param  array<string,string>  $errors  slug => 错误信息
     */
    public static function batchReportPage(array $ctx, array $queue, array $results, array $errors, string $adminBase): string
    {
        $mode = (string) ($ctx['mode'] ?? 'refine');
        $modeLabel = GeoContentStudio::MODES[$mode] ?? $mode;
        $topic = trim((string) ($ctx['topic'] ?? ''));
        $headline = $topic !== '' ? mb_substr($topic, 0, 44, 'UTF-8') : '原文改造';

        $okCount = 0;
        $failCount = 0;
        $tabs = '';
        $panes = '';
        $first = true;

        foreach ($queue as $q) {
            $slug = $q['slug'];
            $name = $q['name'];
            $r = $results[$slug] ?? null;

            if ($r === null) {
                $failCount++;
                $err = $errors[$slug] ?? '生成失败';
                $tabs .= '<span class="g-btn g-btn--ghost" style="opacity:.55;cursor:not-allowed" title="'.self::e($err).'">'
                    .self::e($name).' ✕</span>';
                $panes .= '<div class="bq-pane" data-pane="'.self::e($slug).'" style="display:none">'
                    .'<div class="g-card"><h3>'.self::e($name).' 生成失败</h3>'
                    .'<div class="g-err">'.self::e($err).'</div>'
                    .'<p class="g-cap">可以回上一页点「重试」，或换短一点的主题再跑。</p></div></div>';
                continue;
            }

            $okCount++;
            $pc = (array) ($r['platform_check'] ?? []);
            $pass = (int) ($pc['pass'] ?? 0);
            $tot = (int) ($pc['total'] ?? 0);
            $badge = $tot > 0
                ? '<span class="g-pill '.($pass === $tot ? 'g-pill--ok' : 'g-pill--warn').'" style="font-size:10.5px;margin-left:5px">'.$pass.'/'.$tot.'</span>'
                : '';

            $on = $first ? 'g-btn--primary' : 'g-btn--ghost';
            $tabs .= '<button type="button" class="g-btn '.$on.' bq-tab" data-tab="'.self::e($slug).'">'
                .self::e($name).$badge.'</button>';

            $panes .= '<div class="bq-pane" data-pane="'.self::e($slug).'" style="display:'.($first ? 'block' : 'none').'">'
                .'<div class="g-toolbar" style="margin-bottom:14px">'
                .'<a class="g-btn g-btn--primary" href="'.$adminBase.'/content-studio?download=1&p='.self::e($slug).'">下载 '.self::e($name).' 版 Markdown</a>'
                .'<span class="g-sep">耗时 '.number_format(((int) ($r['elapsed_ms'] ?? 0)) / 1000, 1).' 秒 · tokens '
                .((int) ($r['usage']['total_tokens'] ?? 0)).'　字数 '.(int) ($r['stats']['chars'] ?? 0).'</span></div>'
                .self::resultBlock($r, $adminBase, $slug)
                .'</div>';

            $first = false;
        }

        $body = '<div class="g-toolbar">'
            .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/content-studio?mode='.self::e($mode).'">← 再来一批</a>'
            .'<span class="g-sep">'.self::e($modeLabel).' · '.self::e($headline)
            .'　成功 '.$okCount.' 个平台'.($failCount > 0 ? '，失败 '.$failCount.' 个' : '').'</span>'
            .'</div>'
            .'<div class="g-card"><h3>一文多发：同一主题，'.$okCount.' 个平台各自原生</h3>'
            .'<p class="g-cap">每个 tab 是一个独立生成的版本，不是互相改写。'
            .'<b>发布时按平台分开复制，不要串用</b>——知乎版发小红书会立刻显得很怪。</p>'
            .'<div class="g-row" id="bq-tabs">'.$tabs.'</div></div>'
            .$panes
            .'<div class="g-card"><h3>发布顺序建议</h3>'
            .'<ul class="g-md">'
            .'<li><b>先发长文阵地</b>（知乎/公众号），它是内容母版，也最容易被别的平台转引。</li>'
            .'<li><b>再发短文案</b>（小红书/抖音），用来承接口语化搜索。</li>'
            .'<li><b>每发一个平台就记下 URL</b>，后面复测时要按平台看收录效果。</li>'
            .'<li>各版本的事实必须一致。如果有版本把数字写错了，会伤品牌可信度。</li>'
            .'</ul></div>'
            .'<script>(function(){var tabs=document.querySelectorAll(".bq-tab"),panes=document.querySelectorAll(".bq-pane");'
            .'for(var i=0;i<tabs.length;i++){(function(t){'
            .'t.addEventListener("click",function(){var k=t.getAttribute("data-tab");'
            .'for(var j=0;j<tabs.length;j++){tabs[j].className="g-btn bq-tab "+(tabs[j]===t?"g-btn--primary":"g-btn--ghost");}'
            .'for(var j=0;j<panes.length;j++){panes[j].style.display=panes[j].getAttribute("data-pane")===k?"block":"none";}'
            .'});})(tabs[i]);}})();</script>';

        return self::shell($body, '一文多发结果');
    }

    private static function mdToHtml(string $md): string
    {
        if (method_exists(\Illuminate\Support\Str::class, 'markdown')) {
            try {
                return (string) \Illuminate\Support\Str::markdown($md, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]);
            } catch (\Throwable) {
                // 落到下面的纯文本兜底
            }
        }

        return '<pre style="white-space:pre-wrap;font:13px/1.8 inherit">'.self::e($md).'</pre>';
    }

    private static function shell(string $body, string $title): string
    {
        return '<style>'.GeoUi::css().'</style>'
            .'<div class="geo-ui" data-geo-title="'.self::e($title).'" '
            .'style="max-width:1040px;margin:0 auto;padding:4px 4px 48px;font:14px/1.7 var(--g-font);color:#111827">'
            .$body.'</div>'.GeoUi::busyOverlay();
    }

    private static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
