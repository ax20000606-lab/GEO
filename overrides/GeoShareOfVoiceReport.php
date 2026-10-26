<?php

namespace App\Support\GeoAudit;

/**
 * 声量对标页面：SOV 竞品份额 + 引用来源结构
 *
 * 为什么两件事放一页：
 *   SOV 说"我占多少份额"，信源结构说"这份额凭什么来的"。
 *   只看 SOV 会把"内容问题"和"信任问题"混为一谈，而这两件事的动作完全相反。
 */
final class GeoShareOfVoiceReport
{
    /**
     * @param  array<string,mixed>  $sov
     * @param  array<string,mixed>  $src
     * @param  list<array{keyword:string,runs:int}>  $keywords
     * @param  array{days:int,keyword:string}  $opts
     */
    public static function page(array $sov, array $src, array $keywords, array $libraries, array $pageLevel, array $compMap, array $selfTalk, array $opts, string $adminBase, string $brandName, string $brandSlug, array $cross = []): string
    {
        $base = $adminBase.'/share-of-voice';
        $brands = GeoShareOfVoice::brands();

        // ---------- 品牌切换 + 范围筛选 ----------
        $brandTabs = '';
        foreach ($brands as $b) {
            $on = (string) ($b['slug'] ?? '') === $brandSlug;
            $brandTabs .= '<a class="g-btn '.($on ? 'g-btn--primary' : 'g-btn--ghost').'" '
                .'style="font-size:12.5px;padding:6px 13px" href="'.$base.'?brand='.urlencode((string) ($b['slug'] ?? '')).'">'
                .self::e((string) ($b['name'] ?? '')).'</a>';
        }

        $days = (int) ($opts['days'] ?? 0);
        $scopeRaw = (string) ($opts['scope_raw'] ?? '');
        $catLabels = ['A' => '品牌认知', 'B' => '品类场景', 'C' => '产品深挖', 'D' => '竞品对比', 'E' => '泛需求词'];

        $sel = static function ($a, $b): string {
            return (string) $a === (string) $b ? ' selected' : '';
        };

        $brandOpts = '';
        foreach ($brands as $b) {
            $bs = (string) ($b['slug'] ?? '');
            $brandOpts .= '<option value="'.self::e($bs).'"'.$sel($bs, $brandSlug).'>'
                .self::e((string) ($b['name'] ?? $bs)).'</option>';
        }

        $dayOpts = '<option value="0"'.$sel($days, 0).'>全部时间</option>'
            .'<option value="30"'.$sel($days, 30).'>最近 30 天</option>'
            .'<option value="7"'.$sel($days, 7).'>最近 7 天</option>';

        // 「看哪批问题」：按品牌过滤词库。每个库已经只装一类，所以不需要再选分类。
        // 库名格式固定为「品牌｜类别 中文名」，一眼能看出跑的是哪类题。
        $scopeOpts = '<option value=""'.$sel($scopeRaw, '').'>全部问题（三类合并）</option>';
        foreach ($libraries as $lb) {
            if ($brandName !== '' && ! str_contains((string) $lb['name'], $brandName)) {
                continue;
            }
            $cats = array_values(array_intersect(['B', 'C', 'D'], (array) $lb['categories']));
            if ($cats === []) {
                continue;
            }
            $lid = (int) $lb['id'];
            $kws = [];
            foreach ($keywords as $k) {
                if ((int) ($k['library_id'] ?? 0) === $lid) {
                    $kws[] = $k;
                }
            }
            if ($kws === []) {
                continue;
            }

            $scopeOpts .= '<option value="lib:'.$lid.'"'.$sel($scopeRaw, 'lib:'.$lid).'>'
                .self::e((string) $lb['name']).'（'.count($kws).' 题）</option>';
            foreach ($kws as $k) {
                $v = 'kw:'.$k['keyword'];
                $scopeOpts .= '<option value="'.self::e($v).'"'.$sel($scopeRaw, $v).'>'
                    .'　　'.self::e(mb_substr($k['keyword'], 0, 28, 'UTF-8')).'</option>';
            }
        }

        $sStyle = 'padding:7px 10px;border:1px solid #d1d5db;border-radius:9px;font-size:12.5px;background:#fff';
        $lStyle = 'font-size:11.5px;color:#6b7280;margin-right:4px';

        $filterBar = '<form method="get" action="'.$base.'" id="sov-filter" '
            .'style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:18px 0 12px;'
            .'background:#fff;border:1px solid #e5e7eb;border-radius:11px;padding:12px 16px">'
            .'<span style="'.$lStyle.'">品牌</span>'
            .'<select name="brand" onchange="this.form.submit()" style="'.$sStyle.'">'.$brandOpts.'</select>'
            .'<span style="'.$lStyle.'">时间</span>'
            .'<select name="days" onchange="this.form.submit()" style="'.$sStyle.'">'.$dayOpts.'</select>'
            .'<span style="'.$lStyle.'">看哪批问题</span>'
            .'<select name="scope" onchange="this.form.submit()" style="'.$sStyle.';max-width:360px">'.$scopeOpts.'</select>'
            .'</form>';

        // 单题样本很少，必须提示，否则会拿一道题的波动当结论
        // 双保险：scope 以 kw: 开头，或者 keyword 参数非空，都算单题
        $isSingle = str_starts_with($scopeRaw, 'kw:') || trim((string) ($opts['keyword'] ?? '')) !== '';
        $singleKw = str_starts_with($scopeRaw, 'kw:')
            ? ltrim(substr($scopeRaw, 3))
            : trim((string) ($opts['keyword'] ?? ''));
        $scopeHint = '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:11px 15px;'
            .'font-size:12.5px;line-height:1.75;margin-bottom:14px;color:#0c4a6e">'
            .($isSingle
                ? '<b>你正在看单独一道题。</b>样本只有这一题的采样次数，数字波动会很大，'
                    .'适合看"这道题里谁排在前面、信源是谁"，<b>不适合拿来下结论</b>。'
                    .'想看趋势请选「整个词库」或「某一类」。'
                : '<b>三类问题的生态位不同：</b>B 品类场景（用户说需求）决定你能不能进候选池；'
                    .'C 产品深挖看具体 SKU 有没有进候选；D 竞品对比看你在对比名单里排第几。'
                    .'品牌认知（A）不在这个页面看，归「覆盖核查」。')
            .'当前共 '.(int) ($sov['runs'] ?? 0).' 次采样、'.(int) ($sov['sources'] ?? 0).' 条来源。'
            .($isSingle && $singleKw !== '' ? self::titleGenBlock($adminBase, $brandSlug, $singleKw) : '')
            .'</div>';

        // ---------- 数据不够时的提示 ----------
        if (($sov['runs'] ?? 0) === 0 || empty($sov['brands'])) {
            // 单题没采样时也要能补内容——没数据恰恰说明这题最该攻，不能只给一句"没数据"就完了
            $emptyKw = str_starts_with($scopeRaw, 'kw:')
                ? ltrim(substr($scopeRaw, 3))
                : trim((string) ($opts['keyword'] ?? ''));
            $emptyExtra = $emptyKw !== '' ? self::titleGenBlock($adminBase, $brandSlug, $emptyKw) : '';

            return self::shell(
                '<div class="g-card"><h3>声量对标</h3>'
                .'<p class="g-cap">'
                .($emptyKw !== ''
                    ? '<b>「'.self::e($emptyKw).'」这道题还没有采样数据</b>，所以算不出份额。'
                        .'要么先跑一次覆盖核查拿到数据，要么直接按下面生成标题把它补起来。'
                    : '当前筛选条件下没有采集数据。先去 <a href="'.self::e($adminBase.'/coverage-check').'" style="color:#4f46e5">覆盖核查</a> 跑一批问题，'
                        .'这里会直接基于那些采样结果算份额，<b>不需要额外采集</b>。')
                .'</p>'
                .$emptyExtra
                .'</div>'
                .$filterBar,
                '声量对标'
            );
        }

        // ---------- 区块一：SOV ----------
        $self = (array) ($sov['self'] ?? []);
        $selfSov = (float) ($self['sov'] ?? 0);
        $selfRank = (int) ($self['rank'] ?? 0);
        $rosterSize = (int) ($sov['roster_size'] ?? 1);
        $sovColor = $selfRank === 1 ? '#047857' : ($selfRank > 0 && $selfRank <= 3 ? '#b45309' : '#b91c1c');

        $bars = '';
        $maxSov = 0.1;
        foreach ((array) $sov['brands'] as $b) {
            $maxSov = max($maxSov, (float) $b['sov']);
        }
        foreach ((array) $sov['brands'] as $b) {
            $w = (float) $b['sov'] / $maxSov * 100;
            $isSelf = ! empty($b['is_self']);
            $c = $isSelf ? '#4f46e5' : '#9ca3af';
            $bars .= '<div style="display:flex;align-items:center;gap:9px;margin:6px 0">'
                .'<span style="width:15px;font-size:11px;color:#9ca3af">'.((int) $b['rank'] > 0 ? '#'.(int) $b['rank'] : '—').'</span>'
                .'<span style="width:96px;font-size:12.5px;font-weight:'.($isSelf ? '700' : '400').';color:'.($isSelf ? '#111827' : '#4b5563').'">'
                .self::e((string) $b['name']).($isSelf ? ' <span style="font-size:10px;color:#4f46e5">你</span>' : '').'</span>'
                .'<span style="flex:1;height:9px;background:#f3f4f6;border-radius:99px;overflow:hidden">'
                .'<span style="display:block;height:100%;width:'.round($w, 1).'%;background:'.$c.';border-radius:99px"></span></span>'
                .'<span style="width:52px;text-align:right;font-size:12.5px;font-weight:700;color:'.($isSelf ? '#4f46e5' : '#6b7280').'">'
                .$b['sov'].'%</span>'
                .'</div>';
        }

        $rows = '';
        foreach ((array) $sov['brands'] as $b) {
            $isSelf = ! empty($b['is_self']);
            $rows .= '<tr'.($isSelf ? ' style="background:#f5f3ff"' : '').'>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;width:30px;color:#9ca3af">'.((int) $b['rank'] > 0 ? (int) $b['rank'] : '—').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px"><b>'.self::e((string) $b['name']).'</b>'
                .($isSelf ? ' <span class="g-tag" style="font-size:10px">本品牌</span>' : '').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:right;font-weight:700;color:'.($isSelf ? '#4f46e5' : '#374151').'">'
                .$b['sov'].'%</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:right">'.$b['sources'].'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:right">'.$b['mention_rate'].'%</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:right">'.$b['domain_count'].'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:right;color:#6b7280">'
                .($b['avg_rank'] !== null ? $b['avg_rank'] : '—').'</td>'
                .'</tr>';
        }

        $sovBlock = '<div class="g-card"><h3>声量份额（SOV）</h3>'
            .'<div style="display:flex;gap:9px;flex-wrap:wrap;margin:14px 0">'
            .self::stat('本品牌 SOV', $selfSov.'%', $sovColor, '占全部品牌提及的比例')
            .self::stat('份额排名', $selfRank > 0 ? '#'.$selfRank : '—', $sovColor, '共 '.$rosterSize.' 个品牌参与')
            .self::stat('提及率', ($self['mention_rate'] ?? 0).'%', '#111827', '被多少问题提到')
            .self::stat('信源广度', (string) ($self['domain_count'] ?? 0), '#111827', '来自多少个不同域名')
            .'</div>'
            .'<p class="g-cap">SOV ＝ 提到你的来源条数 ÷ 所有参与品牌的提及总数。'
            .'这是成熟 GEO 产品的<b>第一指标</b>——"我排第几"回答不了"我比竞品强还是弱"，份额能。</p>'
            .'<div style="margin:14px 0">'.$bars.'</div>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
            .'<th>#</th><th>品牌</th><th style="text-align:right">SOV</th><th style="text-align:right">提及来源</th>'
            .'<th style="text-align:right">提及率</th><th style="text-align:right">域名数</th><th style="text-align:right">平均排位</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p class="g-cap" style="margin:12px 0 0">'
            .'样本：'.(int) ($sov['runs'] ?? 0).' 次采样 / '.(int) ($sov['sources'] ?? 0).' 条来源，'
            .'合计 '.$sov['total_mentions'].' 次品牌提及。<b>问题数少于 20 时数字波动大，别急着下结论。</b></p>'
            .'</div>';

        // ---------- 区块二：信源结构 ----------
        $dg = (array) ($src['diagnosis'] ?? []);
        $verdict = (string) ($dg['verdict'] ?? '');
        $dColor = (string) ($dg['color'] ?? '#6b7280');
        $dText = (string) ($dg['text'] ?? '');
        $dActions = (array) ($dg['actions'] ?? []);

        $actionList = '';
        foreach ($dActions as $a) {
            $actionList .= '<li>'.$a.'</li>';
        }

        $diagBlock = '<div class="g-card" style="border-left:4px solid '.$dColor.'">'
            .'<h3 style="display:flex;align-items:center;gap:9px">引用来源结构'
            .'<span class="g-pill" style="background:'.$dColor.'14;color:'.$dColor.';font-size:11px">'.$verdict.'</span></h3>'
            .'<p style="font-size:13.5px;line-height:1.85;margin:6px 0 12px">'.$dText.'</p>'
            .($actionList !== ''
                ? '<div style="background:#fafafa;border-radius:10px;padding:12px 16px">'
                    .'<div style="font-size:12px;color:#6b7280;margin-bottom:7px">所以下一步该做的是</div>'
                    .'<ul class="g-md" style="margin:0;font-size:13px">'.$actionList.'</ul></div>'
                : '')
            .'</div>';

        // 自有 / 第三方比例条
        $my = (int) ($src['my_sources'] ?? 0);
        $own = (int) ($src['my_own'] ?? 0);
        $third = (int) ($src['my_third'] ?? 0);
        $ownPct = $my > 0 ? round($own / $my * 100) : 0;
        $thirdPct = $my > 0 ? 100 - $ownPct : 0;

        $ratioBar = '';
        if ($my === 0) {
            // 当前口径下自己一条引用都没有，画空条只会让人以为页面坏了
            $ratioBar = '<div class="g-card"><h3>我的引用来自哪里</h3>'
                .'<p class="g-cap" style="margin:0">当前口径下，'.self::e($brandName).' 一条引用都没有，这块暂时没有可分析的东西。'
                .'<b>这不是页面出错，是真实结果</b>——说明这个口径的问题里，你根本没被任何来源提到。'
                .'往下看「谁在占这个品类的位置」，那些域名就是你要挤进去的地方。</p></div>';
        } else {
            $ratioBar = '<div class="g-card"><h3>我的引用来自哪里</h3>'
                .'<p class="g-cap">这是判断「内容问题」还是「信任问题」的关键：'
                .'如果全来自自己官网，说明没人替你背书，此时加内容的边际收益很低。</p>'
                .'<div style="display:flex;height:26px;border-radius:8px;overflow:hidden;margin:14px 0 8px;font-size:11.5px">'
                .($own > 0 ? '<div style="width:'.$ownPct.'%;background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center">'
                    .($ownPct >= 12 ? '自有 '.$ownPct.'%' : '').'</div>' : '')
                .($third > 0 ? '<div style="width:'.$thirdPct.'%;background:#10b981;color:#fff;display:flex;align-items:center;justify-content:center">'
                    .($thirdPct >= 12 ? '第三方 '.$thirdPct.'%' : '').'</div>' : '')
                .'</div>'
                .'<div class="g-row" style="font-size:12px;color:#6b7280">'
                .'<span>自有 <b style="color:#4f46e5">'.$own.'</b> 条 / '.(int) ($src['my_own_domains'] ?? 0).' 个域名</span>'
                .'<span>第三方 <b style="color:#10b981">'.$third.'</b> 条 / '.(int) ($src['my_third_domains'] ?? 0).' 个域名</span>'
                .'<span>合计 '.$my.' 条提到我</span></div>'
                .self::pageListBlock($pageLevel['mine'] ?? [], '具体是这些页面提到了你（去这里维护）', 15)
                .'</div>';
        }

        // 竞品信源广度对比
        $breadthChips = '';
        foreach ((array) ($src['competitor_breadth'] ?? []) as $c) {
            $breadthChips .= '<span style="display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:99px;'
                .'background:#f3f4f6;font-size:12px;margin:0 6px 6px 0">'.self::e((string) $c['name'])
                .'<b style="color:#4b5563">'.(int) $c['domains'].'</b><span style="color:#9ca3af">域名</span></span>';
        }

        $breadthBlock = '';
        if ($breadthChips !== '') {
            $breadthBlock = '<div class="g-card"><h3>信源广度对比：谁被更多地方讨论</h3>'
                .'<p class="g-cap">同样的口径（不同域名数）。这个数字比提及次数更能说明"影响力半径"——'
                .'被 30 个域名提到和被同一批 3 个网站提到 30 次，是两回事。</p>'
                .'<div style="margin-top:10px">'
                .'<span style="display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:99px;'
                .'background:#eef2ff;font-size:12px;margin:0 6px 6px 0;border:1px solid #c7d2fe">'.self::e($brandName)
                .'<b style="color:#4f46e5">'.(int) ($src['my_own_domains'] ?? 0) + (int) ($src['my_third_domains'] ?? 0).'</b>'
                .'<span style="color:#818cf8">域名</span></span>'
                .$breadthChips.'</div></div>';
        }

        // 域名排行
        $domainRows = '';
        foreach ((array) ($src['domains'] ?? []) as $d) {
            $isOwn = ! empty($d['is_own']);
            $m = (int) $d['mentions_self'];
            $mentionPill = $m > 0
                ? '<span class="g-pill g-pill--ok" style="font-size:10px">提到我 '.$m.'</span>'
                : '<span style="color:#d1d5db">—</span>';

            $domainRows .= '<tr'.($isOwn ? ' style="background:#eef2ff"' : '') .'>'
                .'<td style="border:1px solid #e5e7eb;padding:7px 10px;font-size:12px">'
                .self::e(mb_substr((string) $d['domain'], 0, 34, 'UTF-8'))
                .($isOwn ? ' <span class="g-tag" style="font-size:9.5px">自有</span>' : '').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:7px 10px;text-align:right">'.(int) $d['runs'].'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:7px 10px;text-align:right">'.(int) $d['sources'].'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:7px 10px;text-align:right">'.(int) $d['ranked'].'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:7px 10px;text-align:right;color:#6b7280">'
                .($d['avg_rank'] !== null ? $d['avg_rank'] : '—').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:7px 10px">'.$mentionPill.'</td>'
                .'</tr>';
        }

        // ---------- 承诺分级达成表 ----------
        $gradeBlock = '';
        $grade = (array) ($opts['grade_stats'] ?? []);
        if (($grade['win']['total'] ?? 0) + ($grade['fight']['total'] ?? 0) > 0) {
            $gRow = static function (string $label, string $color, int $total, int $hit, float $pct, string $note): string {
                $bar = $total > 0 ? (int) round($hit / $total * 100) : 0;

                return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f9fafb">'
                    .'<span style="flex-shrink:0;width:3px;height:16px;background:'.$color.';border-radius:2px"></span>'
                    .'<span style="flex-shrink:0;width:132px;font-size:12.5px;color:#111827">'.$label.'</span>'
                    .'<span style="flex-shrink:0;width:56px;font-size:12px;color:#6b7280">'.$total.' 道</span>'
                    .'<div style="flex:1;height:16px;background:#f3f4f6;border-radius:4px;overflow:hidden;min-width:60px">'
                    .'<div style="width:'.$bar.'%;height:100%;background:'.$color.'"></div></div>'
                    .'<span style="flex-shrink:0;width:86px;font-size:12px;color:'.$color.';text-align:right">'.$hit.' 道达成 '.$pct.'%</span>'
                    .'<span style="flex-shrink:0;font-size:11px;color:#9ca3af;display:none;md:inline">'.$note.'</span>'
                    .'</div>';
            };

            $gradeBlock = '<div class="g-card"><h3>按承诺分级看达成（给客户交付的那张表）</h3>'
                .'<p class="g-cap">GEO 不该承诺"每道题都进前三"——那是做不到的。合理的做法是分级承诺：'
                .'必赢题守住 Top3，争夺题打 Top10，其余人工判断值不值得投入。</p>'
                .'<div style="margin-top:10px">'
                .$gRow('必赢题 · 承诺 Top3', '#047857',
                    (int) ($grade['win']['total'] ?? 0), (int) ($grade['win']['hit'] ?? 0),
                    (float) ($grade['win']['pct'] ?? 0), '已在前三，守住即可')
                .$gRow('争夺题 · 承诺 Top10', '#b45309',
                    (int) ($grade['fight']['total'] ?? 0), (int) ($grade['fight']['hit'] ?? 0),
                    (float) ($grade['fight']['pct'] ?? 0), '有位置，加把劲能上去')
                .$gRow('待定 · 不承诺', '#6b7280',
                    (int) ($grade['pending']['total'] ?? 0), (int) ($grade['pending']['hit'] ?? 0),
                    (float) ($grade['pending']['pct'] ?? 0), '需人工判断')
                .'</div>'
                .'<p class="g-cap" style="margin:12px 0 0">分级依据来自实际采样：已在前三的自动记为必赢，'
                .'前十或前三名里有可投稿平台的记为争夺，其余标"待定"留给人工判断——'
                .'<b>放弃是战略决策，机器不该替你下</b>。</p></div>';
        }

        // ---------- 竞品内容地图 ----------
        $compBlock = '';
        if ($compMap !== []) {
            $cards = '';
            foreach ($compMap as $b) {
                $isSelf = ! empty($b['is_self']);
                $accent = $isSelf ? '#4f46e5' : '#6b7280';
                $rows = '';
                foreach (array_slice((array) $b['pages'], 0, 6) as $pg) {
                    $rows .= '<div style="display:flex;gap:8px;align-items:flex-start;padding:5px 0;border-bottom:1px solid #f9fafb">'
                        .'<div style="min-width:0;flex:1">'
                        .'<a href="'.self::e((string) $pg['url']).'" target="_blank" rel="noopener noreferrer" '
                        .'style="font-size:12px;color:#374151;text-decoration:none;display:block;overflow:hidden;'
                        .'text-overflow:ellipsis;white-space:nowrap">'.self::e((string) $pg['title']).'</a>'
                        .'<div style="font-size:10.5px;color:#9ca3af">'.self::e((string) $pg['domain'])
                        .' · 出现 '.(int) $pg['hits'].' 次</div></div></div>';
                }
                $cards .= '<div style="flex:1;min-width:260px;border:1px solid #e5e7eb;border-radius:11px;padding:12px 14px;background:#fff">'
                    .'<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">'
                    .'<span style="width:3px;height:14px;background:'.$accent.';border-radius:2px;display:inline-block"></span>'
                    .'<b style="font-size:13px;color:#111827">'.self::e((string) $b['name']).($isSelf ? '（我）' : '').'</b>'
                    .'<span style="font-size:11.5px;color:#6b7280">占 '.(int) $b['count'].' 个页面</span>'
                    .'</div>'.$rows.'</div>';
            }

            $stBanner = '';
            if (! empty($selfTalk['verdict']) && ! empty($selfTalk['text'])) {
                $stColor = (string) ($selfTalk['color'] ?? '#6b7280');
                $stBanner = '<div style="border-left:4px solid '.$stColor.';background:'.$stColor.'08;'
                    .'border-radius:10px;padding:12px 16px;margin-bottom:14px">'
                    .'<div style="display:flex;align-items:center;gap:9px;margin-bottom:6px;flex-wrap:wrap">'
                    .'<b style="font-size:13.5px;color:'.$stColor.'">诊断：'.$selfTalk['verdict'].'</b>'
                    .'<span style="font-size:11.5px;color:#9ca3af">'
                    .(int) $selfTalk['my_total'].' 个我的页面 · '
                    .(int) $selfTalk['my_named'].' 个带品牌名 · '
                    .(int) $selfTalk['my_scene'].' 个场景露出 · '
                    .'竞品合计 '.(int) $selfTalk['comp_total'].' 个</span>'
                    .'</div>'
                    .'<div style="font-size:13px;line-height:1.85;color:#374151">'.$selfTalk['text'].'</div>'
                    .'</div>';
            }

            $compBlock = '<div class="g-card"><h3>竞品靠什么内容占位</h3>'.$stBanner
                .'<p class="g-cap">这一块是给客户看的重点：<b>不是"我占多少"，而是"对手靠哪些页面占住的"</b>。'
                .'点开标题能直接看到对方怎么写的——照着补，比凭空构思快得多。</p>'
                .'<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">'.$cards.'</div>'
                .'<p class="g-cap" style="margin:12px 0 0">口径：只统计<b>页面标题里出现品牌名</b>的来源（不做语义推断，避免误判）。'
                .'所以数字等于"明确提到这个品牌的页面数"，不等于"AI 引用它的次数"。</p></div>';
        }

        $kindMeta = [
            'own' => ['自有页面', '#4f46e5', '你能直接改内容、补参数、加结构化数据'],
            'platform' => ['可维护平台', '#10b981', '能去评论区补充、发新回答、投稿'],
            'third' => ['不可控信源', '#f59e0b', '别人写的稿，只能间接影响'],
        ];

        $plHtml = '';
        foreach ($kindMeta as $k => [$kLabel, $kColor, $kTip]) {
            $list = (array) ($pageLevel['groups'][$k] ?? []);
            $rows = '';
            foreach (array_slice($list, 0, 10) as $pg) {
                $rows .= self::pageRow($pg);
            }
            if ($rows === '') {
                continue;
            }
            $plHtml .= '<div style="margin-bottom:18px">'
                .'<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap">'
                .'<span style="width:3px;height:14px;background:'.$kColor.';border-radius:2px;display:inline-block"></span>'
                .'<b style="font-size:13px;color:#111827">'.$kLabel.'</b>'
                .'<span style="font-size:11.5px;color:#9ca3af">'.count($list).' 个页面 · '.$kTip.'</span>'
                .'</div>'.$rows.'</div>';
        }

        $plTotal = (int) ($pageLevel['stats']['total'] ?? 0);
        $plMine = (int) ($pageLevel['stats']['mine'] ?? 0);

        $domainBlock = '<div class="g-card"><h3>谁在占这个品类的位置</h3>'
            .'<p class="g-cap">精确到<b>具体网页</b>，不是笼统的网站——因为 AI 引用的是一个页面，'
            .'你要去维护或投稿的也是那个页面。按「你能不能动手」分三类，动作完全不同。</p>'
            .($plTotal === 0
                ? '<p class="g-cap" style="margin:10px 0 0">当前口径下还没有采到任何来源，先去「覆盖核查」跑一批问题。</p>'
                : '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px;font-size:12px">'
                    .'<span style="padding:4px 11px;border-radius:99px;background:#f3f4f6">共 <b>'.$plTotal.'</b> 个页面占位</span>'
                    .'<span style="padding:4px 11px;border-radius:99px;background:#ecfdf5;color:#047857">其中 <b>'.$plMine.'</b> 个提到了你</span>'
                    .'<span style="padding:4px 11px;border-radius:99px;background:#fffbeb;color:#b45309">剩下 '.($plTotal - $plMine).' 个在替竞品说话</span>'
                    .(($pageLevel['stats']['has_citation'] ?? 0) === 1
                        ? '<span style="padding:4px 11px;border-radius:99px;background:#eef2ff;color:#4f46e5">其中 <b>'.(int) ($pageLevel['stats']['cited'] ?? 0).'</b> 个被 AI 实际引用</span>'
                        : '<span style="padding:4px 11px;border-radius:99px;background:#f9fafb;color:#9ca3af">还没跑引用确认</span>')
                    // 引用确认是花钱的操作（每题一次模型调用），做成手动按钮，不自动跑
                    .'<a href="'.self::e($base).'?brand='.urlencode($brandSlug).'&confirm=5" '
                    .'onclick="return confirm(\'引用确认会给 5 道题各调一次模型（约 40 秒、几分钱），确定运行？\')" '
                    .'style="padding:4px 12px;border-radius:99px;background:#4f46e5;color:#fff;font-size:12px;'
                    .'text-decoration:none;white-space:nowrap">运行引用确认（5 题）</a>'
                    .'</div>'.$plHtml)
            .'<p class="g-cap" style="margin:10px 0 0">每类最多显示 10 个，按「提到我的优先 + 出现次数」排。'
            .'<b>标记「无我」的页面是重点</b>：它高频回答这类问题却没提你，说明位置被它占了。</p>'
            .'<p class="g-cap" style="margin:8px 0 0;padding-top:8px;border-top:1px solid #f3f4f6">'
            .'<b>三种标记：</b><b style="color:#047857">有我</b>=标题里就有品牌名（主题就是它）；'
            .'<b style="color:#92400e">提及</b>=标题没有但摘要/正文里提到了；<b style="color:#b91c1c">无我</b>=两处都没出现。<br>'
            .'<b>数字怎么读：</b>「出现 N 次」= 在我们跑过的问题里，这个页面被搜到过 N 次；'
            .'「搜索结果第 X 位」= 被搜到的那几次里，最好的一次排在搜索引擎返回的第 X 条。'
            .'<b>这两个数都是"被搜到"的记录，不是"AI 引用"</b>——'
            .'我们用的是搜索接口，它只返回搜索结果列表，不生成答案，所以拿不到 AI 最终引用了谁。</p>'
            .'</div>';

        // ---------- 口径说明 ----------
        $bounds = '<div class="g-card" style="background:#fafafa">'
            .'<h3 style="font-size:14px">口径说明（这几条会影响你怎么读上面的数字）</h3>'
            .'<ul class="g-md" style="font-size:12.5px;margin:0">'
            .'<li><b>零新增采集</b>：本页全部基于已有的搜索采样结果聚合，不会多花一分钱。'
            .'数据量取决于你跑过多少问题——在 <a href="'.self::e($adminBase.'/coverage-check').'" style="color:#4f46e5">覆盖核查</a> 多跑几批，这里就更准。</li>'
            .'<li><b>品牌识别是文本匹配</b>：在标题、摘要、站名里找品牌名。同名缩写或普通词可能误伤'
            .'（比如「至本」这类词）。发现明显识别错，去品牌档案里补别名或删掉容易撞车的竞品。</li>'
            .'<li><b>SOV 的分母是你配的品牌数</b>。配太多竞品会稀释自己的份额，配太少会虚高。'
            .'建议 3–5 个同量级直接竞品，别把行业头部全塞进去。</li>'
            .'<li><b>「进前 10」用的是采样返回的顺序</b>，不是豆包/元宝的官方排序——这两家没有公开 API，'
            .'目前检索底座是 DeepSeek 自研搜索。方向一致，但严格说是代理指标。</li>'
            .'</ul></div>';

        // ---------- 跨口径对比（放在最前，这是整页最重要的一句话）----------
        $crossBlock = '';
        // 跨口径改成 B（品类场景）vs D（竞品对比）：
        // A/C 归覆盖核查了，这里只比"用户说需求"和"用户做对比"两种场景。
        $bSelf = (array) (($cross['b']['self'] ?? []));
        $gSelf = (array) (($cross['d']['self'] ?? []));
        $bSov = (float) ($bSelf['sov'] ?? 0);
        $dSov = (float) ($gSelf['sov'] ?? 0);
        $bRuns = (int) ($cross['b']['runs'] ?? 0);
        $dRuns = (int) ($cross['d']['runs'] ?? 0);

        if ($bRuns > 0 && $dRuns > 0) {
            [$verdict, $vColor, $vText, $vAction] = match (true) {
                $bSov < 20 && $dSov >= 30 => [
                    '只在被点名时出现', '#b91c1c',
                    '用户<b>说需求</b>时（B 品类场景 '.$bSov.'%），你不在；'
                        .'但用户<b>把你和竞品放一起比</b>时（D 竞品对比 '.$dSov.'%），你能占住位置。',
                    '结论是<b>打不过的问题不存在，真正的问题是进不了候选池</b>。对比类内容已经够了，'
                        .'要去写那 '.$bRuns.' 次采样里的品类场景问题——用户描述自己皮肤状况时问的那些话。',
                ],
                $bSov < 20 && $dSov < 20 => [
                    '两头都弱', '#b91c1c',
                    '品类场景和竞品对比都没占住（'.$bSov.'% / '.$dSov.'%）。用户说需求时没有你，做对比时也没有你。',
                    '先解决"能被检索到"：确认站点可抓取、核心页面被收录，再谈场景覆盖。',
                ],
                $bSov >= 30 => [
                    '赛道占位强', '#047857',
                    '品类场景 SOV 到 '.$bSov.'%，说明用户在没提你名字的情况下也会遇到你。这是最难拿的位置。',
                    '转去守位：盯住 SOV 排名第二的竞品，看它在哪些具体问题上超过你。',
                ],
                default => [
                    '场景偏弱，对比还行', '#b45309',
                    '品类场景只有 '.$bSov.'%，竞品对比 '.$dSov.'%。需求场景的覆盖还不够。',
                    '把现有的对比内容改造成"场景型"：同一个产品，换成"什么情况下该用"来写。',
                ],
            };

            $crossBlock = '<div class="g-card" style="border:1px solid '.$vColor.'33;background:'.$vColor.'08">'
                .'<h3 style="display:flex;align-items:center;gap:9px;margin-bottom:12px">跨口径对比：说需求 vs 做对比'
                .'<span class="g-pill" style="background:'.$vColor.'14;color:'.$vColor.';font-size:11px">'.$verdict.'</span></h3>'
                .'<div style="display:flex;gap:12px;margin-bottom:14px">'
                .'<div style="flex:1;background:#fff;border:1px solid #e5e7eb;border-radius:11px;padding:12px 15px">'
                .'<div style="font-size:11.5px;color:#6b7280">B 品类场景（用户说需求）</div>'
                .'<div style="font-size:26px;font-weight:800;color:#111827;line-height:1.3">'.$bSov.'%</div>'
                .'<div style="font-size:11px;color:#9ca3af">'.$bRuns.' 次采样 · '.(((int) ($bSelf['rank'] ?? 0)) > 0 ? '排名 #'.((int) $bSelf['rank']) : '无排名').'</div></div>'
                .'<div style="display:flex;align-items:center;color:#d1d5db;font-size:18px">→</div>'
                .'<div style="flex:1;background:#fff;border:1px solid #e5e7eb;border-radius:11px;padding:12px 15px">'
                .'<div style="font-size:11.5px;color:#6b7280">D 竞品对比（用户做选择）</div>'
                .'<div style="font-size:26px;font-weight:800;color:'.$vColor.';line-height:1.3">'.$dSov.'%</div>'
                .'<div style="font-size:11px;color:#9ca3af">'.$dRuns.' 次采样 · '.(((int) ($gSelf['rank'] ?? 0)) > 0 ? '排名 #'.((int) $gSelf['rank']) : '无排名').'</div></div>'
                .'</div>'
                .'<p style="font-size:13.5px;line-height:1.85;margin:0 0 10px">'.$vText.'</p>'
                .'<div style="background:#fff;border-radius:9px;padding:10px 14px;font-size:13px;line-height:1.75">'
                .'<b>所以：</b>'.$vAction.'</div>'
                .'<p class="g-cap" style="margin:12px 0 0">这两个场景是分开的：'
                .'<b>用户说需求时你在不在候选池</b>，和<b>用户拿你做对比时你排第几</b>，'
                .'是两个独立的能力，要分开补。</p>'
                .'</div>';
        } else {
            $crossBlock = '<div class="g-card" style="background:#fffbeb;border:1px solid #fde68a">'
                .'<h3 style="font-size:14px">跨口径对比暂缺数据</h3>'
                .'<p class="g-cap" style="margin:0">品牌词问题 '.$bRuns.' 个、品类词问题 '.$gRuns.' 个。'
                .'<b>两类都要有才能对比。</b>去 <a href="'.self::e($adminBase.'/coverage-check').'" style="color:#4f46e5">覆盖核查</a> 跑一批，'
                .'题库里要同时包含「'.self::e($brandName).'怎么样」和「敏感肌护肤品推荐」这两类问法——'
                .'只有品牌词会虚高，只有品类词看不出守位能力。</p></div>';
        }

        $body = '<div class="g-hd"><h2 style="margin:0 0 4px;font-size:19px">声量对标</h2>'
            .'<p class="g-cap" style="margin:0">'.self::e($brandName).' 对比竞品的 AI 声量份额，以及这份额来自哪些信源。</p></div>'
            .$filterBar.$scopeHint.$crossBlock.$sovBlock.$diagBlock.$ratioBar.$breadthBlock.$compBlock.$domainBlock.$bounds;

        return self::shell($body, '声量对标');
    }

    /**
     * 一组"提到我的页面"（用于「我的引用来自哪里」卡）
     *
     * @param  list<array<string,mixed>>  $pages
     */
    private static function pageListBlock(array $pages, string $title, int $limit = 15): string
    {
        if ($pages === []) {
            return '';
        }
        $rows = '';
        foreach (array_slice($pages, 0, $limit) as $pg) {
            $rows .= self::pageRow((array) $pg);
        }

        return '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #f3f4f6">'
            .'<div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">'.$title.'</div>'
            .$rows.'</div>';
    }

    /**
     * 单个页面行：标题可点、域名、出现次数、最好排位。
     *
     * @param  array<string,mixed>  $pg
     */
    /**
     * 「为这道题生成标题」区块：声量对标看到某题没覆盖时，直接生成可发布的标题存进标题库。
     * 放在这里是因为——看到缺口的那一刻才是动手时机，切到内容资产再想标题，中间就断了。
     */
    private static function titleGenBlock(string $adminBase, string $brandSlug, string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return '';
        }

        $opts = '<option value="">通用</option>';
        foreach (GeoPlatforms::all() as $slug => $p) {
            $opts .= '<option value="'.self::e((string) $slug).'">'.self::e((string) ($p['name'] ?? $slug)).'</option>';
        }

        $url = self::e($adminBase.'/share-of-voice/gen-titles');
        $token = '';
        try {
            $token = self::e((string) csrf_token());
        } catch (\Throwable) {
        }

        return '<div style="margin-top:12px;padding-top:12px;border-top:1px dashed #7dd3fc">'
            .'<div style="font-size:12.5px;color:#0c4a6e;margin-bottom:7px">'
            .'<b>这道题没覆盖住？</b> 把问题改写成可发布的标题，直接进标题库</div>'
            .'<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
            .'<select id="gt-plat" style="padding:6px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:12.5px;background:#fff">'.$opts.'</select>'
            .'<button type="button" id="gt-run" style="padding:7px 14px;border:none;border-radius:8px;'
            .'background:#0c4a6e;color:#fff;font-size:12.5px;font-weight:600;cursor:pointer">生成 3 个标题</button>'
            .'<span id="gt-tip" style="font-size:11.5px;color:#6b7280">约 15 秒，生成后自动存入标题库</span>'
            .'</div><div id="gt-out" style="margin-top:9px"></div>'
            .'<script>(function(){'
            .'var b=document.getElementById("gt-run"),p=document.getElementById("gt-plat"),'
            .'tip=document.getElementById("gt-tip"),out=document.getElementById("gt-out");'
            .'if(!b)return;'
            .'b.addEventListener("click",function(){'
            .'b.disabled=true;b.style.opacity=".65";b.textContent="生成中…";tip.textContent="正在按平台调性改写…";out.innerHTML="";'
            .'var fd=new FormData();fd.append("_token",'.json_encode($token, JSON_UNESCAPED_UNICODE).');'
            .'fd.append("keyword",'.json_encode($keyword, JSON_UNESCAPED_UNICODE).');fd.append("platform",p.value);'
            .'fetch("'.$url.'",{method:"POST",body:fd,headers:{"X-Requested-With":"XMLHttpRequest"}})'
            .'.then(function(r){return r.json();})'
            .'.then(function(j){'
            .'b.disabled=false;b.style.opacity="1";b.textContent="再生成一批";'
            .'if(!j||!j.ok){tip.textContent=(j&&j.error)||"生成失败";return;}'
            .'tip.textContent="已存入标题库，去内容工坊选标题就能用";'
            .'var h="";(j.titles||[]).forEach(function(t){'
            .'h+="<div style=\"padding:7px 10px;margin:5px 0;background:#ecfdf5;border-left:3px solid #047857;border-radius:6px;font-size:12.5px;color:#065f46\">"+t+"</div>";'
            .'});out.innerHTML=h;'
            .'})'
            .'.catch(function(){b.disabled=false;b.style.opacity="1";b.textContent="生成 3 个标题";tip.textContent="生成失败";});'
            .'});'
            .'})();</script></div>';
    }

    private static function pageRow(array $pg): string
    {
        $url = (string) ($pg['url'] ?? '');
        $title = (string) ($pg['title'] ?? '');
        $domain = (string) ($pg['domain'] ?? '');
        $hits = (int) ($pg['hits'] ?? 0);
        $best = (int) ($pg['best_rank'] ?? 99);
        $mentioned = ! empty($pg['mentioned']);

        // 三态：标题有我（强）/ 仅摘要提及（弱）/ 无我
        $in = (string) ($pg['mention_in'] ?? '');
        if ($mentioned && $in === 'title') {
            $tag = '<span style="flex-shrink:0;font-size:10px;padding:1px 5px;border-radius:4px;background:#ecfdf5;color:#047857" title="页面标题里就有品牌名">有我</span>';
        } elseif ($mentioned) {
            $tag = '<span style="flex-shrink:0;font-size:10px;padding:1px 5px;border-radius:4px;background:#fef3c7;color:#92400e" title="标题没有，但摘要/正文里提到了">提及</span>';
        } else {
            $tag = '<span style="flex-shrink:0;font-size:10px;padding:1px 5px;border-radius:4px;background:#fef2f2;color:#b91c1c">无我</span>';
        }

        // 被 AI 实际引用过的加一个标记（只有跑过引用确认的题才有这个信息）
        if (($pg['cited'] ?? null) === true) {
            $tag .= '<span style="flex-shrink:0;font-size:10px;padding:1px 5px;border-radius:4px;background:#4f46e5;color:#fff" title="AI 生成答案时实际引用了这一条">被引用</span>';
        }

        $label = $title !== '' ? $title : $url;

        return '<div style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f9fafb">'
            .$tag
            .'<div style="min-width:0;flex:1">'
            .'<a href="'.self::e($url).'" target="_blank" rel="noopener noreferrer" '
            .'style="font-size:12.5px;color:#4f46e5;text-decoration:none;display:block;overflow:hidden;'
            .'text-overflow:ellipsis;white-space:nowrap" title="'.self::e($url).'">'.self::e($label).'</a>'
            .'<div style="font-size:11px;color:#9ca3af;margin-top:1px">'.self::e($domain)
            .' · 出现 '.$hits.' 次'.($best < 99 ? ' · 搜索结果第 '.$best.' 位' : '').'</div>'
            .'</div></div>';
    }

    private static function stat(string $k, string $v, string $color, string $sub = ''): string
    {
        return '<div style="flex:1;min-width:118px;border:1px solid #e5e7eb;border-radius:12px;padding:11px 14px">'
            .'<div style="font-size:11px;color:#6b7280">'.self::e($k).'</div>'
            .'<div style="font-size:21px;font-weight:750;color:'.$color.';line-height:1.35">'.self::e($v).'</div>'
            .($sub !== '' ? '<div style="font-size:10.5px;color:#9ca3af">'.self::e($sub).'</div>' : '')
            .'</div>';
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
