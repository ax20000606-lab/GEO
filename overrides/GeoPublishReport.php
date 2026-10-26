<?php

namespace App\Support\GeoAudit;

/**
 * 发布追踪页面（阶段 3）
 *
 * 三个页面：
 *   indexPage()   台账主页（统计 + 按平台对比 + 资产列表）
 *   detailPage()  单篇归因（发布记录 + 被哪些问题采到 + 排名趋势）
 *   bulkPage()    批量登记（粘一堆 URL → 自动猜平台 → 自动匹配资产）
 */
final class GeoPublishReport
{
    private const LABEL_STYLE = 'display:block;font-size:12px;color:#6b7280;font-weight:600';

    private const INPUT_STYLE = 'width:100%;margin-top:6px;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;font-size:13px;font-family:inherit';

    // =========================================================================
    // 主页
    // =========================================================================

    /**
     * @param  array<string,mixed>  $ov   overview()
     * @param  list<array<string,mixed>>  $stats  platformStats()
     * @param  \Illuminate\Support\Collection<int,object>  $assets
     * @param  \Illuminate\Support\Collection<int,object>  $stale
     * @param  array<string,string>  $filter
     * @param  array<string,mixed>  $span  collectionSpan()
     */
    public static function indexPage(array $ov, array $stats, $assets, $stale, array $filter, array $span, string $adminBase): string
    {
        $base = $adminBase.'/publish-track';

        // ---------- 统计卡 ----------
        $card = static function (string $k, string $v, string $color = '#111827', string $sub = ''): string {
            return '<div style="flex:1;min-width:118px;border:1px solid #e5e7eb;border-radius:12px;padding:11px 14px">'
                .'<div style="font-size:11px;color:#6b7280">'.self::e($k).'</div>'
                .'<div style="font-size:21px;font-weight:750;color:'.$color.';line-height:1.35">'.self::e($v).'</div>'
                .($sub !== '' ? '<div style="font-size:10.5px;color:#9ca3af">'.self::e($sub).'</div>' : '')
                .'</div>';
        };

        $rateColor = $ov['rate'] >= 50 ? '#047857' : ($ov['rate'] >= 20 ? '#b45309' : '#b91c1c');

        $cards = '<div style="display:flex;gap:9px;flex-wrap:wrap;margin:14px 0">'
            .$card('内容资产', (string) $ov['assets'], '#111827', '内容工坊产出')
            .$card('已登记发布', (string) $ov['published'], '#111827', '有发布记录的资产')
            .$card('发布链接', (string) $ov['pubs'], '#111827', '多平台分别计')
            .$card('被 AI 引用', (string) $ov['cited'], $ov['cited'] > 0 ? '#047857' : '#6b7280', '至少被采到 1 次')
            .$card('引用率', $ov['rate'].'%', $rateColor, '被引用 / 发布链接')
            .$card('平均收录延迟', $ov['avg_delay'] !== null ? $ov['avg_delay'].' 天' : '—', '#111827', '发布→首次被采到')
            .'</div>';

        // ---------- 工具栏 ----------
        $toolbar = '<div class="g-toolbar">'
            .'<a class="g-btn g-btn--primary" id="pub-poll" href="'.$base.'?poll=1">刷新收录状态</a>'
            .'<a class="g-btn g-btn--ghost" href="'.$base.'?bulk=1">批量登记发布</a>'
            .'<a class="g-btn g-btn--ghost" href="'.self::e($adminBase.'/content-studio').'">去内容工坊生成</a>'
            .'<span class="g-sep">采集数据：'.(int) $span['runs'].' 次采样'
            .($span['first_at'] !== '' ? '，'.self::e(substr($span['first_at'], 0, 10)).' 起' : '')
            .'</span></div>';

        // ---------- 按平台对比 ----------
        $platformBlock = '';
        if ($stats !== []) {
            $rows = '';
            foreach ($stats as $s) {
                $rateC = $s['rate'] >= 50 ? '#047857' : ($s['rate'] > 0 ? '#b45309' : '#b91c1c');
                $rows .= '<tr>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px"><b>'.self::e($s['name']).'</b></td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right">'.$s['pubs'].'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right;color:#047857">'.$s['cited'].'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right;color:#6b7280">'.$s['pending'].'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right;font-weight:700;color:'.$rateC.'">'.$s['rate'].'%</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right">'.($s['best_rank'] !== null ? '第 '.$s['best_rank'].' 位' : '—').'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right;color:#6b7280">'.($s['avg_delay'] !== null ? $s['avg_delay'].' 天' : '—').'</td>'
                    .'</tr>';
            }

            $platformBlock = '<div class="g-card"><h3>按平台对比：哪个平台发出去最有效</h3>'
                .'<p class="g-cap">这张表是阶段 3 的核心产出。它回答的是<b>「同样一篇内容，发知乎和发小红书，哪个更容易被 AI 检索到」</b>——'
                .'这个问题只有数据能答，猜不出来。</p>'
                .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
                .'<th>平台</th><th style="text-align:right">发布</th><th style="text-align:right">被引用</th>'
                .'<th style="text-align:right">待收录</th><th style="text-align:right">引用率</th>'
                .'<th style="text-align:right">最好排位</th><th style="text-align:right">平均收录延迟</th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table>'
                .'<p class="g-cap" style="margin:12px 0 0">'
                .'<b>引用率低不一定是平台差</b>——样本太少时（比如每个平台只发了 1 篇）数字波动极大，至少要每平台 3 篇以上才有参考价值。</p>'
                .'</div>';
        }

        // ---------- 筛选 ----------
        $curPlatform = (string) ($filter['platform'] ?? '');
        $curStatus = (string) ($filter['status'] ?? '');
        $curQ = (string) ($filter['q'] ?? '');

        $tab = static function (string $label, string $status, string $cur, string $base, string $plat, string $q) {
            $on = $status === $cur;
            $href = $base.'?status='.$status.($plat !== '' ? '&platform='.$plat : '').($q !== '' ? '&q='.urlencode($q) : '');

            return '<a class="g-btn '.($on ? 'g-btn--primary' : 'g-btn--ghost').'" href="'.self::e($href).'" style="font-size:12.5px;padding:6px 13px">'.self::e($label).'</a>';
        };

        $filters = '<div class="g-row" style="margin:18px 0 12px;align-items:center">'
            .$tab('全部', '', $curStatus, $base, $curPlatform, $curQ)
            .$tab('未发布', 'unpublished', $curStatus, $base, $curPlatform, $curQ)
            .$tab('已发布', 'published', $curStatus, $base, $curPlatform, $curQ)
            .$tab('已被引用', 'cited', $curStatus, $base, $curPlatform, $curQ)
            .'<form method="get" action="'.$base.'" style="display:flex;gap:8px;align-items:center;margin-left:auto">'
            .($curStatus !== '' ? '<input type="hidden" name="status" value="'.self::e($curStatus).'">' : '')
            .'<input type="text" name="q" value="'.self::e($curQ).'" placeholder="搜标题 / 主题 / 品牌" '
            .'style="padding:6px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:12.5px;width:170px">'
            .'<button type="submit" class="g-btn g-btn--ghost" style="font-size:12.5px;padding:6px 12px">搜索</button>'
            .'</form></div>';

        // ---------- 资产列表 ----------
        $assetRows = '';
        foreach ($assets as $a) {
            $recCount = (int) ($a->rec_count ?? 0);
            $citedCount = (int) ($a->cited_count ?? 0);
            $isDraft = (string) $a->status === 'draft';

            $statusPill = $isDraft
                ? '<span class="g-pill" style="background:#f3f4f6;color:#6b7280">未发布</span>'
                : ($citedCount > 0
                    ? '<span class="g-pill g-pill--ok">被引用 '.$citedCount.' 次</span>'
                    : '<span class="g-pill g-pill--warn">已发布待收录</span>');

            $checkCls = ((int) $a->check_total > 0 && (int) $a->check_pass === (int) $a->check_total) ? '#047857' : '#b45309';

            $assetRows .= '<tr>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:92px"><span class="g-tag">'.self::e((string) $a->platform_name).'</span></td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px">'
                .'<a href="'.$base.'?asset='.(int) $a->id.'" style="color:#111827;font-weight:600;text-decoration:none">'
                .self::e(mb_substr((string) ($a->title ?: $a->topic ?: '（无标题）'), 0, 40, 'UTF-8')).'</a>'
                .'<div style="font-size:11px;color:#9ca3af;margin-top:2px">'
                .self::e(mb_substr((string) $a->topic, 0, 46, 'UTF-8'))
                .((string) $a->brand !== '' ? ' · '.self::e((string) $a->brand) : '')
                .'</div></td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right;width:74px">'.(int) $a->words.'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right;width:74px;color:'.$checkCls.'">'
                .(int) $a->check_pass.'/'.(int) $a->check_total.'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:132px">'.$statusPill.'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right;width:88px;color:#6b7280">'
                .($a->best_rank !== null ? '第 '.(int) $a->best_rank.' 位' : '—').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;text-align:right;width:86px;color:#6b7280">'
                .($a->delay_days !== null ? (int) $a->delay_days.' 天' : '—').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:120px;white-space:nowrap;text-align:center">'
                .'<a class="g-btn g-btn--ghost" href="'.$base.'?asset='.(int) $a->id.'#reg" style="font-size:11.5px;padding:4px 10px">登记发布</a>'
                .'</td>'
                .'</tr>';
        }

        if ($assetRows === '') {
            $assetRows = '<tr><td colspan="8" style="border:1px solid #e5e7eb;padding:26px;text-align:center;color:#6b7280">'
                .'还没有内容资产。先去 <a href="'.self::e($adminBase.'/content-studio').'" style="color:#4f46e5">内容工坊</a> 生成一篇，'
                .'生成完会自动进这个台账。</td></tr>';
        }

        $assetBlock = '<div class="g-card"><h3>内容资产台账</h3>'
            .'<p class="g-cap">内容工坊每次生成的版本都会自动进这里。<b>登记发布</b>后填一个链接，'
            .'系统会自动识别平台、域名，并在下次刷新时检查有没有被 AI 采到。</p>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
            .'<th>平台</th><th>标题 / 主题</th><th style="text-align:right">字数</th><th style="text-align:right">合规</th>'
            .'<th>状态</th><th style="text-align:right">最好排位</th><th style="text-align:right">收录延迟</th><th></th>'
            .'</tr></thead><tbody>'.$assetRows.'</tbody></table></div>';

        // ---------- 长期未收录提醒 ----------
        $staleBlock = '';
        if (! $stale->isEmpty()) {
            $rows = '';
            foreach ($stale as $r) {
                $days = 0;
                if ((string) $r->published_at !== '') {
                    $d = (time() - strtotime((string) $r->published_at)) / 86400;
                    $days = (int) floor($d);
                }
                $rows .= '<tr>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px"><span class="g-tag">'.self::e((string) $r->platform_name).'</span></td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px">'.self::e(mb_substr((string) $r->asset_title, 0, 36, 'UTF-8')).'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px;text-align:right;color:#b45309;font-weight:600">'.$days.' 天</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 11px;font-size:11px;color:#6b7280">'
                    .self::e(mb_substr((string) $r->url, 0, 48, 'UTF-8')).'</td>'
                    .'</tr>';
            }

            $staleBlock = '<div class="g-card" style="border-left:4px solid #f59e0b">'
                .'<h3>已发布超过 7 天，但一次都没被采到</h3>'
                .'<p class="g-cap">这几条要重点看。可能的原因按概率排序：'
                .'① 还没被搜索引擎收录（等收录，或被平台限流）'
                .'② 平台的账号权重不够，搜索结果里排不进前 10'
                .'③ 内容本身质量不够，DeepSeek 过滤了低质 AIGC'
                .'④ 这个平台根本不在当前采样的搜索源覆盖范围内</p>'
                .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
                .'<th>平台</th><th>资产</th><th style="text-align:right">已发布</th><th>链接</th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table></div>';
        }

        // ---------- 边界说明 ----------
        $bounds = '<div class="g-card" style="background:#fafafa">'
            .'<h3 style="font-size:14px">这个模块和 AI 可见性的区别（重要）</h3>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
            .'<th>模块</th><th>聚合维度</th><th>回答的问题</th></tr></thead><tbody>'
            .'<tr><td>AI 可见性</td><td><b>按天</b></td><td>我整体在 AI 里表现如何</td></tr>'
            .'<tr><td>覆盖核查</td><td><b>按问题</b></td><td>哪些问题里我缺席</td></tr>'
            .'<tr><td style="background:#f0f9ff"><b>发布追踪</b></td><td style="background:#f0f9ff"><b>按内容资产</b></td>'
            .'<td style="background:#f0f9ff">我发出去的哪一篇进了榜、哪个平台有效</td></tr>'
            .'</tbody></table>'
            .'<p class="g-cap" style="margin:12px 0 0">三者<b>共用同一份采集数据</b>，本模块不新增任何采集成本，'
            .'只是把已有的 300+ 条来源记录，反向匹配回你发出去的内容。<br>'
            .'<b>必须知道的边界</b>：① 采样源是 DeepSeek 自研搜索，不是豆包/元宝官方排序；'
            .'② 收录有滞后，刚发的文章当天查不到是正常的；'
            .'③ 引用率需要每平台 ≥3 篇样本才有参考价值。</p>'
            .'</div>';

        $body = '<div class="g-hd"><h2 style="margin:0 0 4px;font-size:19px">发布追踪</h2>'
            .'<p class="g-cap" style="margin:0">记录内容发到了哪里，回头看哪一篇真的进了 AI 的答案。</p></div>'
            .$cards.$toolbar.$platformBlock.$filters.$assetBlock.$staleBlock.$bounds
            .'<script>(function(){'
            .'var b=document.getElementById("pub-poll");if(!b)return;'
            .'b.addEventListener("click",function(ev){'
            .'if(b.dataset.busy==="1"){ev.preventDefault();return;}'
            .'b.dataset.busy="1";b.style.opacity=".7";b.textContent="正在刷新…";'
            .'if(window.geoBusy){window.geoBusy("正在核对收录状态","逐条比对已有的采集数据，约 5–15 秒，请勿关闭页面");}'
            .'});})();</script>';

        return self::shell($body, '发布追踪');
    }

    // =========================================================================
    // 单篇详情
    // =========================================================================

    /**
     * @param  object  $asset
     * @param  \Illuminate\Support\Collection<int,object>  $records
     * @param  array<int,array<string,mixed>>  $attrs  记录 id => attribution()
     */
    public static function detailPage(object $asset, $records, array $attrs, string $adminBase, string $notice = '', string $noticeType = 'ok'): string
    {
        $base = $adminBase.'/publish-track';
        $assetId = (int) $asset->id;

        $noticeHtml = '';
        if ($notice !== '') {
            $bg = $noticeType === 'ok' ? '#ecfdf5' : '#fef2f2';
            $bd = $noticeType === 'ok' ? '#047857' : '#b91c1c';
            $noticeHtml = '<div style="background:'.$bg.';border:1px solid '.$bd.';border-radius:10px;padding:10px 14px;'
                .'font-size:13px;color:'.$bd.';margin-bottom:14px">'.self::e($notice).'</div>';
        }

        $meta = json_decode((string) ($asset->meta ?? '{}'), true) ?: [];
        $modeLabel = (string) $asset->mode === 'refine' ? '旧文改造' : '科普生成';
        $checkTotal = (int) $asset->check_total;
        $checkPass = (int) $asset->check_pass;

        $head = '<div class="g-toolbar">'
            .'<a class="g-btn g-btn--ghost" href="'.$base.'">← 返回台账</a>'
            .'<a class="g-btn g-btn--primary" href="'.$base.'?download='.$assetId.'">下载 Markdown</a>'
            .'<a class="g-btn g-btn--ghost" href="'.$base.'?delasset='.$assetId.'" '
            .'onclick="return confirm(\'删除这条资产和它的全部发布记录？\')" style="color:#b91c1c">删除</a>'
            .'<span class="g-sep">#'.$assetId.' · '.self::e($modeLabel).' · '.self::e((string) $asset->model).'</span>'
            .'</div>';

        $info = '<div class="g-card"><h3>'.self::e(mb_substr((string) ($asset->title ?: $asset->topic), 0, 80, 'UTF-8')).'</h3>'
            .'<div style="display:flex;gap:9px;flex-wrap:wrap;margin:12px 0">'
            .self::miniStat('生成平台', (string) $asset->platform_name)
            .self::miniStat('字数', (string) (int) $asset->words)
            .self::miniStat('合规', $checkPass.'/'.$checkTotal, $checkPass === $checkTotal && $checkTotal > 0 ? '#047857' : '#b45309')
            .self::miniStat('生成时间', substr((string) $asset->created_at, 0, 16))
            .self::miniStat('品牌', (string) ($asset->brand ?: '—'))
            .'</div>'
            .((string) $asset->topic !== ''
                ? '<p class="g-cap" style="margin:0 0 4px"><b>主题 / 原文摘要：</b>'.self::e(mb_substr((string) $asset->topic, 0, 160, 'UTF-8')).'</p>'
                : '')
            .(! empty($meta['check_items'])
                ? '<details style="margin-top:10px"><summary style="cursor:pointer;font-size:12.5px;color:#4f46e5">查看平台合规明细</summary>'
                    .'<table class="g-table" style="font-size:12px;margin-top:8px"><tbody>'
                    .implode('', array_map(static function ($it) {
                        $ok = ! empty($it['ok']);
                        $soft = ! empty($it['soft']);
                        $mark = $ok ? '✅' : ($soft ? '⚠️' : '❌');

                        return '<tr><td style="border:1px solid #e5e7eb;padding:7px 10px;width:28px;text-align:center">'.$mark.'</td>'
                            .'<td style="border:1px solid #e5e7eb;padding:7px 10px;width:160px">'.self::e((string) ($it['label'] ?? '')).'</td>'
                            .'<td style="border:1px solid #e5e7eb;padding:7px 10px;color:#6b7280">'.self::e((string) ($it['detail'] ?? '')).'</td></tr>';
                    }, (array) $meta['check_items']))
                    .'</tbody></table></details>'
                : '')
            .'</div>';

        // ---------- 登记表单 ----------
        $regForm = '<div class="g-card" id="reg"><h3>登记一条发布记录</h3>'
            .'<p class="g-cap">只填链接就够了——平台、域名、URL 指纹全部自动识别。'
            .'<b>同一篇文章发到多个平台，就登记多条。</b></p>'
            .'<form method="get" action="'.$base.'" style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-top:12px">'
            .'<input type="hidden" name="add" value="1">'
            .'<input type="hidden" name="asset" value="'.$assetId.'">'
            .'<label style="'.self::LABEL_STYLE.'">发布后的链接（必填）'
            .'<input type="text" name="url" required placeholder="https://zhuanlan.zhihu.com/p/xxxx" style="'.self::INPUT_STYLE.'"></label>'
            .'<label style="'.self::LABEL_STYLE.'">发布时间（留空＝现在）'
            .'<input type="date" name="published" style="'.self::INPUT_STYLE.'"></label>'
            .'<label style="'.self::LABEL_STYLE.'">备注（可选）'
            .'<input type="text" name="notes" placeholder="例如：投了知乎圆桌" style="'.self::INPUT_STYLE.'"></label>'
            .'<div style="display:flex;align-items:flex-end">'
            .'<button type="submit" class="g-btn g-btn--primary" style="width:100%">登记</button></div>'
            .'</form></div>';

        // ---------- 发布记录 + 归因 ----------
        $recRows = '';
        foreach ($records as $r) {
            $rid = (int) $r->id;
            $attr = $attrs[$rid] ?? ['hits' => [], 'total' => 0, 'best' => null, 'avg' => null];
            $st = (string) $r->status;

            [$stLabel, $stBg, $stColor] = match ($st) {
                GeoPublish::ST_CITED => ['已引用', '#ecfdf5', '#047857'],
                GeoPublish::ST_DROPPED => ['已掉榜', '#fffbeb', '#b45309'],
                GeoPublish::ST_DEAD => ['链接失效', '#fef2f2', '#b91c1c'],
                default => ['待收录', '#f3f4f6', '#6b7280'],
            };

            // 归因明细（被哪些问题采到）
            $hitDetail = '';
            if ($attr['hits'] !== []) {
                $hrows = '';
                foreach (array_slice($attr['hits'], 0, 12) as $h) {
                    $hrows .= '<tr>'
                        .'<td style="border:1px solid #e5e7eb;padding:6px 10px">'.self::e(mb_substr((string) $h['keyword'], 0, 34, 'UTF-8')).'</td>'
                        .'<td style="border:1px solid #e5e7eb;padding:6px 10px;text-align:center;font-weight:600;color:'
                        .((int) $h['rank'] <= 3 ? '#047857' : '#b45309').'">'.($h['rank'] !== null ? '第 '.$h['rank'].' 位' : '—').'</td>'
                        .'<td style="border:1px solid #e5e7eb;padding:6px 10px;color:#6b7280;font-size:11px">'
                        .self::e(substr((string) $h['seen_at'], 0, 16)).'</td>'
                        .'</tr>';
                }
                $hitDetail = '<div style="margin-top:10px;padding:10px 12px;background:#f9fafb;border-radius:9px">'
                    .'<div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:7px">'
                    .'被这些提问采到了（最多显示 12 条）</div>'
                    .'<table class="g-table" style="font-size:12px"><thead><tr>'
                    .'<th>提问</th><th style="text-align:center;width:84px">排位</th><th style="width:130px">采到时间</th>'
                    .'</tr></thead><tbody>'.$hrows.'</tbody></table></div>';
            }

            $recRows .= '<tr>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:100px">'
                .'<span class="g-tag">'.self::e((string) $r->platform_name).'</span></td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px">'
                .'<a href="'.self::e((string) $r->url).'" target="_blank" rel="noopener" '
                .'style="color:#4f46e5;text-decoration:none;font-size:12px">'
                .self::e(mb_substr((string) $r->url, 0, 62, 'UTF-8')).'</a>'
                .'<div style="font-size:11px;color:#9ca3af;margin-top:3px">'
                .'发布于 '.self::e(substr((string) $r->published_at, 0, 10))
                .((string) $r->notes !== '' ? ' · '.self::e(mb_substr((string) $r->notes, 0, 30, 'UTF-8')) : '')
                .'</div>'
                .$hitDetail.'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:86px;text-align:center">'
                .'<span style="display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;background:'.$stBg.';color:'.$stColor.'">'
                .$stLabel.'</span></td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:78px;text-align:right;font-weight:600;color:'
                .($attr['best'] !== null && $attr['best'] <= 3 ? '#047857' : '#6b7280').'">'
                .($attr['best'] !== null ? '第 '.$attr['best'].' 位' : '—').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:96px;text-align:right;color:#6b7280;font-size:12px">'
                .($r->delay_days !== null ? (int) $r->delay_days.' 天' : '—').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:9px 11px;width:52px;text-align:center">'
                .'<a href="'.$base.'?delrec='.$rid.'&asset='.$assetId.'" onclick="return confirm(\'删除这条发布记录？\')" '
                .'style="color:#b91c1c;font-size:12px;text-decoration:none">删除</a></td>'
                .'</tr>';
        }

        if ($recRows === '') {
            $recRows = '<tr><td colspan="6" style="border:1px solid #e5e7eb;padding:24px;text-align:center;color:#6b7280">'
                .'还没有发布记录。把这篇发出去（知乎/小红书/官网都行），回来在上面填个链接。</td></tr>';
        }

        $recBlock = '<div class="g-card"><h3>发布记录（'.count($records).' 条）</h3>'
            .'<p class="g-cap"><b>排位和收录延迟来自真实采样</b>：系统会拿这条 URL 去比对每次搜索采到的来源，'
            .'命中就说明这篇进了 AI 的候选池。<b>收录延迟 = 发布到第一次被采到的天数</b>，这是 GEO 里最该盯的数字之一。</p>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
            .'<th>平台</th><th>链接 / 归因</th><th style="text-align:center">状态</th>'
            .'<th style="text-align:right">最好排位</th><th style="text-align:right">收录延迟</th><th></th>'
            .'</tr></thead><tbody>'.$recRows.'</tbody></table></div>';

        // ---------- 正文预览 ----------
        $contentBlock = '<div class="g-card"><h3>正文</h3>'
            .'<div class="g-md" style="max-height:560px;overflow:auto;padding-right:8px">'
            .self::mdToHtml((string) $asset->markdown).'</div></div>';

        $body = $noticeHtml.$head.$info.$regForm.$recBlock.$contentBlock
            .'<div class="g-card" style="background:#fafafa">'
            .'<p class="g-cap" style="margin:0"><b>为什么这块单独做一页，而不是塞进 AI 可见性：</b>'
            .'AI 可见性按天看整体，回答不了"是哪一篇在起作用"。这一页把所有维度收到<b>单个内容资产</b>上——'
            .'它发到了哪、被哪个提问采到、排第几、隔了几天才被采到。<br>'
            .'<b>注意</b>：如果同一篇内容你在多个平台都发了，各条记录要分别看，'
            .'因为不同平台的收录速度差很远（公众号通常最快，小红书最慢）。</p></div>';

        return self::shell($body, '发布追踪 · 单篇归因');
    }

    // =========================================================================
    // 批量登记
    // =========================================================================

    /**
     * @param  list<array{url:string,slug:string,name:string,asset_id:int,asset_title:string,ok:bool,reason:string}>  $plan
     * @param  \Illuminate\Support\Collection<int,object>  $recentAssets
     */
    public static function bulkPage(array $plan, $recentAssets, string $adminBase, string $notice = '', string $rawInput = ''): string
    {
        $base = $adminBase.'/publish-track';

        $noticeHtml = $notice !== ''
            ? '<div style="background:#ecfdf5;border:1px solid #047857;border-radius:10px;padding:10px 14px;'
                .'font-size:13px;color:#047857;margin-bottom:14px">'.self::e($notice).'</div>'
            : '';

        $form = '<div class="g-card"><h3>批量登记发布</h3>'
            .'<p class="g-cap">一行一个链接，粘完点解析。<b>系统会按域名自动判断平台，并匹配同平台最近生成的资产</b>——'
            .'匹配错了可以在下面改。一次最多 20 条。</p>'
            .'<form method="get" action="'.$base.'">'
            .'<input type="hidden" name="bulk" value="1">'
            .'<input type="hidden" name="parse" value="1">'
            .'<textarea name="urls" rows="7" placeholder="https://zhuanlan.zhihu.com/p/xxxx&#10;https://www.xiaohongshu.com/explore/xxxx&#10;https://mp.weixin.qq.com/s/xxxx" '
            .'style="'.self::INPUT_STYLE.';resize:vertical;font-family:ui-monospace,Menlo,monospace;font-size:12px">'
            .self::e($rawInput).'</textarea>'
            .'<div class="g-row" style="margin-top:12px">'
            .'<button type="submit" class="g-btn g-btn--primary">解析并匹配</button>'
            .'<a class="g-btn g-btn--ghost" href="'.$base.'">返回台账</a></div>'
            .'</form></div>';

        $planBlock = '';
        if ($plan !== []) {
            $rows = '';
            $assetOptions = '';
            foreach ($recentAssets as $a) {
                $assetOptions .= '<option value="'.(int) $a->id.'">#'.(int) $a->id.' ['
                    .self::e((string) $a->platform_name).'] '
                    .self::e(mb_substr((string) ($a->title ?: $a->topic), 0, 34, 'UTF-8')).'</option>';
            }

            foreach ($plan as $i => $p) {
                $opts = '<option value="0">— 选择资产 —</option>';
                foreach ($recentAssets as $a) {
                    $sel = (int) $a->id === $p['asset_id'] ? ' selected' : '';
                    $opts .= '<option value="'.(int) $a->id.'"'.$sel.'>#'.(int) $a->id.' ['
                        .self::e((string) $a->platform_name).'] '
                        .self::e(mb_substr((string) ($a->title ?: $a->topic), 0, 30, 'UTF-8')).'</option>';
                }

                $mark = $p['ok'] ? '<span style="color:#047857">✓</span>' : '<span style="color:#b91c1c">✗</span>';

                $rows .= '<tr>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 10px;text-align:center;width:30px">'.$mark.'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 10px;font-size:11.5px;word-break:break-all">'
                    .self::e(mb_substr($p['url'], 0, 58, 'UTF-8')).'</td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 10px;width:88px">'
                    .'<span class="g-tag">'.self::e($p['name']).'</span></td>'
                    .'<td style="border:1px solid #e5e7eb;padding:8px 10px;width:250px">'
                    .($p['ok']
                        ? '<select name="bind['.$i.']" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:7px;font-size:11.5px">'.$opts.'</select>'
                            .'<input type="hidden" name="u['.$i.']" value="'.self::e($p['url']).'">'
                        : '<span style="font-size:11.5px;color:#b91c1c">'.self::e($p['reason']).'</span>')
                    .'</td>'
                    .'</tr>';
            }

            $planBlock = '<form method="get" action="'.$base.'">'
                .'<input type="hidden" name="bulkrun" value="1">'
                .'<div class="g-card"><h3>确认匹配（共 '.count($plan).' 条链接）</h3>'
                .'<p class="g-cap">左边是链接，右边选它对应的内容资产。绿色✓的已自动匹配好，红色✗的匹配不到（同平台没有资产），'
                .'可以先去内容工坊生成，或者到资产详情页单独登记。</p>'
                .'<table class="g-table" style="font-size:12.5px"><thead><tr>'
                .'<th></th><th>链接</th><th>识别平台</th><th>绑定内容资产</th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table>'
                .'<div class="g-row" style="margin-top:14px">'
                .'<button type="submit" class="g-btn g-btn--primary">确认登记</button>'
                .'<a class="g-btn g-btn--ghost" href="'.$base.'?bulk=1">重新粘贴</a></div>'
                .'</div></form>';
        }

        $body = '<div class="g-toolbar"><a class="g-btn g-btn--ghost" href="'.$base.'">← 返回台账</a></div>'
            .$noticeHtml.$form.$planBlock
            .'<div class="g-card" style="background:#fafafa"><p class="g-cap" style="margin:0">'
            .'<b>自动匹配规则</b>：按 URL 域名判断平台，然后在「同平台 + 生成时间最近」的资产里找一条还没登记过的。'
            .'如果同平台有多篇候选，按生成时间倒序取第一篇空着的——<b>所以顺序很重要：先生成哪篇就先发哪篇</b>。'
            .'匹配错了手动改下拉即可。</p></div>';

        return self::shell($body, '发布追踪 · 批量登记');
    }

    // =========================================================================
    // 工具
    // =========================================================================

    private static function miniStat(string $k, string $v, string $color = '#111827'): string
    {
        return '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:8px 12px;min-width:86px">'
            .'<div style="font-size:10.5px;color:#6b7280">'.self::e($k).'</div>'
            .'<div style="font-size:14px;font-weight:700;color:'.$color.';line-height:1.4">'.self::e($v).'</div></div>';
    }

    /** 极简 markdown 渲染（与内容工坊同口径，避免两处不一致） */
    private static function mdToHtml(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $lines = explode("\n", $md);
        $html = '';
        $inList = false;

        foreach ($lines as $line) {
            $raw = rtrim($line);
            $t = trim($raw);

            if ($t === '') {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                continue;
            }

            if (preg_match('/^###\s+(.*)$/u', $t, $m)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h4>'.self::e($m[1]).'</h4>';
                continue;
            }
            if (preg_match('/^##\s+(.*)$/u', $t, $m)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h3>'.self::e($m[1]).'</h3>';
                continue;
            }
            if (preg_match('/^#\s+(.*)$/u', $t, $m)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h2>'.self::e($m[1]).'</h2>';
                continue;
            }
            if (preg_match('/^[-*]\s+(.*)$/u', $t, $m)) {
                if (! $inList) { $html .= '<ul>'; $inList = true; }
                $html .= '<li>'.self::e($m[1]).'</li>';
                continue;
            }
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<p>'.self::e($t).'</p>';
        }
        if ($inList) {
            $html .= '</ul>';
        }

        return $html;
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
