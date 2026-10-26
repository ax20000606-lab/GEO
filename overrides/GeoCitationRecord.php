<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;

/**
 * 引用样本录入页：把 `php artisan geoflow:citation:record` 的能力搬到界面上。
 *
 * 为什么需要这个页面：
 *   「豆包 App 里的对话」和「豆包搜索接口」是两套完全不同的信源池——
 *   同一个问题，对话里 10 条引用全是抖音，搜索接口返回的是值得买 / 百科 / 微博。
 *   对话的联网检索不开放 API，只能人工跑一遍再录入。
 *   原来靠手写 JSON 文件 + 跑命令，运营同学做不了，所以做成表单。
 *
 * 数据表：geo_citation_samples（已存在，本页不建表、不改表结构）
 *   query / source_type / rank / domain / title / topics(text[]) / note / created_at
 *
 * 口径归一说明（重要）：
 *   历史数据里 source_type 存的是 'doubao_chat' / 'doubao_search_api'，
 *   而 artisan 命令只接受 'chat' / 'api'。这里统一按「包含 chat / 包含 api」归一，
 *   列表合并展示、删除时两种写法一起删，避免同一个问题出现两批重复数据。
 *   写入时落的是历史口径 doubao_*（见 storedSourceType()），
 *   这样命令的 --replace（严格等值删除）也能覆盖到页面上录的数据，不会两种写法并存。
 */
final class GeoCitationRecord
{
    /** 数据表名（已存在） */
    private const TABLE = 'geo_citation_samples';

    /** 界面只暴露这两种口径，与 artisan 命令保持一致 */
    public const SOURCES = ['chat' => '豆包对话', 'api' => '搜索接口'];

    /**
     * 渲染整页 HTML（嵌在 admin.geo-tools.index 里，由路由传进来）。
     *
     * @param  string  $adminBase    后台前缀，如 /geo_admin
     * @param  string  $query        当前选中的问题（空=未选中）
     * @param  string  $source       当前选中的来源类型（chat / api）
     * @param  string  $notice       顶部提示文案（成功或失败）
     * @param  bool    $isError      true=红色错误条，false=绿色成功条
     * @param  array<int|string, mixed>  $pendingRows  校验失败时把用户刚填的内容原样回填，别让人重填
     */
    public static function page(
        string $adminBase,
        string $query = '',
        string $source = '',
        string $notice = '',
        bool $isError = false,
        array $pendingRows = []
    ): string {
        $base = $adminBase.'/citation-record';
        $query = trim($query);
        // 只有认识的来源类型才认，避免 URL 手改出一个查不到数据的过滤条件
        $source = isset(self::SOURCES[$source]) ? $source : '';

        $noticeHtml = '';
        if ($notice !== '') {
            $noticeHtml = $isError
                ? '<div class="g-err">'.self::e($notice).'</div>'
                : '<div class="g-note">'.self::e($notice).'</div>';
        }

        // 选中某一批时先把明细读出来：表单回填和下方统计复用同一份数据，只查一次
        $selected = ($query !== '' && $source !== '') ? self::samples($query, $source) : [];
        $batches = self::batches();

        $head = '<div style="margin-bottom:14px">'
            .'<h2 style="margin:0 0 4px;font-size:19px">引用样本录入</h2>'
            .'<p class="g-cap" style="margin:0">把「豆包 App 对话里引用了哪些内容」手工录进来，'
            .'不用再写 JSON 文件跑命令。录完的数据会进 AI 可见性 / 声量对标的信源统计。</p>'
            .'</div>';

        $body = $head
            .$noticeHtml
            .self::formBlock($base, $query, $source, $selected, $pendingRows)
            .self::listBlock($base, $batches, $query, $source)
            .(($query !== '' && $source !== '') ? self::detailBlock($query, $source, $selected) : '');

        return '<style>'.GeoUi::css().'</style>'
            .'<div class="geo-ui" data-geo-title="引用样本录入" '
            .'style="max-width:1040px;margin:0 auto;padding:4px 4px 48px;font:14px/1.7 var(--g-font);color:#111827">'
            .$body.'</div>';
    }

    // =========================================================================
    // 区块一：录入表单
    // =========================================================================

    /**
     * 录入表单：问题 + 来源类型 + 动态引用行。
     *
     * @param  list<array{rank:int,domain:string,title:string,topics:string}>  $existing     选中批次的已有明细（用于回填）
     * @param  array<int|string, mixed>                                      $pendingRows  校验失败时用户刚填的行（优先于 $existing）
     */
    private static function formBlock(string $base, string $query, string $source, array $existing, array $pendingRows = []): string
    {
        // 回填优先级：刚提交但因为校验失败被退回的内容 > 选中批次的库里内容 > 3 行空表单
        $prefill = $pendingRows !== [] ? self::rowsFromInput($pendingRows) : $existing;
        $isEdit = $pendingRows === [] && $existing !== [];

        $rowsHtml = '';
        $idx = 0;
        foreach ($prefill as $row) {
            $rowsHtml .= self::rowHtml((string) $idx, $row);
            $idx++;
        }
        if ($rowsHtml === '') {
            // 默认 3 行：豆包对话一般就是 3-5 条引用，给的起点刚好
            for ($i = 0; $i < 3; $i++) {
                $rowsHtml .= self::rowHtml((string) $i, []);
            }
            $idx = 3;
        }

        $src = $source !== '' ? $source : 'chat';
        $opts = '';
        foreach (self::SOURCES as $k => $label) {
            $opts .= '<option value="'.self::e($k).'"'.($k === $src ? ' selected' : '').'>'.self::e($label).'</option>';
        }

        $labelStyle = 'display:block;font-size:12px;color:#6b7280;margin-bottom:5px';
        $inputStyle = 'padding:8px 11px;border:1px solid #d1d5db;border-radius:9px;font-size:13px;background:#fff';

        $editTip = $isEdit
            ? '<div class="g-note" style="margin:0 0 12px">正在编辑已有批次（'.count($existing).' 条引用）。'
                .'<b>保存会先删掉这一批再整批写入</b>，所以删掉的行保存后就真没了。</div>'
            : '';
        if ($pendingRows !== []) {
            // 上面已经有一条红色的失败说明了，这里只补一句"内容没丢"，避免两个红框重复喊
            $editTip = '<div style="background:#f0f9ff;border:1px solid #bae6fd;color:#0c4a6e;'
                .'border-radius:10px;padding:10px 14px;font-size:12.5px;margin:0 0 12px">'
                .'下面是刚才填的内容，<b>已经原样保留</b>，改完再点一次「保存」就行。</div>';
        }

        return '<div class="g-card">'
            .'<h3>录入引用样本</h3>'
            .'<p class="g-cap">先把用户提的问题写上，再把豆包回答下面「引用了 N 个网页」那几条内容照抄进来。'
            .'同一个问题 + 同一种来源录第二遍会整批覆盖，不会叠加重复。</p>'
            .$editTip
            .'<form method="post" action="'.self::e($base).'" style="margin:0">'
            .self::csrfField()
            .'<div style="margin-bottom:14px">'
            .'<label for="cr-query" style="'.$labelStyle.'">问题（必填）</label>'
            .'<textarea id="cr-query" name="query" rows="2" required '
            .'placeholder="比如：秋天皮肤干燥 推荐一个国产护肤品牌" '
            .'style="'.$inputStyle.';width:100%;resize:vertical;line-height:1.6">'.self::e($query).'</textarea>'
            .'</div>'
            .'<div style="margin-bottom:14px;max-width:240px">'
            .'<label for="cr-source" style="'.$labelStyle.'">来源类型</label>'
            .'<select id="cr-source" name="source_type" style="'.$inputStyle.';width:100%">'.$opts.'</select>'
            .'<div style="font-size:11px;color:#9ca3af;margin-top:4px">'
            .'豆包对话 = App 里聊天，引用多为抖音；搜索接口 = 搜索返回的网页</div>'
            .'</div>'
            .'<div style="font-size:12px;color:#6b7280;margin:0 0 6px">引用明细<span '
            .'style="color:#9ca3af">（位次从 1 开始；标签用逗号分隔，如「护肤,面霜」）</span></div>'
            .'<table class="g-table" style="margin-bottom:10px">'
            .'<thead><tr>'
            .'<th style="width:66px">位次</th>'
            .'<th style="width:170px">平台域名</th>'
            .'<th>标题</th>'
            .'<th style="width:180px">话题标签</th>'
            .'<th style="width:64px"></th>'
            .'</tr></thead>'
            .'<tbody id="cr-rows">'.$rowsHtml.'</tbody>'
            .'</table>'
            .'<div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap">'
            .'<button type="button" id="cr-add" class="g-btn g-btn--ghost" style="font-size:12.5px;padding:7px 13px">+ 加一行</button>'
            .'<button type="submit" class="g-btn g-btn--primary" style="font-size:13px;padding:9px 20px">保存</button>'
            .'<span style="font-size:11.5px;color:#9ca3af">没有标题的行会被跳过（空行直接忽略）</span>'
            .'</div>'
            .'</form>'
            .'<script>(function(){'
            .'var tbody=document.getElementById("cr-rows"),addBtn=document.getElementById("cr-add");'
            .'if(!tbody||!addBtn)return;'
            .'var idx='.(int) $idx.';'
            // 新增行 = 克隆最后一行再清空 + 重排 name 里的序号。
            // 不用 <template> 存模板，是因为 <tr> 放进 template 要靠解析器特殊规则兜着，
            // 克隆真实行更稳，而且行结构只在 rowHtml() 里维护一份。
            .'function addRow(){'
            .'var last=tbody.querySelector("tr");'
            .'if(!last)return;'
            .'var node=last.cloneNode(true);'
            .'var ins=node.querySelectorAll("input");'
            .'for(var i=0;i<ins.length;i++){'
            .'ins[i].value="";'
            .'if(ins[i].name){ins[i].name=ins[i].name.replace(/\\[\\d+\\]/,"["+idx+"]");}'
            .'}'
            .'tbody.appendChild(node);idx++;'
            .'}'
            .'addBtn.addEventListener("click",addRow);'
            .'tbody.addEventListener("click",function(e){'
            .'var btn=e.target.closest?e.target.closest(".cr-del"):null;'
            .'if(!btn)return;'
            .'var tr=btn.closest("tr");'
            .'if(!tr)return;'
            .'if(tbody.querySelectorAll("tr").length<=1){'
            // 只剩一行时不允许删空，否则用户会以为表单坏了；改成清空内容
            .'var ins=tr.querySelectorAll("input");'
            .'for(var i=0;i<ins.length;i++){ins[i].value="";}'
            .'return;}'
            .'tr.parentNode.removeChild(tr);'
            .'});'
            .'})();</script>'
            .'</div>';
    }

    /**
     * 单行引用输入框。$i 是行序号，直接拼进 name（rows[0][rank] 这种格式 Laravel 能解析成数组）。
     *
     * @param  array{rank?:int|string,domain?:string,title?:string,topics?:string}  $row
     */
    private static function rowHtml(string $i, array $row): string
    {
        $rank = (string) ($row['rank'] ?? '');
        $domain = (string) ($row['domain'] ?? '');
        $title = (string) ($row['title'] ?? '');
        $topics = (string) ($row['topics'] ?? '');

        $in = 'padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:12.5px;width:100%;background:#fff';

        return '<tr>'
            .'<td style="vertical-align:top"><input type="number" min="1" step="1" name="rows['.$i.'][rank]" '
            .'value="'.self::e($rank).'" placeholder="1" style="'.$in.';text-align:center"></td>'
            .'<td style="vertical-align:top"><input type="text" name="rows['.$i.'][domain]" '
            .'value="'.self::e($domain).'" placeholder="douyin.com" style="'.$in.'"></td>'
            .'<td style="vertical-align:top"><input type="text" name="rows['.$i.'][title]" '
            .'value="'.self::e($title).'" placeholder="引用内容标题" style="'.$in.'"></td>'
            .'<td style="vertical-align:top"><input type="text" name="rows['.$i.'][topics]" '
            .'value="'.self::e($topics).'" placeholder="护肤,面霜" style="'.$in.'"></td>'
            .'<td style="vertical-align:top;text-align:center">'
            .'<button type="button" class="cr-del g-btn g-btn--ghost" style="padding:6px 10px;font-size:12px">删除</button>'
            .'</td></tr>';
    }

    /**
     * 把表单提交上来的 rows[] 还原成回填用的行结构（保留用户原样输入，不做校验）。
     *
     * @param  array<int|string, mixed>  $pending
     * @return list<array{rank:string,domain:string,title:string,topics:string}>
     */
    private static function rowsFromInput(array $pending): array
    {
        $out = [];
        foreach (array_values($pending) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'rank' => trim((string) ($row['rank'] ?? '')),
                'domain' => trim((string) ($row['domain'] ?? '')),
                'title' => trim((string) ($row['title'] ?? '')),
                'topics' => trim((string) ($row['topics'] ?? '')),
            ];
        }

        return $out;
    }

    // =========================================================================
    // 区块二：已录入问题列表
    // =========================================================================

    /**
     * 已录入问题列表：点一行看详情，行内可整批删除。
     *
     * @param  list<array{query:string,source:string,count:int,last_at:string}>  $batches
     */
    private static function listBlock(string $base, array $batches, string $query, string $source): string
    {
        $total = 0;
        foreach ($batches as $b) {
            $total += $b['count'];
        }

        $rows = '';
        foreach ($batches as $b) {
            $bq = $b['query'];
            $bs = $b['source'];
            $active = $bq === $query && $bs === $source;
            $link = $base.'?'.http_build_query(['query' => $bq, 'source' => $bs]);
            $label = mb_strlen($bq, 'UTF-8') > 60 ? mb_substr($bq, 0, 60, 'UTF-8').'…' : $bq;

            $rows .= '<tr'.($active ? ' style="background:#eef2ff"' : '').'>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px">'
                .'<a href="'.self::e($link).'" style="color:'.($active ? '#4f46e5' : '#374151').';'
                .'text-decoration:none;font-weight:'.($active ? '700' : '400').'">'.self::e($label).'</a></td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;white-space:nowrap">'
                .self::sourcePill($bs).'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:right">'.$b['count'].'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;color:#6b7280;white-space:nowrap">'
                .self::e(mb_substr($b['last_at'], 0, 16, 'UTF-8')).'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:6px 9px;text-align:center">'
                .'<form method="post" action="'.self::e($base).'" style="margin:0" '
                .'onsubmit="return confirm(\'删除这批引用样本？删掉就找不回来了。\')">'
                .self::csrfField()
                .'<input type="hidden" name="_action" value="delete">'
                .'<input type="hidden" name="query" value="'.self::e($bq).'">'
                .'<input type="hidden" name="source_type" value="'.self::e($bs).'">'
                .'<button type="submit" class="g-btn g-btn--ghost" style="padding:5px 11px;font-size:11.5px">删除这批</button>'
                .'</form></td>'
                .'</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" style="border:1px solid #e5e7eb;padding:14px 11px;color:#6b7280">'
                .'还没有录入任何引用样本。在上面填一个问题、抄几条引用，点保存就有了。</td></tr>';
        }

        return '<div class="g-card">'
            .'<h3>已录入的问题</h3>'
            .'<p class="g-cap">共 '.count($batches).' 个问题批次、'.$total.' 条引用。'
            .'点问题名会在页面下方展开这一批的明细统计。</p>'
            .'<table class="g-table"><thead><tr>'
            .'<th>问题</th><th style="width:96px">来源类型</th>'
            .'<th style="width:80px;text-align:right">引用条数</th>'
            .'<th style="width:150px">最近录入时间</th>'
            .'<th style="width:96px"></th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'</div>';
    }

    // =========================================================================
    // 区块三：选中批次的明细 + 标签频次 + 平台分布
    // =========================================================================

    /**
     * 选中批次的三块统计。
     *
     * @param  list<array{rank:int,domain:string,title:string,topics:string}>  $samples
     */
    private static function detailBlock(string $query, string $source, array $samples): string
    {
        $head = '<div class="g-card" style="background:#fafafa">'
            .'<h3 style="font-size:14px">当前查看：'.self::e(mb_strlen($query, 'UTF-8') > 40
                ? mb_substr($query, 0, 40, 'UTF-8').'…'
                : $query).' '.self::sourcePill($source).'</h3>'
            .'<p class="g-cap" style="margin:0">这一批共 '.count($samples).' 条引用。'
            .'下面的标签频次和平台分布只统计这一批，换一行问题就换一批数据。</p></div>';

        if ($samples === []) {
            return '<div class="g-card"><h3>引用明细</h3>'
                .'<p class="g-cap" style="margin:0">这个问题下没有查到数据（可能刚被删掉），重新录一遍即可。</p></div>';
        }

        // ---------- 引用明细 ----------
        $rows = '';
        foreach ($samples as $s) {
            $tags = '';
            if ($s['topics'] !== '') {
                foreach (explode(',', $s['topics']) as $t) {
                    $t = trim($t);
                    if ($t === '') {
                        continue;
                    }
                    $tags .= '<span class="g-tag" style="font-size:10.5px;margin-right:4px">'.self::e($t).'</span>';
                }
            }

            $rows .= '<tr>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:center;color:#6b7280">'
                .($s['rank'] > 0 ? $s['rank'] : '—').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;white-space:nowrap">'
                .self::e($s['domain'] !== '' ? $s['domain'] : '—').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px">'
                .self::e($s['title'] !== '' ? $s['title'] : '（无标题）').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;width:200px">'
                .($tags !== '' ? $tags : '<span style="color:#d1d5db">—</span>').'</td>'
                .'</tr>';
        }

        $detail = '<div class="g-card"><h3>引用明细</h3>'
            .'<p class="g-cap">按位次排。位次就是豆包回答里引用列表的先后顺序，1 最靠前。</p>'
            .'<table class="g-table"><thead><tr>'
            .'<th style="width:60px;text-align:center">位次</th><th style="width:180px">域名</th>'
            .'<th>标题</th><th style="width:200px">标签</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>';

        // ---------- 标签频次 Top 10 ----------
        $topics = self::topTopics($query, $source, 10);
        $topicHtml = '<div class="g-card"><h3>标签频次 Top 10</h3>'
            .'<p class="g-cap">这批引用里被反复打上的标签——出现次数越多，说明这个角度是'
            .'<b>AI 认可的主流叙事</b>，写内容时优先贴着这些角度写。</p>';

        if ($topics === []) {
            $topicHtml .= '<p class="g-cap" style="margin:0">这批引用还没打过标签。'
                .'在表单里给每行补上「话题标签」，保存后这里就能看了。</p></div>';
        } else {
            $max = 1;
            foreach ($topics as $t) {
                $max = max($max, $t['count']);
            }
            $bars = '';
            foreach ($topics as $i => $t) {
                $w = (int) round($t['count'] / $max * 100);
                $bars .= '<div style="display:flex;align-items:center;gap:9px;margin:6px 0">'
                    .'<span style="width:18px;font-size:11px;color:#9ca3af;text-align:right">'.($i + 1).'</span>'
                    .'<span style="width:110px;font-size:12.5px;color:#111827;overflow:hidden;'
                    .'text-overflow:ellipsis;white-space:nowrap" title="'.self::e($t['topic']).'">'
                    .self::e($t['topic']).'</span>'
                    .'<span style="flex:1;height:9px;background:#f3f4f6;border-radius:99px;overflow:hidden">'
                    .'<span style="display:block;height:100%;width:'.$w.'%;background:#4f46e5;border-radius:99px"></span></span>'
                    .'<span style="width:38px;text-align:right;font-size:12.5px;font-weight:700;color:#4f46e5">'
                    .$t['count'].'</span>'
                    .'</div>';
            }
            $topicHtml .= $bars
                .'<p class="g-cap" style="margin:12px 0 0">口径：把每行的 <code>topics</code> 数组展开后计数，'
                .'同一个标签在一条引用里重复写只算一次。</p></div>';
        }

        // ---------- 平台分布 ----------
        $domains = self::domainStats($query, $source);
        $domainHtml = '<div class="g-card"><h3>平台分布</h3>'
            .'<p class="g-cap">这批引用来自哪些平台。全部集中在单一平台（比如全是抖音）是很强的信号：'
            .'说明这条问法下 AI 只认那一个池子，别的地方写再多也进不去。</p>';

        if ($domains === []) {
            $domainHtml .= '<p class="g-cap" style="margin:0">这批引用没有填域名。</p></div>';
        } else {
            $total = 0;
            foreach ($domains as $d) {
                $total += $d['count'];
            }
            $dRows = '';
            foreach ($domains as $d) {
                $pct = $total > 0 ? round($d['count'] / $total * 100, 1) : 0;
                $dRows .= '<tr>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px">'
                    .self::e($d['domain'] !== '' ? $d['domain'] : '（未填域名）').'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:right">'.$d['count'].'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:right;'
                    .'font-weight:700;color:#4f46e5">'.$pct.'%</td>'
                    .'</tr>';
            }
            $domainHtml .= '<table class="g-table"><thead><tr>'
                .'<th>域名</th><th style="width:100px;text-align:right">引用条数</th>'
                .'<th style="width:100px;text-align:right">占比</th>'
                .'</tr></thead><tbody>'.$dRows.'</tbody></table>'
                .'<p class="g-cap" style="margin:12px 0 0">共 '.count($domains).' 个域名、'.$total.' 条引用。</p></div>';
        }

        return $head.$detail.$topicHtml.$domainHtml;
    }

    // =========================================================================
    // 写库（供路由调用）
    // =========================================================================

    /**
     * 保存一批引用样本：同一 query + source_type 先删后插（覆盖式）。
     *
     * @param  array<int|string, mixed>  $rows  表单 rows[] 原始输入
     * @return array{inserted:int,skipped:int,query:string}  query 是实际落库的值（可能被截到 500 字）
     *
     * @throws \RuntimeException 输入不合法时抛出，路由捕获后回显
     */
    public static function save(string $query, string $source, array $rows): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \RuntimeException('问题不能为空，请先填写用户提的问题。');
        }
        if (mb_strlen($query, 'UTF-8') > 500) {
            $query = mb_substr($query, 0, 500, 'UTF-8');
        }

        $source = trim($source);
        if (! isset(self::SOURCES[$source])) {
            throw new \RuntimeException('来源类型只能是「豆包对话」或「搜索接口」。');
        }
        // 写库用历史口径 doubao_chat / doubao_search_api：和库里现有 20 条、
        // 以及 artisan geoflow:citation:record 的 --replace（严格等值删除）保持一致。
        // 只存 chat / api 的话，用命令 --replace 覆盖同一批时会删不掉旧行，两种写法并存，
        // 而列表又按「包含 chat/api」归一成一个批次，重复会被藏起来看不见。
        $storedSource = self::storedSourceType($source);

        $clean = [];
        $skipped = 0;
        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                $skipped++;
                continue;
            }
            $rankRaw = trim((string) ($row['rank'] ?? ''));
            $domain = trim((string) ($row['domain'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $topicsRaw = trim((string) ($row['topics'] ?? ''));

            // 整行都空 = 用户没填，不算错误，直接忽略
            if ($rankRaw === '' && $domain === '' && $title === '' && $topicsRaw === '') {
                continue;
            }
            // 没标题的行没有统计价值（和 artisan 命令行为一致：跳过而不是存半条）
            if ($title === '') {
                $skipped++;
                continue;
            }

            $rank = (int) $rankRaw;
            $clean[] = [
                'rank' => $rank > 0 ? $rank : (count($clean) + 1),
                'domain' => mb_substr($domain, 0, 240, 'UTF-8'),
                'title' => mb_substr($title, 0, 1000, 'UTF-8'),
                'topics' => self::parseTopics($topicsRaw),
            ];
        }

        if ($clean === []) {
            throw new \RuntimeException('没有可保存的引用行：每一行至少要有标题。');
        }

        $inserted = 0;
        DB::transaction(function () use ($query, $storedSource, $source, $clean, &$inserted): void {
            // 覆盖式：先删掉这一批的旧数据（含历史 doubao_* 写法），再整批写入
            DB::table(self::TABLE)
                ->where('query', $query)
                ->whereIn('source_type', self::sourceVariants($source))
                ->delete();

            foreach ($clean as $row) {
                DB::table(self::TABLE)->insert([
                    'query' => $query,
                    'source_type' => $storedSource,
                    'rank' => $row['rank'],
                    'domain' => $row['domain'],
                    'title' => $row['title'],
                    'topics' => self::pgArrayLiteral($row['topics']),
                    'note' => null,
                    'created_at' => now(),
                ]);
                $inserted++;
            }
        }, 1);

        // 把实际落库的 query 带回去：超 500 字时会被截断，路由必须用这个值拼回跳地址，
        // 否则回跳页查的是截断前的原文，会出现「已保存 N 条」+「没有查到数据」并存。
        return ['inserted' => $inserted, 'skipped' => $skipped, 'query' => $query];
    }

    /**
     * 删除一批引用样本（两种 source_type 写法一起删）。
     *
     * @throws \RuntimeException
     */
    public static function deleteBatch(string $query, string $source): int
    {
        $query = trim($query);
        $source = trim($source);
        if ($query === '') {
            throw new \RuntimeException('删除失败：缺少问题内容。');
        }
        if (! isset(self::SOURCES[$source])) {
            throw new \RuntimeException('删除失败：来源类型不合法。');
        }

        return (int) DB::table(self::TABLE)
            ->where('query', $query)
            ->whereIn('source_type', self::sourceVariants($source))
            ->delete();
    }

    // =========================================================================
    // 读库
    // =========================================================================

    /**
     * 已录入的问题批次（按来源类型归一后聚合）。
     *
     * @return list<array{query:string,source:string,count:int,last_at:string}>
     */
    private static function batches(): array
    {
        $expr = self::sourceExpr();
        $rows = DB::table(self::TABLE)
            ->selectRaw('query, '.$expr.' as src, count(*) as cnt, max(created_at) as last_at')
            ->groupByRaw('query, '.$expr)
            ->orderByRaw('max(created_at) desc')
            ->limit(300)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'query' => (string) ($r->query ?? ''),
                'source' => (string) ($r->src ?? ''),
                'count' => (int) ($r->cnt ?? 0),
                'last_at' => (string) ($r->last_at ?? ''),
            ];
        }

        return $out;
    }

    /**
     * 某一批的引用明细。
     *
     * @return list<array{rank:int,domain:string,title:string,topics:string}>
     */
    private static function samples(string $query, string $source): array
    {
        $rows = DB::table(self::TABLE)
            ->select(['rank', 'domain', 'title'])
            ->selectRaw("coalesce(array_to_string(topics, ','), '') as topics_str")
            ->where('query', $query)
            ->whereIn('source_type', self::sourceVariants($source))
            ->orderBy('rank')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'rank' => (int) ($r->rank ?? 0),
                'domain' => (string) ($r->domain ?? ''),
                'title' => (string) ($r->title ?? ''),
                'topics' => (string) ($r->topics_str ?? ''),
            ];
        }

        return $out;
    }

    /**
     * 标签频次 Top N：把 text[] 展开（unnest）后计数。
     *
     * @return list<array{topic:string,count:int}>
     */
    private static function topTopics(string $query, string $source, int $limit = 10): array
    {
        $variants = self::sourceVariants($source);
        $in = implode(', ', array_fill(0, count($variants), '?'));
        $sql = 'select t, count(*) as c from ('
            .'select unnest(topics) as t from '.self::TABLE
            .' where query = ? and source_type in ('.$in.')'
            .") s where t is not null and t <> '' group by 1 order by 2 desc, 1 limit ".(int) $limit;

        $rows = DB::select($sql, array_merge([$query], $variants));

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['topic' => (string) ($r->t ?? ''), 'count' => (int) ($r->c ?? 0)];
        }

        return $out;
    }

    /**
     * 平台（域名）分布。
     *
     * @return list<array{domain:string,count:int}>
     */
    private static function domainStats(string $query, string $source): array
    {
        $rows = DB::table(self::TABLE)
            ->selectRaw('domain, count(*) as c')
            ->where('query', $query)
            ->whereIn('source_type', self::sourceVariants($source))
            ->groupBy('domain')
            ->orderByRaw('count(*) desc')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['domain' => (string) ($r->domain ?? ''), 'count' => (int) ($r->c ?? 0)];
        }

        return $out;
    }

    // =========================================================================
    // 小工具
    // =========================================================================

    /**
     * 来源类型归一表达式：历史数据是 doubao_chat / doubao_search_api，
     * 界面统一按 chat / api 展示，否则同一个问题会在列表里出现两行。
     */
    private static function sourceExpr(): string
    {
        return "case when source_type like '%chat%' then 'chat'"
            ." when source_type like '%api%' then 'api'"
            .' else source_type end';
    }

    /**
     * 一个来源类型对应的所有库里写法（读取、删除都要覆盖到）。
     *
     * @return list<string>
     */
    private static function sourceVariants(string $source): array
    {
        return match ($source) {
            'chat' => ['chat', 'doubao_chat'],
            'api' => ['api', 'doubao_search_api'],
            default => [$source],
        };
    }

    /**
     * 界面口径（chat / api）→ 落库口径（doubao_chat / doubao_search_api）。
     *
     * 为什么落库要写历史写法：库里已有 20 条是 doubao_*，而 artisan 命令的 --replace
     * 是严格等值删除。新数据写 chat 的话，用命令覆盖同一批会删不掉旧行，
     * 结果是 10 条 doubao_chat + N 条 chat 并存；列表又按「包含 chat/api」归一成一个批次，
     * 重复会被隐藏掉，谁都看不出来。统一写 doubao_* 就没有这个问题，也不用迁移历史数据。
     */
    private static function storedSourceType(string $source): string
    {
        return match ($source) {
            'chat' => 'doubao_chat',
            'api' => 'doubao_search_api',
            default => $source,
        };
    }

    /** 逗号分隔的标签串 → 去重后的标签数组（中英文逗号、顿号都认） */
    private static function parseTopics(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/[,，、;；]+/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $t = trim((string) $p);
            if ($t === '') {
                continue;
            }
            $t = mb_substr($t, 0, 40, 'UTF-8');
            if (! in_array($t, $out, true)) {
                $out[] = $t;
            }
            if (count($out) >= 20) {
                break;
            }
        }

        return $out;
    }

    /**
     * PHP 数组 → PostgreSQL 数组字面量（与 artisan 命令里的写法保持一致）。
     *
     * @param  list<string>  $topics
     */
    private static function pgArrayLiteral(array $topics): ?string
    {
        if ($topics === []) {
            return null;
        }

        return '{'.implode(',', array_map(
            static fn (string $t): string => '"'.str_replace('"', '\\"', $t).'"',
            $topics
        )).'}';
    }

    /** 来源类型小标签 */
    private static function sourcePill(string $source): string
    {
        $label = self::SOURCES[$source] ?? ($source !== '' ? $source : '未知');
        $color = $source === 'chat' ? '#4f46e5' : '#047857';
        $bg = $source === 'chat' ? '#eef2ff' : '#ecfdf5';

        return '<span class="g-pill" style="background:'.$bg.';color:'.$color.';font-size:11px">'
            .self::e($label).'</span>';
    }

    /** CSRF 隐藏域（表单必须带，否则 POST 会被 419 拦掉） */
    private static function csrfField(): string
    {
        $token = '';
        try {
            $token = (string) csrf_token();
        } catch (\Throwable) {
            $token = '';
        }

        return $token === '' ? '' : '<input type="hidden" name="_token" value="'.self::e($token).'">';
    }

    private static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
