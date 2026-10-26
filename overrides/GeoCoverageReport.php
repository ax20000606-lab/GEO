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
     * @param  array<string,mixed>  $old
     * @param  list<object>  $libraries
     */
    public static function formPage(array $old = [], array $libraries = [], string $error = ''): string
    {
        $brand = self::e((string) ($old['brand'] ?? ''));
        $url = self::e((string) ($old['url'] ?? ''));
        $competitors = self::e((string) ($old['competitors'] ?? ''));
        $questions = self::e((string) ($old['questions'] ?? ''));
        $lib = (int) ($old['lib'] ?? 0);
        $limit = (int) ($old['limit'] ?? 12);
        $rounds = (int) ($old['rounds'] ?? 3);
        if (! in_array($rounds, [1, 3, 5], true)) {
            $rounds = 3;
        }

        $libOptions = '<option value="0">不使用词库，只用下面手填的问题</option>';
        foreach ($libraries as $row) {
            $libOptions .= '<option value="'.(int) $row->id.'"'.($lib === (int) $row->id ? ' selected' : '').'>'
                .self::e((string) $row->name).'（'.((int) ($row->keyword_count ?? 0)).' 词，优先取问句）</option>';
        }

        $inputStyle = 'width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13.5px';
        $labelStyle = 'font-size:12.5px;font-weight:600;color:#374151';
        $err = $error !== '' ? '<div class="g-err">'.self::e($error).'</div>' : '';

        $body = '<div class="g-card"><h3>覆盖核查</h3>'
            .'<p class="g-cap">把用户会问的问题逐条丢给 AI 搜索，看<b>真实推荐位</b>里有没有你；没进前 10 的直接生成内容任务</p>'
            .$err
            .'<form method="get" action="" id="cov-form">'
            .'<input type="hidden" name="plan" value="1">'
            .'<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">'
            .'<label style="'.$labelStyle.'">品牌 / 产品名（必填，支持别名）'
            .'<input type="text" name="brand" value="'.$brand.'" placeholder="例如：冰溪　或　冰溪,冰溪生物" style="'.$inputStyle.'"></label>'
            .'<label style="'.$labelStyle.'">自有站点 URL（必填，用来认领推荐位）'
            .'<input type="text" name="url" value="'.$url.'" placeholder="例如：https://www.binsis.cn/" style="'.$inputStyle.'"></label>'
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
            .'<label style="'.$labelStyle.';display:flex;align-items:center;gap:8px">本次核查题数'
            .'<select name="limit" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:9px;font-size:13px">'
            .'<option value="6"'.($limit === 6 ? ' selected' : '').'>6 题</option>'
            .'<option value="12"'.($limit === 12 ? ' selected' : '').'>12 题</option>'
            .'<option value="20"'.($limit === 20 ? ' selected' : '').'>20 题</option>'
            .'</select></label>'
            .'<label style="'.$labelStyle.';display:flex;align-items:center;gap:8px">每题材采样'
            .'<select name="rounds" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:9px;font-size:13px">'
            .'<option value="1"'.($rounds === 1 ? ' selected' : '').'>1 次（快）</option>'
            .'<option value="3"'.($rounds === 3 ? ' selected' : '').'>3 次（推荐）</option>'
            .'<option value="5"'.($rounds === 5 ? ' selected' : '').'>5 次（严谨）</option>'
            .'</select></label>'
            .'<button type="submit" class="g-btn g-btn--primary" id="cov-submit">开始核查</button>'
            .'<span class="g-cap" style="margin:0" id="cov-eta">每题约 '.($rounds * 5).' 秒 × '.$limit.' 题 ≈ '.ceil($limit * $rounds * 5 / 60).' 分钟</span></div>'
            .'</form></div>'
            .'<div class="g-card"><h3>为什么要重复采样</h3>'
            .'<p class="g-cap">搜索结果本身有波动。只跑一次说「我排第 3」经不起追问；'
            .'跑 3 次说「3 次里 2 次进前三（67%）」才是能拿去汇报的数据。</p>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr><th style="width:110px">采样次数</th><th>得到的结论</th><th style="width:110px">成本</th></tr></thead><tbody>'
            .'<tr><td>1 次</td><td>单点结果，会说「排第几」但不知道稳不稳</td><td>1 倍</td></tr>'
            .'<tr><td><b>3 次</b></td><td><b>能给出命中率与前三率，波动看得见</b></td><td>3 倍</td></tr>'
            .'<tr><td>5 次</td><td>适合关键问题做严谨基线</td><td>5 倍</td></tr>'
            .'</tbody></table>'
            .'<p class="g-cap" style="margin:10px 0 0">成本参考：DeepSeek 搜索 1000 次 5 元，'
            .'12 题 × 3 次 = 36 次 ≈ 0.18 元。每轮都是一条独立采样，都会进数据中心。</p></div>'
            .'<script>(function(){var f=document.getElementById("cov-form");if(!f)return;'
            .'var eta=document.getElementById("cov-eta"),lim=f.querySelector("select[name=limit]"),rnd=f.querySelector("select[name=rounds]");'
            .'function syncEta(){if(!eta||!lim||!rnd)return;var n=parseInt(lim.value,10)||12,r=parseInt(rnd.value,10)||1;'
            .'var sec=n*r*5;eta.textContent="每题约 "+(r*5)+" 秒 × "+n+" 题 ≈ "+(sec<60?sec+" 秒":Math.ceil(sec/60)+" 分钟");}'
            .'if(lim)lim.addEventListener("change",syncEta);if(rnd)rnd.addEventListener("change",syncEta);syncEta();'
            .'f.addEventListener("submit",function(ev){var b=document.getElementById("cov-submit");'
            .'var brand=f.querySelector("input[name=brand]");var url=f.querySelector("input[name=url]");'
            .'if(brand&&!brand.value.trim()){ev.preventDefault();brand.focus();brand.style.borderColor="#ef4444";return;}'
            .'if(url&&!url.value.trim()){ev.preventDefault();url.focus();url.style.borderColor="#ef4444";return;}'
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
            .'<a class="g-btn g-btn--primary" id="cov-goto-report" href="'.$adminBase.'/coverage-check?report=1" style="display:none">查看核查报告</a>'
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
     */
    private static function authorityGapSection(array $ctx): string
    {
        $brandSlug = GeoShareOfVoice::resolveSlug();
        $brand = GeoShareOfVoice::brand($brandSlug);
        $brandName = $brand !== null ? trim((string) ($brand['name'] ?? '')) : '';
        $brandLabel = $brandName !== '' ? $brandName : ($brandSlug !== '' ? $brandSlug : '当前品牌');

        $samples = [];
        $error = '';
        try {
            $samples = GeoCoverageCheck::authorityGapSamples(self::ownDomainKeys($ctx), $brandSlug);
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 140, 'UTF-8');
        }

        return self::authorityGapHtml($samples, $brandLabel, $error);
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

    /**
     * 报告页
     *
     * @param  array<string,mixed>  $ctx
     * @param  list<array<string,mixed>>  $results
     */
    public static function reportPage(array $ctx, array $results, string $adminBase): string
    {
        $brand = (string) ($ctx['brand'] ?? '');
        $url = (string) ($ctx['url'] ?? '');

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
                GeoShareOfVoice::resolveSlug()
            );
            $categoryStats = (array) ($breakdown['categories'] ?? []);
            $categoryMeta = [
                'brand' => (array) ($breakdown['brand'] ?? []),
                'brands' => (array) ($breakdown['brands'] ?? []),
                'own_sites' => (array) ($breakdown['own_sites'] ?? []),
                'all_questions' => (array) ($breakdown['all_questions'] ?? []),
                'requested_slug' => GeoShareOfVoice::resolveSlug(),
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

        $catBlock = self::categorySection($categoryStats, $categoryMeta, $categoryError);

        // ---------- 本次核查（逐题） ----------
        // 这里只放"计数"和逐题情况；比率类指标全部按类别放到上面的分块里，
        // 避免再出现一个把 A/B/C/D 混在一起的总覆盖率。
        //
        // 会话里的这批题不一定属于页首分块的那个品牌（例如用 ?brand= 切了品牌），
        // 两块数字并排却不是一个品牌，必须显式提醒，否则很容易被误读成同一套结论。
        //
        // 比较前两边都要归一成 slug 再比：用户填的是 `binsis`（slug）还是「冰溪」
        // （品牌名）其实是同一个品牌，直接比字符串会误报。
        $panelBrand = trim((string) ($categoryMeta['brand']['name'] ?? ''));
        $sessionBrand = trim($brand);
        $panelKey = self::brandKeyOf((string) ($categoryMeta['brand']['slug'] ?? ''));
        $sessionKey = self::brandKeyOf($sessionBrand);
        $brandMismatch = $panelKey !== '' && $sessionKey !== '' && $panelKey !== $sessionKey
            ? '<div class="g-err" style="margin:0 0 12px">注意：本次核查这一批题是「'.self::e($sessionBrand)
                .'」的会话结果，和页首分块（'.self::e($panelBrand)
                .'）不是同一个品牌。<b>上面分块只统计'.self::e($panelBrand).'</b>；'
                .'要看'.self::e($panelBrand).'的逐题结果，请用 ?brand= 切到该品牌后重新核查。</div>'
            : '';

        $summary = '<div class="g-card"><h3>本次核查逐题结果</h3>'
            .$brandMismatch
            .'<p class="g-cap">品牌：<b>'.self::e($brand).'</b>'
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
                .'<div class="g-row" style="margin-top:16px">'
                .'<a class="g-btn g-btn--primary" id="cov-to-task" href="'.$adminBase.'/coverage-check?to-tasks=1&picked=all">按勾选生成任务草稿</a>'
                .'<span class="g-cap" style="margin:0">生成 1 个标题库 + 1 个任务（暂停态），每个问题产出一篇文章</span></div>'
                .'<script>(function(){var all=document.getElementById("cov-all");var boxes=document.querySelectorAll(".cov-pick");'
                .'var btn=document.getElementById("cov-to-task");'
                .'function sync(){var ids=[];for(var i=0;i<boxes.length;i++){if(boxes[i].checked){ids.push(boxes[i].value);}}'
                .'btn.setAttribute("href","'.$adminBase.'/coverage-check?to-tasks=1&picked="+(ids.join(",")||"none"));'
                .'btn.style.opacity=ids.length?"1":".45";}'
                .'if(all){all.addEventListener("change",function(){for(var i=0;i<boxes.length;i++){boxes[i].checked=all.checked;}sync();});}'
                .'for(var i=0;i<boxes.length;i++){boxes[i].addEventListener("change",sync);}sync();})();</script></div>';
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
        $authorityBlock = self::authorityGapSection($ctx);

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
            .'<span class="g-sep">结果保存在本次会话中，刷新不丢；重新核查会覆盖</span>'
            .'</div>'
            .$catBlock.$summary.$ownedBlock.$todoBlock.$authorityBlock.$failedBlock.$bounds,
            '覆盖核查报告'
        );
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
     */
    private static function categorySection(array $stats, array $meta, string $error): string
    {
        $brand = (array) ($meta['brand'] ?? []);
        $brandName = trim((string) ($brand['name'] ?? ''));
        $brandSlug = trim((string) ($brand['slug'] ?? ''));
        $ownSites = (array) ($meta['own_sites'] ?? []);
        $allQuestions = (array) ($meta['all_questions'] ?? []);

        // 页头必须写明"这是哪个品牌的数"，否则切换品牌后数字变了没人知道为什么
        $switch = [];
        foreach ((array) ($meta['brands'] ?? []) as $b) {
            $slug = trim((string) ($b['slug'] ?? ''));
            if ($slug !== '' && $slug !== $brandSlug) {
                $switch[] = $slug;
            }
        }
        // ?brand= 写了一个品牌档案里没有的 slug 时，GeoShareOfVoice 会静默回退到默认品牌；
        // 这里必须说出来，否则用户看到的品牌名和 URL 对不上
        $requested = trim((string) ($meta['requested_slug'] ?? ''));
        $fallbackNote = ($requested !== '' && $brandSlug !== '' && $requested !== $brandSlug)
            ? '（'.self::e($requested).' 不在品牌档案里，已回退到默认品牌）'
            : '';

        $brandLine = '';
        if ($brandName !== '' || $brandSlug !== '') {
            $brandLine = '<p class="g-cap" style="margin:0 0 10px">当前品牌：<b>'.self::e($brandName !== '' ? $brandName : $brandSlug).'</b>'
                .($brandSlug !== '' ? '（'.self::e($brandSlug).'）' : '')
                .($fallbackNote !== '' ? ' <span style="color:#b45309">'.self::e($fallbackNote).'</span>' : '')
                .($switch !== [] ? '　·　切换品牌请用 '.self::e('?brand='.$switch[0]) : '')
                .'</p>';
        }

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
        $needle = mb_strtolower(trim($nameOrSlug), 'UTF-8');
        if ($needle === '') {
            return '';
        }

        foreach (GeoShareOfVoice::brands() as $b) {
            $slug = mb_strtolower(trim((string) ($b['slug'] ?? '')), 'UTF-8');
            $candidates = [$slug];
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
                return $slug !== '' ? $slug : $needle;
            }
        }

        return $needle;
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
