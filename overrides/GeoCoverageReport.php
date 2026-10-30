<?php

namespace App\Support\GeoAudit;

/**
 * 覆盖核查 —— 表单页 / 核查进行页 / 报告页 / 建任务结果页
 *
 * 页面拆成三段而不是一段的原因：
 *   12 道题串行调搜索要 40–90 秒，一个请求干完页面会长时间空白。
 *   拆成「清单页 + 逐题 fetch」后，用户能一题一题看到结果出来，
 *   单次请求最长也就 8 秒，不会撞 nginx / php-fpm 的任何超时。
 */
final class GeoCoverageReport
{
    /**
     * 题面 → 类别索引的请求内缓存（见 categoryIndex()）。
     *
     * 报告页一次渲染要判几十道题的类别，逐题查库会把页面拖慢；
     * 整表只有百来行，读一次就够。
     *
     * @var array<string,list<array{owner:string,category:string}>>|null
     */
    private static ?array $categoryIndex = null;

    /**
     * 常见站点的展示名归一（只用于显示，不参与任何计数与判定）。
     *
     * 数据库里同一个站点的 site_name 有多种写法（"Taobao.com" / "淘寶" / 空），
     * 直接印出来用户认不出是同一家，也没法和页面上其它中文站点名对读。
     * 长键在前：baike.baidu.com 必须先于 baidu.com 命中，否则百科会变成「百度」。
     *
     * @var array<string,string>
     */
    private const SITE_LABELS = [
        'baike.baidu.com' => '百度百科',
        'zhidao.baidu.com' => '百度知道',
        'xiaohongshu.com' => '小红书',
        'smzdm.com' => '什么值得买',
        'jiemian.com' => '界面新闻',
        'bilibili.com' => '哔哩哔哩',
        'douyin.com' => '抖音',
        'taobao.com' => '淘宝',
        'tmall.com' => '天猫',
        'zhihu.com' => '知乎',
        'weibo.com' => '微博',
        'douban.com' => '豆瓣',
        'jd.com' => '京东',
        'shihuo.cn' => '识货',
        '39.net' => '39健康网',
        'baidu.com' => '百度',
    ];

    /**
     * A 类历史采样明细的请求内缓存（见 authoritySamples()）。
     *
     * @var array{samples:list<array<string,mixed>>,error:string}|null
     */
    private static ?array $gapSamplesCache = null;

    /** @var string 上面这份缓存的口径键（品牌 + 自有域名），口径变了要重查 */
    private static string $gapSamplesKey = '';

    /**
     * B/C/D 题级内容缺口的请求内缓存（见 contentGapStats()）。
     *
     * @var array{pending:int,missed:int,mention:int,owned:int,questions:int,error:string}|null
     */
    private static ?array $contentGapCache = null;

    /** @var string 上面这份缓存的口径键（品牌 slug） */
    private static string $contentGapKey = '';

    /**
     * @param  array<string,mixed>  $old
     * @param  list<object>  $libraries
     * @param  string  $error  表单级错误（品牌名/题数等校验）
     * @param  array<string,mixed>  $brandForm  品牌档案内联表单状态（POST 失败时由路由传入；
     *                                         为空则按 ?brand_form= / ?brand_edit= 现算）
     */
    public static function formPage(array $old = [], array $libraries = [], string $error = '', array $brandForm = []): string
    {
        // 【本地修复】品牌输入框改成「只读展示 + 切换按钮」。
        //
        // 原来这一格是自由文本 input，用户随手敲「海洋至尊」，而它只在本次请求里生效，
        // 不会写 cookie —— 于是跑完核查，报告页按 cookie（冰溪）出分块卡，
        // 逐题结果却是海洋至尊，同一页两个品牌。
        //
        // 现在品牌只有一条来源：当前口径（?brand= → cookie geo_brand → 品牌档案 default）。
        // 想换品牌点 chip，链接带 ?brand=<slug>，web.php 的路由加载段会把它写进 cookie，
        // 全局生效。表单仍用 hidden input 把品牌带上，保证提交时口径不丢。
        //
        // $old['brand'] 有值时以它为准：那是用户刚刚提交/回填过的品牌。
        $cookieSlug = GeoShareOfVoice::resolveSlug();
        $cookieRow = GeoShareOfVoice::brand($cookieSlug);
        $oldBrandRaw = trim((string) ($old['brand'] ?? ''));
        $oldBrandRow = $oldBrandRaw !== '' ? self::brandRowOf($oldBrandRaw) : null;

        if ($oldBrandRow !== null) {
            // 回填值就是档案里的品牌（可能是 slug，也可能是中文名）→ 统一显示成中文名
            $brandValue = trim((string) ($oldBrandRow['name'] ?? ''));
            if ($brandValue === '') {
                $brandValue = $oldBrandRaw;
            }
            $activeSlug = trim((string) ($oldBrandRow['slug'] ?? ''));
        } elseif ($oldBrandRaw !== '') {
            // 用户手填的自定义品牌（档案里没有）：原样保留，但没有可高亮的 chip
            $brandValue = $oldBrandRaw;
            $activeSlug = '';
        } else {
            $brandValue = trim((string) ($cookieRow['name'] ?? ''));
            if ($brandValue === '') {
                $brandValue = $cookieSlug;
            }
            $activeSlug = trim((string) ($cookieRow['slug'] ?? ''));
        }

        $brand = self::e($brandValue);
        $url = self::e((string) ($old['url'] ?? ''));

        // 【本地扩展】品牌档案：当前品牌有没有官网。
        // has_site=false 的品牌（新增品牌时 site_url 留空）不再强制填自有站点，
        // 输入框改成只读占位「该品牌无官网」，后端也不再因为 domain 为空拒跑。
        $activeBrandRow = $activeSlug !== '' ? GeoShareOfVoice::findBrand($activeSlug) : null;
        if ($activeBrandRow === null && $brandValue !== '') {
            $activeBrandRow = GeoShareOfVoice::findBrand($brandValue);
        }
        $activeHasSite = GeoShareOfVoice::hasSite($activeBrandRow);
        if (! $activeHasSite) {
            $url = '';
        }

        // 品牌档案内联表单（新增 / 编辑 / 删除）的初始状态。
        // POST 失败时由路由把提交值和错误一起传进来，用户不用重填。
        $brandForm = self::brandFormState($brandForm);

        $competitors = self::e((string) ($old['competitors'] ?? ''));
        $questions = self::e((string) ($old['questions'] ?? ''));
        $lib = (int) ($old['lib'] ?? 0);
        // 【本地修复】「本次核查题数 6 / 12 / 20」下拉已删除 —— 真实核查就是把题跑全，
        // 人为截断到 20 题会让 78 题的词库里剩下的 58 题永远没有结论。
        // 现在题数 = 词库全部题 + 手填题，上限保护在后端（COVERAGE_MAX_QUESTIONS）。
        // 这里不再有 $limit：表单不再提交这个参数，页面上的预估由 JS 按实际题数实时算。
        $rounds = (int) ($old['rounds'] ?? 3);
        if (! in_array($rounds, [1, 3, 5], true)) {
            $rounds = 3;
        }

        // data-count 是给表单页 ETA 用的：删掉题数下拉后，「本次要跑多少题」
        // 只能由当前选中的词库 + 手填行数现算，JS 直接读这个属性，
        // 不去解析「78 词」这串展示文案（文案改一个字就废了）。
        $libOptions = '<option value="0" data-count="0">不使用词库，只用下面手填的问题</option>';
        foreach ($libraries as $row) {
            $libCount = (int) ($row->keyword_count ?? 0);
            $libOptions .= '<option value="'.(int) $row->id.'" data-count="'.$libCount.'"'.($lib === (int) $row->id ? ' selected' : '').'>'
                .self::e((string) $row->name).'（'.$libCount.' 词，优先取问句）</option>';
        }

        $inputStyle = 'width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13.5px';
        $labelStyle = 'font-size:12.5px;font-weight:600;color:#374151';
        $err = $error !== '' ? '<div class="g-err">'.self::e($error).'</div>' : '';

        // 【本地扩展】品牌档案写完后的成功提示（session flash，只显示一次）。
        // 走闪存而不是 query 参数：提示文案里有品牌名，放 URL 里会被复制粘贴传出去。
        $flashNotice = '';
        try {
            $flashNotice = trim((string) (session()->pull('brand_notice') ?? ''));
        } catch (\Throwable) {
            $flashNotice = '';
        }
        $noticeHtml = $flashNotice !== '' ? '<div class="g-note">'.self::e($flashNotice).'</div>' : '';

        // 【本地修复】删掉「本次核查题数」下拉后，用户点下去之前必须知道要等多久、花多少钱。
        // 这里先按「当前选中的词库词数 + 手填非空行数」渲染一版预估（服务端算，
        // 保证没有 JS 也能看到数字），页面上的 JS 在同一口径上实时重算。
        //   总次数 = 题数 × 采样次数
        //   耗时   = 总次数 × 5 秒（沿用原来"每题约 15 秒 ≈ 3 次采样"的口径）
        //   成本   = 总次数 / 1000 × 5 元（DeepSeek 搜索 1000 次 5 元）
        $etaLibCount = 0;
        foreach ($libraries as $row) {
            if ($lib === (int) $row->id) {
                $etaLibCount = (int) ($row->keyword_count ?? 0);
                break;
            }
        }
        $etaManualCount = 0;
        foreach (preg_split('/[\r\n]+/u', (string) ($old['questions'] ?? '')) ?: [] as $etaLine) {
            if (trim((string) $etaLine) !== '') {
                $etaManualCount++;
            }
        }
        $etaCount = $etaLibCount + $etaManualCount;
        if ($etaCount <= 0) {
            $etaText = '还没有题目：选一个关键词库，或在下面手填问题';
        } else {
            $etaCalls = $etaCount * $rounds;
            $etaText = '本次将跑 '.$etaCount.' 题 × '.$rounds.' 次采样 = '.$etaCalls.' 次搜索 ≈ 约 '
                .max(1, (int) ceil($etaCalls * 5 / 60)).' 分钟 · 约 ¥'.number_format($etaCalls / 1000 * 5, 2, '.', '');
            if ($etaCount > 300) {
                // 采样已经是 1 次时再说"降到 1 次"是废话，改成只提分批
                $etaText .= '（题数较多，预计耗时较长：'
                    .($rounds > 1 ? '建议先把采样次数降到 1 次，或分两批跑' : '建议分两批跑').'）';
            }
        }

        $body = self::formHistoryBar($activeSlug)
            .'<div class="g-card"><h3>覆盖核查</h3>'
            .'<p class="g-cap">把用户会问的问题逐条丢给 AI 搜索，看<b>真实推荐位</b>里有没有你；没进前 10 的直接生成内容任务</p>'
            .$noticeHtml
            .$err
            .'<form method="get" action="" id="cov-form">'
            .'<input type="hidden" name="plan" value="1">'
            .'<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">'
            .'<div>'
            .'<span style="'.$labelStyle.'">品牌 / 产品名</span>'
            .self::brandChips($activeSlug, ['plan'], '', true, (string) ($brandForm['mode'] ?? ''))
            .'<div class="g-cap" style="margin:6px 0 0">只读：核查只跑当前品牌。点上面的按钮换品牌，'
            .'换完全站生效（所有页面都跟着变）。右边的「编辑 / + 新增品牌」改的是品牌档案本身。</div>'
            .'<input type="hidden" name="brand" value="'.$brand.'">'
            .'</div>'
            .($activeHasSite
                ? '<label style="'.$labelStyle.'">自有站点 URL（必填，用来认领推荐位）'
                    .'<input type="text" name="url" value="'.$url.'" placeholder="例如：https://www.binsis.cn/" style="'.$inputStyle.'"></label>'
                : '<label style="'.$labelStyle.'">自有站点 URL（该品牌无官网，不用填）'
                    .'<input type="text" name="url" value="" readonly placeholder="该品牌无官网" '
                    .'style="'.$inputStyle.';background:#f3f4f6;color:#6b7280;cursor:not-allowed"></label>'
                    .'<div class="g-cap" style="margin:4px 0 0;color:#b45309">该品牌在档案里标记为「无官网」（has_site=false）。'
                    .'核查照常跑，只是不认领自有推荐位；官网相关指标的折叠口径下一批上线。</div>')
            .'<label style="'.$labelStyle.'">对照品牌（可选，逗号分隔）'
            .'<input type="text" name="competitors" value="'.$competitors.'" placeholder="例如：薇诺娜,理肤泉" style="'.$inputStyle.'"></label>'
            .'<label style="'.$labelStyle.'">题目来源：关键词库'
            .'<select name="lib" style="'.$inputStyle.'">'.$libOptions.'</select></label>'
            .'</div>'
            .'<label style="display:block;margin-top:14px;'.$labelStyle.'">手填问题（一行一个，可与词库叠加）'
            .'<textarea name="questions" rows="5" placeholder="敏感肌用什么护肤品？&#10;红血丝能不能用酸？" style="'.$inputStyle.';resize:vertical;font-family:inherit">'.$questions.'</textarea></label>'
            .'<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:10px 14px;'
            .'font-size:12.5px;line-height:1.75;margin:6px 0 14px;color:#0c4a6e">'
            .'<b>写题之前先分清两种问题，它们的判断标准不一样：</b><br>'
            .'<b>品类词</b>（「敏感肌护肤品推荐」，不提品牌名）→ 看的是<b>有没有进前 10</b>。'
            .'这里没进就是真缺口，适合拿来做内容任务。<br>'
            .'<b>品牌词</b>（「'.($brand !== '' ? self::e($brand) : '品牌名').'怎么样」，带品牌名）→ '
            .'看的是<b>第 1 名是不是你自己的页面</b>。这种题几乎一定"被提到"，'
            .'所以拿"有没有覆盖"来判断没意义；有意义的是一堆别人的文章里，你的官网排第几。'
            .'<b>品牌词的份额对比建议去「声量对标」看，那里有品牌词/品类词分开的 SOV。</b>'
            .'</div>'
            .'<div class="g-row" style="margin-top:16px">'
            // 【本地修复】这里原来是「本次核查题数 6 / 12 / 20」下拉，已整块删除。
            // 删的理由：真实核查就是把题跑全。冰溪 B 类有 78 个词，下拉最高 20 题，
            // 剩下 58 题永远没有结论 —— 这不是"节省成本"，是数据缺口。
            // 「每题材采样 1 / 3 / 5」保留不动：它控制的是单题结果的可信度，不是覆盖面。
            .'<label style="'.$labelStyle.';display:flex;align-items:center;gap:8px">每题材采样'
            .'<select name="rounds" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:9px;font-size:13px">'
            .'<option value="1"'.($rounds === 1 ? ' selected' : '').'>1 次（快）</option>'
            .'<option value="3"'.($rounds === 3 ? ' selected' : '').'>3 次（推荐）</option>'
            .'<option value="5"'.($rounds === 5 ? ' selected' : '').'>5 次（严谨）</option>'
            .'</select></label>'
            .'<button type="submit" class="g-btn g-btn--primary" id="cov-submit">开始核查</button>'
            // 预估先由 PHP 按「当前选中的词库词数 + 手填行数」渲染一版（无 JS 也能看到数字），
            // 之后 JS 在切换词库 / 改手填内容 / 换采样次数时实时重算，两边文案格式完全一致。
            .'<span class="g-cap" style="margin:0'.($etaCount > 300 ? ';color:#c2410c' : '').'" id="cov-eta">'.$etaText.'</span></div>'
            .'</form>'
            // 品牌档案表单必须放在 GET 表单【外面】：HTML 不允许 form 嵌套，
            // 套在一起浏览器会把内层 form 丢掉，"新增品牌"点了就变成提交核查。
            .self::brandFormSection($brandForm)
            .'</div>'
            .self::conclusionSection()
            .'<script>(function(){var f=document.getElementById("cov-form");if(!f)return;'
            // NOSITE：当前品牌档案里 has_site=false，自有站点是只读占位，不能再当必填拦下来
            .'var NOSITE='.($activeHasSite ? 'false' : 'true').';'
            .'var eta=document.getElementById("cov-eta"),lib=f.querySelector("select[name=lib]"),'
            .'rnd=f.querySelector("select[name=rounds]"),ta=f.querySelector("textarea[name=questions]");'
            // 题数 = 词库词数（option 上的 data-count）+ 手填非空行数
            .'function libN(){if(!lib)return 0;var o=lib.options[lib.selectedIndex];if(!o)return 0;'
            .'var n=parseInt(o.getAttribute("data-count")||"0",10);return isNaN(n)?0:n;}'
            .'function manualN(){if(!ta)return 0;var ls=(ta.value||"").split(/[\r\n]+/),n=0;'
            .'for(var i=0;i<ls.length;i++){if(ls[i].replace(/\\s+/g,"")!=="")n++;}return n;}'
            .'function roundN(){var r=rnd?parseInt(rnd.value,10):3;return isNaN(r)?3:r;}'
            .'function estimate(){var n=libN()+manualN(),r=roundN(),calls=n*r,'
            .'mins=Math.max(1,Math.ceil(calls*5/60)),cost=(calls/1000*5).toFixed(2);'
            .'return{n:n,r:r,calls:calls,mins:mins,cost:cost};}'
            .'function syncEta(){if(!eta)return;var s=estimate();'
            .'if(s.n<=0){eta.textContent="还没有题目：选一个关键词库，或在下面手填问题";eta.style.color="#6b7280";return;}'
            .'var t="本次将跑 "+s.n+" 题 × "+s.r+" 次采样 = "+s.calls+" 次搜索 ≈ 约 "+s.mins+" 分钟 · 约 ¥"+s.cost;'
            .'if(s.n>300){t+="（题数较多，预计耗时较长："'
            .'+(s.r>1?"建议先把采样次数降到 1 次，或分两批跑":"建议分两批跑")+"）";eta.style.color="#c2410c";}'
            .'else{eta.style.color="#6b7280";}eta.textContent=t;}'
            .'if(lib)lib.addEventListener("change",syncEta);if(rnd)rnd.addEventListener("change",syncEta);'
            .'if(ta)ta.addEventListener("input",syncEta);syncEta();'
            .'f.addEventListener("submit",function(ev){'
            .'var brand=f.querySelector("input[name=brand]");var url=f.querySelector("input[name=url]");'
            .'if(brand&&!brand.value.trim()){ev.preventDefault();'
            // 品牌现在是 hidden input（只读展示 + 切换按钮），focus 无效，只对可见控件标红
            .'if(brand.type!=="hidden"){brand.focus();brand.style.borderColor="#ef4444";}return;}'
            // 自有站点：无官网品牌（has_site=false）时 url 是 readonly 占位（永远为空），不能拦
            .'if(!NOSITE&&url&&!url.value.trim()){ev.preventDefault();url.focus();url.style.borderColor="#ef4444";return;}'
            .'if(NOSITE&&url){url.style.borderColor="#d1d5db";}'
            // 题数不再受人控制，点下去之前必须让用户知道要等多久、花多少钱。
            // 超过 200 次搜索（约 17 分钟、¥1 起）才拦一次，小题量不打断。
            .'var s=estimate();'
            .'if(s.calls>200){var msg="本次将执行 "+s.calls+" 次搜索，预计 "+s.mins+" 分钟、约 ¥"+s.cost+"。\\n"'
            .'+"中途关闭页面会导致未完成的题标记为「未核查」。";'
            .'if(s.calls>500){msg+="\\n本次搜索次数偏多："'
            .'+(s.r>1?"建议把采样次数降到 1 次，或分两批跑。":"建议分两批跑。");}'
            .'if(!window.confirm(msg)){ev.preventDefault();return;}}'
            .'var b=document.getElementById("cov-submit");'
            .'if(b){b.disabled=true;b.style.opacity=".65";b.textContent="正在生成核查清单…";}'
            .'if(window.geoBusy){window.geoBusy("正在整理核查清单","合并词库问句与手填问题，约 1 秒");}});})();</script>';

        return self::shell($body, '覆盖核查');
    }

    /**
     * 核查进行页：清单先渲染好，JS 逐题拉结果回填。
     *
     * @param  array<string,mixed>  $ctx
     * @param  list<string>  $questions
     */
    public static function runPage(array $ctx, array $questions, string $adminBase): string
    {
        $total = count($questions);
        $brand = (string) ($ctx['brand'] ?? '');
        // 【本地修复】核查完成后自动跳转的报告地址必须带上「这次核查的品牌」。
        // 原来只带 report=1，报告页只能按 cookie 解析品牌，于是跑完 A 品牌却看到 B 品牌的分块卡。
        $reportUrl = $adminBase.'/coverage-check?report=1'
            .'&brand='.rawurlencode(self::coverageBrandSlug($ctx));
        $rows = '';
        foreach ($questions as $i => $q) {
            $rows .= '<tr id="cov-row-'.$i.'">'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:34px;text-align:center;color:#9ca3af">'.($i + 1).'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px">'.self::e($q).'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:130px" id="cov-st-'.$i.'"><span style="color:#9ca3af">等待</span></td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:80px" id="cov-pos-'.$i.'"><span style="color:#9ca3af">—</span></td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;color:#6b7280;font-size:12px" id="cov-snip-'.$i.'">—</td>'
                .'</tr>';
        }

        $body = '<div class="g-card"><h3>正在核查：'.self::e($brand).'</h3>'
            .'<p class="g-cap" id="cov-state">0 / '.$total.' 题已核查</p>'
            .'<div style="height:8px;background:#eef0f3;border-radius:99px;overflow:hidden;margin:0 0 16px">'
            .'<div id="cov-bar" style="height:100%;width:0;background:linear-gradient(90deg,#4f46e5,#7c3aed);transition:width .3s"></div></div>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
            .'<th style="width:34px">#</th><th>问题</th><th style="width:130px">结果</th>'
            .'<th style="width:80px">排位</th><th style="width:auto">当前第 1 名 / 命中说明</th>'
            .'</tr></thead><tbody id="cov-body">'.$rows.'</tbody></table>'
            .'<div class="g-row" style="margin-top:18px">'
            .'<a class="g-btn g-btn--primary" id="cov-goto-report" href="'.self::e($reportUrl).'" style="display:none">查看核查报告</a>'
            .'<span class="g-cap" style="margin:0" id="cov-hint">请勿关闭页面，逐题出结果中…</span></div></div>'
            .'<script>(function(){'
            .'var TOTAL='.$total.';var BASE="'.$adminBase.'/coverage-check";'
            .'var STATE=new Array(TOTAL);'   // undefined=未跑 / "busy"=核查中 / object=结果
            .'var bar=document.getElementById("cov-bar"),stateEl=document.getElementById("cov-state"),'
            .'btn=document.getElementById("cov-goto-report"),hint=document.getElementById("cov-hint"),'
            .'body=document.getElementById("cov-body");'
            .'function pill(t,cls){return "<span class=\\"g-pill "+cls+"\\">"+t+"</span>";}'
            .'function el(id){return document.getElementById(id);}'
            .'function render(i){'
            .'var s=STATE[i],st=el("cov-st-"+i),pos=el("cov-pos-"+i),sn=el("cov-snip-"+i);'
            .'if(!st)return;'
            .'if(s===undefined){st.innerHTML="<span style=\\"color:#9ca3af\\">等待</span>";return;}'
            .'if(s==="busy"){st.innerHTML="<span style=\\"color:#4f46e5\\">搜索中…</span>";return;}'
            .'if(s.error){st.innerHTML=pill("失败","g-pill--warn")+" <a href=\\"#\\" data-retry=\\""+i+"\\" style=\\"font-size:11px;color:#4f46e5\\">重试</a>";'
            .'if(pos)pos.innerHTML="<span style=\\"color:#9ca3af\\">—</span>";if(sn)sn.textContent=s.error;return;}'
            .'var hitNote=(s.rounds>1)?" <span class=\\"g-tag\\" style=\\"font-size:10.5px\\">"+s.covered_rounds+"/"+s.rounds+"</span>":"";'
            .'if(s.covered_type==="自有页面"){st.innerHTML=pill("自有页面","g-pill--ok")+hitNote;}'
            .'else if(s.covered_type==="仅被提及"){st.innerHTML=pill("仅被提及","g-pill--warn")+hitNote;}'
            .'else{st.innerHTML=pill("未进前 10","g-pill--bad")+hitNote;}'
            .'if(pos){'
            .'if(s.rounds>1){'
            .'var seq=(s.ranks||[]).map(function(v){return v?("#"+v):"-";}).join(" / ");'
            .'pos.innerHTML=(s.best_rank?"<b>最好 #"+s.best_rank+"</b>":"<span style=\\"color:#9ca3af\\">未进</span>")'
            .'+"<br><span style=\\"color:#6b7280;font-size:11px\\">"+seq+"</span>";'
            .'}else{'
            .'pos.innerHTML=s.best_rank?"<b>第 "+s.best_rank+" 位</b>":"<span style=\\"color:#9ca3af\\">—</span>";'
            .'}}'
            .'var extra="";if(s.hit_competitors){var ks=Object.keys(s.hit_competitors);if(ks.length){'
            .'extra=" <span class=\\"g-tag\\" style=\\"background:#fef2f2;color:#b91c1c\\">竞品:"+ks.map(function(k){return k+"(第"+s.hit_competitors[k]+")";}).join("/")+"</span>";}}'
            .'if(sn){var t=(s.mine_title||"");if(!t&&s.top1_title){t="① "+(s.top1_domain||"")+" — "+s.top1_title;}sn.innerHTML=t+extra;}'
            .'}'
            .'function counters(){var done=0,own=0,men=0,miss=0,fail=0;for(var i=0;i<TOTAL;i++){var s=STATE[i];'
            .'if(s===undefined||s==="busy")continue;done++;'
            .'if(s.error){fail++;}else if(s.covered_type==="自有页面"){own++;}else if(s.covered_type==="仅被提及"){men++;}else{miss++;}}'
            .'return{done:done,own:own,men:men,miss:miss,fail:fail};}'
            .'function tick(){var c=counters();bar.style.width=(c.done/TOTAL*100)+"%";'
            .'stateEl.textContent=c.done+" / "+TOTAL+" 题已核查　自有页面 "+c.own+"　仅被提及 "+c.men+"　未进前 10 "+c.miss+(c.fail?"　失败 "+c.fail:"");'
            .'if(c.done>=TOTAL&&hint){hint.textContent="核查完成 —— 自有页面 "+c.own+"，仅被提及 "+c.men+"，未进前 10 "+c.miss'
            .'+(c.fail?"，失败 "+c.fail+"（可点该行「重试」）":"")+"；进入报告可勾选待补问题生成任务草稿";}'
            .'if(c.done>=TOTAL&&btn){btn.style.display="inline-flex";}}'
            .'async function step(i){STATE[i]="busy";render(i);'
            .'try{var r=await fetch(BASE+"?probe=1&i="+i,{credentials:"same-origin",headers:{"Accept":"application/json"}});'
            .'var j=await r.json();STATE[i]=j;}catch(e){STATE[i]={error:"请求失败，可点重试"};}'
            .'render(i);tick();}'
            .'if(body){body.addEventListener("click",function(ev){var t=ev.target;'
            .'while(t&&t!==body){if(t.getAttribute&&t.getAttribute("data-retry")!==null){ev.preventDefault();'
            .'var i=parseInt(t.getAttribute("data-retry"),10);if(!isNaN(i)){step(i);}return;}t=t.parentNode;}});}'
            .'(async function(){for(var i=0;i<TOTAL;i++){await step(i);}})();'
            .'})();</script>';

        return self::shell($body, '覆盖核查进行中');
    }

    /**
     * 内容缺口清单（B/C/D）的<b>唯一来源</b>。
     *
     * 报告页渲染（checkbox 的 value）和 to-tasks 生成任务（picked 的索引）
     * 都必须走这里，不能各写一份排序逻辑——一旦两侧顺序不一致，
     * 前端勾第 N 个、后端建第 M 个，会静默生成"错题"的任务，
     * 而且从结果页看不出来（标题看着都合理，只是不是用户想选的那几题）。
     *
     * 顺序规则：未进前 10 优先，其次仅被提及；同组内保持传入顺序。
     * 失败题（error 非空）和已有自有页面的题不进清单。
     *
     * ── A 类为什么不在这里 ────────────────────────────────────────────
     * A 类（品牌认知，例如"海洋至尊是什么档次"）缺位时，抢走位置的是媒体和
     * 百科，不是竞品。这类题的正确动作是补权威信源（品牌词条、新闻露出、
     * 百科条目），写产品软文抢不到那个位置。两类缺口的动作类型根本不同，
     * 所以 A 类改由 authorityGapList() 出只读清单，不生成内容任务。
     *
     * picked 的索引必须继续只对应本方法的结果：A 类被排除后，
     * 页面上的第 N 个 checkbox 依旧是 picked=N，勾选/生成逻辑一行不用改。
     *
     * @param  array<int,array<string,mixed>>  $results  本次核查逐题结果
     * @param  string  $brand  当前品牌（用来把题限定在它的词库里判类别；空 = 不限定）
     * @return array<int,array<string,mixed>> 返回的<b>数组下标即 picked 索引</b>（已 array_values 重排）
     */
    public static function todoList(array $results, string $brand = ''): array
    {
        $missed = [];
        $mentioned = [];

        foreach ($results as $r) {
            if (! is_array($r)) {
                continue;
            }
            // 搜索失败 / 未核查的题无法判断覆盖情况，不能塞进待补清单
            if ((string) ($r['error'] ?? '') !== '') {
                continue;
            }
            // A 类不进内容任务（见方法注释）：这类要补的是信源，不是文章
            if (self::isAuthorityQuestion((string) ($r['question'] ?? ''), $brand)) {
                continue;
            }
            $type = (string) ($r['covered_type'] ?? '');
            if ($type === '自有页面') {
                continue;
            }
            if ($type === '仅被提及') {
                $mentioned[] = $r;
            } else {
                $missed[] = $r;
            }
        }

        return array_values(array_merge($missed, $mentioned));
    }

    /**
     * 信源缺口清单（A 类专属，不进内容任务）。
     *
     * A 类问题是用户主动搜品牌名，AI 回答必然提到品牌，所以「覆盖率」恒为 100%、没有区分度。
     * 这类要看的是：官网在不在信源里、不在时顶上来的是谁。
     * 实测官网缺位时抢位的是媒体和百科（界面新闻/百度百科），不是竞品——
     * 所以要补的是权威信源，不是产品软文。因此这类问题不进 to-tasks。
     *
     * 缺位程度分档，档位口径与报告页 A 区块的「首位归属」完全一致：
     *   · 官网第 1 名      → 已经占住了，不是缺口，<b>不出现在返回值里</b>
     *   · 官网第 2-3 名    → 官网在场但被媒体/平台压在前面，是缺口
     *   · 官网第 4 名及以后 → 更靠后，同属缺口（A 区块的「第 4 名及以后」档）
     *   · 完全缺位        → 官网根本不在信源里，是最严重的缺口
     *
     * 返回顺序保持传入顺序（调用方按 run_id 升序传入 → 按时间先后看缺口演变）。
     * 不在这里按题去重：一次采样一行才能和 A 区块的「缺位替身榜」次数对上，
     * 折叠会让"界面新闻网 5 次"和清单条数自相矛盾。去重展示由调用方决定。
     *
     * @param  array<int,array<string,mixed>>  $results
     *         每项需含 question / own_rank（null = 官网不在信源里）/ top1_domain；
     *         可选 top1_site / run_id。error 非空的项会被跳过。
     * @return array<int,array<string,mixed>> 每项含 question / rank / gap_level / top1_domain
     *                                       （另附 top1_site / run_id）
     */
    public static function authorityGapList(array $results): array
    {
        $out = [];

        foreach ($results as $r) {
            if (! is_array($r)) {
                continue;
            }
            // 没有有效采样的题无法判断官网在不在，算成缺口就是拿故障当缺口
            if (mb_strlen(trim((string) ($r['error'] ?? '')), 'UTF-8') > 0) {
                continue;
            }

            $question = trim((string) ($r['question'] ?? ''));
            if ($question === '') {
                continue;
            }

            $rawRank = $r['own_rank'] ?? null;
            $rank = ($rawRank === null || $rawRank === '') ? null : (int) $rawRank;

            if ($rank === 1) {
                // 官网已经站在第 1 名，没有缺口
                continue;
            }
            if ($rank === null) {
                $level = '完全缺位';
            } elseif ($rank <= 3) {
                $level = '官网第 2-3 名';
            } else {
                $level = '官网第 4 名及以后';
            }

            $out[] = [
                'question' => $question,
                'rank' => $rank,
                'gap_level' => $level,
                'top1_domain' => trim((string) ($r['top1_domain'] ?? '')),
                'top1_site' => trim((string) ($r['top1_site'] ?? '')),
                'run_id' => (int) ($r['run_id'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * 这道题是不是 A 类（品牌认知）。
     *
     * 类别不在核查结果里（probe() 只答"排第几"，不答"这题属于哪类"），
     * 所以按题面回 keywords 表关联 category。这里做三件事：
     *   1. 先按品牌把词库限定住——同一个题面可能同时存在于两个品牌的词库，
     *      不限定就会把别家的 A 类判到自己头上。
     *   2. 但在当前品牌的词库里找不到这道题时（用户手填了品牌名、或这一批题
     *      本来就不是这个品牌的库），<b>退回全量判定</b>：宁可多挡一道，
     *      也不要把明明写着品牌名的题放去写软文——那正是这次要修的方向性错误。
     *   3. 同一题面出现在多个类别库时（实测「海洋至尊是什么档次」同时在
     *      A 库和 D 库），<b>只要有一处标 A 就按 A 处理</b>：这题是不是品牌词，
     *      由题面决定（用户有没有打出品牌名），不由它从哪个库被取出来决定。
     *   4. 题面完全查不到 → 返回 false（按内容缺口处理）。宁可多产出一篇文章，
     *      也不要因为一次查询失败把整批题静默丢出任务清单。
     */
    public static function isAuthorityQuestion(string $question, string $brand = ''): bool
    {
        $key = self::questionKey($question);
        if ($key === '') {
            return false;
        }

        $index = self::categoryIndex();
        if (! isset($index[$key])) {
            return false;
        }

        $brandName = self::brandNameOf($brand);
        $scoped = [];
        foreach ((array) $index[$key] as $row) {
            if ($brandName !== '' && (string) ($row['owner'] ?? '') !== $brandName) {
                continue;
            }
            $scoped[] = $row;
        }
        // 当前品牌的词库里没有这道题 → 退回全量（见方法注释第 2 条）
        if ($scoped === []) {
            $scoped = (array) $index[$key];
        }

        foreach ($scoped as $row) {
            if (mb_strtoupper((string) ($row['category'] ?? ''), 'UTF-8') === 'A') {
                return true;
            }
        }

        return false;
    }

    /**
     * 题面归一（小写 + 折叠空白），用来和 keywords.keyword 对齐。
     */
    private static function questionKey(string $question): string
    {
        $q = preg_replace('/\s+/u', ' ', trim($question));

        return mb_strtolower((string) $q, 'UTF-8');
    }

    /**
     * 题面 → 类别索引。整表读一次，按请求缓存，避免逐题查库（20 题就是 20 次往返）。
     *
     * 结构：归一题面 => [ ['owner' => 词库所属品牌名, 'category' => A/B/C/D], ... ]
     *
     * @return array<string,list<array{owner:string,category:string}>>
     */
    private static function categoryIndex(): array
    {
        if (self::$categoryIndex !== null) {
            return self::$categoryIndex;
        }

        $index = [];
        try {
            $rows = \Illuminate\Support\Facades\DB::table('keywords as k')
                ->join('keyword_libraries as l', 'l.id', '=', 'k.library_id')
                ->whereIn('k.category', GeoCoverageCheck::CATEGORIES)
                ->get(['k.keyword as keyword', 'k.category as category', 'l.name as library_name']);

            foreach ($rows as $row) {
                $key = self::questionKey((string) ($row->keyword ?? ''));
                if ($key === '') {
                    continue;
                }
                $index[$key][] = [
                    'owner' => GeoCoverageCheck::libraryBrandName((string) ($row->library_name ?? '')),
                    'category' => mb_strtoupper(trim((string) ($row->category ?? '')), 'UTF-8'),
                ];
            }
        } catch (\Throwable $e) {
            // 查不到类别时退回旧行为（全部按内容缺口处理）。
            // 静默降级比抛错好：报告页的主体（逐题结果、分块指标）不依赖这一块。
            $index = [];
        }

        return self::$categoryIndex = $index;
    }

    /**
     * 品牌名归一：认得出就返回品牌档案里的正式名，认不出就原样返回。
     *
     * 词库名里写的是品牌正式名（"海洋至尊｜A 品牌认知"），用户填的可能是
     * slug（haiyangzhizun）或别名，不归一就永远匹配不上，A 类过滤会全部失效。
     */
    private static function brandNameOf(string $nameOrSlug): string
    {
        $needle = self::questionKey($nameOrSlug);
        if ($needle === '') {
            return '';
        }

        foreach (GeoShareOfVoice::brands() as $b) {
            $candidates = [];
            foreach ([$b['slug'] ?? '', $b['name'] ?? '', $b['full_name'] ?? ''] as $v) {
                $v = self::questionKey((string) $v);
                if ($v !== '') {
                    $candidates[] = $v;
                }
            }
            foreach ((array) ($b['aliases'] ?? []) as $a) {
                $a = self::questionKey((string) $a);
                if ($a !== '') {
                    $candidates[] = $a;
                }
            }
            if (in_array($needle, $candidates, true)) {
                $name = trim((string) ($b['name'] ?? ''));

                return $name !== '' ? $name : $needle;
            }
        }

        return self::questionKey($nameOrSlug);
    }

    /**
     * 「信源缺口」区块：A 类专属，只读，不提供勾选与建任务入口。
     *
     * ── 为什么单独一块、且不能生成任务 ────────────────────────────────
     * A 类问题是用户主动搜品牌名（"海洋至尊是什么档次"）。官网缺位时，
     * AI 推的是媒体和百科（实测：界面新闻网 5 次、百度百科 4 次），不是竞品。
     * 这种位置写软文抢不到——补的是权威信源（品牌词条、新闻露出、百科条目）。
     * 所以这里只列缺口，把"补什么"交给运营判断，不套用内容任务的流水线。
     *
     * ── 口径为什么用历史采样，而不是本次这一批 ────────────────────────
     * 页首 A 区块的「缺位替身榜」就是历史采样算出来的。这一块如果是"本次批次"，
     * 同一个品牌在同一个页面上会出现两个对不上的数（上面写 5 次、下面列 3 条）。
     * 而且 A 类题是品牌词，几乎不新增，做一张"当前全量缺口清单"比"这一批"有用。
     * 页头已明示口径，不让人误以为它和上面的逐题面板是一套数。
     *
     * @param  array<string,mixed>  $ctx
     * @param  string  $brandSlug  当前口径的品牌 slug（由 reportPage() 统一算好传进来）
     * @param  list<int>|null  $runIds  只看这几条 run（历史报告按批次过滤）；null = 全部历史
     */
    private static function authorityGapSection(array $ctx, string $brandSlug, ?array $runIds = null): string
    {
        // 【本地修复】不再在区块内部自己调 resolveSlug()：
        // 同一个页面三处各算一次品牌，只要 cookie / ?brand= / session 有一处不同步，
        // 就会出现「分块是冰溪、逐题是海洋至尊」。品牌只在 reportPage() 算一次。
        $brand = GeoShareOfVoice::brand($brandSlug);
        $brandName = $brand !== null ? trim((string) ($brand['name'] ?? '')) : '';
        $brandLabel = $brandName !== '' ? $brandName : ($brandSlug !== '' ? $brandSlug : '当前品牌');

        // 与表单页的「已经测出来的结论」共用同一份缓存：同一次请求里
        // 两个区块各查一遍 authorityGapSamples() 是纯浪费。
        $gap = self::authoritySamples($brandSlug, $ctx, $runIds);

        return self::authorityGapHtml($gap['samples'], $brandLabel, $gap['error']);
    }

    /**
     * 「信源缺口」区块的渲染（纯函数：只吃数据，不查库）。
     *
     * 单独拆出来是为了能拿合成数据验证三种文案分支：
     * 有缺口 / 官网全在第 1 名 / 还没有有效采样。
     * 这三种情况在真实数据里要凑齐得改库，拆开就不用动生产数据。
     *
     * @param  list<array<string,mixed>>  $samples  authorityGapSamples() 的返回
     * @param  string  $brandLabel  展示用品牌名
     * @param  string  $error  非空表示取数失败
     */
    private static function authorityGapHtml(array $samples, string $brandLabel, string $error = ''): string
    {
        $head = '<div class="g-card"><h3>信源缺口</h3>';
        $scope = '<p class="g-cap" style="margin:0 0 10px">口径：<b>'.self::e($brandLabel).'</b>'
            .'的 A 类（品牌认知）历史采样，一次采样算一条，按 run 去重；'
            .'与页首「A 品牌认知」区块的<b>缺位替身榜同源同口径</b>，两处次数应当一致。'
            .'这里列的是<b>当前全量</b>缺口，不是上面那一批题的逐题结果。</p>';

        $why = '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:11px 14px;'
            .'font-size:12.5px;line-height:1.8;margin:0 0 14px;color:#0c4a6e">'
            .'品牌认知类的问题，用户是主动搜你的名字。官网缺位时抢走位置的是媒体和百科（不是竞品），'
            .'所以要补的是权威信源（品牌词条、新闻露出、百科条目），不是写产品软文。'
            .'这一块只列缺口，不生成内容任务。</div>';

        if ($error !== '') {
            return $head.$scope
                .'<div class="g-err">信源缺口清单生成失败：'.self::e($error).'</div>'
                .'<p class="g-cap">报告其余部分照常可用。这个报错持续出现时，把这段原文发给开发排查。</p></div>';
        }

        if ($samples === []) {
            return $head.$scope.$why
                .'<p class="g-cap">「'.self::e($brandLabel).'」的 A 类问题<b>还没有有效采样</b>'
                .'（没有信源的数据不算：那无法判断官网在不在）。'
                .'用 A 类词库跑一批覆盖核查，这里就有清单了。</p></div>';
        }

        $gaps = self::authorityGapList($samples);

        $total = count($samples);
        $questions = [];
        foreach ($samples as $s) {
            $q = trim((string) ($s['question'] ?? ''));
            if ($q !== '') {
                $questions[$q] = true;
            }
        }

        if ($gaps === []) {
            return $head.$scope.$why
                .'<p class="g-cap"><b>本批 A 类问题官网都有占位，而且都排在第 1 名，没有信源缺口。</b>'
                .'（'.$total.' 次有效采样）这几道题上，媒体和百科没有压在你官网前面，'
                .'不需要额外补权威信源；把力气放到 B/C/D 类的内容缺口上。</p></div>';
        }

        $rows = '';
        foreach ($gaps as $g) {
            $rank = $g['rank'];
            $isAbsent = $rank === null;
            $cls = $isAbsent ? 'g-pill--bad' : 'g-pill--warn';
            $levelPill = '<span class="g-pill '.$cls.'" style="font-size:11px">'.self::e((string) $g['gap_level']).'</span>';
            $exact = $isAbsent
                ? '<span class="g-cap" style="margin:0">官网不在前 10 条信源里</span>'
                : '<span class="g-tag" style="font-size:10.5px">实际第 '.(int) $rank.' 名</span>';

            $domain = (string) $g['top1_domain'];
            $site = (string) $g['top1_site'];
            $who = '';
            if ($site !== '' && $domain !== '' && mb_strtolower($site, 'UTF-8') !== mb_strtolower($domain, 'UTF-8')) {
                $who = '<div style="font-weight:600;color:#374151">'.self::e($site).'</div>'
                    .'<div style="color:#6b7280;font-size:11.5px">'.self::e($domain).'</div>';
            } elseif ($domain !== '') {
                $who = '<div style="font-weight:600;color:#374151">'.self::e($domain).'</div>';
            } elseif ($site !== '') {
                $who = '<div style="font-weight:600;color:#374151">'.self::e($site).'</div>';
            } else {
                $who = '<span style="color:#9ca3af">—</span>';
            }

            $rows .= '<tr>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px">'.self::e((string) $g['question']).'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:170px">'.$levelPill.'<br>'.$exact.'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:200px">'.$who.'</td>'
                .'</tr>';
        }

        $fileTotal = $total;
        $gapTotal = count($gaps);

        return $head.$scope.$why
            .'<p class="g-cap" style="margin:0 0 10px">'
            .'<b>'.$fileTotal.'</b> 次有效采样 / <b>'.count($questions).'</b> 道题，'
            .'其中 <b>'.$gapTotal.'</b> 条是缺口（官网没站住第 1 名）；'
            .'剩下的 '.($fileTotal - $gapTotal).' 条官网已经是第 1 名，不算缺口，不列在这里。'
            .'<br>同一道题跑过多轮时会出现多行——<b>一行一次采样</b>，'
            .'这样才能和上面的「缺位替身榜」次数对上（不是重复渲染）。</p>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
            .'<th>问题（原样，用户就是这么搜的）</th><th style="width:170px">官网缺位程度</th>'
            .'<th style="width:200px">AI 第 1 名推的是</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p class="g-cap" style="margin:12px 0 0">这一块<b>没有勾选框、也没有建任务入口</b>：'
            .'动作是补权威信源（品牌词条 / 新闻露出 / 百科条目 / 官网 About 与资质页），'
            .'不是按这个标题写文章。要出内容任务，请用 B/C/D 类问题的「内容缺口」。</p></div>';
    }

    // =========================================================================
    // 表单页「已经测出来的结论」—— 核查前先告诉用户已经测出什么
    // =========================================================================

    /**
     * 「已经测出来的结论」区块（表单页，位置在表单之后、脚本之前）。
     *
     * ── 这一块为什么存在 ──────────────────────────────────────────────
     * 原来这里是「为什么要重复采样」：讲的是产品为什么设计成跑 3 次，属于产品说明。
     * 但用户在这一页要回答的是「这次该重点核查什么」，产品说明答不了。
     * 换成历史实测事实之后，点「开始核查」之前用户就已经知道三件事：
     *   · A 类（品牌词）现在被谁抢了位置，那是竞品还是媒体
     *   · 官网是有占位、还是占得住（第 2 名以后也算缺口）
     *   · B/C/D 还有多少题待补（决定这次该跑多少题）
     *
     * ── 数字全部现算，一个都不写死 ────────────────────────────────────
     * 口径 = 当前品牌（?brand= → cookie → 品牌档案 default）的历史采样。
     * 换品牌就换数字；取不到就降级成空态文案，绝不用示例值填充。
     */
    private static function conclusionSection(): string
    {
        $brandSlug = GeoShareOfVoice::resolveSlug();
        $brand = GeoShareOfVoice::brand($brandSlug);
        $brandName = $brand !== null ? trim((string) ($brand['name'] ?? '')) : '';
        $brandLabel = $brandName !== '' ? $brandName : ($brandSlug !== '' ? $brandSlug : '当前品牌');

        // 表单页还没有会话（用户还没提交），自有域名只能靠品牌档案的 site_url；
        // authorityGapSamples() 本来就是「site_url 优先、传入域名兜底」，所以传 []。
        return self::conclusionHtml(
            $brandLabel,
            self::authoritySamples($brandSlug, []),
            self::contentGapStats($brandSlug),
            self::competitorAliases($brand)
        );
    }

    /**
     * A 类历史采样明细（带请求内缓存）。
     *
     * 表单页的结论区块和报告页的「信源缺口」清单用的是同一份
     * GeoCoverageCheck::authorityGapSamples()。同一次请求里两边都可能要，
     * 不缓存就是同一张表联查两遍、同一份数据判两次。
     * 缓存键带品牌和自有域名：切品牌或换站点后口径变了，必须重查。
     *
     * 取数失败也缓存：失败时页面显示错误文案，再查一遍只会更慢。
     *
     * @param  array<string,mixed>  $ctx  核查上下文（表单页没有会话，传 []）
     * @param  list<int>|null  $runIds  只看这几条 run（历史报告按批次过滤）；null = 全部历史
     * @return array{samples:list<array<string,mixed>>,error:string}
     */
    private static function authoritySamples(string $brandSlug, array $ctx = [], ?array $runIds = null): array
    {
        $domains = self::ownDomainKeys($ctx);
        // 缓存键必须带 run 过滤条件：同一请求里"全部历史"和"某一批"是两份完全不同的数据
        $key = $brandSlug.'|'.implode(',', $domains).'|'.($runIds === null ? 'all' : implode(',', $runIds));

        if (self::$gapSamplesCache !== null && self::$gapSamplesKey === $key) {
            return self::$gapSamplesCache;
        }

        $out = ['samples' => [], 'error' => ''];
        try {
            $out['samples'] = GeoCoverageCheck::authorityGapSamples($domains, $brandSlug, $runIds);
        } catch (\Throwable $e) {
            $out['error'] = mb_substr($e->getMessage(), 0, 140, 'UTF-8');
        }

        self::$gapSamplesKey = $key;

        return self::$gapSamplesCache = $out;
    }

    /**
     * 品牌档案里的竞品别名（小写），用来把抢位来源分成「竞品 / 媒体」两类。
     *
     * 别名不在这里另维护一份：直接读 storage/app/geo-brands.json（GeoShareOfVoice），
     * 否则同一个竞品会出现两套写法，判定结果和「声量对标」对不上。
     *
     * @param  array<string,mixed>|null  $brand
     * @return list<string>
     */
    private static function competitorAliases(?array $brand): array
    {
        $out = [];
        if ($brand === null) {
            return $out;
        }

        foreach ((array) ($brand['competitors'] ?? []) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $aliases = [];
            foreach ((array) ($c['aliases'] ?? []) as $a) {
                $a = mb_strtolower(trim((string) $a), 'UTF-8');
                if ($a !== '') {
                    $aliases[] = $a;
                }
            }
            // 没配 aliases 时用 name 兜底，否则这个竞品永远判不出来
            if ($aliases === []) {
                $name = mb_strtolower(trim((string) ($c['name'] ?? '')), 'UTF-8');
                if ($name !== '') {
                    $aliases[] = $name;
                }
            }
            foreach ($aliases as $a) {
                $out[$a] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * B/C/D 类「内容缺口」的题级统计 —— 表单页第三段的唯一数据源。
     *
     * ── 为什么每题只取最近一次采样 ────────────────────────────────────
     * 这一段的用途是「这次该跑多少题」，问的是<b>现在</b>还差多少题，不是历史累计。
     * 同一道题跑过多轮时取最后一次（run_id 最大）等于取当前状态；把每一轮都算成
     * 一道题会让缺口虚高（一道题跑 3 次变 3 道题，用户会被吓到去跑没必要的一批）。
     *
     * ── 判定为什么复用 judge() ────────────────────────────────────────
     * 报告页逐题结果的判定就是 GeoCoverageCheck::judge()（自有域名命中 → 自有页面；
     * 仅品牌名命中 → 仅被提及；都没有 → 未进前 10）。这里直接调它，不另写一套
     * 字符串匹配，免得表单页和报告页对同一道题给出两个不同结论。
     *
     * ── 什么不计入 ────────────────────────────────────────────────────
     * 最近一次采样一条信源都没有的题（被限流 / 搜索无结果）不计入分母：
     * 那是搜索故障，不是内容缺口。
     *
     * @return array{pending:int,missed:int,mention:int,owned:int,questions:int,error:string}
     */
    private static function contentGapStats(string $brandSlug): array
    {
        if (self::$contentGapCache !== null && self::$contentGapKey === $brandSlug) {
            return self::$contentGapCache;
        }

        $out = ['pending' => 0, 'missed' => 0, 'mention' => 0, 'owned' => 0, 'questions' => 0, 'error' => ''];

        try {
            $brand = GeoShareOfVoice::brand($brandSlug);
            $brandName = $brand !== null ? trim((string) ($brand['name'] ?? '')) : '';
            $siteUrl = $brand !== null ? trim((string) ($brand['site_url'] ?? '')) : '';
            $domains = $siteUrl !== '' ? (array) (GeoCoverageCheck::domainOf($siteUrl)['keys'] ?? []) : [];

            // 品牌别名：judge() 用它判「仅被提及」，和报告页 / 声量对标同一份档案
            $aliases = [];
            foreach ((array) ($brand['aliases'] ?? []) as $a) {
                $a = trim((string) $a);
                if ($a !== '') {
                    $aliases[] = $a;
                }
            }
            if ($aliases === [] && $brand !== null) {
                foreach ([$brand['name'] ?? '', $brand['full_name'] ?? ''] as $n) {
                    $n = trim((string) $n);
                    if ($n !== '') {
                        $aliases[] = $n;
                    }
                }
            }

            // 词库 → 品牌：只统计当前品牌的库，别家的题不算进这个品牌的缺口
            /** @var array<int,bool> $libAllowed */
            $libAllowed = [];
            foreach (\Illuminate\Support\Facades\DB::table('keyword_libraries')->pluck('name', 'id')->all() as $libId => $libName) {
                $owner = GeoCoverageCheck::libraryBrandName((string) $libName);
                if ($brandName === '' || $owner === $brandName) {
                    $libAllowed[(int) $libId] = true;
                }
            }

            if ($libAllowed !== []) {
                $rows = \Illuminate\Support\Facades\DB::table('ai_visibility_runs as r')
                    ->join('keywords as k', 'k.keyword', '=', 'r.keyword')
                    ->leftJoin('ai_visibility_sources as s', 's.ai_visibility_run_id', '=', 'r.id')
                    ->whereIn('k.category', ['B', 'C', 'D'])
                    ->whereIn('k.library_id', array_keys($libAllowed))
                    ->orderBy('r.id')
                    ->orderByRaw('COALESCE(s.rank, 999999) asc')
                    ->orderBy('s.id')
                    ->get([
                        'k.keyword as keyword',
                        'r.id as run_id',
                        's.id as source_id',
                        's.rank as rank',
                        's.url as url',
                        's.domain as domain',
                        's.site_name as site_name',
                        's.title as title',
                    ]);

                // 每题 → 最近一次 run（run_id 最大）
                /** @var array<string,int> $latest */
                $latest = [];
                /** @var array<int,list<object>> $sources */
                $sources = [];

                foreach ($rows as $row) {
                    $question = trim((string) ($row->keyword ?? ''));
                    $runId = (int) ($row->run_id ?? 0);
                    if ($question === '' || $runId <= 0) {
                        continue;
                    }
                    if (! isset($latest[$question]) || $runId > $latest[$question]) {
                        $latest[$question] = $runId;
                    }
                    // leftJoin 的占位行：这次采样一条信源都没有
                    if ($row->source_id === null) {
                        continue;
                    }
                    $sources[$runId][] = $row;
                }

                $engine = new GeoCoverageCheck();
                foreach ($latest as $question => $runId) {
                    if (! isset($sources[$runId])) {
                        // 没有信源 → 无法判断，不计入（不把搜索故障当缺口）
                        continue;
                    }
                    $out['questions']++;
                    $verdict = $engine->judge($sources[$runId], $aliases, $domains);
                    $type = (string) ($verdict['covered_type'] ?? '');
                    if ($type === '自有页面') {
                        $out['owned']++;
                    } elseif ($type === '仅被提及') {
                        $out['mention']++;
                    } else {
                        $out['missed']++;
                    }
                }

                $out['pending'] = $out['missed'] + $out['mention'];
            }
        } catch (\Throwable $e) {
            $out['error'] = mb_substr($e->getMessage(), 0, 140, 'UTF-8');
        }

        self::$contentGapKey = $brandSlug;

        return self::$contentGapCache = $out;
    }

    /**
     * 「已经测出来的结论」区块的渲染（纯函数：只吃数据，不查库）。
     *
     * 拆成纯函数是为了能拿合成数据逐个验证文案分支——有缺口 / 官网全第 1 名 /
     * 有占位但占不住 / 完全没有采样，这四种情况在真实数据里凑齐得改库。
     *
     * @param  string  $brandLabel  展示用品牌名
     * @param  array{samples:list<array<string,mixed>>,error:string}  $gap  authoritySamples() 的返回
     * @param  array{pending:int,missed:int,mention:int,owned:int,questions:int,error:string}  $content  contentGapStats() 的返回
     * @param  list<string>  $competitorAliases  竞品别名（小写），用于判别抢位来源类型
     */
    private static function conclusionHtml(string $brandLabel, array $gap, array $content, array $competitorAliases): string
    {
        $samples = (array) ($gap['samples'] ?? []);
        $error = (string) ($gap['error'] ?? '');

        $head = '<div class="g-card"><h3>已经测出来的结论</h3>';
        $scope = '<p class="g-cap" style="margin:0 0 12px">口径：<b>'.self::e($brandLabel).'</b>'
            .'的历史采样（一次采样算一条，按 run 去重）。'
            .'下面是已经跑出来的事实，用来判断这次该重点核查什么；数字随品牌切换，不是示例值。</p>';

        if ($error !== '') {
            return $head.$scope
                .'<div class="g-err">历史采样读取失败：'.self::e($error).'</div>'
                .'<p class="g-cap">核查表单照常可用，这一块先不显示结论。'
                .'这个报错持续出现时，把这段原文发给开发排查。</p></div>';
        }

        $dist = self::rankDistribution($samples);
        $blocks = [];

        // ---------- 第一段：A 类信源缺口 —— 谁抢走了位置 ----------
        if ($samples === []) {
            $blocks[] = '<p>当前品牌「'.self::e($brandLabel).'」<b>还没有 A 类采样数据</b>。'
                .'先在下面选好词库与采样次数，跑一批就能看到结论。</p>';
        } else {
            $rank = self::replacementRanking($samples, $competitorAliases);

            if ($rank['gap_total'] === 0) {
                $blocks[] = '<p><b>A 类信源缺口：没有。</b>'
                    .$dist['total'].' 次 A 类有效采样里，官网每次都站在第 1 名 —— '
                    .'这一批 A 类问题官网都排第 1 名，没有信源缺口。'
                    .'媒体和百科没有压在你前面，力气可以放到 B/C/D 的内容缺口上。</p>';
            } else {
                $rows = '';
                foreach ($rank['rows'] as $r) {
                    $kind = (string) $r['kind'];
                    if ($kind === 'competitor') {
                        $pill = '<span class="g-pill g-pill--bad" style="font-size:10.5px">竞品</span>';
                    } elseif ($kind === 'media') {
                        $pill = '<span class="g-pill g-pill--warn" style="font-size:10.5px">媒体/电商</span>';
                    } else {
                        $pill = '<span class="g-pill" style="font-size:10.5px">暂无法分类</span>';
                    }

                    $label = (string) $r['label'];
                    $domainsText = (string) $r['domains'];
                    $sub = $domainsText !== ''
                        ? '<div style="color:#6b7280;font-size:11.5px">'.self::e($domainsText).'</div>'
                        : '';

                    $rows .= '<tr>'
                        .'<td style="border:1px solid #e5e7eb;padding:9px 11px">'
                        .'<div style="font-weight:600;color:#374151">'.self::e($label).'</div>'.$sub.'</td>'
                        .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:90px">'.(int) $r['count'].' 次</td>'
                        .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:110px">'.$pill.'</td>'
                        .'</tr>';
                }

                $blocks[] = '<p><b>A 类信源缺口：谁抢走了位置</b>　'
                    .$dist['total'].' 次有效采样里，<b>'.$rank['gap_total'].'</b> 次官网没站在第 1 名'
                    .'（其中完全缺位 '.$dist['absent'].' 次）</p>'
                    .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
                    .'<th>抢位来源（AI 第 1 名）</th><th style="width:90px">次数</th><th style="width:110px">类型</th>'
                    .'</tr></thead><tbody>'.$rows.'</tbody></table>'
                    .'<p class="g-cap" style="margin:10px 0 0">合计 <b>'.$rank['gap_total'].'</b> 次 —— '
                    .$rank['domains'].' 家来源：媒体/电商/平台 '.$rank['media'].' 次，竞品 '.$rank['competitor'].' 次'
                    .($rank['unknown'] > 0 ? '，暂无法分类 '.$rank['unknown'].' 次' : '').'。'
                    .self::gapVerdict($rank['competitor'], $rank['media'], $rank['unknown']).'</p>';
            }
        }

        // ---------- 第二段：有占位 ≠ 占得住 ----------
        // 只在「没有完全缺位、但存在第 2 名以后」时出现。有完全缺位时第一段已经
        // 把更严重的问题讲清楚了，再说一句"占不住"会把两件事混成一件。
        if ($samples !== [] && $dist['absent'] === 0 && ($dist['rank23'] + $dist['rank4p']) > 0) {
            $detail = '';
            foreach ($dist['offsets'] as $off) {
                $detail .= '<li style="margin:0 0 4px">'.$off['count'].' 次：'
                    .'「'.self::e((string) $off['question']).'」'
                    .'　官网最好排第 '.(int) $off['best'].'，第 1 名是 '.self::e((string) $off['top1'])
                    .($off['domains'] !== '' ? '（'.self::e((string) $off['domains']).'）' : '')
                    .'</li>';
            }
            if ($dist['offsets_more'] > 0) {
                $detail .= '<li style="margin:0">还有 '.$dist['offsets_more'].' 道题同类，不逐条列。</li>';
            }

            $blocks[] = '<p><b>「有占位」不等于「占得住」</b>　'
                .$dist['total'].' 次采样：第 1 名 '.$dist['rank1'].' 次'
                .' · 第 2-3 名 '.$dist['rank23'].' 次'
                .($dist['rank4p'] > 0 ? ' · 第 4 名及以后 '.$dist['rank4p'].' 次' : '')
                .' · 完全缺位 '.$dist['absent'].' 次</p>'
                .'<ul class="g-cap" style="margin:8px 0;padding-left:18px;line-height:1.8">'.$detail.'</ul>'
                .'<p class="g-cap" style="margin:6px 0 0">官网进了信源但排在第 2 名以后，也算缺口 —— '
                .'用户看到的第一条不是你。这类题要先争首位，不是继续加量。</p>';
        }

        // ---------- 第三段：B/C/D 内容缺口概况（决定这次该跑多少题）----------
        $cError = (string) ($content['error'] ?? '');
        $cQuestions = (int) ($content['questions'] ?? 0);
        $cPending = (int) ($content['pending'] ?? 0);

        if ($cError !== '') {
            $blocks[] = '<p><b>B/C/D 类内容缺口：统计失败。</b>'.self::e($cError)
                .'　其余部分照常可用；这个报错持续出现时，把这段原文发给开发排查。</p>';
        } elseif ($cQuestions === 0) {
            $blocks[] = '<p><b>B/C/D 类内容缺口：还没有数据。</b>'
                .'当前品牌的词库里还没有跑过 B/C/D 类的核查，跑一批才有缺口数字。</p>';
        } elseif ($cPending === 0) {
            $blocks[] = '<p><b>B/C/D 类内容缺口：0 题。</b>'
                .$cQuestions.' 道有采样的题里，官网都进了前 10 —— '
                .'这一批内容缺口为 0，可以只做 A 类的信源补强。</p>';
        } else {
            $maxQ = GeoCoverageCheck::MAX_QUESTIONS;
            // 单次核查有题数上限，待补题数超过上限时要说明分几批，
            // 否则「把题数设成接近 45 题」是一句用户根本做不到的建议
            $advice = $cPending <= $maxQ
                ? '这是「这次该跑多少题」的依据：题数设成接近 '.$cPending.' 题，一次就能把主要缺口覆盖完。'
                : '这是「这次该跑多少题」的依据：待补 '.$cPending.' 题，单次核查最多 '.$maxQ
                    .' 题，按 '.((int) ceil($cPending / $maxQ)).' 批跑完比较稳。';

            $blocks[] = '<p><b>B/C/D 类待补：'.$cPending.' 题</b>　'
                .'其中未进前 10：'.(int) $content['missed'].' 题（真缺口，写内容能解决）　'
                .'仅被提及 '.(int) $content['mention'].' 题（别人写了你，你自己没有落地页）。</p>'
                .'<p class="g-cap" style="margin:6px 0 0">'.$advice
                .'口径是<b>每题取最近一次采样</b>判定（不累计历史轮次），'
                .'没有有效信源的题不计入。</p>';
        }

        return $head.$scope.implode('', $blocks).'</div>';
    }

    /**
     * 「谁抢走了位置」的判断句：竞品占多数 / 媒体占多数 / 两者都有 / 无法分类。
     *
     * 四种情况给的动作完全相反，所以必须分开说，不能合成一句"要重视信源"：
     *   · 竞品占多数 → 那位置是内容和产品页争来的，写内容能拿回来
     *   · 媒体/电商占多数 → 那不是内容位置，是权威信源位置，写软文抢不到
     */
    private static function gapVerdict(int $competitor, int $media, int $unknown): string
    {
        $total = $competitor + $media + $unknown;

        if ($total === 0) {
            return '<b>还没有可统计的抢位采样。</b>';
        }
        if ($competitor === 0 && $media === 0) {
            return '<b>抢位来源暂无法分类</b>（域名和站点名都缺）。'
                .'先按「补权威信源」处理；品牌档案里补上竞品别名后，这里会自动分类。';
        }
        if ($competitor === 0) {
            return '<b>一个竞品都没有。</b>所以 A 类写软文抢不到这些位置 —— '
                .'要补的是权威信源（品牌词条、新闻露出、百科条目）。';
        }
        if ($media === 0 && $unknown === 0) {
            return '<b>抢位的全是竞品。</b>这种位置可以靠内容和产品页去争 —— '
                .'竞品能被排上来，你的官网也能。';
        }
        // 一边明显更多时按多数给结论：两种动作（写内容 / 补信源）不能同时是重点
        if ($competitor > $media + $unknown) {
            return '<b>抢位的主要是竞品</b>（'.$competitor.' 次）。这种位置可以靠内容和产品页去争 —— '
                .'竞品能被排上来，你的官网也能拿到那一条。';
        }
        if ($media > $competitor + $unknown) {
            return '<b>抢位的主要是媒体/电商</b>（'.$media.' 次，竞品只有 '.$competitor.' 次）。'
                .'写软文抢不到这些位置 —— 要补的是权威信源（品牌词条、新闻露出、百科条目）。';
        }

        return '<b>抢位的既有竞品也有媒体/电商。</b>竞品那 '.$competitor.' 次可以靠内容和产品页去争；'
            .'媒体/电商那 '.$media.' 次写软文抢不到，要补的是权威信源（品牌词条、新闻露出、百科条目）。';
    }

    /**
     * 抢位来源榜：官网没站在第 1 名的那些采样里，AI 第 1 名落在谁头上。
     *
     * 口径直接复用 authorityGapList()（与报告页「信源缺口」同一套档位判断），
     * 不在这里另写一遍"官网在不在信源里"的规则 —— 两处口径一旦不同，
     * 同一个品牌会在表单页和报告页看到两个对不上的次数。
     *
     * @param  list<array<string,mixed>>  $samples
     * @param  list<string>  $competitorAliases  竞品别名（小写）
     * @return array{rows:list<array{label:string,domains:string,count:int,kind:string}>,
     *               gap_total:int,competitor:int,media:int,unknown:int,domains:int}
     */
    private static function replacementRanking(array $samples, array $competitorAliases): array
    {
        $gaps = self::authorityGapList($samples);

        /** @var array<string,array{label:string,domains:array<string,bool>,count:int,kind:string}> $agg */
        $agg = [];
        $competitor = 0;
        $media = 0;
        $unknown = 0;

        foreach ($gaps as $g) {
            $domain = trim((string) ($g['top1_domain'] ?? ''));
            $site = trim((string) ($g['top1_site'] ?? ''));
            $label = self::siteLabel($domain, $site);
            $kind = self::sourceKind($domain, $site, $competitorAliases);

            if ($kind === 'competitor') {
                $competitor++;
            } elseif ($kind === 'media') {
                $media++;
            } else {
                $unknown++;
            }

            // 按「站点」归并而不是按二级域名：同一家站点可能落在多个子域上
            // （world.taobao.com / beaut.taobao.com），按域名分会把「淘宝 4 次」
            // 拆成两行，看着像两个来源。原始域名仍然一并展示，可追溯。
            if (! isset($agg[$label])) {
                $agg[$label] = ['label' => $label, 'domains' => [], 'count' => 0, 'kind' => $kind];
            }
            $agg[$label]['count']++;
            if ($domain !== '') {
                $agg[$label]['domains'][$domain] = true;
            }
        }

        $rows = [];
        foreach ($agg as $item) {
            $rows[] = [
                'label' => (string) $item['label'],
                'domains' => implode(' / ', array_keys($item['domains'])),
                'count' => (int) $item['count'],
                'kind' => (string) $item['kind'],
            ];
        }

        // PHP 8 的 sort 是稳定排序：次数相同时保持首次出现的顺序，
        // 不会每次刷新页面都换一遍名次。
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $domainCount = count($rows);

        return [
            'rows' => array_slice($rows, 0, 5),
            'gap_total' => count($gaps),
            'competitor' => $competitor,
            'media' => $media,
            'unknown' => $unknown,
            'domains' => $domainCount,
        ];
    }

    /**
     * 抢位来源分类：竞品 / 媒体（含电商、平台、百科）/ 无法分类。
     *
     * 判定依据是<b>域名 + 站点名</b>是否命中品牌档案里的竞品别名。只用域名会漏判：
     * 实测竞品的中文名大多不在域名里（loreal.com.hk 里没有「欧莱雅男士」），
     * 但站点名里几乎一定有。
     */
    private static function sourceKind(string $domain, string $site, array $competitorAliases): string
    {
        $hay = mb_strtolower(trim($domain.' '.$site), 'UTF-8');
        if (trim($hay) === '') {
            return 'unknown';
        }

        foreach ($competitorAliases as $alias) {
            $alias = mb_strtolower(trim((string) $alias), 'UTF-8');
            // 单字别名（"漾"这类）会把任何站点都判成竞品，至少两个字符才参与匹配
            if ($alias === '' || mb_strlen($alias, 'UTF-8') < 2) {
                continue;
            }
            if (str_contains($hay, $alias)) {
                return 'competitor';
            }
        }

        return 'media';
    }

    /**
     * A 类排位分布：own_rank 分四档（第 1 名 / 第 2-3 名 / 第 4 名及以后 / 完全缺位）。
     *
     * 档位口径与 authorityGapList() 完全一致：own_rank 为 null = 官网不在信源里。
     * 同时把「有占位但没站住首位」的题按题面归并，给第二段用（同一道题跑过 4 轮
     * 就是 4 条采样，展示时必须折叠成一句「那 4 次」，否则会把一件事说成四件事）。
     *
     * @param  list<array<string,mixed>>  $samples
     * @return array{total:int,rank1:int,rank23:int,rank4p:int,absent:int,
     *               offsets:list<array{question:string,best:int,count:int,top1:string,domains:string}>,
     *               offsets_more:int}
     */
    private static function rankDistribution(array $samples): array
    {
        $out = [
            'total' => 0, 'rank1' => 0, 'rank23' => 0, 'rank4p' => 0, 'absent' => 0,
            'offsets' => [], 'offsets_more' => 0,
        ];

        /** @var array<string,array{best:int,count:int,top1:array<string,bool>,domains:array<string,bool>}> $grouped */
        $grouped = [];

        foreach ($samples as $s) {
            if (! is_array($s)) {
                continue;
            }
            $out['total']++;

            $rawRank = $s['own_rank'] ?? null;
            $rank = ($rawRank === null || $rawRank === '') ? null : (int) $rawRank;

            if ($rank === null) {
                $out['absent']++;
                continue;
            }
            if ($rank === 1) {
                $out['rank1']++;
                continue;
            }
            if ($rank <= 3) {
                $out['rank23']++;
            } else {
                $out['rank4p']++;
            }

            $question = trim((string) ($s['question'] ?? ''));
            if ($question === '') {
                continue;
            }

            $domain = trim((string) ($s['top1_domain'] ?? ''));
            $label = self::siteLabel($domain, trim((string) ($s['top1_site'] ?? '')));

            if (! isset($grouped[$question])) {
                $grouped[$question] = ['best' => $rank, 'count' => 0, 'top1' => [], 'domains' => []];
            }
            $grouped[$question]['best'] = min($grouped[$question]['best'], $rank);
            $grouped[$question]['count']++;
            if ($label !== '') {
                $grouped[$question]['top1'][$label] = true;
            }
            if ($domain !== '') {
                $grouped[$question]['domains'][$domain] = true;
            }
        }

        // 次数多的题先列：同一品牌通常集中在一两道题上
        uasort($grouped, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $offsets = [];
        foreach ($grouped as $question => $g) {
            $labels = array_keys($g['top1']);
            $offsets[] = [
                'question' => (string) $question,
                'best' => (int) $g['best'],
                'count' => (int) $g['count'],
                'top1' => $labels !== [] ? implode(' / ', $labels) : '暂无法识别',
                'domains' => implode(' / ', array_keys($g['domains'])),
            ];
        }

        $out['offsets'] = array_slice($offsets, 0, 5);
        $out['offsets_more'] = max(0, count($offsets) - 5);

        return $out;
    }

    /**
     * 域名 / 站点名 → 展示名（只影响显示，不影响任何计数与判定）。
     *
     * 优先用 SITE_LABELS 里的规范中文名（"Taobao.com" 和 "淘寶" 都归一成「淘宝」，
     * 否则同一家站点在页面上会以两种名字出现）。但站点名本身已经含有这个规范名时
     * 用站点名——实测库里存的是「界面新闻网」，比字典里的「界面新闻」信息更全，
     * 直接覆盖掉反而比数据库原文更差。
     *
     * 字典里没有的站点退回 site_name，再退回域名，都没有才显示「暂无法识别」。
     */
    private static function siteLabel(string $domain, string $siteName): string
    {
        $d = mb_strtolower(trim($domain), 'UTF-8');
        $s = trim($siteName);

        foreach (self::SITE_LABELS as $needle => $label) {
            if ($d !== '' && str_contains($d, $needle)) {
                if ($s !== '' && str_contains($s, $label)) {
                    return $s;
                }

                return $label;
            }
        }

        if ($s !== '') {
            return $s;
        }
        if ($d !== '') {
            return $d;
        }

        return '暂无法识别';
    }

    /**
     * 报告页
     *
     * @param  array<string,mixed>  $ctx
     * @param  list<array<string,mixed>>  $results
     */
    public static function reportPage(array $ctx, array $results, string $adminBase, ?int $batchId = null, bool $readOnly = false): string
    {
        $brand = (string) ($ctx['brand'] ?? '');
        $url = (string) ($ctx['url'] ?? '');

        // 【本地修复】报告页原来只能看"当前品牌 + 全部历史采样"，
        // 想对比"这次比上次好了没有"必须靠人肉记忆。
        // 现在支持 ?batch=<id>：传了就只统计该批次关联的 run（见 ai_visibility_batch_runs），
        // 不传就还是原来的口径（该品牌全部历史）。
        // 注意：batch 只影响统计范围，<b>不影响品牌</b>——品牌仍然走 ?brand= → cookie → default。
        $activeBatchId = null;
        $batchNotice = '';
        $batchRunIds = null;

        // 【本地修复】报告页原来三个区块各取各的品牌：
        // 分块卡和信源缺口走 resolveSlug()（?brand= → cookie → 品牌档案 default），
        // 逐题结果走 ctx['brand']（本次核查会话），
        // 导致跑完 A 品牌的核查却看到 B 品牌的分块数据。
        // 现在统一在这里算一次，全页共用：?brand= → cookie geo_brand → 品牌档案 default。
        // （session 不参与品牌解析；ctx['brand'] 只用来标注"这次核查跑的是谁"，不一致时红框提醒。）
        $brandSlug = GeoShareOfVoice::resolveSlug();

        // 校验（存在 / 属于当前品牌）必须放在品牌算好之后、统计之前：
        // $batchRunIds 就是统计口径，提示文案跟着它走。
        // 两边分开判会出现"提示说按全量算、数字其实按批次算"。
        if ($batchId !== null && $batchId > 0) {
            $owner = self::batchOwnerSlug($batchId);
            if ($owner === null) {
                $batchNotice = 'batch='.self::e((string) $batchId).' 不存在（可能已被清理），已按<b>全部历史</b>统计。';
            } elseif ($owner !== $brandSlug) {
                $batchNotice = 'batch='.self::e((string) $batchId).' 属于品牌「'.self::e($owner)
                    .'」，不是当前品牌「'.self::e($brandSlug).'」，已按<b>全部历史</b>统计。';
            } else {
                $activeBatchId = $batchId;
            }
        }
        $batchRunIds = $activeBatchId !== null ? GeoCoverageCheck::batchRunIds($activeBatchId) : null;

        $total = count($results);
        $failed = 0;
        $owned = [];
        $mention = [];
        $missed = [];
        $top1 = 0;
        $top3 = 0;

        foreach ($results as $i => $r) {
            if ((string) ($r['error'] ?? '') !== '') {
                $failed++;
                continue;
            }
            $rank = $r['best_rank'] ?? null;
            if ($rank !== null) {
                if ((int) $rank === 1) {
                    $top1++;
                }
                if ((int) $rank <= 3) {
                    $top3++;
                }
            }
            if (($r['covered_type'] ?? '') === '自有页面') {
                $owned[] = $r;
            } elseif (($r['covered_type'] ?? '') === '仅被提及') {
                $mention[] = $r;
            } else {
                $missed[] = $r;
            }
        }

        $valid = $total - $failed;

        // 平均命中率（只在重复采样时有意义）：把每题的"几轮里命中几轮"平均
        $hitSum = 0;
        $hitN = 0;
        $maxRounds = 1;
        foreach ($results as $r) {
            if ((string) ($r['error'] ?? '') !== '') {
                continue;
            }
            $rd = (int) ($r['rounds'] ?? 1);
            $maxRounds = max($maxRounds, $rd);
            if ($rd > 0) {
                $hitSum += (int) ($r['hit_rate'] ?? 0);
                $hitN++;
            }
        }
        $avgHitRate = $hitN > 0 ? (int) round($hitSum / $hitN) : 0;

        // 每题的采样稳定性标记
        $rateBadge = static function (array $r): string {
            $rd = (int) ($r['rounds'] ?? 1);
            if ($rd <= 1) {
                return '';
            }
            $cr = (int) ($r['covered_rounds'] ?? 0);
            $cls = $cr === $rd ? 'g-pill--ok' : ($cr === 0 ? 'g-pill--bad' : 'g-pill--warn');

            return ' <span class="g-pill '.$cls.'" style="font-size:10.5px">'.$cr.'/'.$rd.'</span>';
        };

        // ---------- 首屏：按问题类别分块 ----------
        // 全部指标都从已有的 ai_visibility_runs / ai_visibility_sources 算，不新采一条数据。
        // A 类不进「前三率 / 前 10 覆盖率」：那类题是用户主动搜品牌名，AI 必然提到品牌，
        // 覆盖率恒 100%、方差为零，混进总指标只会把 B 类 0% 这种真缺口抬到看不出问题。
        $categoryStats = [];
        $categoryMeta = [];
        $categoryError = '';
        try {
            // 分块只统计「当前品牌」的词库问题（?brand= → cookie → 品牌档案 default），
            // 否则 A 类会算成跨品牌混合值：一个 100% 一个 0%，平均出来的"69%"
            // 恰好把最重要的对比抹平了。
            $breakdown = GeoCoverageCheck::categoryBreakdown(
                self::ownDomainKeys($ctx),
                $brandSlug,
                $batchRunIds
            );
            $categoryStats = (array) ($breakdown['categories'] ?? []);
            $categoryMeta = [
                'brand' => (array) ($breakdown['brand'] ?? []),
                'brands' => (array) ($breakdown['brands'] ?? []),
                'own_sites' => (array) ($breakdown['own_sites'] ?? []),
                'all_questions' => (array) ($breakdown['all_questions'] ?? []),
                'requested_slug' => $brandSlug,
            ];
        } catch (\Throwable $e) {
            $categoryError = mb_substr($e->getMessage(), 0, 140, 'UTF-8');
        }

        [$verdict, $verdictColor] = self::verdict($valid, count($owned), count($mention), count($missed), $categoryStats);

        $stat = static function (string $label, string $value, string $color = '#111827'): string {
            return '<div style="flex:1;min-width:110px;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;background:#fff">'
                .'<div style="font-size:11.5px;color:#6b7280">'.self::e($label).'</div>'
                .'<div style="font-size:21px;font-weight:750;color:'.$color.';line-height:1.35">'.self::e($value).'</div></div>';
        };

        $catBlock = self::categorySection($categoryStats, $categoryMeta, $categoryError, $brandSlug);

        // ---------- 本次核查（逐题） ----------
        // 这里只放"计数"和逐题情况；比率类指标全部按类别放到上面的分块里，
        // 避免再出现一个把 A/B/C/D 混在一起的总覆盖率。
        //
        // 会话里的这批题不一定属于页首分块的那个品牌（例如用 ?brand= 切了品牌），
        // 两块数字并排却不是一个品牌，必须显式提醒，否则很容易被误读成同一套结论。
        //
        // 比较前两边都要归一成 slug 再比：用户填的是 `binsis`（slug）还是「冰溪」
        // （品牌名）其实是同一个品牌，直接比字符串会误报。
        //
        // 【本地修复】归一后仍不一致时不再只提示、不给出口：
        // 直接把「切换到本次核查的品牌」做成链接。用户看到"要看海洋至尊"却被要求
        // 自己拼 ?brand= 是不现实的，最后就变成两边数字混着念。
        $panelBrand = trim((string) ($categoryMeta['brand']['name'] ?? ''));
        $sessionBrand = trim($brand);
        $panelKey = self::brandKeyOf((string) ($categoryMeta['brand']['slug'] ?? ''));
        $sessionKey = self::brandKeyOf($sessionBrand);
        if ($panelKey === '' && $brandSlug !== '') {
            $panelKey = self::brandKeyOf($brandSlug);
        }
        $panelLabel = $panelBrand !== '' ? $panelBrand : ($brandSlug !== '' ? $brandSlug : '当前品牌');
        $brandMismatch = $panelKey !== '' && $sessionKey !== '' && $panelKey !== $sessionKey
            ? '<div class="g-err" style="margin:0 0 12px">注意：本次核查跑的是「'.self::e($sessionBrand)
                .'」，当前页首显示的是「'.self::e($panelLabel).'」。'
                .'上面分块和信源缺口只统计'.self::e($panelLabel).'。'
                .'要看这次核查的'.self::e($sessionBrand).'结果，'
                .'<a href="'.self::e(self::brandSwitchUrl($sessionKey)).'" style="color:#991b1b;text-decoration:underline;font-weight:600">'
                .'切换到'.self::e($sessionBrand).'</a>。</div>'
            : '';

        // 【本地修复】$brand 是会话里存的原值，可能是 slug（如 binsis）也可能是中文名。
        // 展示层统一用品牌档案里的中文名，slug 只留在 URL 和 title 里，避免页面上出现「品牌：binsis」。
        $brandRow = self::brandRowOf($brand);
        $brandDisplay = $brandRow !== null && trim((string) ($brandRow['name'] ?? '')) !== ''
            ? trim((string) $brandRow['name'])
            : $brand;

        $summary = '<div class="g-card"><h3>本次核查逐题结果</h3>'
            .$brandMismatch
            .'<p class="g-cap">品牌：<b>'.self::e($brandDisplay).'</b>'
            .($url !== '' ? '　自有站点：'.self::e($url) : '')
            .'　核查时间：'.date('Y-m-d H:i').'</p>'
            .'<div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0">'
            .$stat('本次核查题数', (string) $valid)
            .$stat('自有页面进前 10', (string) count($owned), '#047857')
            .$stat('仅被提及', (string) count($mention), '#b45309')
            .$stat('未进前 10', (string) count($missed), '#b91c1c')
            .($maxRounds > 1 ? $stat('平均命中率', $avgHitRate.'%', $avgHitRate >= 60 ? '#047857' : ($avgHitRate >= 30 ? '#b45309' : '#b91c1c')) : '')
            .($failed > 0 ? $stat('失败', (string) $failed, '#b45309') : '')
            .'</div>'
            .'<div style="border-left:4px solid '.$verdictColor.';background:#fafafa;border-radius:10px;padding:12px 16px;font-size:13.5px;color:#111827;line-height:1.7">'
            .$verdict.'</div>'
            .'<p class="g-cap" style="margin:14px 0 0">'
            .($maxRounds > 1
                ? '每题材采样 <b>'.$maxRounds.' 次</b>，命中率 = 命中轮数 ÷ 采样轮数。　　平均命中率 '.$avgHitRate.'% ｜ '
                : '')
            .'排第一 '.$top1.' 题　｜　进前三 '.$top3.' 题　｜　'
            .'<a href="'.$adminBase.'/analytics/ai-visibility" style="color:#4f46e5">这批数据已写入数据中心 → 去 AI 可见性看趋势</a>'
            .'</p>'
            .'<p class="g-cap" style="margin:6px 0 0">上面这组是<b>本次这一批题</b>的计数；'
            .'按问题类别拆开的比率看页首「按问题类别看」，两者口径不同，不要混着念。</p>'
            .'</div>';

        // ---------- 内容缺口（B/C/D：未进前 10 + 仅被提及，都可勾选建任务）----------
        // 顺序必须来自 todoList()：页面上的 checkbox value 和 to-tasks 的 picked
        // 是同一套编号，两边各写一份排序逻辑迟早会错配（点了 A 题建出 B 题的任务）。
        //
        // A 类不在这里：那是品牌认知题，缺位时抢位置的是媒体和百科，
        // 要补的是权威信源而不是产品软文，改由下面「信源缺口」只读清单承担。
        $todo = self::todoList($results, $brand);

        // A 类被移出内容缺口后，"内容缺口 0 条"有两种完全不同的成因：
        //   · 自有页面都进了前 10（覆盖好）
        //   · 这一批题全是 A 类，压根没有内容缺口可补（类别不对）
        // 混用文案会把"这类题不该写文章"说成"覆盖得好"，所以先数清楚。
        $authoritySkipped = 0;
        foreach ($results as $r) {
            if (! is_array($r)) {
                continue;
            }
            if ((string) ($r['error'] ?? '') !== '') {
                continue;
            }
            if (self::isAuthorityQuestion((string) ($r['question'] ?? ''), $brand)) {
                $authoritySkipped++;
            }
        }

        $todoBlock = '';
        // 历史批次是只读的：to-tasks 走的是 session 里的 ctx/results，
        // 会话已经结束，点了只会得到"会话已过期"。不如直接把出口讲清楚，
        // 让用户知道要建任务得重跑一次，而不是点一下撞墙。
        $taskActions = $readOnly
            ? '<div class="g-row" style="margin-top:16px">'
                .'<span class="g-pill g-pill--warn" style="font-size:11.5px">历史批次 · 只读</span>'
                .'<span class="g-cap" style="margin:0">这批结果来自历史核查（会话已结束），不能直接生成任务草稿。'
                .'要按这份清单建任务，请用同一批题重新核查一次，再在当次报告里勾选。</span></div>'
            : '<div class="g-row" style="margin-top:16px">'
                .'<a class="g-btn g-btn--primary" id="cov-to-task" href="'.$adminBase.'/coverage-check?to-tasks=1&picked=all">按勾选生成任务草稿</a>'
                .'<span class="g-cap" style="margin:0">生成 1 个标题库 + 1 个任务（暂停态），每个问题产出一篇文章</span></div>'
                .'<script>(function(){var all=document.getElementById("cov-all");var boxes=document.querySelectorAll(".cov-pick");'
                .'var btn=document.getElementById("cov-to-task");'
                .'function sync(){var ids=[];for(var i=0;i<boxes.length;i++){if(boxes[i].checked){ids.push(boxes[i].value);}}'
                .'btn.setAttribute("href","'.$adminBase.'/coverage-check?to-tasks=1&picked="+(ids.join(",")||"none"));'
                .'btn.style.opacity=ids.length?"1":".45";}'
                .'if(all){all.addEventListener("change",function(){for(var i=0;i<boxes.length;i++){boxes[i].checked=all.checked;}sync();});}'
                .'for(var i=0;i<boxes.length;i++){boxes[i].addEventListener("change",sync);}sync();})();</script>';

        if ($todo !== []) {
            $rows = '';
            foreach ($todo as $n => $r) {
                $isMiss = ($r['covered_type'] ?? '') === '';
                $typePill = $isMiss
                    ? '<span class="g-pill g-pill--bad" style="font-size:11px">未进前 10</span>'
                    : '<span class="g-pill g-pill--warn" style="font-size:11px">仅被提及 '.(int) $r['mention_rank'].'</span>';

                $comp = '';
                if (! empty($r['hit_competitors'])) {
                    $parts = [];
                    foreach ((array) $r['hit_competitors'] as $name => $rank) {
                        $parts[] = self::e((string) $name).'(第'.(int) $rank.')';
                    }
                    $comp = '<span class="g-tag" style="background:#fef2f2;color:#b91c1c">竞品: '.implode(' / ', $parts).'</span>';
                }

                $top1 = trim((string) ($r['top1_domain'] ?? ''));
                $top1Title = trim((string) ($r['top1_title'] ?? ''));
                $top1Html = $top1 !== ''
                    ? '<div style="font-weight:600;color:#374151">'.self::e($top1).'</div>'
                        .'<div style="color:#6b7280;font-size:11.5px">'.self::e(mb_substr($top1Title, 0, 40, 'UTF-8')).'</div>'
                    : '<span style="color:#9ca3af">—</span>';

                $rows .= '<tr>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:34px;text-align:center">'
                    .'<input type="checkbox" class="cov-pick" value="'.$n.'" checked style="accent-color:#4f46e5"></td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px">'.self::e((string) $r['question']).'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:130px">'.$typePill.$rateBadge($r).'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:180px">'.$top1Html.$comp.'</td>'
                    .'</tr>';
            }

            $todoBlock = '<div class="g-card"><h3>内容缺口：'.count($todo).' 条</h3>'
                .'<p class="g-cap">勾选后一键生成内容任务草稿。<b>「未进前 10」优先</b>；「仅被提及」说明别人写了你但你自己没落地页，也要补'
                .($authoritySkipped > 0
                    ? '　·　另有 <b>'.$authoritySkipped.'</b> 条品牌认知题（A 类）不在这个清单里，见下方「信源缺口」'
                    : '')
                .'</p>'
                .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
                .'<th style="width:34px"><input type="checkbox" id="cov-all" checked style="accent-color:#4f46e5"></th>'
                .'<th>问题（将作为文章标题）</th><th style="width:110px">状态</th><th style="width:180px">当前第 1 名 / 竞品</th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table>'
                .$taskActions.'</div>';
        } elseif ($valid === 0) {
            // 0 条有效结果（全部搜索失败）时，"都进了前 10"是彻头彻尾的误导：
            // 不是覆盖得好，是根本没测到。这两句话不能混用。
            $todoBlock = '<div class="g-card"><h3>内容缺口：无法判断</h3>'
                .'<p class="g-cap">本批核查<b>没有产生有效结果</b>'
                .($failed > 0 ? '（'.$failed.' 题全部搜索失败）' : '')
                .'，无法判断覆盖情况，也不能据此生成内容任务。'
                .'请先解决上面列出的失败原因，再重新运行核查。</p>'
                .'<div class="g-row" style="margin-top:14px">'
                .'<a class="g-btn g-btn--primary" href="'.$adminBase.'/coverage-check">重新核查</a>'
                .'<a class="g-btn g-btn--soft" href="'.$adminBase.'/analytics/ai-visibility">去数据中心看趋势</a></div></div>';
        } elseif ($authoritySkipped >= $valid) {
            // 这一批全是 A 类：不是"覆盖得好"，是"这类题不该写文章"
            $todoBlock = '<div class="g-card"><h3>内容缺口：0 条</h3>'
                .'<p class="g-cap">这一批 <b>'.$valid.' 题全部是品牌认知题（A 类）</b>，'
                .'已归到下面「信源缺口」，<b>不生成内容任务</b>——'
                .'品牌词的缺位要靠权威信源补，不是靠产品软文。'
                .'要出内容任务，请换 B/C/D 类的词库再核查一批。</p></div>';
        } else {
            $todoBlock = '<div class="g-card"><h3>内容缺口：0 条</h3>'
                .'<p class="g-cap">这一批问题里，你的自有页面都进了前 10。'
                .'下一步该看排位和情感：位置靠后的争前三，口碑不好的先处理舆情。</p></div>';
        }

        // ---------- 信源缺口（A 类专属，只读清单）----------
        // 与「内容缺口」并列而不是合并：两类的动作类型不同。
        // 数据源是 A 类的历史采样明细（GeoCoverageCheck::authorityGapSamples），
        // 和页首 A 区块的「缺位替身榜」同源同口径——两处次数必须对得上，
        // 否则用户会同时看到"界面新闻网 5 次"和"清单里 4 条"。
        // 品牌由 reportPage() 统一算好传进来，区块内部不再自己解析（否则又会各取各的品牌）
        $authorityBlock = self::authorityGapSection($ctx, $brandSlug, $batchRunIds);

        // ---------- 已进前 10 ----------
        $ownedBlock = '';
        if ($owned !== []) {
            $rows = '';
            foreach ($owned as $r) {
                $rank = (int) $r['best_rank'];
                $tierCls = $rank === 1 ? 'g-pill--ok' : ($rank <= 3 ? 'g-pill--warn' : 'g-pill--bad');
                $mineUrl = trim((string) ($r['mine_url'] ?? ''));
                $mineTitle = trim((string) ($r['mine_title'] ?? ''));
                $rows .= '<tr>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px">'.self::e((string) $r['question']).'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:130px;white-space:nowrap">'
                    .'<span class="g-pill '.$tierCls.'" style="font-size:11px">第 '.$rank.' 位</span>'.$rateBadge($r).'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;font-size:12px">'
                    .($mineUrl !== ''
                        ? '<a href="'.self::e($mineUrl).'" target="_blank" rel="noreferrer" style="color:#4f46e5">'.self::e(mb_substr($mineTitle !== '' ? $mineTitle : $mineUrl, 0, 46, 'UTF-8')).'</a>'
                        : '<span style="color:#9ca3af">—</span>')
                    .'</td></tr>';
            }
            $ownedBlock = '<div class="g-card"><h3>自有页面已进前 10：'.count($owned).' 条</h3>'
                .'<p class="g-cap">这些场景你的页面已经在推荐位里。重点不是补内容，而是争位置与盯口碑</p>'
                .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
                .'<th>问题</th><th style="width:96px">排位</th><th>命中的页面</th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table></div>';
        }

        // ---------- 失败 ----------
        $failedBlock = '';
        if ($failed > 0) {
            $rows = '';
            foreach ($results as $r) {
                if ((string) ($r['error'] ?? '') === '') {
                    continue;
                }
                $rows .= '<tr><td style="border:1px solid #e5e7eb;padding:9px 11px">'.self::e((string) ($r['question'] ?? '')).'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;color:#b45309">'.self::e((string) $r['error']).'</td></tr>';
            }
            $failedBlock = '<div class="g-card"><h3>核查失败：'.$failed.' 条</h3>'
                .'<p class="g-cap">这些题没拿到有效搜索结果，<b>不计入前三率分母</b>，也不当成「未进前 10」（否则会把网络问题误判成内容缺口）</p>'
                .'<table class="g-table" style="font-size:12.5px"><thead><tr><th>问题</th><th style="width:220px">原因</th></tr></thead><tbody>'.$rows.'</tbody></table>'
                .'<div class="g-row" style="margin-top:14px"><a class="g-btn g-btn--soft" href="'.$adminBase.'/coverage-check">重新核查</a>'
                .'<span class="g-cap" style="margin:0">重新核查会逐题执行，失败的题目可以单独点「重试」</span></div></div>';
        }

        // ---------- 边界 ----------
        $scopeBrand = trim((string) ($categoryMeta['brand']['name'] ?? ''));
        $scopeBrandSlug = trim((string) ($categoryMeta['brand']['slug'] ?? ''));
        $scopeDetail = [];
        foreach (['A', 'B', 'C', 'D'] as $c) {
            $all = (int) ($categoryMeta['all_questions'][$c] ?? 0);
            if ($all <= 0) {
                continue;
            }
            $mine = (int) ($categoryStats[$c]['questions'] ?? 0);
            $scopeDetail[] = $c.' '.$all.' 题中属于'.($scopeBrand !== '' ? $scopeBrand : '本品牌').' '.$mine.' 题';
        }
        $scopeLine = $scopeDetail !== []
            ? implode('；', $scopeDetail).'（其他品牌的题不计入）'
            : '按关键词库的 A/B/C/D 分类统计当前品牌的题';

        $bounds = '<div class="g-card"><h3>这份报告的边界</h3>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr><th style="width:200px">项</th><th>说明</th></tr></thead><tbody>'
            .'<tr><td>数据来源</td><td>DeepSeek 自研搜索引擎实时检索结果（每题取前 10 条），计费 1000 次 5 元</td></tr>'
            .'<tr><td>类别口径</td><td>A 类（品牌认知）不计「覆盖率」：这类题 AI 必然提到品牌，覆盖率恒 100%、没有区分度；'
            .'A 类看官网占位率 / 首位归属 / 缺位替身榜。前三率与前 10 覆盖率<b>只对 B/C/D 计算</b></td></tr>'
            .'<tr><td>统计范围</td><td>当前品牌'
            .($scopeBrand !== '' ? '「'.self::e($scopeBrand).'」' : '')
            .($scopeBrandSlug !== '' ? '（'.self::e($scopeBrandSlug).'）' : '')
            .'的词库问题；切换品牌用 '.self::e('?brand=<slug>').'，不带参数时用品牌档案里的 default</td></tr>'
            .'<tr><td>数据范围</td><td>'.$scopeLine
            .'；按 run 去重。<b>本次核查逐题结果</b>只统计本次这一批题——两块数字口径不同，不能混着念</td></tr>'
            .'<tr><td>它是什么</td><td>真实网页排名，<b>能反映内容有没有被检索到</b>；不是豆包/元宝自家排序（那两家无公开 API）</td></tr>'
            .'<tr><td>收录滞后</td><td>新发的文章要等搜索引擎收录才会出现在结果里，通常几天到几周，<b>复测不是当天见效</b></td></tr>'
            .'<tr><td>判定方式</td><td>自有域名命中 → 自有页面；仅品牌名命中 → 仅被提及；纯字符串匹配，可复现</td></tr>'
            .'<tr><td>单次采样</td><td>每题只搜一次，结果有轻微波动；要严谨就同一批题隔天再跑对比</td></tr>'
            .'</tbody></table></div>';

        return self::shell(
            '<div class="g-toolbar">'
            .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/coverage-check">← 重新核查</a>'
            .'<a class="g-btn g-btn--soft" href="'.$adminBase.'/analytics/ai-visibility">去数据中心看趋势</a>'
            .'<span class="g-sep">'.($readOnly
                ? '这是历史核查的留档，只读；上面统计只算这一批采样'
                : '本批结果已留档，可在上方下拉里随时回看；重新核查会新建一批').'</span>'
            .'</div>'
            .self::batchBar($brandSlug, $activeBatchId, $readOnly, $batchNotice)
            .$catBlock.$summary.$ownedBlock.$todoBlock.$authorityBlock.$failedBlock.$bounds,
            '覆盖核查报告'
        );
    }

    /**
     * 报告页顶部的「本次核查」下拉 + 统计范围说明。
     *
     * ── 为什么放在页顶 ────────────────────────────────────────────────
     * 报告页有两块口径完全不同的数（按类别的历史分块 / 本次逐题结果）。
     * 如果用户不知道"现在看的是哪一次"，切品牌或隔天回来都会把旧数当新数。
     * 把"这是哪一批、什么时候跑的、跑成什么样"钉在页顶，一眼就能对上。
     *
     * ── 为什么选中项要写成"全部历史"而不是留空 ────────────────────────
     * 不带 ?batch= 时的口径是"该品牌全部历史采样"，不是"没有报告"。
     * 下拉里必须有一项能代表这个状态，否则用户点开看不到当前选项。
     *
     * @param  string  $brandSlug  当前品牌（只列这个品牌的批次）
     * @param  int|null  $activeBatchId  当前选中的批次；null = 全部历史
     * @param  bool  $readOnly  历史批次只读模式（提示语不同）
     */
    private static function batchBar(string $brandSlug, ?int $activeBatchId, bool $readOnly = false, string $batchNotice = ''): string
    {
        if ($brandSlug === '') {
            return '';
        }

        // ---------- 一个品牌的批次总数与最早时间（过滤前先数，不受下面 limit 影响）----------
        $total = 0;
        $firstAt = '';
        try {
            $agg = \Illuminate\Support\Facades\DB::table('ai_visibility_batches')
                ->where('brand_slug', $brandSlug)
                ->selectRaw('count(*) as n, min(created_at) as first_at')
                ->first();
            $total = $agg !== null ? (int) ($agg->n ?? 0) : 0;
            $firstAt = $agg !== null ? trim((string) ($agg->first_at ?? '')) : '';
        } catch (\Throwable $e) {
            // 批次表还没建（迁移没跑）时不能把整个报告页拖死：下拉降级成"暂无"
            $total = 0;
            $firstAt = '';
        }

        $list = $total > 0 ? self::batchList($brandSlug) : [];

        // ---------- 当前选中项 ----------
        $active = null;
        foreach ($list as $row) {
            if ($activeBatchId !== null && (int) $row['id'] === $activeBatchId) {
                $active = $row;
                break;
            }
        }

        // $batchNotice 由 reportPage() 判定（批次不存在 / 不是这个品牌的）。
        // 判定必须放在算统计口径之前，否则会出现"提示说按全量算、数字其实按批次算"。
        $scope = $batchNotice !== ''
            ? '<div class="g-err" style="margin:0 0 10px">'.$batchNotice.'</div>'
            : '';

        // ---------- 统计范围文案 ----------
        if ($activeBatchId !== null && $active !== null) {
            $state = self::batchState($active['status'], (int) $active['runs'], (int) $active['valid']);
            $scope .= '<p class="g-cap" style="margin:0 0 10px">'
                .'本次核查：<b>'.self::e($active['created_at']).'</b>'
                .'　·　'.(int) $active['questions'].' 题 × '.(int) $active['rounds'].' 次采样'
                .'　·　<span style="color:'.$state['color'].';font-weight:650">'.self::e($state['label']).'</span>'
                .'　·　实际采样 '.(int) $active['runs'].' 条（其中 '.(int) $active['valid'].' 条拿到有效信源）'
                .'</p>';
        } elseif ($activeBatchId !== null) {
            // 批次有效但排不进最近 20 条（看的是很久以前那一次）
            $scope .= '<p class="g-cap" style="margin:0 0 10px">'
                .'本次核查：<b>batch='.self::e((string) $activeBatchId).'</b>（这一次较早，不在下面最近 20 条的下拉里）'
                .'</p>';
        } else {
            $scope .= '<p class="g-cap" style="margin:0 0 10px">'
                .'统计范围：<b>本品牌全部历史采样</b>（'.$total.' 个批次'
                .($firstAt !== '' ? '，最早 '.self::e($firstAt) : '')
                .'）。要看某一次核查，用下面下拉选。'
                .'<b>逐题结果区</b>只统计当前会话这一批，两块口径不同，不能混着念。</p>';
        }

        // ---------- 下拉 ----------
        return $scope
            .'<div class="g-row" style="gap:8px;margin:0 0 12px;align-items:center">'
            .'<span style="font-size:12.5px;font-weight:600;color:#374151">本次核查：</span>'
            .self::batchSelectHtml($brandSlug, $activeBatchId, $total, 'cov-batch')
            .($readOnly
                ? '<span class="g-cap" style="margin:0;color:#b45309">历史批次（只读）· 明细按当时落库的信源重新判定，不重新调搜索</span>'
                : '')
            .'</div>';
    }

    /**
     * 历史批次 <select>（报告页和表单页共用，保证两处选项文案一致）。
     *
     * 跳转目标只带 report / brand / batch 三个参数：
     * 别的参数（例如品牌切换留下的）留着只会让 URL 越滚越长，还可能把旧的 plan=1 带回来。
     */
    private static function batchSelectHtml(string $brandSlug, ?int $activeBatchId, int $total, string $id): string
    {
        $opts = '<option value=""'.($activeBatchId === null ? ' selected' : '').'>'
            .'全部历史（'.$total.' 个批次）</option>';
        foreach (self::batchList($brandSlug) as $row) {
            $state = self::batchState($row['status'], (int) $row['runs'], (int) $row['valid']);
            $sel = $activeBatchId !== null && (int) $row['id'] === $activeBatchId ? ' selected' : '';
            $opts .= '<option value="'.(int) $row['id'].'"'.$sel.'>'
                .self::e($row['created_at']).' · '.(int) $row['questions'].' 题 × '.(int) $row['rounds'].' 次'
                .' · '.self::e($state['label'])
                .'</option>';
        }

        $baseUrl = self::reportUrl($brandSlug);

        return '<select id="'.self::e($id).'" onchange="(function(v){location.href=v?'
            ."'".$baseUrl."&batch='+encodeURIComponent(v):'".$baseUrl."')(this.value)\" "
            .'style="padding:7px 10px;border:1px solid #d1d5db;border-radius:9px;font-size:12.5px;'
            .'background:#fff;color:#111827;max-width:520px">'
            .$opts.'</select>';
    }

    /**
     * 「这个品牌的某一批报告」URL（当前后台根路径 + report=1 + brand）。
     *
     * 用 REQUEST_URI 推路径，而不是让每个调用点都传 $adminBase：
     * 表单页那条渲染路径拿不到 $adminBase，硬加参数会把签名改得到处都是。
     */
    private static function reportUrl(string $brandSlug): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        if ($path === '') {
            $path = '/coverage-check';
        }

        return $path.'?report=1&brand='.rawurlencode($brandSlug);
    }

    /**
     * 表单页顶部的「往期核查报告」入口。
     *
     * ── 为什么历史入口不能只放在报告页 ────────────────────────────────
     * 会话过期后用户是从菜单点进「覆盖核查」的，落到的就是这一页；
     * 如果历史入口只在报告页，他压根到不了报告页，留档等于没留。
     *
     * 没有批次（或品牌认不出来）时返回空串，页面一个字都不多出来。
     */
    private static function formHistoryBar(string $brandSlug): string
    {
        if ($brandSlug === '') {
            return '';
        }

        $total = 0;
        try {
            $agg = \Illuminate\Support\Facades\DB::table('ai_visibility_batches')
                ->where('brand_slug', $brandSlug)
                ->selectRaw('count(*) as n, min(created_at) as first_at')
                ->first();
            $total = $agg !== null ? (int) ($agg->n ?? 0) : 0;
        } catch (\Throwable $e) {
            return '';
        }
        if ($total <= 0) {
            return '';
        }

        $list = self::batchList($brandSlug);
        $last = $list[0] ?? null;
        $lastLabel = $last !== null
            ? $last['created_at'].' · '.(int) $last['questions'].' 题 × '.(int) $last['rounds'].' 次'
            : '';

        return '<div class="g-card" style="padding:14px 18px">'
            .'<div class="g-row" style="gap:10px;align-items:center;margin:0;flex-wrap:wrap">'
            .'<span style="font-size:12.5px;font-weight:600;color:#374151">往期核查报告：</span>'
            .self::batchSelectHtml($brandSlug, null, $total, 'cov-batch-form')
            .'<span class="g-cap" style="margin:0">共 '.$total.' 批留档'
            .($lastLabel !== '' ? '，最近一次 '.self::e($lastLabel) : '')
            .'　·　核查结果不再只存在浏览器会话里，关掉页面也能回看</span>'
            .'</div></div>';
    }

    /**
     * 批次属于哪个品牌（brand_slug）。null = 这条批次不存在。
     *
     * 单独查一次而不是从 batchList() 里找：batchList 只取最近 20 条，
     * 拿很久以前那一次的报告时会找不到，误报成"不存在"。
     */
    private static function batchOwnerSlug(int $batchId): ?string
    {
        try {
            $row = \Illuminate\Support\Facades\DB::table('ai_visibility_batches')
                ->where('id', $batchId)
                ->first(['brand_slug']);
        } catch (\Throwable $e) {
            return null;
        }

        return $row !== null ? trim((string) ($row->brand_slug ?? '')) : null;
    }

    /**
     * 当前品牌的批次列表（最近 20 条，created_at 倒序）。
     *
     * @return list<array{id:int,created_at:string,questions:int,rounds:int,runs:int,valid:int,status:string}>
     */
    private static function batchList(string $brandSlug): array
    {
        try {
            $rows = \Illuminate\Support\Facades\DB::table('ai_visibility_batches')
                ->where('brand_slug', $brandSlug)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'created_at', 'questions_count', 'rounds', 'runs_count', 'valid_count', 'status']);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $created = trim((string) ($row->created_at ?? ''));
            $out[] = [
                'id' => (int) $row->id,
                'created_at' => $created !== '' ? $created : '—',
                'questions' => (int) ($row->questions_count ?? 0),
                'rounds' => max(1, (int) ($row->rounds ?? 1)),
                'runs' => (int) ($row->runs_count ?? 0),
                'valid' => (int) ($row->valid_count ?? 0),
                'status' => trim((string) ($row->status ?? 'running')),
            ];
        }

        return $out;
    }

    /**
     * 批次状态 → 展示文案 + 颜色。
     *
     * 判定只看 valid_count 与 runs_count 的比：
     *   runs = 0                  → 还没跑出采样（running / 刚建）
     *   valid = 0                 → 全部失败
     *   0 < valid < runs          → 部分成功
     *   valid = runs              → 已完成
     *
     * 为什么不用 status 字段单独决定：状态只说明"跑没跑完"，
     * 而用户看下拉时想知道的恰恰是"这批数据能不能用"。两者要分开。
     *
     * @return array{label:string,color:string}
     */
    private static function batchState(string $status, int $runs, int $valid): array
    {
        if ($runs <= 0) {
            return $status === 'aborted'
                ? ['label' => '已中止（无采样）', 'color' => '#b45309']
                : ['label' => '进行中 / 无采样', 'color' => '#6b7280'];
        }
        if ($valid <= 0) {
            return ['label' => '全部失败', 'color' => '#b91c1c'];
        }
        if ($valid < $runs) {
            return ['label' => '部分成功', 'color' => '#b45309'];
        }

        return ['label' => '已完成', 'color' => '#047857'];
    }

    /**
     * 会话过期后打开某一批历史核查的报告页（?batch=<id> 的兜底路径）。
     *
     * ── 为什么需要这条路径 ────────────────────────────────────────────
     * 留档的意义就是"浏览器关了 / 隔天回来还能看"。如果只有 session 里那批
     * 才能渲染报告，历史下拉就变成了摆设：点开就是"会话已过期"。
     *
     * ── 重建出来的东西和当时的差别 ────────────────────────────────────
     * 逐题判定用当次落库的信源重新跑 judge()（纯字符串规则，结果一致），
     * 多轮采样按 probe() 同一套 aggregate() 多数决合并。
     * 唯一无法复原的是"对照品牌"（当时填的竞品名单没落库），
     * 所以竞品标注退回品牌档案里配置的竞品 —— 只影响标注，不影响覆盖判定。
     *
     * @param  int  $batchId  批次 id
     * @param  string  $adminBase  后台根路径
     * @return string|null  null = 批次不存在（调用方回退到普通错误提示）
     */
    public static function historicalReportPage(int $batchId, string $adminBase): ?string
    {
        if ($batchId <= 0) {
            return null;
        }

        $ctx = GeoCoverageCheck::batchContext($batchId);
        if ($ctx === null) {
            return null;
        }

        $results = GeoCoverageCheck::resultsForBatch($batchId);

        return self::reportPage($ctx, $results, $adminBase, $batchId, true);
    }

    /**
     * 建任务结果页
     *
     * @param  array<string,mixed>  $result
     * @param  string  $pickedNotice  部分 picked 被忽略时的提示（空串 = 不显示）
     */
    public static function taskDonePage(array $result, string $adminBase, string $pickedNotice = ''): string
    {
        $noticeHtml = $pickedNotice !== ''
            ? '<div class="g-err" style="background:#fffbeb;border-color:#fde68a;color:#b45309">'
                .self::e($pickedNotice).'</div>'
            : '';

        if (empty($result['ok'])) {
            $body = '<div class="g-card"><h3>未能自动创建任务</h3>'
                .$noticeHtml
                .'<div class="g-err">'.self::e((string) ($result['error'] ?? '未知错误')).'</div>'
                .(! empty($result['fallback_url'])
                    ? '<a class="g-btn g-btn--primary" href="'.self::e((string) $result['fallback_url']).'">打开预填好的任务表单</a>'
                    : '')
                .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/coverage-check?report=1" style="margin-left:8px">返回核查报告</a></div>';

            return self::shell($body, '生成任务草稿');
        }

        $rows = '';
        foreach ((array) ($result['titles'] ?? []) as $t) {
            $rows .= '<tr><td style="border:1px solid #e5e7eb;padding:8px 11px">'.self::e((string) $t).'</td></tr>';
        }

        $body = '<div class="g-card"><h3>任务草稿已生成</h3>'
            .$noticeHtml
            .'<p class="g-cap">一个标题库 + 一个任务，问题已作为文章标题写入；任务为<b>暂停态</b>，参数确认后再启动</p>'
            .'<div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0">'
            .'<div style="flex:1;min-width:130px;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px">'
            .'<div style="font-size:11.5px;color:#6b7280">标题库</div>'
            .'<div style="font-size:14px;font-weight:700;color:#111827">#'.(int) ($result['library_id'] ?? 0).'　'.count((array) ($result['titles'] ?? [])).' 个标题</div></div>'
            .'<div style="flex:1;min-width:130px;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px">'
            .'<div style="font-size:11.5px;color:#6b7280">任务</div>'
            .'<div style="font-size:14px;font-weight:700;color:#111827">#'.(int) ($result['task_id'] ?? 0).'　'.self::e((string) ($result['name'] ?? '')).'</div></div>'
            .'</div>'
            .'<p class="g-cap" style="margin-bottom:6px">写进标题库的问题：</p>'
            .'<div style="max-height:280px;overflow:auto"><table class="g-table" style="font-size:12.5px"><tbody>'.$rows.'</tbody></table></div>'
            .'<div class="g-row" style="margin-top:16px">'
            .'<a class="g-btn g-btn--primary" href="'.$adminBase.'/tasks/'.(int) ($result['task_id'] ?? 0).'/edit">去任务详情补充参数</a>'
            .'<a class="g-btn g-btn--soft" href="'.$adminBase.'/title-libraries/'.(int) ($result['library_id'] ?? 0).'/titles">查看标题库</a>'
            .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/coverage-check?report=1">返回核查报告</a>'
            .'</div>'
            .'<p class="g-cap" style="margin:14px 0 0">启动顺序建议：先进任务详情把知识库换成对应品牌的知识库，再点启动。'
            .'否则文章会引用默认知识库，问题虽然对上了，事实依据却是别的产品的。</p></div>';

        return self::shell($body, '任务草稿已生成');
    }

    /**
     * 首屏「按问题类别看」：B/D/C 用官网覆盖，A 单独一块用三个新指标。
     *
     * 两条口径约束（都来自踩过的坑）：
     *   1. 不能合成一个总覆盖率：A 类是用户主动搜品牌名（"XX 是什么档次"），
     *      AI 回答里必然提到品牌，覆盖率恒 100%、方差为零，混算只会把 B 类 0%
     *      这种真缺口抬到看不出问题。A 类真正有信息量的是：
     *      官网占位率 / 首位归属 / 缺位替身榜。
     *   2. 不能跨品牌混算：没有官网的品牌在品牌词上必然 0%，和一个满分的品牌
     *      平均出来的中间值（69%）恰好把最重要的对比抹平。所以只统计当前品牌，
     *      页头明示品牌，用户能自己切。
     *
     * @param  array<string,array<string,mixed>>  $stats  GeoCoverageCheck::categoryBreakdown()['categories']
     * @param  array<string,mixed>  $meta  品牌 / 站点 / 全品牌题数
     * @param  string  $error  非空表示取数失败
     * @param  string  $pageBrandSlug  当前口径的品牌 slug（由 reportPage() 统一算好传进来）
     */
    private static function categorySection(array $stats, array $meta, string $error, string $pageBrandSlug = ''): string
    {
        $brand = (array) ($meta['brand'] ?? []);
        $brandName = trim((string) ($brand['name'] ?? ''));
        $brandSlug = trim((string) ($brand['slug'] ?? ''));
        $ownSites = (array) ($meta['own_sites'] ?? []);
        $allQuestions = (array) ($meta['all_questions'] ?? []);

        // ?brand= 写了一个品牌档案里没有的 slug 时，GeoShareOfVoice 会静默回退到默认品牌；
        // 这里必须说出来，否则用户看到的品牌名和 URL 对不上
        $requested = trim($pageBrandSlug !== '' ? $pageBrandSlug : (string) ($meta['requested_slug'] ?? ''));
        $fallbackNote = ($requested !== '' && $brandSlug !== '' && $requested !== $brandSlug)
            ? $requested.' 不在品牌档案里，已回退到默认品牌'
            : '';

        // 页头必须写明"这是哪个品牌的数"，否则切换品牌后数字变了没人知道为什么。
        // 【本地修复】原来这里印的是「切换品牌请用 ?brand=haiyangzhizun」——
        // 让运营手改 URL。现在直接渲染成可点的 chip：点一下就写 cookie 并跳回本页。
        $brandLine = '<div style="margin:0 0 10px">'
            .self::brandChips($brandSlug !== '' ? $brandSlug : $requested, [], $fallbackNote)
            .'</div>';

        $head = '<div class="g-card"><h3>按问题类别看</h3>'
            .$brandLine
            .'<p class="g-cap">同一个「覆盖率」不能套在四类问题上：品类词看的是<b>有没有进榜</b>，'
            .'品牌词看的是<b>首位是不是你</b>。所以下面按类别分开算，A 类单独一块，'
            .'不参与前三率与前 10 覆盖率。</p>';

        if ($error !== '') {
            return $head.'<div class="g-err">类别分块统计失败：'.self::e($error).'</div>'
                .'<p class="g-cap">报告其余部分照常可用。如果这个报错持续出现，请把这段原文发给开发排查。</p></div>';
        }

        $hasAny = false;
        foreach ($stats as $s) {
            if ((int) ($s['with_src'] ?? 0) > 0) {
                $hasAny = true;
                break;
            }
        }
        if (! $hasAny) {
            return $head.'<p class="g-cap">这个品牌还没有可统计的历史采样：关键词库里的题要先跑过核查，'
                .'结果落到 ai_visibility_sources 之后这一块才有数。跑完一批再回来看。</p></div>';
        }

        // ---------- B / D / C：官网覆盖（真缺口优先，所以 B、D 排在 C 前面）----------
        $cards = '';
        foreach (['B', 'D', 'C'] as $cat) {
            $s = $stats[$cat] ?? null;
            if (! is_array($s)) {
                continue;
            }
            $den = (int) ($s['with_src'] ?? 0);
            if ($den <= 0) {
                continue;
            }
            $own = (int) $s['own_present'];
            $rate = (int) $s['own_rate'];
            if ($rate >= 40) {
                [$color, $border] = ['#047857', '#bbf7d0'];
            } elseif ($rate >= 15) {
                [$color, $border] = ['#b45309', '#fde68a'];
            } else {
                [$color, $border] = ['#b91c1c', '#fecaca'];
            }
            $gapPill = $rate === 0
                ? ' <span class="g-pill g-pill--bad" style="font-size:10.5px">真缺口</span>'
                : '';

            $cards .= '<div style="flex:1;min-width:200px;border:1px solid '.$border.';border-radius:12px;padding:12px 14px;background:#fff">'
                .'<div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap">'
                .'<b style="font-size:13px">'.self::e((string) $s['label']).'</b>'.$gapPill.'</div>'
                .'<div style="font-size:11.5px;color:#6b7280;margin-top:2px">'
                .(int) $s['questions'].' 题 / '.(int) $s['samples'].' 次采样（其中 '.$den.' 次有信源）</div>'
                .'<div style="font-size:20px;font-weight:750;color:'.$color.';line-height:1.35;margin-top:6px">'
                .'官网覆盖 '.$own.' / '.$den
                .'<span style="font-size:13px;font-weight:600">（'.$rate.'%）</span></div>'
                .'<div class="g-cap" style="margin:6px 0 0">前三率 '.(int) $s['top3_rate'].'%'
                .'　｜　前 10 覆盖率 '.(int) $s['cover_rate'].'%'
                .'　｜　品牌名出现 '.(int) $s['brand_present'].' / '.$den.'</div>'
                .'</div>';
        }

        // ---------- A：换指标，指标标签里不出现「覆盖率」 ----------
        $aBlock = '';
        $a = $stats['A'] ?? null;
        if (is_array($a) && (int) ($a['with_src'] ?? 0) > 0) {
            $den = (int) $a['with_src'];
            $own = (int) $a['own_present'];
            $absent = (int) $a['own_absent'];
            $pct = static fn (int $n): int => $den > 0 ? (int) round($n / $den * 100) : 0;

            $tierParts = [
                '官网第 1 名 '.$pct((int) $a['own_rank1']).'%（'.(int) $a['own_rank1'].'/'.$den.'）',
                '2–3 名 '.$pct((int) $a['own_rank23']).'%（'.(int) $a['own_rank23'].'/'.$den.'）',
                '完全缺位 '.$pct($absent).'%（'.$absent.'/'.$den.'）',
            ];
            if ((int) $a['own_rank4p'] > 0) {
                $tierParts[] = '第 4 名及以后 '.$pct((int) $a['own_rank4p']).'%（'.(int) $a['own_rank4p'].'/'.$den.'）';
            }

            $repl = [];
            foreach ((array) ($a['replacements'] ?? []) as $row) {
                $repl[] = self::e((string) $row['name']).' '.(int) $row['count'];
            }
            // 官网从没缺席过时这一行必须是"无缺位"，不能留空白让人以为漏算了
            $replText = $repl !== []
                ? implode(' · ', $repl)
                : '无缺位（官网每次都出现在信源里，没有需要"看谁顶上来"的采样）';

            $aBlock = '<div style="border:1px solid #c7d2fe;background:#f5f7ff;border-radius:12px;padding:14px 16px">'
                .'<div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap">'
                .'<b style="font-size:13px">'.self::e((string) $a['label']).'</b>'
                .'<span class="g-cap" style="margin:0">'
                .(int) $a['questions'].' 题 / '.(int) $a['samples'].' 次采样（其中 '.$den.' 次有信源）</span></div>'
                .'<p class="g-cap" style="margin:8px 0 10px">品牌认知类的问题是用户主动搜品牌名，'
                .'AI 回答里必然提到品牌，所以「覆盖率」恒为 100%，没有区分度。'
                .'这类要看的是：用户搜你名字时，AI 第一条推的是你官网，还是媒体和百科。</p>'
                .'<table class="g-table" style="font-size:12.5px"><tbody>'
                .'<tr><td style="width:110px;color:#3730a3;font-weight:600">官网占位率</td>'
                .'<td><b>'.(int) $a['own_rate'].'%</b>（'.$own.'/'.$den.'）'
                .'<span style="color:#b45309">　缺口 '.$pct($absent).'%（'.$absent.'/'.$den.'）</span></td></tr>'
                .'<tr><td style="color:#3730a3;font-weight:600">首位归属</td>'
                .'<td>'.implode(' · ', $tierParts).'</td></tr>'
                .'<tr><td style="color:#3730a3;font-weight:600">缺位替身榜</td>'
                .'<td>'.$replText.'</td></tr>'
                .'</tbody></table>'
                .'<p class="g-cap" style="margin:10px 0 0">'
                .'口径：官网占位率 = 官网出现在信源里的采样数 ÷ 该类有效采样数；'
                .'首位归属 = 官网是否在第 1 名 / 在 2–3 名 / 完全不在；'
                .'缺位替身榜 = 官网缺席的那些采样里，top1 的域名频次。</p>'
                .'</div>';
        } elseif (is_array($a)) {
            $aBlock = '<div style="border:1px solid #e5e7eb;background:#fafafa;border-radius:12px;padding:12px 14px">'
                .'<b style="font-size:13px">'.self::e((string) $a['label']).'</b>'
                .'<p class="g-cap" style="margin:6px 0 0">这个品牌的 A 类还没有有效采样'
                .'（'.(int) $a['questions'].' 题 / '.(int) $a['samples'].' 次采样），跑一批才有数。</p></div>';
        }

        // ---------- 口径说明：品牌过滤 + 各品牌题数 ----------
        $siteText = $ownSites !== []
            ? '「官网」= 当前品牌站点 '.self::e((string) $ownSites[0]).'。'
            : '「官网」按当前品牌档案里的 site_url 判定。';

        $scopeParts = [];
        foreach (['A', 'B', 'C', 'D'] as $c) {
            $all = (int) ($allQuestions[$c] ?? 0);
            if ($all <= 0) {
                continue;
            }
            $mine = (int) ($stats[$c]['questions'] ?? 0);
            $scopeParts[] = $c.' '.$all.' 题中属于本品牌 '.$mine.' 题';
        }
        $scopeText = $scopeParts !== []
            ? '数据范围：'.implode('；', $scopeParts).'（其他品牌的题不计入）。'
            : '';

        $foot = '<p class="g-cap" style="margin:12px 0 0">数据口径：按关键词库的 A/B/C/D 分类，'
            .'只统计<b>当前品牌'.($brandName !== '' ? '「'.self::e($brandName).'」' : '').'</b>的<b>历史采样</b>'
            .'（ai_visibility_sources，按 run 去重）。'
            .$siteText
            .'判定与逐题结果一致（域名命中 = 自有页面，品牌名命中 = 仅被提及）。'
            .($scopeText !== '' ? '<br>'.$scopeText : '')
            .'</p></div>';

        return $head
            .'<div style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 12px">'.$cards.'</div>'
            .$aBlock
            .$foot;
    }

    /**
     * 把「品牌名 / slug / 别名」归一成 slug，用来判断两个品牌是不是同一个。
     *
     * 用户填 `binsis`（slug）或「冰溪」（品牌名）其实是同一个品牌，
     * 直接比字符串会误报"不是同一个品牌"，所以先归一。
     * 认不出来时（用户手填的自定义品牌名）退回小写原文做字符串比较，
     * 不能因为不认识就永远不提醒。
     */
    private static function brandKeyOf(string $nameOrSlug): string
    {
        $row = self::brandRowOf($nameOrSlug);
        if ($row !== null) {
            $slug = mb_strtolower(trim((string) ($row['slug'] ?? '')), 'UTF-8');

            return $slug !== '' ? $slug : mb_strtolower(trim($nameOrSlug), 'UTF-8');
        }

        return mb_strtolower(trim($nameOrSlug), 'UTF-8');
    }

    /**
     * 按「slug / 品牌名 / 全称 / 别名」在品牌档案里找那一条，找不到返回 null。
     *
     * 和 brandKeyOf() 共用同一套匹配规则（brandKeyOf 就是走这里）：
     * 页面上要显示中文名、要判断高亮哪个 chip、要不要提示"不在品牌档案里"，
     * 都必须按同一口径认品牌，否则又会出现「binsis 和冰溪被当成两个品牌」。
     *
     * @return array<string,mixed>|null
     */
    private static function brandRowOf(string $nameOrSlug): ?array
    {
        $needle = mb_strtolower(trim($nameOrSlug), 'UTF-8');
        if ($needle === '') {
            return null;
        }

        foreach (GeoShareOfVoice::brands() as $b) {
            if (! is_array($b)) {
                continue;
            }
            $candidates = [mb_strtolower(trim((string) ($b['slug'] ?? '')), 'UTF-8')];
            foreach ([$b['name'] ?? '', $b['full_name'] ?? ''] as $v) {
                $v = mb_strtolower(trim((string) $v), 'UTF-8');
                if ($v !== '') {
                    $candidates[] = $v;
                }
            }
            foreach ((array) ($b['aliases'] ?? []) as $a) {
                $a = mb_strtolower(trim((string) $a), 'UTF-8');
                if ($a !== '') {
                    $candidates[] = $a;
                }
            }
            if (in_array($needle, $candidates, true)) {
                return $b;
            }
        }

        return null;
    }

    /**
     * 品牌切换链接：保留当前页所有 query 参数，只替换 brand。
     *
     * 为什么要保留其余参数：报告页靠 ?report=1 才进报告，丢掉就跳回表单了。
     * 为什么换品牌只要改 URL：web.php 的路由加载段已经统一处理
     * 「?brand= 存在且是档案里的品牌 → setcookie('geo_brand')」，
     * 这里复用那一条，不再另写一套 cookie 逻辑。
     *
     * @param  list<string>  $strip  需要丢掉的参数（表单页要丢掉 plan=1，否则一跳转就重新发起核查）
     */
    private static function brandSwitchUrl(string $slug, array $strip = []): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');

        $args = [];
        if ($query !== '') {
            parse_str($query, $args);
        }
        foreach ($strip as $key) {
            unset($args[$key]);
        }
        $args['brand'] = $slug;

        $qs = http_build_query($args);
        if ($path === '') {
            return '?'.$qs;
        }

        return $qs !== '' ? $path.'?'.$qs : $path;
    }

    /**
     * 品牌档案内联表单的初始状态。
     *
     * 三个入口共用一份状态：
     *   · ?brand_form=create             → 新增（空白表单）
     *   · ?brand_form=edit&brand_edit=x  → 编辑（预填现有值）
     *   · POST 校验失败（路由传入）        → 保留用户刚填的值 + 错误列表
     *
     * @param  array<string,mixed>  $given
     * @return array{mode:string,editSlug:string,values:array<string,string>,errors:list<string>,notice:string}
     */
    private static function brandFormState(array $given): array
    {
        $blank = [
            'mode' => '',
            'editSlug' => '',
            'values' => ['name' => '', 'slug' => '', 'site_url' => '', 'aliases' => '', 'competitors' => '', 'note' => ''],
            'errors' => [],
            'notice' => '',
        ];

        if ($given !== []) {
            return [
                'mode' => (string) ($given['mode'] ?? ''),
                'editSlug' => (string) ($given['editSlug'] ?? ''),
                'values' => array_merge($blank['values'], (array) ($given['values'] ?? [])),
                'errors' => array_values((array) ($given['errors'] ?? [])),
                'notice' => (string) ($given['notice'] ?? ''),
            ];
        }

        $mode = (string) ($_GET['brand_form'] ?? '');
        if ($mode === 'create') {
            return array_merge($blank, ['mode' => 'create']);
        }
        if ($mode !== 'edit') {
            return $blank;
        }

        $slug = trim((string) ($_GET['brand_edit'] ?? ''));
        $row = $slug !== '' ? GeoShareOfVoice::findBrand($slug) : null;
        if ($row === null) {
            return array_merge($blank, [
                'mode' => 'create',
                'errors' => ['要编辑的品牌不存在（slug='.($slug !== '' ? $slug : '空').'），下面按新增品牌处理'],
            ]);
        }

        $aliases = [];
        foreach ((array) ($row['aliases'] ?? []) as $a) {
            $a = trim((string) $a);
            if ($a !== '') {
                $aliases[] = $a;
            }
        }
        $competitorLines = [];
        foreach ((array) ($row['competitors'] ?? []) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $cName = trim((string) ($c['name'] ?? ''));
            if ($cName === '') {
                continue;
            }
            $cAliases = [];
            foreach ((array) ($c['aliases'] ?? []) as $a) {
                $a = trim((string) $a);
                if ($a !== '' && $a !== $cName) {
                    $cAliases[] = $a;
                }
            }
            $competitorLines[] = $cAliases !== [] ? $cName.'='.implode(',', $cAliases) : $cName;
        }

        return [
            'mode' => 'edit',
            'editSlug' => trim((string) ($row['slug'] ?? '')),
            'values' => [
                'name' => trim((string) ($row['name'] ?? '')),
                'slug' => trim((string) ($row['slug'] ?? '')),
                'site_url' => trim((string) ($row['site_url'] ?? '')),
                'aliases' => implode("\n", $aliases),
                'competitors' => implode("\n", $competitorLines),
                'note' => trim((string) ($row['note'] ?? '')),
            ],
            'errors' => [],
            'notice' => '',
        ];
    }

    /**
     * 品牌档案内联表单（新增 / 编辑 / 删除）。
     *
     * 为什么不用弹窗：这一页的其它交互（切换品牌、历史报告下拉）都在页内，
     * 弹窗会遮住用户正在对比的 chip 和整页数据；而且弹窗里改完再回来看，
     * 分不清"档案改了"和"这一页刷新了"。内联表单改完就落在页面同一处，
     * 保存成功直接跳回核查页并把当前品牌切过去，chip 立刻高亮新品牌。
     */
    private static function brandFormSection(array $state): string
    {
        $mode = (string) ($state['mode'] ?? '');
        $isEdit = $mode === 'edit';
        $open = in_array($mode, ['create', 'edit'], true);
        $values = (array) ($state['values'] ?? []);
        $editSlug = (string) ($state['editSlug'] ?? '');

        $v = static fn (string $k): string => (string) ($values[$k] ?? '');
        $inputStyle = 'width:100%;margin-top:6px;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;font-size:13px';
        $labelStyle = 'font-size:12.5px;font-weight:600;color:#374151;display:block';
        $adminBase = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        // 提交地址带上当前品牌：校验失败要就地重渲染，品牌口径不能丢
        $currentSlug = trim(GeoShareOfVoice::resolveSlug());
        $formAction = $adminBase.'/coverage-check'.($currentSlug !== '' ? '?brand='.rawurlencode($currentSlug) : '');

        $errors = '';
        foreach ((array) ($state['errors'] ?? []) as $msg) {
            $msg = trim((string) $msg);
            if ($msg !== '') {
                $errors .= '<div class="g-err" style="margin:0 0 10px">'.self::e($msg).'</div>';
            }
        }
        $notice = trim((string) ($state['notice'] ?? ''));
        $noticeHtml = $notice !== '' ? '<div class="g-note" style="margin:0 0 10px">'.self::e($notice).'</div>' : '';

        $title = $isEdit
            ? '编辑品牌档案：'.self::e($v('name')).'（'.self::e($editSlug).'）'
            : '新增品牌档案';
        $cap = $isEdit
            ? '短标识 slug 不允许改 —— 关键词库名字前缀和核查批次的 brand_slug 都靠它关联，改了历史数据就全对不上。'
            : '保存后会写进 storage/app/geo-brands.json（先备份再原子替换），并自动把当前品牌切到新建的这一个。';

        $slugInput = $isEdit
            ? '<input type="text" id="bf_slug" name="bf_slug" value="'.self::e($v('slug')).'" readonly '
                .'style="'.$inputStyle.';background:#f3f4f6;color:#6b7280;cursor:not-allowed">'
                .'<span class="g-cap" style="margin:2px 0 0">只读：slug 是历史数据的品牌归属键</span>'
            : '<input type="text" id="bf_slug" name="bf_slug" value="'.self::e($v('slug')).'" '
                .'placeholder="例如 rebecca（按品牌名自动生成，可改）" style="'.$inputStyle.'">'
                .'<span class="g-cap" style="margin:2px 0 0">只允许小写字母 / 数字 / 连字符，会用在 URL 上</span>';

        $deleteBtn = $isEdit
            ? '<button type="submit" name="brand_action" value="delete" class="g-btn g-btn--ghost" '
                .'style="color:#b91c1c;border-color:#fecaca;background:#fef2f2" '
                .'onclick="return confirm(\'确认删除品牌「'.self::e($v('name')).'」？\\n有词库或核查批次的品牌会被拒绝删除。\')">删除该品牌</button>'
            : '';

        $hidden = $open ? '' : ' hidden';

        return '<div id="brand-form"'.$hidden.' style="margin-top:18px">'
            .'<div style="border-top:1px dashed #e5e7eb;margin:0 0 14px"></div>'
            .'<h4 style="margin:0 0 4px;font-size:13.5px;font-weight:700;color:#111827">'.$title.'</h4>'
            .'<p class="g-cap" style="margin:0 0 12px">'.$cap.'</p>'
            .$noticeHtml
            .$errors
            .'<form method="post" action="'.self::e($formAction).'" id="brand-form-el">'
            .'<input type="hidden" name="_token" value="'.self::e((string) csrf_token()).'">'
            .'<input type="hidden" name="brand_slug" value="'.self::e($editSlug).'">'
            .'<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
            .'<label style="'.$labelStyle.'">品牌名（必填）'
            .'<input type="text" id="bf_name" name="bf_name" value="'.self::e($v('name')).'" '
            .'placeholder="例如 瑞贝卡" style="'.$inputStyle.'"></label>'
            .'<label style="'.$labelStyle.'">短标识 slug（必填）'.$slugInput.'</label>'
            .'<label style="'.$labelStyle.'">官网地址（可留空 = 该品牌无官网）'
            .'<input type="text" id="bf_site_url" name="bf_site_url" value="'.self::e($v('site_url')).'" '
            .'placeholder="例如 https://www.example.com" style="'.$inputStyle.'"></label>'
            .'<label style="'.$labelStyle.'">别名（一行一个，或用逗号分隔）'
            .'<textarea name="bf_aliases" rows="2" placeholder="留空默认用品牌名" '
            .'style="'.$inputStyle.';resize:vertical;font-family:inherit">'.self::e($v('aliases')).'</textarea></label>'
            .'<label style="'.$labelStyle.'">竞品（每行「名称=别名1,别名2」，只写名称也行）'
            .'<textarea name="bf_competitors" rows="3" placeholder="薇诺娜=薇诺娜,Winona" '
            .'style="'.$inputStyle.';resize:vertical;font-family:inherit">'.self::e($v('competitors')).'</textarea></label>'
            .'<label style="'.$labelStyle.'">备注'
            .'<input type="text" name="bf_note" value="'.self::e($v('note')).'" placeholder="自由文本，可留空" '
            .'style="'.$inputStyle.'"></label>'
            .'</div>'
            .'<div class="g-cap" id="bf-site-hint" style="margin:10px 0 0;color:#b45309;display:none">'
            .'官网地址留空 = 该品牌暂无官网：档案里会写 has_site=false，核查页的自有站点框变成只读提示，'
            .'官网相关指标按无官网处理。</div>'
            .'<div class="g-row" style="margin-top:14px">'
            .'<button type="submit" name="brand_action" value="save" class="g-btn g-btn--primary">'
            .($isEdit ? '保存修改' : '新增品牌').'</button>'
            .'<button type="button" class="g-btn g-btn--ghost" data-brand-close="1">取消</button>'
            .$deleteBtn
            .'<span class="g-cap" style="margin:0">写入前会自动备份成 geo-brands.json.bak-&lt;时间戳&gt;</span>'
            .'</div>'
            .'</form></div>'
            .'<script>(function(){'
            .'var box=document.getElementById("brand-form");if(!box)return;'
            .'var btn=document.getElementById("brand-new-btn");'
            .'function show(){box.hidden=false;if(btn)btn.textContent="收起表单";}'
            .'function hide(){box.hidden=true;if(btn)btn.textContent="+ 新增品牌";}'
            .'if(btn){btn.addEventListener("click",function(){'
            .'if(box.hidden){show();var n=document.getElementById("bf_name");if(n){n.focus();}}else{hide();}});}'
            .'var cl=box.querySelectorAll("[data-brand-close]");'
            .'for(var i=0;i<cl.length;i++){cl[i].addEventListener("click",hide);}'
            // 品牌名 → slug 自动生成（常用汉字拼音表；不认识的字跳过，用户可手改）
            .'var PY=(function(){var s="'
            .'冰bing,溪xi,海hai,洋yang,至zhi,尊zun,瑞rui,贝bei,卡ka,薇wei,诺nuo,娜na,理li,肤fu,泉quan,雅ya,漾yang,'
            .'玉yu,泽ze,本ben,欧ou,莱lai,妮ni,维wei,曼man,秀xiu,雷lei,敦dun,碧bi,奥ao,士shi,男nan,护hu,品pin,牌pai,'
            .'美mei,妆zhuang,女nv,丝si,塔ta,兰lan,蔻kou,堂tang,医yi,药yao,生sheng,物wu,科ke,技ji,集ji,团tuan,公gong,'
            .'司si,有you,限xian,新xin,能neng,源yuan,汽qi,车che,电dian,子zi,数shu,码ma,智zhi,慧hui,家jia,居ju,装zhuang,'
            .'饰shi,服fu,鞋xie,包bao,箱xiang,食shi,饮yin,茶cha,咖ka,啡fei,酒jiu,水shui,果guo,汁zhi,奶nai,粉fen,面mian,'
            .'油you,米mi,粮liang,健jian,康kang,养yang,素su,钙gai,铁tie,锌xin,儿er,童tong,玩wan,具ju,文wen,教jiao,学xue,'
            .'书shu,笔bi,纸zhi,印yin,刷shua,机ji,器qi,人ren,工gong,建jian,材cai,钢gang,铝lv,木mu,石shi,门men,窗chuang,'
            .'墙qiang,地di,板ban,灯deng,纺fang,床chuang,上shang,用yong,清qing,洁jie,洗xi,香xiang,氛fen,精jing,华hua,'
            .'液ye,霜shuang,膜mo,膏gao,露lu,乳ru,底di,眼yan,影ying,睫jie,毛mao,唇chun,腮sai,红hong,甲jia,指zhi,发fa,'
            .'染ran,烫tang,剪jian,刀dao,梳shu,镜jing,盒he,袋dai,杯bei,壶hu,锅guo,碗wan,盘pan,筷kuai,勺shao,铲chan,'
            .'灶zao,烟yan,热re,空kong,调diao,风feng,扇shan,暖nuan,气qi,净jing,化hua,滤lv,芯xin,泵beng,管guan,阀fa,'
            .'线xian,缆lan,插cha,座zuo,开kai,关guan,遥yao,控kong,充chong,池chi,锂li,储chu,光guang,伏fu,轮lun,胎tai,'
            .'椅yi,桌zhuo,柜gui,架jia,毯tan,帘lian,枕zhen,被bei,褥ru,巾jin,浴yu,卫wei,厨chu,房fang,厅ting,室shi,'
            .'园yuan,林lin,花hua,草cao,鸟niao,鱼yu,宠chong,猫mao,狗gou,沙sha,龙long,腾teng,飞fei,越yue,恒heng,'
            .'大da,小xiao,中zhong,华hua,国guo,东dong,西xi,南nan,北bei,京jing,沪hu,深shen,广guang,州zhou,杭hang,'
            .'苏su,宁ning,波bo,无wu,锡xi,长chang,成cheng,都du,重zhong,庆qing,天tian,津jin,庄zhuang,保bao,定ding,'
            .'唐tang,山shan,安an,连lian,沈shen,阳yang,哈ha,尔er,滨bin,春chun,吉ji,延yan,川chuan,银yin,拉la,萨sa,'
            .'昆kun,明ming,贵gui,桂gui,澳ao,台tai,港gang,鸿hong,星xing,辰chen,元yuan,正zheng,味wei,道dao,鲜xian,'
            .'悦yue,乐le,喜xi,福fu,寿shou,康kang,泰tai,和he,顺shun,盛sheng,丰feng,盈ying,利li,通tong,达da,远yuan,'
            .'邦bang,泰tai,鑫xin,隆long,昌chang,兴xing,旺wang,发fa,财cai,宝bao,钻zuan,金jin,银yin,铜tong,钢gang,'
            .'铁tie,锡xi,晶jing,莹ying,彩cai,炫xuan,酷ku,潮chao,尚shang,品pin,质zhi,优you,极ji,超chao,微wei,轻qing,'
            .'测ce,试shi,官guan,网wang,站zhan,店dian,商shang,城cheng,区qu,县xian,市shi,省sheng,街jie,镇zhen,'
            .'白bai,黑hei,蓝lan,绿lv,紫zi,灰hui,黄huang,橙cheng,青qing,粉fen,红hong,'
            .'"'
            .';var a=s.split(","),m={};for(var i=0;i<a.length;i++){var e=a[i];'
            // 每条是「一个汉字 + 拼音」（如 冰bing），首字当键、其余当拼音
            .'if(e.length>1){m[e.charAt(0)]=e.slice(1);}}return m;})();'
            .'function slugify(t){t=String(t||"").toLowerCase();var o="";'
            .'for(var i=0;i<t.length;i++){var c=t.charAt(i);'
            .'if(/[a-z0-9]/.test(c)){o+=c;continue;}'
            .'if(Object.prototype.hasOwnProperty.call(PY,c)){o+=PY[c];continue;}'
            .'if(/[ _\\/\\-\\u3000]/.test(c)){o+="-";continue;}'
            .'if(c.charCodeAt(0)>127){o+="-";continue;}}'
            .'o=o.replace(/-+/g,"-").replace(/^-+|-+$/g,"");return o;}'
            .'var nm=document.getElementById("bf_name"),sl=document.getElementById("bf_slug");'
            .'var auto=!(sl&&sl.value.trim()!=="");'
            .'if(nm&&sl&&!sl.readOnly){'
            .'nm.addEventListener("input",function(){if(!auto)return;sl.value=slugify(nm.value);});'
            .'sl.addEventListener("input",function(){auto=sl.value.trim()==="";});}'
            .'var su=document.getElementById("bf_site_url"),sh=document.getElementById("bf-site-hint");'
            .'function siteHint(){if(!su||!sh)return;sh.style.display=su.value.trim()===""?"block":"none";}'
            .'if(su){su.addEventListener("input",siteHint);siteHint();}'
            .'})();</script>';
    }

    /**
     * 在当前页 URL 上增删 query 参数（品牌档案表单的链接用）。
     *
     * 和 brandSwitchUrl() 的分工：那个是"换品牌"（一定会写 ?brand=），
     * 这个只用来打开/收起档案表单，绝不能顺手把 ?brand= 改掉 ——
     * 编辑 B 品牌的档案不应该把当前统计口径切成 B。
     *
     * @param  array<string,string>  $add
     * @param  list<string>  $strip
     */
    private static function brandAdminUrl(array $add = [], array $strip = []): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');

        $args = [];
        if ($query !== '') {
            parse_str($query, $args);
        }
        foreach ($strip as $key) {
            unset($args[$key]);
        }
        // brand_form / brand_edit 是互斥的一组：换目标前先清掉旧的
        unset($args['brand_form'], $args['brand_edit']);
        foreach ($add as $k => $v) {
            $args[$k] = $v;
        }

        $qs = http_build_query($args);
        if ($path === '') {
            return $qs !== '' ? '?'.$qs : '';
        }

        return $qs !== '' ? $path.'?'.$qs : $path;
    }

    /**
     * 品牌切换条：当前品牌高亮，其余品牌可点。
     *
     * 用户口径：任何时候视野里只有一个品牌。所以这里不是"多选过滤器"，
     * 而是"当前口径是哪个"＋"怎么换"——换完 cookie 全局生效，所有页面跟着变。
     *
     * @param  string  $activeSlug  当前页面实际生效的品牌 slug（档案里认不出来时为空）
     * @param  list<string>  $strip  生成链接时要丢掉的参数，见 brandSwitchUrl()
     * @param  string  $note  额外提示（例如"?brand= 写的品牌不在档案里，已回退"）
     * @param  bool  $withAdmin  是否在 chip 行右侧渲染「+ 新增品牌」和每个 chip 的「编辑」
     * @param  string  $openMode  当前展开的档案表单模式（create/edit），用来高亮按钮
     */
    private static function brandChips(
        string $activeSlug,
        array $strip = [],
        string $note = '',
        bool $withAdmin = false,
        string $openMode = ''
    ): string {
        $brands = GeoShareOfVoice::brands();
        if ($brands === []) {
            return '<div class="g-err" style="margin:0">品牌档案不可用（storage/app/geo-brands.json '
                .'读不到或 brands 为空），暂时无法切换品牌。核查和报告仍按当前配置运行。</div>';
        }

        $active = trim($activeSlug);
        $known = false;
        foreach ($brands as $b) {
            if (is_array($b) && trim((string) ($b['slug'] ?? '')) === $active && $active !== '') {
                $known = true;
                break;
            }
        }
        if (! $known) {
            // 档案里认不出来（自定义品牌名 / 空）→ 不假装高亮某一个，避免"高亮的和数字不是一回事"
            $active = '';
        }

        $chips = '';
        foreach ($brands as $b) {
            if (! is_array($b)) {
                continue;
            }
            $slug = trim((string) ($b['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $name = trim((string) ($b['name'] ?? ''));
            if ($name === '') {
                $name = $slug;
            }
            $isActive = $slug === $active;
            $chip = '<a class="g-btn '.($isActive ? 'g-btn--primary' : 'g-btn--soft').'" '
                .'href="'.self::e(self::brandSwitchUrl($slug, $strip)).'" '
                .($isActive ? 'aria-current="true" ' : '')
                .'title="切换到'.self::e($name).'（写入 cookie，全站生效）">'
                .self::e($name).'</a>';

            if ($withAdmin) {
                // 编辑链接必须独立成节点：chip 已经是 <a>，嵌 <a> 是非法 HTML
                $editUrl = self::brandAdminUrl(['brand_form' => 'edit', 'brand_edit' => $slug], array_merge($strip, ['plan']));
                $chip = '<span style="display:inline-flex;align-items:center;gap:4px">'
                    .$chip
                    .'<a href="'.self::e($editUrl).'" '
                    .'style="font-size:11.5px;color:#6b7280;text-decoration:none;padding:2px 4px;'
                    .'border:1px solid #e5e7eb;border-radius:7px;background:#fff" '
                    .'title="编辑「'.self::e($name).'」的品牌档案">编辑</a>'
                    .'</span>';
            }
            $chips .= $chip;
        }

        $head = $active !== ''
            ? '当前品牌：'
            : '当前品牌：<b>'.self::e(self::resolveSlugLabel()).'</b>（不在品牌档案里，点下面按钮切换）';

        // 【本地扩展】品牌档案原来只能手工改 storage/app/geo-brands.json，
        // 管理员加第二家客户必须上服务器。这里给一个纯前端展开的入口，
        // 表单本体渲染在 GET 表单外面（见 brandFormSection），不产生嵌套 form。
        $newButton = '';
        if ($withAdmin) {
            $creating = $openMode === 'create';
            $newButton = '<button type="button" id="brand-new-btn" class="g-btn '
                .($creating ? 'g-btn--ghost' : 'g-btn--soft').'" style="margin-left:auto" '
                .'data-brand-open="1">'.($creating ? '收起表单' : '+ 新增品牌').'</button>';
        }

        return '<div class="g-row" style="gap:8px;margin:0">'
            .'<span style="font-size:12.5px;font-weight:600;color:#374151">'.$head.'</span>'
            .$chips
            .$newButton
            .($note !== '' ? '<span class="g-cap" style="margin:0;color:#b45309">'.self::e($note).'</span>' : '')
            .'</div>';
    }

    /**
     * 当前口径的品牌展示名（?brand= → cookie → default），认不出档案时退回原始值。
     *
     * 只用于 brandChips() 的"当前品牌是自定义值"那一行文案，不参与任何统计。
     */
    private static function resolveSlugLabel(): string
    {
        $slug = trim(GeoShareOfVoice::resolveSlug());
        if ($slug === '') {
            return '未配置';
        }
        // 这里故意不用 GeoShareOfVoice::brand()：它认不出 slug 时会回退到第一个品牌，
        // 那会渲染成「冰溪（不在品牌档案里）」——把回退值当成用户写的值，越看越糊涂。
        $row = self::brandRowOf($slug);
        $name = $row !== null ? trim((string) ($row['name'] ?? '')) : '';

        return $name !== '' ? $name : $slug;
    }

    /**
     * 本次核查实际跑的品牌 slug —— 用来给「查看核查报告 / 返回核查报告」拼 ?brand=。
     *
     * 只认品牌档案里存在的 slug：用户手填的自定义品牌（档案里没有）不往 URL 上带，
     * 否则报告页的 resolveSlug() 拿到一个不存在的 slug，会静默回退到默认品牌，
     * 分块卡换成另一个品牌的数字——正是要修的那个坑。
     *
     * @param  array<string,mixed>  $ctx
     */
    public static function coverageBrandSlug(array $ctx): string
    {
        $first = trim((string) (($ctx['brands'] ?? [])[0] ?? ''));
        if ($first === '') {
            $list = GeoCoverageCheck::parseList((string) ($ctx['brand'] ?? ''));
            $first = trim((string) ($list[0] ?? ''));
        }

        if ($first !== '') {
            $row = self::brandRowOf($first);
            $slug = $row !== null ? trim((string) ($row['slug'] ?? '')) : '';
            if ($slug !== '') {
                return $slug;
            }
        }

        return GeoShareOfVoice::resolveSlug();
    }

    /**
     * 本次核查登记的自有域名关键词 —— 「官网」的判定基准。
     *
     * 优先用会话里已经解析好的 domains（与逐题 judge() 用的是同一组），
     * 没有就从 url 现推一次，保证分块指标和逐题结论口径一致。
     *
     * @param  array<string,mixed>  $ctx
     * @return list<string>
     */
    private static function ownDomainKeys(array $ctx): array
    {
        $keys = [];
        foreach ((array) ($ctx['domains'] ?? []) as $d) {
            $d = trim((string) $d);
            if ($d !== '') {
                $keys[] = $d;
            }
        }
        if ($keys === []) {
            $keys = (array) (GeoCoverageCheck::domainOf((string) ($ctx['url'] ?? ''))['keys'] ?? []);
        }

        return array_values(array_unique($keys));
    }

    /**
     * 结论文案。
     *
     * 口径变化（这一版）：不再用「混算的前三率 / 覆盖率」写结论，
     * 因为 A 类是品牌词、覆盖率恒 100%，混算出来的数字会掩盖 B 类 0% 的真缺口。
     * 结论优先点出 B 类（品类场景）的官网覆盖缺口，其余按本次逐题计数说。
     *
     * @param  array<string,array<string,mixed>>  $cats
     * @return array{0:string,1:string} [文案, 左侧色条]
     */
    private static function verdict(int $valid, int $owned, int $mention, int $missed, array $cats = []): array
    {
        if ($valid === 0) {
            return ['本次没有成功核查任何题目（搜索全部失败）。请检查「搜索源」配置与网络后重跑。', '#b45309'];
        }

        // 最严重的真缺口先说：B 类（品类词）官网一条都没进榜
        $b = $cats['B'] ?? null;
        if (is_array($b) && (int) ($b['with_src'] ?? 0) > 0 && (int) $b['own_present'] === 0) {
            return ['<b>品类场景类的官网覆盖是 0。</b>B 类 '.(int) $b['with_src'].' 次有效采样里，'
                .'你的官网一次都没进榜，品牌名出现 '.(int) $b['brand_present'].' 次——'
                .'用户不带品牌名问「这类东西推荐什么」的时候，AI 完全不会想到你。'
                .'这是整份报告里最该先补的缺口，比排位靠后严重得多。'
                .'<br>A 类的口径不同（覆盖恒为 100%，没有区分度），看的是官网占位率与首位归属，见页首 A 区块。', '#b91c1c'];
        }

        if ($owned === 0 && $mention === 0) {
            return ['<b>一条都没进前 10。</b>这一批问题里，AI 搜索返回的网页完全没有你——'
                .'不是排得靠后，是根本没被检索到。原因通常两个：官网没被收录，或者页面内容与这些问题没有语义对应。'
                .'优先动作：先确认官网能被正常收录（robots/sitemap/结构化），再按这批问题逐条产出内容。', '#b91c1c'];
        }
        if ($owned === 0 && $mention > 0) {
            return ['<b>有讨论，没阵地。</b>'.$mention.' 个问题里别人写了你，但没有一条是你自己的页面。'
                .'这意味着 AI 搜索到的都是第三方表述，你无法控制口径。'
                .'优先动作：把这些问题的内容做到自己官网上，把话语权拿回来。', '#b91c1c'];
        }

        $rest = $missed + $mention;
        if ($rest > 0) {
            return ['<b>有阵地，但没铺满。</b>本次 '.$valid.' 题里，自有页面进前 10 '.$owned.' 题，'
                .'仅被提及 '.$mention.' 题，未进前 10 '.$missed.' 题。'
                .'剩下 '.$rest.' 个是可补的长尾——先补哪类，看页首「按问题类别看」里哪一类的官网覆盖最低。', '#b45309'];
        }

        return ['<b>这一批题的自有页面全部进了前 10。</b>下一步不是继续堆量，而是争位置与盯口碑：'
            .'位置靠后的争前三，被第三方提及的先把自己的落地页补上。', '#047857'];
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
