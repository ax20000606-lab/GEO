<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;

/**
 * 单篇文章的 GEO 十维体检页。
 *
 * 为什么单独一页而不是塞在编辑页里：
 *   · 编辑页是 Vditor 富文本，插进去会被编辑器样式吃掉；
 *   · 十维数据量大（每维还要展示命中示例），塞进编辑页会把正文挤走；
 *   · 独立页可以做得好看（彩色维度条 + 分数 + 建议），这是用户明确要的。
 *
 * 配色：每个维度用它自己在数据里的 color（和参考版一致），
 * 不再统一用绿色——统一色分不出维度，也看不出哪几维弱。
 */
final class GeoCheckReport
{
    /**
     * 体检页。
     */
    public static function page(int $articleId): string
    {
        $base = url(rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/'));
        $row = null;
        try {
            $row = DB::table('articles')->where('id', $articleId)->first(['id', 'title', 'content', 'geo_scoring', 'original_keyword', 'updated_at']);
        } catch (\Throwable) {
            $row = null;
        }

        if ($row === null) {
            return self::shell('<div class="g-card"><h3>文章不存在</h3><p class="g-cap">这篇文章可能已被删除。</p></div>', 'GEO 体检');
        }

        $data = json_decode((string) ($row->geo_scoring ?? ''), true);
        $runBtn = self::runForm($articleId, '开始体检');

        if (! is_array($data)) {
            return self::shell(
                '<div class="g-topbar"><a class="g-btn g-btn--ghost" href="'.self::e($base.'/articles').'">← 返回内容工坊</a></div>'
                .'<div class="g-card"><h3>这篇还没有体检结果</h3>'
                .'<p class="g-cap">体检是纯本地分析（十个维度逐条扫正文），不调模型、不花钱，点一下几秒就出。</p>'
                .'<p class="g-cap">不管这篇文章是 AI 生成的、手写的还是粘贴的，都能体检。</p>'
                .'<div style="margin-top:16px">'.$runBtn.'</div></div>',
                'GEO 体检'
            );
        }

        $scoring = (array) ($data['scoring'] ?? []);
        $dims = (array) ($scoring['dimensions'] ?? []);
        $total = (float) ($scoring['total'] ?? 0);
        $grade = (string) ($scoring['grade'] ?? '');
        $gradeColor = (string) ($scoring['grade_color'] ?? '#6b7280');
        $check = (array) ($data['platform_check'] ?? []);
        $platformSlug = (string) ($data['platform'] ?? '');
        $platformName = $platformSlug !== ''
            ? (string) (GeoPlatforms::all()[$platformSlug]['name'] ?? $platformSlug)
            : '通用';

        // ---- 顶部摘要 ----
        $head = '<div class="gc-head">'
            .'<div class="gc-score-wrap">'
            .'<div class="gc-score" style="color:'.self::e($gradeColor).'">'.number_format($total, 1).'</div>'
            .'<div class="gc-score-cap">/ 100</div>'
            .'<div class="gc-grade" style="background:'.$gradeColor.'1a;color:'.$gradeColor.'">'.self::e($grade).'</div>'
            .'</div>'
            .'<div class="gc-meta">'
            .'<h1>'.self::e((string) $row->title).'</h1>'
            .'<div class="gc-tags">'
            .'<span class="gc-tag">平台 · '.self::e($platformName).'</span>'
            .($check !== [] ? '<span class="gc-tag">平台合规 '.(int) ($check['pass'] ?? 0).'/'.(int) ($check['total'] ?? 0).'</span>' : '')
            .((string) ($row->original_keyword ?? '') !== '' ? '<span class="gc-tag">原题 · '.self::e(mb_substr((string) $row->original_keyword, 0, 24, 'UTF-8')).'</span>' : '')
            .'<span class="gc-tag">体检于 '.self::e((string) ($data['graded_at'] ?? '')).'</span>'
            .'</div></div></div>';

        // ---- 十维 ----
        $dimHtml = '';
        foreach ($dims as $d) {
            $score = (int) ($d['score'] ?? 0);
            $weight = (int) ($d['weight'] ?? 0);
            $weighted = (float) ($d['weighted'] ?? 0);
            $color = (string) ($d['color'] ?? '#9ca3af');
            $name = (string) ($d['name'] ?? '');
            $desc = (string) ($d['desc'] ?? '');
            $mark = (string) ($d['mark'] ?? '');
            $hits = (int) ($d['hits'] ?? 0);
            $examples = (array) ($d['examples'] ?? []);

            $exHtml = '';
            foreach (array_slice($examples, 0, 6) as $ex) {
                $exHtml .= '<span class="gc-ex" style="background:'.$color.'1a;color:'.$color.'">'
                    .self::e(mb_substr((string) $ex, 0, 20, 'UTF-8')).'</span>';
            }

            $dimHtml .= '<div class="gc-dim">'
                .'<div class="gc-dim-head">'
                .'<span class="gc-dim-mark" style="background:'.$color.'">'.$mark.'</span>'
                .'<b class="gc-dim-name">'.self::e($name).'</b>'
                .'<span class="gc-dim-weight">权重 '.$weight.'%</span>'
                .'<span class="gc-dim-score" style="color:'.$color.'">'.$score.'<i>/100</i></span>'
                .'</div>'
                .'<div class="gc-bar"><i style="width:'.$score.'%;background:'.$color.'"></i></div>'
                .'<div class="gc-dim-desc">'.self::e($desc).'</div>'
                .($exHtml !== ''
                    ? '<div class="gc-ex-wrap">命中 '.$hits.' 处：'.$exHtml.'</div>'
                    : '<div class="gc-ex-wrap" style="color:#b45309">这维没检测到有效信号——补具体成分/数字/来源能直接拉分</div>')
                .'<div class="gc-weighted">贡献 <b style="color:'.$color.'">'.number_format($weighted, 1).'</b> 分</div>'
                .'</div>';
        }

        // ---- 平台合规明细 ----
        $checkHtml = '';
        if ($check !== []) {
            $rows = '';
            foreach ((array) ($check['checks'] ?? []) as $c) {
                $ok = ! empty($c['ok']);
                $rows .= '<div class="gc-chk">'
                    .'<span class="gc-chk-icon" style="background:'.($ok ? '#ecfdf5' : '#fef2f2').';color:'.($ok ? '#047857' : '#b91c1c').'">'
                    .($ok ? '✓' : '✕').'</span>'
                    .'<div><b>'.self::e((string) ($c['label'] ?? '')).'</b>'
                    .'<div class="gc-chk-detail">'.self::e((string) ($c['detail'] ?? '')).'</div></div>'
                    .'</div>';
            }
            if ($rows !== '') {
                $checkHtml = '<div class="g-card"><h3>平台合规检查</h3>'
                    .'<p class="g-cap">生成时按「'.self::e($platformName).'」的结构和字数要求做的核对。</p>'
                    .$rows.'</div>';
            }
        }

        // 正文 + 高亮标注
        $articleHtml = self::highlight((string) ($row->content ?? ''), $dims);

        $body = '<div class="gc-topbar">'
            .'<a class="g-btn g-btn--ghost" href="'.self::e($base.'/articles').'">← 返回内容工坊</a>'
            .'<div style="display:flex;gap:10px;align-items:center">'
            .self::runForm($articleId, '重新体检')
            .'<a class="g-btn" href="'.self::e($base.'/articles/'.$articleId.'/edit').'">编辑这篇文章</a>'
            .'</div>'
            .'</div>'
            .'<div class="g-card">'.$head.'</div>'
            .'<div class="gc-cols">'
            .'<div class="g-card gc-article"><h3>正文 · 标注视图</h3>'
            .'<p class="g-cap">彩色高亮是十维扫描命中的信号，颜色对应右边那一维。'
            .'<b>大片没颜色的段落，就是 AI 抓不到东西的部分。</b></p>'
            .'<div class="gc-body">'.$articleHtml.'</div></div>'
            .'<div class="g-card gc-side"><h3>十维体检</h3>'
            .'<p class="g-cap">每维 0–100 分，按权重折算总分。<b>分低的维度就是最该改的地方</b>。</p>'
            .'<div class="gc-dims">'.$dimHtml.'</div>'
            .'<div class="gc-legend">'.self::legend($dims).'</div>'
            .'</div></div>'
            .$checkHtml;

        return self::shell($body, 'GEO 体检');
    }

    /**
     * 对现有文章跑一次体检（不管文章是怎么来的：官方生成、手写、粘贴都行）。
     *
     * 正文存的是 HTML，而评分器吃 markdown，所以要先把结构还原：
     * h1-h6 → #，li → -，strong → **，其余标签剥掉。
     * 不还原结构的话，「组织序号」「关键结论」这些维度会全部误判成 0 分。
     *
     * @return array{ok:bool,total:float,grade:string,error:string}
     */
    public static function run(int $articleId): array
    {
        $fail = static fn (string $msg): array => ['ok' => false, 'total' => 0.0, 'grade' => '', 'error' => $msg];

        try {
            $row = DB::table('articles')->where('id', $articleId)->first(['id', 'content']);
        } catch (\Throwable $e) {
            return $fail('读取文章失败');
        }
        if ($row === null) {
            return $fail('文章不存在');
        }

        $html = (string) ($row->content ?? '');
        if (trim(strip_tags($html)) === '') {
            return $fail('这篇文章还没有正文内容，先写点什么再体检');
        }

        $markdown = self::htmlToMarkdown($html);
        $scoring = GeoScorer::score($markdown);
        if (empty($scoring['ok'])) {
            return $fail('评分器没有返回结果');
        }

        try {
            $existing = json_decode((string) (DB::table('articles')->where('id', $articleId)->value('geo_scoring') ?? ''), true);
            $payload = is_array($existing) ? $existing : [];
            $payload['scoring'] = $scoring;
            $payload['graded_at'] = now()->toDateTimeString();
            $payload['source'] = 'manual_run';
            DB::table('articles')->where('id', $articleId)->update([
                'geo_scoring' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            return $fail('保存体检结果失败');
        }

        return [
            'ok' => true,
            'total' => (float) ($scoring['total'] ?? 0),
            'grade' => (string) ($scoring['grade'] ?? ''),
            'error' => '',
        ];
    }

    /** 简易 HTML → Markdown，够评分器识别结构就行 */
    private static function htmlToMarkdown(string $html): string
    {
        $s = $html;
        $s = preg_replace('/<(h[1-6])[^>]*>(.*?)<\/\1>/isu', "

".'$1'."PLACEHOLDER$2

", $s) ?? $s;

        // 还原标题标记
        for ($i = 1; $i <= 6; $i++) {
            $s = preg_replace('/h'.$i.'PLACEHOLDER/isu', str_repeat('#', $i).' ', $s) ?? $s;
        }

        $s = preg_replace('/<li[^>]*>(.*?)<\/li>/isu', "- $1
", $s) ?? $s;
        $s = preg_replace('/<(strong|b)[^>]*>(.*?)<\/\1>/isu', '**$2**', $s) ?? $s;
        $s = preg_replace('/<br\s*\/?>/iu', "
", $s) ?? $s;
        $s = preg_replace('/<\/(p|div|ul|ol|table|tr)>/iu', "

", $s) ?? $s;
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\n{3,}/u', "

", $s) ?? $s;

        return trim($s);
    }

    /**
     * 正文高亮：把十维命中的词在正文里标上对应颜色。
     *
     * 用占位符而不是直接替换——同一个词可能在多个维度命中，
     * 直接 str_replace 会互相嵌套出坏 HTML。
     */
    private static function highlight(string $html, array $dims): string
    {
        if (trim(strip_tags($html)) === '') {
            return '<p class="gc-empty">这篇文章还没有正文。</p>';
        }

        // 词 => 颜色（长词优先，避免短词把长词切碎）
        $map = [];
        foreach ($dims as $d) {
            $color = (string) ($d['color'] ?? '#9ca3af');
            foreach ((array) ($d['examples'] ?? []) as $ex) {
                $w = trim((string) $ex);
                if ($w !== '' && mb_strlen($w, 'UTF-8') >= 2) {
                    if (! isset($map[$w])) {
                        $map[$w] = $color;
                    }
                }
            }
        }
        if ($map === []) {
            return '<div class="gc-body">'.$html.'</div>';
        }
        uksort($map, static fn ($a, $b): int => mb_strlen((string) $b, 'UTF-8') <=> mb_strlen((string) $a, 'UTF-8'));

        // 先把命中词换成占位符
        $store = [];
        $i = 0;
        foreach ($map as $w => $color) {
            $ph = "\x02GEOHL".($i++)."\x03";
            $store[$ph] = '<span class="gc-hl" style="background:'.$color.'24;border-bottom:2px solid '.$color.'" title="命中：'.$color.'">'.$w.'</span>';
            // 只在标签外的文本里替换
            $html = preg_replace_callback('/<[^>]+>|[^<]+/', static function ($m) use ($w, $ph) {
                return ($m[0] !== '' && $m[0][0] === '<') ? $m[0] : str_replace($w, $ph, $m[0]);
            }, $html) ?? $html;
        }

        // 还原占位符
        return '<div class="gc-body">'.strtr($html, $store).'</div>';
    }

    /** 左侧图例：每维颜色 + 命中次数 */
    private static function legend(array $dims): string
    {
        $items = '';
        foreach ($dims as $d) {
            $color = (string) ($d['color'] ?? '#9ca3af');
            $items .= '<div class="gc-lg-item">'
                .'<i class="gc-lg-dot" style="background:'.$color.'"></i>'
                .'<span>'.self::e((string) ($d['name'] ?? '')).'</span>'
                .'<b style="color:'.$color.'">'.(int) ($d['hits'] ?? 0).'</b>'
                .'</div>';
        }

        return $items === '' ? '' : '<h3 style="margin-top:18px">标注图例</h3>'
            .'<p class="g-cap">数字是该维在正文里命中的处数。</p><div class="gc-lg">'.$items.'</div>';
    }

    /** 「跑体检」按钮 */
    private static function runForm(int $articleId, string $label): string
    {
        $action = url(rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/').'/articles/'.$articleId.'/geo-check/run');
        $csrf = '';
        try {
            $t = (string) csrf_token();
            if ($t !== '') {
                $csrf = '<input type="hidden" name="_token" value="'.self::e($t).'">';
            }
        } catch (\Throwable) {
        }

        return '<form method="post" action="'.self::e($action).'" style="display:inline;margin:0">'.$csrf
            .'<button type="submit" class="g-btn g-btn--primary" style="background:linear-gradient(135deg,#7c3aed,#4f46e5);border:none">'
            .'◎ '.self::e($label).'</button></form>';
    }

    private static function shell(string $body, string $title): string
    {
        return '<style>'.self::css().'</style>'
            .'<div class="geo-ui gc-wrap" data-geo-title="'.self::e($title).'">'
            .'<style>'.GeoUi::css().'</style>'
            .$body.'</div>'.GeoUi::busyOverlay();
    }

    private static function css(): string
    {
        return <<<'CSS'
.gc-wrap{max-width:1000px;margin:0 auto;padding:4px 4px 56px;font-family:-apple-system,"Segoe UI","Microsoft YaHei",sans-serif;font-size:14px;line-height:1.75;color:#111827}
.gc-topbar{display:flex;gap:10px;justify-content:space-between;margin-bottom:14px}
.gc-head{display:flex;gap:22px;align-items:center;flex-wrap:wrap}
.gc-score-wrap{display:flex;align-items:baseline;gap:4px;flex-wrap:wrap}
.gc-score{font-size:46px;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.gc-score-cap{font-size:13px;color:#9ca3af;margin-right:10px}
.gc-grade{font-size:12.5px;font-weight:700;padding:3px 11px;border-radius:99px}
.gc-meta{flex:1;min-width:240px}
.gc-meta h1{font-size:17px;font-weight:700;margin:0 0 6px;line-height:1.5}
.gc-tags{display:flex;gap:6px;flex-wrap:wrap}
.gc-tag{font-size:11.5px;color:#6b7280;background:#f3f4f6;padding:2px 9px;border-radius:99px}
.gc-dims{margin-top:14px}
.gc-dim{padding:14px 0;border-bottom:1px solid #f3f4f6}
.gc-dim:last-child{border-bottom:none}
.gc-dim-head{display:flex;align-items:center;gap:9px;margin-bottom:7px;flex-wrap:wrap}
.gc-dim-mark{width:19px;height:19px;border-radius:5px;color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.gc-dim-name{font-size:13.5px;color:#111827}
.gc-dim-weight{font-size:11px;color:#9ca3af}
.gc-dim-score{margin-left:auto;font-size:19px;font-weight:800;font-variant-numeric:tabular-nums}
.gc-dim-score i{font-size:11px;font-weight:400;color:#9ca3af;font-style:normal}
.gc-bar{height:7px;background:#f3f4f6;border-radius:4px;overflow:hidden;margin-bottom:7px}
.gc-bar i{display:block;height:100%;border-radius:4px;transition:width .3s}
.gc-dim-desc{font-size:12px;color:#6b7280}
.gc-ex-wrap{margin-top:7px;font-size:11.5px;color:#6b7280}
.gc-ex{display:inline-block;padding:2px 8px;border-radius:5px;margin:2px 4px 2px 0;font-size:11px}
.gc-weighted{margin-top:5px;font-size:11.5px;color:#9ca3af}
.gc-chk{display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-bottom:1px solid #f9fafb}
.gc-chk:last-child{border-bottom:none}
.gc-chk-icon{width:20px;height:20px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;margin-top:1px}
.gc-chk-detail{font-size:11.5px;color:#6b7280;margin-top:1px}
.gc-cols{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:14px;align-items:start}
@media (max-width:900px){.gc-cols{grid-template-columns:1fr}}
.gc-article{position:relative}
.gc-body{max-height:74vh;overflow:auto;margin-top:10px;padding-right:6px;font-size:13.5px;line-height:1.85}
.gc-body h1{font-size:18px;font-weight:700;margin:14px 0 8px}
.gc-body h2{font-size:15.5px;font-weight:700;margin:16px 0 7px;padding-left:9px;border-left:3px solid #e5e7eb}
.gc-body h3{font-size:14px;font-weight:600;margin:12px 0 6px}
.gc-body p{margin:9px 0}
.gc-body ul,.gc-body ol{margin:9px 0 9px 20px}
.gc-body li{margin:4px 0}
.gc-hl{border-radius:3px;padding:0 1px}
.gc-empty{color:#9ca3af}
.gc-side{position:sticky;top:12px}
.gc-legend{margin-top:6px;border-top:1px solid #f3f4f6;padding-top:12px}
.gc-lg{display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;margin-top:8px}
.gc-lg-item{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#6b7280}
.gc-lg-dot{width:9px;height:9px;border-radius:3px;flex-shrink:0}
CSS;
    }

    private static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
