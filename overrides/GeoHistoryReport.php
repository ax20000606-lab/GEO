<?php

namespace App\Support\GeoAudit;

/**
 * GEO 记录中心：历史列表、保存结果详情、改前/改后对比
 */
final class GeoHistoryReport
{
    private const KIND_LABEL = ['audit' => '页面诊断', 'advise' => 'AI 解读', 'plan' => '执行方案'];

    private const TYPE_LABEL = ['explain' => '诊断解读', 'content' => '内容结构优化', 'answers' => '可见性问答', 'plan' => '行动方案'];

    /** @param iterable<object> $rows */
    public static function listPage(iterable $rows, string $kind = '', string $url = ''): string
    {
        $tabs = '';
        foreach (['' => '全部', 'audit' => '页面诊断', 'advise' => 'AI 解读', 'plan' => '执行方案'] as $k => $label) {
            $active = $kind === $k ? ' style="background:#4f46e5;color:#fff;border-color:#4f46e5"' : '';
            $tabs .= '<a class="g-btn g-btn--ghost"'.$active.' href="?kind='.urlencode($k).($url !== '' ? '&url='.urlencode($url) : '').'">'.$label.'</a>';
        }

        $trs = '';
        $count = 0;
        foreach ($rows as $row) {
            $count++;
            $meta = json_decode((string) ($row->meta ?? '{}'), true) ?: [];
            $score = isset($meta['total']) ? (int) $meta['total'].' 分' : '—';
            $grade = (string) ($meta['grade'] ?? '');
            $kindLabel = self::KIND_LABEL[$row->kind] ?? $row->kind;
            $sub = $row->sub_type ? '·'.(self::TYPE_LABEL[$row->sub_type] ?? $row->sub_type) : '';
            $trs .= '<tr>'
                .'<td style="white-space:nowrap;color:#6b7280">'.self::e((string) $row->created_at).'</td>'
                .'<td>'.$kindLabel.$sub.'</td>'
                .'<td style="max-width:320px;word-break:break-all">'.self::e((string) $row->url).'</td>'
                .'<td style="white-space:nowrap">'.$score.($grade !== '' ? ' <span class="g-tag">'.self::e($grade).'</span>' : '').'</td>'
                .'<td style="white-space:nowrap">'
                // 三个操作：查看 / 下载已存档的 Markdown / 删除（删除必须 POST + CSRF + 二次确认）
                .'<a class="g-btn g-btn--soft" style="font-size:11.5px;padding:5px 10px;margin-right:6px" '
                .'href="?view='.(int) $row->id.'">查看</a>'
                .'<a class="g-btn g-btn--ghost" style="font-size:11.5px;padding:5px 10px;margin-right:6px" '
                .'href="?download=1&id='.(int) $row->id.'">下载 Markdown</a>'
                .'<form method="POST" action="'.self::e(self::adminBase().'/geo-audit').'" style="display:inline" '
                .'onsubmit="return confirm(\'确定删除这条记录？删除后不可恢复。\')">'
                .'<input type="hidden" name="_token" value="'.self::e((string) csrf_token()).'">'
                .'<input type="hidden" name="_action" value="delete">'
                .'<input type="hidden" name="id" value="'.(int) $row->id.'">'
                .'<button type="submit" class="g-btn g-btn--ghost" '
                .'style="font-size:11.5px;padding:5px 10px;color:#b91c1c;border-color:#fecaca">删除</button>'
                .'</form>'
                .'</td>'
                .'</tr>';
        }
        if ($count === 0) {
            $trs = '<tr><td colspan="5" style="text-align:center;color:#6b7280;padding:26px">还没有记录。去「官网诊断」跑一次就会自动存档。</td></tr>';
        }

        $body = '<div class="g-toolbar">'.$tabs.'</div>'
            .'<div class="g-card"><h3>GEO 记录（自动存档）</h3>'
            .'<p class="g-cap">每次诊断、AI 解读、方案生成都会存一份，刷新或关闭页面都不会丢</p>'
            .'<table class="g-table"><thead><tr><th>时间</th><th>类型</th><th>网址</th><th>分数</th><th>操作</th></tr></thead>'
            .'<tbody>'.$trs.'</tbody></table></div>';

        return self::shell($body, 'GEO 记录');
    }

    public static function detailPage(object $row, ?object $prevAudit = null): string
    {
        $meta = json_decode((string) ($row->meta ?? '{}'), true) ?: [];
        $md = (string) ($row->content ?? '');
        $html = self::markdown($md);
        $kindLabel = self::KIND_LABEL[$row->kind] ?? $row->kind;
        $sub = $row->sub_type ? '·'.(self::TYPE_LABEL[$row->sub_type] ?? $row->sub_type) : '';

        $tags = '<span class="g-badge">'.$kindLabel.$sub.'</span>';
        if (isset($meta['total'])) {
            $tags .= '<span class="g-tag">'.$meta['total'].' 分</span>';
        }
        if (! empty($meta['model'])) {
            $tags .= '<span class="g-tag">'.self::e((string) $meta['model']).'</span>';
        }
        if (! empty($meta['elapsed_ms'])) {
            $tags .= '<span class="g-tag">'.number_format(((int) $meta['elapsed_ms']) / 1000, 1).' 秒</span>';
        }
        $tags .= '<span class="g-tag">'.self::e((string) $row->created_at).'</span>';

        $compare = '';
        if ($row->kind === 'audit' && $prevAudit !== null) {
            $prevMeta = json_decode((string) ($prevAudit->meta ?? '{}'), true) ?: [];
            $compare = self::compareTable($meta, $prevMeta, (string) $prevAudit->created_at);
        }

        $body = '<div class="g-toolbar">'
            .'<a class="g-btn g-btn--ghost" href="'.self::e(self::adminBase().'/geo-audit').'">← 返回诊断</a>'
            .'<a class="g-btn g-btn--soft" href="?download=1&id='.(int) $row->id.'">下载 Markdown</a>'
            // 详情页也放一个删除入口：看完发现这条没用，不用退回列表再找。
            // 必须是 POST + CSRF，GET 链接会被预取/外链 <img src> 触发误删。
            .'<form method="POST" action="'.self::e(self::adminBase().'/geo-audit').'" style="display:inline" '
            .'onsubmit="return confirm(\'确定删除这条记录？删除后不可恢复。\')">'
            .'<input type="hidden" name="_token" value="'.self::e((string) csrf_token()).'">'
            .'<input type="hidden" name="_action" value="delete">'
            .'<input type="hidden" name="id" value="'.(int) $row->id.'">'
            .'<button type="submit" class="g-btn g-btn--ghost" style="color:#b91c1c;border-color:#fecaca">删除这条记录</button>'
            .'</form>'
            .($row->url ? '<a class="g-btn g-btn--soft" href="'.self::e(self::adminBase().'/geo-audit?url='.urlencode((string) $row->url).'&auto=1').'">重新诊断这个网址</a>' : '')
            .'</div>'
            .'<div class="g-card"><h3>'.self::e((string) ($row->title ?: $kindLabel)).'</h3>'
            .'<p class="g-cap">'.self::e((string) $row->url).'</p>'
            .'<div class="g-row" style="margin-bottom:6px">'.$tags.'</div></div>'
            .$compare
            .'<div class="g-card"><div class="g-md">'.$html.'</div></div>';

        return self::shell($body, 'GEO 记录详情');
    }

    /**
     * 诊断报告页顶部的「相较上次」横幅。
     *
     * @param  array<string,mixed>  $current
     */
    public static function compareBanner(array $current, ?object $prevAudit): string
    {
        if ($prevAudit === null) {
            return '';
        }
        $prev = json_decode((string) ($prevAudit->meta ?? '{}'), true) ?: [];
        if (! isset($prev['total'])) {
            return '';
        }
        $nowTotal = (int) ($current['total'] ?? 0);
        $prevTotal = (int) $prev['total'];
        $delta = $nowTotal - $prevTotal;

        // 没有任何变化时不显示对比模块（避免 49→49(0) 这种噪音）
        $changedDims = 0;
        foreach ((array) ($current['dimension_scores'] ?? []) as $k => $v) {
            if (isset($prev['dimension_scores'][$k]) && (int) $v !== (int) $prev['dimension_scores'][$k]) {
                $changedDims++;
            }
        }
        if ($delta === 0 && $changedDims === 0) {
            return '';
        }
        $cls = $delta > 0 ? 'g-up' : ($delta < 0 ? 'g-down' : '');
        $sign = $delta > 0 ? '+' : '';
        $when = (string) ($prevAudit->created_at ?? '');

        return '<div class="g-card" style="border-left:4px solid '.($delta >= 0 ? '#10b981' : '#ef4444').'">'
            .'<h3>相较上次诊断</h3>'
            .'<p class="g-cap">上次：'.self::e($when).' · 共'.count((array) ($prev['dimension_scores'] ?? [])).' 个维度可比</p>'
            .'<div style="font-size:22px;font-weight:750;letter-spacing:-.5px">'
            .$prevTotal.' → '.$nowTotal.' <span class="'.$cls.'">('.$sign.$delta.')</span></div>'
            .self::compareTable($current, $prev, $when, true)
            .'</div>';
    }

    /** @param array<string,mixed> $cur @param array<string,mixed> $prev */
    private static function compareTable(array $cur, array $prev, string $prevWhen, bool $compact = false): string
    {
        $curDims = (array) ($cur['dimension_scores'] ?? []);
        $prevDims = (array) ($prev['dimension_scores'] ?? []);
        if ($curDims === [] || $prevDims === []) {
            return '';
        }
        $rows = '';
        foreach ($curDims as $name => $now) {
            if (! array_key_exists($name, $prevDims)) {
                continue;
            }
            $before = (int) $prevDims[$name];
            $now = (int) $now;
            $d = $now - $before;
            $cls = $d > 0 ? 'g-up' : ($d < 0 ? 'g-down' : '');
            $rows .= '<tr><td>'.self::e((string) $name).'</td><td>'.$before.'</td><td>'.$now.'</td>'
                .'<td class="'.$cls.'">'.($d > 0 ? '+' : '').$d.'</td></tr>';
        }
        if ($rows === '') {
            return '';
        }

        return '<table class="g-table" style="margin-top:12px"><thead><tr><th>维度</th><th>上次</th><th>本次</th><th>变化</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table>'
            .($compact ? '' : '<p class="g-cap" style="margin-top:8px">对比基准：'.self::e($prevWhen).'</p>');
    }

    private static function shell(string $body, string $title): string
    {
        return '<style>'.GeoUi::css().'</style>'
            .'<div class="geo-ui geo-history" data-geo-title="'.self::e($title).'" style="max-width:1080px;margin:0 auto;padding:4px 4px 48px;color:#111827;font:14px/1.7 var(--g-font)">'
            .$body.'</div>'.GeoUi::busyOverlay();
    }

    private static function markdown(string $md): string
    {
        if (method_exists(\Illuminate\Support\Str::class, 'markdown')) {
            try {
                return (string) \Illuminate\Support\Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
            } catch (\Throwable $e) {
                // 回落到简易渲染，但留痕：否则 markdown 扩展出问题时只会看到排版变差，无从排查
                \Illuminate\Support\Facades\Log::warning(
                    'geo_history.markdown_render_failed: '.mb_substr($e->getMessage(), 0, 300, 'UTF-8')
                );
            }
        }
        $html = self::e($md);
        $html = preg_replace('/^### (.+)$/mu', '<h4>$1</h4>', $html) ?? $html;
        $html = preg_replace('/^## (.+)$/mu', '<h3>$1</h3>', $html) ?? $html;
        $html = preg_replace('/^# (.+)$/mu', '<h2>$1</h2>', $html) ?? $html;

        return nl2br($html);
    }

    private static function adminBase(): string
    {
        return rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
    }

    private static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
