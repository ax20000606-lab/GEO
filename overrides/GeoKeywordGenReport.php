<?php

namespace App\Support\GeoAudit;

/**
 * AI 关键词生成页面：表单 → 生成结果（筛选/删除）→ 导入词库
 */
final class GeoKeywordGenReport
{
    private const INPUT_STYLE = 'width:100%;margin-top:6px;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;font-size:13px;font-family:inherit';

    private const LABEL_STYLE = 'display:block;font-size:12px;color:#6b7280;font-weight:600';

    // =========================================================================
    // 表单
    // =========================================================================

    /**
     * @param  array<string,mixed>  $old
     * @param  list<object>  $libraries
     * @param  list<array<string,mixed>>  $categories
     */
    public static function formPage(array $old, iterable $libraries, array $categories, string $adminBase, string $error = '', bool $hasCatColumn = true): string
    {
        $base = $adminBase.'/keyword-gen';
        $brand = self::e((string) ($old['brand'] ?? ''));
        $industry = self::e((string) ($old['industry'] ?? ''));
        $audience = self::e((string) ($old['audience'] ?? ''));
        $products = self::e((string) ($old['products'] ?? ''));
        $competitors = self::e((string) ($old['competitors'] ?? ''));
        $pickedCats = (array) ($old['cats'] ?? ['A', 'C']);
        $perCatDefault = max(3, min(30, (int) ($old['limit'] ?? 10)));

        $err = $error !== ''
            ? '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;'
                .'font-size:13px;color:#b91c1c;margin-bottom:14px">'.self::e($error).'</div>'
            : '';

        // 类别勾选
        $catCards = '';
        foreach ($categories as $c) {
            $k = (string) ($c['key'] ?? '');
            $on = in_array($k, $pickedCats, true);
            $needBrand = ! empty($c['must_have_brand']);
            $catCards .= '<label class="kg-cat" style="display:block;border:1px solid '.($on ? '#4f46e5' : '#d1d5db').';'
                .'background:'.($on ? '#f5f3ff' : '#fff').';border-radius:10px;padding:10px 13px;cursor:pointer">'
                .'<div style="display:flex;align-items:center;gap:8px">'
                .'<input type="checkbox" class="kg-cat-box" name="cats[]" value="'.self::e($k).'"'.($on ? ' checked' : '').' style="margin:0">'
                .'<b style="font-size:13px">'.$k.' '.(string) ($c['label'] ?? '').'</b>'
                .'<span style="font-size:10.5px;padding:1px 7px;border-radius:99px;'
                .'background:'.($needBrand ? '#eff6ff' : '#fef3c7').';color:'.($needBrand ? '#1d4ed8' : '#92400e').'">'
                .($needBrand ? '带品牌名' : '不带品牌名').'</span>'
                .'</div>'
                .'<div style="font-size:11.5px;color:#6b7280;margin-top:4px;line-height:1.6">'.self::e((string) ($c['desc'] ?? '')).'</div>'
                .'<div style="font-size:11px;color:#9ca3af;margin-top:3px">角度：'.self::e(implode('、', (array) ($c['angles'] ?? []))).'</div>'
                .'</label>';
        }

        // 目标词库：默认"自动分配"——按类别自动放进「品牌｜A/B/C/D」对应的库。
        // 手动指定一个库只在"只生成一类"时才有意义；一次生成多类还塞同一个库
        // 会把不同维度混在一起，后面按类看数据就废了。
        $curLib = (int) ($old['lib'] ?? 0);
        $libOptions = '<option value="auto"'.($curLib === 0 ? ' selected' : '').'>按类别自动分配（推荐）</option>'
            .'<option value="0"'.($curLib === 0 ? '' : '').'>— 生成后再决定 —</option>';
        foreach ($libraries as $l) {
            $sel = $curLib === (int) $l->id ? ' selected' : '';
            $libOptions .= '<option value="'.(int) $l->id.'"'.$sel.'>'
                .\App\Support\GeoAudit\GeoKeywordGenReport::eStatic((string) $l->name).'</option>';
        }

        $catWarn = $hasCatColumn ? '' :
            '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;'
            .'font-size:12.5px;color:#92400e;margin-bottom:14px">'
            .'<b>分类字段还没建好</b>：导入时不会保存 A/B/C 标记，覆盖核查没法按类别筛题。'
            .'第一次生成会自动尝试建这一列，如果一直提示这个，说明数据库账号没有 ALTER 权限。</div>';

        $csrf = self::csrfField();

        $body = '<div class="g-toolbar">'
            .'<a class="g-btn g-btn--ghost" href="'.self::e($adminBase.'/keyword-libraries').'">← 返回关键词库</a>'
            .'<span class="g-sep">生成完可以勾选/删除，再导入到指定词库</span></div>'
            .'<div class="g-hd"><h2 style="margin:0 0 4px;font-size:19px">AI 关键词生成</h2>'
            .'<p class="g-cap" style="margin:0">输入品牌名，按类别批量生成用户会真实搜索的问题，筛完一键导入词库。</p></div>'
            .$err.$catWarn
            .'<form method="post" action="'.$base.'" id="kg-form">'
            .$csrf
            .'<input type="hidden" name="gen" value="1">'
            .'<div class="g-card"><h3>1 · 品牌信息</h3>'
            .'<p class="g-cap"><b>填得越具体，生成的题越准。</b>只给品牌名的话，模型容易编出你根本没有的产品——'
            .'所以「已知产品」这一栏最重要，把真实产品名列上去。</p>'
            .'<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">'
            .'<label style="'.self::LABEL_STYLE.'">品牌名 <span style="color:#b91c1c">*</span>'
            .'<input type="text" name="brand" value="'.$brand.'" required placeholder="例如：冰溪" style="'.self::INPUT_STYLE.'"></label>'
            .'<label style="'.self::LABEL_STYLE.'">品类'
            .'<input type="text" name="industry" value="'.$industry.'" placeholder="例如：敏感肌护肤品" style="'.self::INPUT_STYLE.'"></label>'
            .'<label style="'.self::LABEL_STYLE.'">目标人群'
            .'<input type="text" name="audience" value="'.$audience.'" placeholder="例如：18-35 岁敏感肌女性" style="'.self::INPUT_STYLE.'"></label>'
            .'<label style="'.self::LABEL_STYLE.'">竞品（D 类对比用，逗号分隔）'
            .'<input type="text" name="competitors" value="'.$competitors.'" placeholder="例如：薇诺娜,理肤泉,玉泽" style="'.self::INPUT_STYLE.'"></label>'
            .'</div>'
            .'<label style="'.self::LABEL_STYLE.';margin-top:14px">已知产品名（逗号分隔，**不要编造**）'
            .'<input type="text" name="products" value="'.$products.'" placeholder="例如：皮肤屏障修护乳,修红保湿精华液,屏障修护霜" style="'.self::INPUT_STYLE.'"></label>'
            .'<p class="g-cap" style="margin:6px 0 0">留空的话，C 类题只能用「冰溪的产品」这种泛指，问不到具体 SKU。</p>'
            .'</div>'
            .'<div class="g-card"><h3>2 · 生成哪几类</h3>'
            .'<p class="g-cap">四类判断标准不同：<b>A/C 判信息准确性</b>（题里带你名字，AI 必然提你）；'
            .'<b>B 判有没有被提到</b>；<b>D 判在不在对比名单</b>。默认选了 A+C（覆盖核查用），'
            .'B/D 生成的题建议进「赛道库」给声量对标用，别混进品牌库。</p>'
            .'<div class="kg-cats" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px">'.$catCards.'</div>'
            .'<div style="display:flex;align-items:center;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #f3f4f6">'
            .'<label for="kg-limit" style="font-size:12.5px;color:#374151">每类最多生成</label>'
            .'<input id="kg-limit" type="number" name="limit" min="3" max="30" value="'.$perCatDefault.'" '
            .'style="width:76px;padding:7px 10px;border:1px solid #d1d5db;border-radius:9px;font-size:13px">'
            .'<span style="font-size:12px;color:#6b7280">条。'
            .'<b>目标词库里已经有的会自动排除</b>，同一批里语义相近的也只留一条，所以连着跑几次不会堆重复。</span>'
            .'</div>'
            .'</div>'
            .'<div class="g-card"><h3>3 · 导入到哪个词库</h3>'
            .'<div style="display:flex;gap:12px;align-items:flex-end">'
            .'<label style="'.self::LABEL_STYLE.';flex:1">目标词库'
            .'<select name="lib" style="'.self::INPUT_STYLE.'">'.$libOptions.'</select></label>'
            .'<button type="submit" class="g-btn g-btn--primary" id="kg-submit" style="height:38px">生成问题</button>'
            .'</div>'
            .'<p class="g-cap" style="margin:8px 0 0"><b>选「按类别自动分配」时</b>，'
            .'生成后勾选导入，系统会按每道题的类别，自动放进"品牌｜"开头对应的那个库——'
            .'四类一起生成也不会混。生成完会先给你看列表，勾选/删除之后再导入。</p>'
            .'</div></form>'
            .'<script>(function(){'
            .'var boxes=document.querySelectorAll(".kg-cat-box");'
            .'for(var i=0;i<boxes.length;i++){(function(b){b.addEventListener("change",function(){'
            .'var lb=b.closest("label");lb.style.borderColor=b.checked?"#4f46e5":"#d1d5db";'
            .'lb.style.background=b.checked?"#f5f3ff":"#fff";});})(boxes[i]);}'
            .'var f=document.getElementById("kg-form");if(f){f.addEventListener("submit",function(){'
            .'var b=document.getElementById("kg-submit");if(b){b.disabled=true;b.style.opacity=".65";b.textContent="生成中…";}'
            .'if(window.geoBusy){window.geoBusy("正在生成问题","按四类规则逐条生成，约 20–40 秒，请勿关闭页面");}});}'
            .'})();</script>';

        return self::shell($body, 'AI 关键词生成');
    }

    // =========================================================================
    // 结果页
    // =========================================================================

    /**
     * @param  array<string,list<array{q:string,why:string}>>  $items
     * @param  array<string,mixed>  $old
     * @param  list<object>  $libraries
     * @param  list<array<string,mixed>>  $categories
     */
    public static function resultPage(array $items, array $old, iterable $libraries, array $categories, string $adminBase, string $brand, string $meta = ''): string
    {
        $base = $adminBase.'/keyword-gen';

        $total = 0;
        foreach ($items as $list) {
            $total += count($list);
        }

        if ($total === 0) {
            $body = '<div class="g-toolbar"><a class="g-btn g-btn--ghost" href="'.$base.'">← 返回重新生成</a></div>'
                .'<div class="g-card"><h3>一条都没生成出来</h3>'
                .'<p class="g-cap">模型输出没能解析成结构化结果，或者生成的内容全被规则挡掉了'
                .'（比如 B 类题里出现了品牌名、A/C 类题里没有品牌名）。</p>'
                .'<p class="g-cap">建议：① 换个模型再试；② 把「已知产品」填上，模型有依据就不容易跑偏。</p></div>';

            return self::shell($body, 'AI 关键词生成');
        }

        // 目标词库下拉：默认自动分配
        $curLib = (int) ($old['lib'] ?? 0);
        $libOptions = '<option value="auto"'.($curLib === 0 ? ' selected' : '').'>按类别自动分配（推荐）</option>';
        foreach ($libraries as $l) {
            $sel = $curLib === (int) $l->id ? ' selected' : '';
            $libOptions .= '<option value="'.(int) $l->id.'"'.$sel.'>'.self::e((string) $l->name).'</option>';
        }

        // 按类别分组渲染
        $groups = '';
        $catMap = [];
        foreach ($categories as $c) {
            $catMap[(string) ($c['key'] ?? '')] = $c;
        }

        foreach ($items as $catKey => $list) {
            if ($list === []) {
                continue;
            }
            $meta2 = $catMap[$catKey] ?? [];
            $needBrand = ! empty($meta2['must_have_brand']);

            $rows = '';
            foreach ($list as $i => $it) {
                $q = (string) ($it['q'] ?? '');
                $why = (string) ($it['why'] ?? '');
                $rows .= '<tr class="kg-row">'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 10px;width:34px;text-align:center">'
                    .'<input type="checkbox" class="kg-pick" name="picked[]" form="kg-import" '
                    .'value="'.self::e($catKey.'|'.$q).'" checked style="margin:0"></td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px">'.self::e($q)
                    .($why !== '' ? '<div style="font-size:11px;color:#9ca3af;margin-top:2px">'.self::e($why).'</div>' : '')
                    .'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 10px;width:64px;text-align:center">'
                    .'<button type="button" class="kg-del" style="border:none;background:none;color:#b91c1c;'
                    .'font-size:12px;cursor:pointer;padding:0">删除</button></td>'
                    .'</tr>';
            }

            $groups .= '<div class="g-card"><h3>'.$catKey.' '.(string) ($meta2['label'] ?? '').' '
                .'<span class="g-pill" style="background:'.($needBrand ? '#eff6ff' : '#fef3c7').';'
                .'color:'.($needBrand ? '#1d4ed8' : '#92400e').';font-size:10.5px">'
                .($needBrand ? '带品牌名' : '不带品牌名').'</span>'
                .' <span style="font-size:12px;color:#9ca3af;font-weight:400">'.count($list).' 条</span></h3>'
                .'<p class="g-cap">'.self::e((string) ($meta2['desc'] ?? '')).'</p>'
                .'<table class="g-table" style="font-size:13px"><tbody>'.$rows.'</tbody></table></div>';
        }

        $body = '<div class="g-toolbar">'
            .'<a class="g-btn g-btn--ghost" href="'.$base.'">← 重新生成</a>'
            .'<span class="g-sep">'.self::e($meta).'</span></div>'
            .'<div class="g-card" style="background:#f0f9ff;border:1px solid #bae6fd">'
            .'<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;color:#0c4a6e">'
            .'<span>共生成 <b>'.$total.'</b> 条，当前勾选 <b id="kg-count">'.$total.'</b> 条</span>'
            .'<button type="button" class="g-btn g-btn--ghost" id="kg-all" style="font-size:12px;padding:4px 11px">全选</button>'
            .'<button type="button" class="g-btn g-btn--ghost" id="kg-none" style="font-size:12px;padding:4px 11px">全不选</button>'
            .'<span style="font-size:12px;color:#0369a1">不需要的可以直接点「删除」从列表移除</span>'
            .'</div></div>'
            .$groups
            .'<form method="post" action="'.$base.'" id="kg-import">'
            .self::csrfField()
            .'<input type="hidden" name="do_import" value="1">'
            .'<input type="hidden" name="brand_for_auto" value="'.self::e($brand).'">'
            .'<div class="g-card"><h3>导入到词库</h3>'
            .'<div style="display:flex;gap:12px;align-items:flex-end">'
            .'<label style="'.self::LABEL_STYLE.';flex:1">目标词库'
            .'<select name="lib" style="'.self::INPUT_STYLE.'">'.$libOptions.'</select></label>'
            .'<button type="submit" class="g-btn g-btn--primary" id="kg-do" style="height:38px">一键导入选中项</button>'
            .'</div>'
            .'<p class="g-cap" style="margin:8px 0 0"><b>自动分配</b>会按每道题的类别，'
            .'分别放进「'.$brand.'｜A/B/C/D」对应的库，不会混。<br>'
            .'导入时按「同一词库内文本完全相同」去重，已存在的静默跳过——'
            .'<b>但换个说法拦不住</b>（「用什么护肤品」和「用啥护肤品」算两条）。</p>'
            .'</div></form>'
            .'<script>(function(){'
            .'function picks(){return document.querySelectorAll(".kg-pick");}'
            .'function sync(){var n=0,p=picks();for(var i=0;i<p.length;i++){if(p[i].checked)n++;}'
            .'var c=document.getElementById("kg-count");if(c)c.textContent=n;}'
            .'var p=picks();'
            .'for(var i=0;i<p.length;i++){p[i].addEventListener("change",sync);}'
            .'var all=document.getElementById("kg-all"),none=document.getElementById("kg-none");'
            .'if(all)all.addEventListener("click",function(){var q=picks();for(var i=0;i<q.length;i++){q[i].checked=true;}sync();});'
            .'if(none)none.addEventListener("click",function(){var q=picks();for(var i=0;i<q.length;i++){q[i].checked=false;}sync();});'
            .'var dels=document.querySelectorAll(".kg-del");'
            .'for(var i=0;i<dels.length;i++){(function(b){b.addEventListener("click",function(){'
            .'var tr=b.closest("tr");if(tr){tr.parentNode.removeChild(tr);sync();}});})(dels[i]);}'
            .'sync();'
            .'var f=document.getElementById("kg-import");'
            .'if(f)f.addEventListener("submit",function(ev){'
            .'if(document.querySelectorAll(".kg-pick:checked").length===0){ev.preventDefault();'
            .'if(window.geoBusy){window.geoBusy("没有选中任何问题","至少勾选一条再导入");'
            .'setTimeout(function(){var x=document.getElementById("geo-busy");if(x)x.classList.remove("is-on");},2000);}return;}'
            .'var b=document.getElementById("kg-do");if(b){b.disabled=true;b.style.opacity=".65";b.textContent="导入中…";}});'
            .'})();</script>';

        return self::shell($body, 'AI 关键词生成 · 结果');
    }

    /**
     * 导入结果页。
     *
     * @param  array{added:int,skipped:int,total:int,error:string}  $res
     * @param  array<string,int>  $stats
     */
    public static function donePage(array $res, array $stats, string $libName, int $libId, array $categories, string $adminBase): string
    {
        $base = $adminBase.'/keyword-gen';
        $ok = $res['error'] === '';

        $statChips = '';
        $catLabel = [];
        foreach ($categories as $c) {
            $catLabel[(string) ($c['key'] ?? '')] = (string) ($c['label'] ?? '');
        }
        foreach ($stats as $k => $n) {
            if ($n <= 0) {
                continue;
            }
            $label = $k === '' ? '未分类' : $k.' '.($catLabel[$k] ?? '');
            $statChips .= '<span style="display:inline-flex;align-items:center;gap:6px;padding:5px 11px;'
                .'border-radius:99px;background:#f3f4f6;font-size:12px;margin:0 6px 6px 0">'
                .self::e($label).'<b>'.$n.'</b></span>';
        }

        $body = '<div class="g-card" style="border-left:4px solid '.($ok ? '#047857' : '#b91c1c').'">'
            .'<h3>'.($ok ? '导入完成' : '导入失败').'</h3>'
            .($ok
                ? '<p style="font-size:13.5px;line-height:1.85;margin:4px 0 12px">'
                    .'成功写入 <b style="color:#047857">'.$res['added'].'</b> 条到「'.self::e($libName).'」，'
                    .'跳过 <b>'.$res['skipped'].'</b> 条（词库里已经有了）。</p>'
                : '<p style="font-size:13.5px;color:#b91c1c">'.self::e($res['error']).'</p>')
            .($statChips !== ''
                ? '<div style="margin:10px 0 4px"><div style="font-size:12px;color:#6b7280;margin-bottom:7px">'
                    .'这个词库现在的分类构成</div>'.$statChips.'</div>'
                : '')
            .'<div class="g-row" style="margin-top:14px">'
            .'<a class="g-btn g-btn--primary" href="'.self::e($adminBase.'/coverage-check').'">去覆盖核查用这批题</a>'
            .'<a class="g-btn g-btn--ghost" href="'.$base.'">再生成一批</a>'
            .'<a class="g-btn g-btn--ghost" href="'.self::e($adminBase.'/keyword-miner').'">去关键词挖掘（真实搜索词）</a>'
            .'</div>'
            .'<p class="g-cap" style="margin:14px 0 0">'
            .'<b>下一步</b>：覆盖核查里选这个词库时，可以按类别筛（只跑 A+C 判准确性，或只跑 B 判有没有被提到）。'
            .'不同类别的判断标准不一样，别混着跑。</p>'
            .'</div>';

        return self::shell($body, '导入完成');
    }

    // =========================================================================
    // 工具
    // =========================================================================

    /** CSRF 隐藏域。CLI / 无 session 环境取不到，返回空串（正常浏览器访问一定有）。 */
    private static function csrfField(): string
    {
        try {
            if (! function_exists('csrf_token')) {
                return '';
            }
            $t = (string) csrf_token();
            if ($t === '') {
                return '';
            }

            return '<input type="hidden" name="_token" value="'.self::e($t).'">';
        } catch (\Throwable) {
            return '';
        }
    }

    public static function eStatic(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
