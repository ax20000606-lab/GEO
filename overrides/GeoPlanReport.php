<?php

namespace App\Support\GeoAudit;

/**
 * GEO 执行方案 —— 表单页与结果页渲染
 */
final class GeoPlanReport
{
    /** @param array<string,mixed> $input */
    public static function formPage(array $input = [], string $error = ''): string
    {
        $v = static fn (string $k, string $d = ''): string => self::e((string) ($input[$k] ?? $d));
        $focusSel = (array) ($input['focus'] ?? []);

        $stageOpts = '';
        foreach (GeoPlanGenerator::STAGES as $k => $label) {
            $sel = (string) ($input['stage'] ?? '') === $k ? ' selected' : '';
            $stageOpts .= '<option value="'.$k.'"'.$sel.'>'.self::e($label).'</option>';
        }
        $timeOpts = '';
        foreach (GeoPlanGenerator::TIMELINES as $k => $label) {
            $sel = (string) ($input['timeline'] ?? '90') === (string) $k ? ' selected' : '';
            $timeOpts .= '<option value="'.$k.'"'.$sel.'>'.self::e($label).'</option>';
        }
        $resOpts = '';
        foreach (GeoPlanGenerator::RESOURCES as $k => $label) {
            $sel = (string) ($input['resources'] ?? '') === $k ? ' selected' : '';
            $resOpts .= '<option value="'.$k.'"'.$sel.'>'.self::e($label).'</option>';
        }
        $focusBoxes = '';
        foreach (GeoPlanGenerator::FOCUS as $k => $label) {
            $chk = in_array($k, $focusSel, true) ? ' checked' : '';
            $focusBoxes .= '<label class="chk"><input type="checkbox" name="focus[]" value="'.$k.'"'.$chk.'><span>'.self::e($label).'</span></label>';
        }

        $err = $error !== '' ? '<div class="err">'.self::e($error).'</div>' : '';

        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

        // ---------- 题目分级清单 ----------
        // 原本放在"声量对标"里，但那里只显示达成率、看不到具体题目，
        // 而且它本质是"这次方案该优先做什么"的输入，放在方案页才对。
        $gradeList = '';
        try {
            $rows = \Illuminate\Support\Facades\DB::table('keywords as k')
                ->join('keyword_libraries as l', 'l.id', '=', 'k.library_id')
                ->select('k.keyword', 'k.priority', 'l.name as lib')
                ->whereIn('k.priority', ['win', 'fight', 'pending'])
                ->orderBy('k.id')
                ->get();

            $groups = ['win' => [], 'fight' => [], 'pending' => []];
            $libOf = [];
            foreach ($rows as $r) {
                $groups[(string) $r->priority][] = (string) $r->keyword;
                $libOf[(string) $r->keyword] = (string) $r->lib;
            }

            $meta = [
                'win' => ['必赢题', '#047857', '已经在前三，守住就行——不用再往这里投内容'],
                'fight' => ['争夺题', '#b45309', '有位置但还没进去，<b>这是方案该优先解决的</b>'],
                'pending' => ['待定', '#6b7280', '竞争格局不清，建议人工看一眼再决定做不做'],
            ];

            $sections = '';
            foreach ($meta as $k => [$label, $color, $tip]) {
                $list = $groups[$k];
                if ($list === []) {
                    continue;
                }
                $chips = '';
                foreach (array_slice($list, 0, 12) as $kw) {
                    $chips .= '<span style="display:inline-block;padding:3px 9px;margin:0 5px 5px 0;'
                        .'background:#f9fafb;border:1px solid #e5e7eb;border-radius:99px;font-size:11.5px;color:#374151">'
                        .self::e(mb_substr($kw, 0, 26, 'UTF-8')).'</span>';
                }
                if (count($list) > 12) {
                    $chips .= '<span style="font-size:11.5px;color:#9ca3af">还有 '.(count($list) - 12).' 道…</span>';
                }
                $sections .= '<div style="margin-bottom:14px">'
                    .'<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap">'
                    .'<span style="width:3px;height:14px;background:'.$color.';border-radius:2px;display:inline-block"></span>'
                    .'<b style="font-size:13px;color:#111827">'.$label.'</b>'
                    .'<span style="font-size:11.5px;color:#6b7280">'.count($list).' 道 · '.$tip.'</span>'
                    .'</div><div>'.$chips.'</div></div>';
            }

            if ($sections !== '') {
                $gradeList = '<div class="g-card"><h3>题目分级：这次方案该优先解决什么</h3>'
                    .'<p class="g-cap">分级来自实际采样结果（已在前三的记为必赢，前十或前三名有可投稿平台的记为争夺，'
                    .'其余标待定）。<b>方案真正该发力的是「争夺题」</b>——必赢题守着、待定题先放。</p>'
                    .'<div style="margin-top:12px">'.$sections.'</div>'
                    .'<p class="g-cap" style="margin:10px 0 0">想看这些题的详情（我排第几、谁在占位），回'
                    .'<a href="'.self::e($adminBase.'/share-of-voice').'" style="color:#4f46e5">声量对标</a> 按题查看。</p></div>';
            }
        } catch (\Throwable) {
        }

        $auditBanner = '';
        if ((string) ($input['from_audit'] ?? '') === '1' && (string) ($input['score'] ?? '') !== '') {
            $auditBanner = '<div class="broughtin">已带入你的页面诊断结果：<b>'.(int) $input['score'].' 分</b>'
                .'（'.self::e((string) ($input['grade'] ?? '')).'）。方案会以这份真实数据为依据，无需重新填背景。</div>';
        }

        $body = '<div class="hero"><h2>生成一份可执行的 GEO 方案</h2>'
            .'<p class="sub">先补齐目标、官网、受众、资源和周期，再生成适合团队落地的计划。填了官网会先跑一遍规则诊断，用真实分数做依据。</p>'
            .$auditBanner
            .$gradeList
            .$err
            .'<form method="get" action="" id="geo-plan-form">'
            .'<input type="hidden" name="generate" value="1">'

            .'<div class="step"><span class="num">01</span><div><b>目标与业务背景</b><small>先让 AI 知道你是谁、要解决什么问题</small></div></div>'
            .'<div class="row"><label>本次目标<textarea name="goal" rows="2" placeholder="例如：3 个月内让品牌在 DeepSeek/豆包的「敏感肌护肤品推荐」类问题里被提到">'.$v('goal').'</textarea></label></div>'
            .'<div class="grid2">'
            .'<label>品牌 / 产品<input type="text" name="brand" value="'.$v('brand').'" placeholder="例如：冰溪"></label>'
            .'<label>官网 URL<input type="text" name="url" value="'.$v('url').'" placeholder="https://www.example.com"></label>'
            .'<label>行业<input type="text" name="industry" value="'.$v('industry').'" placeholder="例如：功效护肤 / B2B SaaS"></label>'
            .'<label>目标受众<input type="text" name="audience" value="'.$v('audience').'" placeholder="例如：敏感肌人群、皮肤科医生"></label>'
            .'</div>'

            .'<div class="step"><span class="num">02</span><div><b>执行条件</b><small>周期、资源和市场决定方案的颗粒度</small></div></div>'
            .'<div class="grid2">'
            .'<label>当前阶段<select name="stage">'.$stageOpts.'</select></label>'
            .'<label>执行周期<select name="timeline">'.$timeOpts.'</select></label>'
            .'<label>团队资源<select name="resources">'.$resOpts.'</select></label>'
            .'<label>主要市场 / 语言<input type="text" name="market" value="'.$v('market', '中国大陆 · 简体中文').'"></label>'
            .'</div>'

            .'<div class="step"><span class="num">03</span><div><b>本次重点</b><small>至少选 1 个，建议 2–4 个，避免方案发散</small></div></div>'
            .'<div class="chks">'.$focusBoxes.'</div>'

            .'<div class="step"><span class="num">04</span><div><b>边界与补充材料</b><small>竞品与限制条件会让方案更落地</small></div></div>'
            .'<div class="row"><label>竞品 / 对标对象<textarea name="competitors" rows="2" placeholder="例如：薇诺娜、玉泽">'.$v('competitors').'</textarea></label></div>'
            .'<div class="row"><label>限制条件 / 已知问题<textarea name="constraints" rows="2" placeholder="例如：不能改现有 CMS、没有设计资源">'.$v('constraints').'</textarea></label></div>'
            .'<div class="row"><label>补充背景<textarea name="context" rows="2" placeholder="任何有助于判断的信息">'.$v('context').'</textarea></label></div>'

            .'<div style="margin-top:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">'
            .'<button type="submit" id="geo-plan-submit">生成方案（约 20–40 秒）</button>'
            .'<span class="hint" id="geo-plan-hint" style="margin:0">事实来自规则诊断，判断与排期由模型生成</span></div>'

            .'<script>'
            .'(function(){var f=document.getElementById("geo-plan-form");if(!f)return;'
            .'f.addEventListener("submit",function(){'
            .'var b=document.getElementById("geo-plan-submit");'
            .'if(b){b.disabled=true;b.style.opacity=".65";b.textContent="方案生成中…";}'
            .'if(window.geoBusy){window.geoBusy("方案生成中…","正在抓取官网并调用模型，约 20–40 秒，请勿关闭页面");}'
            .'});})();'
            .'</script>'
            .'</form></div>';

        return self::page($body, 'GEO 方案生成器');
    }

    /** @param array<string,mixed> $result */
    public static function resultPage(array $result): string
    {
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $mdRaw = (string) ($result['markdown'] ?? '');
        // 兜底：历史方案里可能还残留任务清单 JSON 块，渲染前统一剥掉
        $mdRaw = (string) preg_replace('/\n?```(?:json|JSON)?\s*\{[^`]*?"tasks"\s*:[^`]*?\}\s*```/su', '', $mdRaw);
        if (($pos = strpos($mdRaw, '{"tasks"')) !== false) {
            $mdRaw = substr($mdRaw, 0, $pos);
        }
        $html = self::markdownToHtml(rtrim($mdRaw));
        $focus = (array) ($result['focus'] ?? []);
        $auditLine = $result['audit_total'] !== null
            ? '官网诊断：'.$result['audit_total'].' 分（'.$result['audit_grade'].'）'
            : '未提供可抓取官网';

        $meta = '<span class="badge">AI 生成</span>'
            .'<span class="tag">'.self::e((string) ($result['model'] ?? '')).'</span>'
            .'<span class="tag">耗时 '.number_format(((int) ($result['elapsed_ms'] ?? 0)) / 1000, 1).' 秒</span>'
            .'<span class="tag">tokens '.((int) ($result['usage']['total_tokens'] ?? 0)).'</span>'
            .'<span class="tag">'.$auditLine.'</span>'
            .'<span class="tag">周期 '.((int) ($result['timeline'] ?? 0)).' 天</span>';

        $body = '<div class="hero"><h2>GEO 执行方案'.($result['brand'] !== '' ? '：'.self::e((string) $result['brand']) : '').'</h2>'
            .'<p class="sub">'.self::e((string) ($result['goal'] ?? '')).'</p>'
            .'<div class="metas">'.$meta.'</div>'
            .($focus !== [] ? '<p class="sub" style="margin-top:10px">本次重点：'.self::e(implode('、', $focus)).'</p>' : '')
            .'<div style="margin-top:14px">'
            .((int) ($result['saved_id'] ?? 0) > 0
                ? '<a class="g-btn g-btn--primary" href="'.$adminBase.'/geo-plan/run-task?id='.(int) $result['saved_id'].'">按此方案创建任务草稿</a>'
                : '')
            .'<a class="g-btn g-btn--soft" href="?download=1" style="margin-left:8px">下载 Markdown</a>'
            .'<a class="g-btn g-btn--soft" href="'.self::e($adminBase.'/geo-plan').'" style="margin-left:8px">重新生成</a>'
            .'<a class="dl" href="'.self::e('/geo_admin/geo-audit?url='.urlencode((string) ($result['url'] ?? '')).'&auto=1').'" style="margin-left:8px">查看诊断报告</a></div>'
            .'</div>'
            .'<div class="card md">'.$html.'</div>'
            .'<div class="foot">方案由 AI 生成，事实来自规则诊断器；执行前请与团队确认资源与时限。</div>';

        return self::page($body, 'GEO 执行方案');
    }

    public static function errorPage(array $input, string $message): string
    {
        return self::formPage($input, '生成失败：'.$message);
    }

    private static function markdownToHtml(string $md): string
    {
        if (method_exists(\Illuminate\Support\Str::class, 'markdown')) {
            try {
                return (string) \Illuminate\Support\Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
            } catch (\Throwable) {
            }
        }

        return nl2br(self::e($md));
    }

    /**
     * 渲染为「页面片段」，由 admin 布局包裹（保留侧边栏与顶栏）。
     * 样式一律限定在 .geo-plan-wrap 作用域内，避免污染后台样式。
     */
    private static function page(string $body, string $title): string
    {
        $css = self::css();

        return '<style>'.GeoUi::css().'</style><style>'.$css.'</style>'
            .'<div class="geo-ui geo-plan-wrap" data-geo-plan-title="'.self::e($title).'" '
            .'style="max-width:1080px;margin:0 auto;padding:4px 4px 48px;font:14px/1.7 var(--g-font);color:#111827">'
            .$body
            .'</div>'.GeoUi::busyOverlay();
    }

    private static function css(): string
    {
        return <<<'CSS'
.geo-plan-wrap{max-width:960px;margin:0 auto;padding:4px 4px 48px;color:#111827;font:14px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.geo-plan-wrap .hero{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:26px 28px;margin-bottom:18px}
.geo-plan-wrap .hero h2{margin:0 0 8px;font-size:21px;letter-spacing:-.3px}
.geo-plan-wrap .hero p.sub{margin:0 0 14px;color:#6b7280;font-size:13px}
.geo-plan-wrap .step{display:flex;gap:12px;align-items:flex-start;margin:26px 0 12px;padding-top:16px;border-top:1px solid #f1f3f5}
.geo-plan-wrap .step:first-of-type{border-top:0;padding-top:0;margin-top:8px}
.geo-plan-wrap .step .num{font:700 12px/1 ui-monospace,monospace;color:#4f46e5;background:#eef2ff;border-radius:7px;padding:6px 8px}
.geo-plan-wrap .step b{display:block;font-size:14px}
.geo-plan-wrap .step small{color:#6b7280;font-size:12px}
.geo-plan-wrap label{display:block;font-size:12.5px;color:#374151;font-weight:600}
.geo-plan-wrap input[type=text],.geo-plan-wrap select,.geo-plan-wrap textarea{width:100%;margin-top:5px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font:13.5px/1.5 inherit;color:#111827;background:#fff;font-weight:400}
.geo-plan-wrap textarea{resize:vertical}
.geo-plan-wrap input:focus,.geo-plan-wrap select:focus,.geo-plan-wrap textarea:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.12)}
.geo-plan-wrap .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:4px}
.geo-plan-wrap .row{margin-top:12px}
.geo-plan-wrap .chks{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px;margin-top:4px}
.geo-plan-wrap .chk{display:flex;align-items:center;gap:8px;background:#fcfcfd;border:1px solid #e5e7eb;border-radius:10px;padding:9px 12px;font-weight:500;cursor:pointer;margin:0}
.geo-plan-wrap .chk input{accent-color:#4f46e5}
.geo-plan-wrap button{padding:12px 22px;border:0;border-radius:11px;background:#4f46e5;color:#fff;font-size:14px;font-weight:650;cursor:pointer}
.geo-plan-wrap button:hover{background:#4338ca}
.geo-plan-wrap button[disabled]{cursor:progress}
.geo-plan-wrap .hint{font-size:12px;color:#6b7280}
.geo-plan-wrap .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:12px 14px;font-size:13px;margin-bottom:16px}
.geo-plan-wrap .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:22px 26px}
.geo-plan-wrap .badge{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px}
.geo-plan-wrap .tag{font-size:11.5px;color:#6b7280;background:#f3f4f6;border-radius:99px;padding:3px 10px;margin-left:6px}
.geo-plan-wrap .metas{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.geo-plan-wrap .dl{display:inline-block;font-size:12.5px;color:#4f46e5;text-decoration:none;border:1px solid #e0e7ff;background:#eef2ff;padding:7px 13px;border-radius:9px;font-weight:650}
.geo-plan-wrap .md h2{font-size:19px;margin:22px 0 10px;padding-bottom:6px;border-bottom:1px solid #eef0f3}
.geo-plan-wrap .md h3{font-size:16px;margin:18px 0 8px}
.geo-plan-wrap .md h4{font-size:14.5px;margin:14px 0 6px;color:#374151}
.geo-plan-wrap .md table{width:100%;border-collapse:collapse;margin:12px 0;font-size:12.5px}
.geo-plan-wrap .md th,.geo-plan-wrap .md td{border:1px solid #e5e7eb;padding:8px 10px;text-align:left;vertical-align:top}
.geo-plan-wrap .md th{background:#f9fafb;font-weight:650}
.geo-plan-wrap .md ul,.geo-plan-wrap .md ol{padding-left:20px}
.geo-plan-wrap .md li{margin:4px 0}
.geo-plan-wrap .md p{margin:10px 0}
.geo-plan-wrap .md code{background:#f3f4f6;padding:1px 5px;border-radius:4px}
.geo-plan-wrap .md pre{background:#0f172a;color:#e5e7eb;padding:14px;border-radius:10px;overflow:auto;font-size:12.5px}
.geo-plan-wrap .foot{color:#6b7280;font-size:12px;text-align:center;margin-top:24px}
.geo-plan-wrap .broughtin{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:12px;padding:11px 14px;font-size:12.5px;margin-bottom:16px}
@media(max-width:720px){.geo-plan-wrap .grid2{grid-template-columns:1fr}}
CSS;
    }

    private static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
