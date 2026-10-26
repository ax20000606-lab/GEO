<?php

namespace App\Support\GeoAudit;

/**
 * 知识库批量导入 —— 表单页 / 结果页
 */
final class GeoKbReport
{
    /** @param array<string,mixed> $old */
    public static function formPage(array $old = [], string $error = ''): string
    {
        $urls = self::e((string) ($old['urls'] ?? ''));
        $title = self::e((string) ($old['title'] ?? ''));
        $err = $error !== '' ? '<div class="g-err">'.self::e($error).'</div>' : '';

        $body = '<div class="g-card"><h3>批量导入研究资料</h3>'
            .'<p class="g-cap">把分散的网页一次抓下来，清洗成带来源标注的资料稿；粘贴到知识库后系统会自动切片与向量化</p>'
            .$err
            .'<form method="get" action="" id="kb-form">'
            .'<input type="hidden" name="run" value="1">'
            .'<label style="display:block;font-size:12.5px;font-weight:600;color:#374151">资料标题（可选）'
            .'<input type="text" name="title" value="'.$title.'" placeholder="例如：冰溪品牌与成分资料汇编" '
            .'style="width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13.5px"></label>'
            .'<label style="display:block;font-size:12.5px;font-weight:600;color:#374151;margin-top:14px">网址列表（每行一个，最多 10 个）'
            .'<textarea name="urls" rows="8" placeholder="https://example.com/about\nhttps://example.com/faq\nhttps://example.com/product" '
            .'style="width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13px;font-family:ui-monospace,monospace">'.$urls.'</textarea></label>'
            .'<div class="g-row" style="margin-top:16px">'
            .'<button type="submit" class="g-btn g-btn--primary" id="kb-submit">抓取并合并</button>'
            .'<span class="g-cap" style="margin:0">每个页面约 1–3 秒；纯 JS 渲染的站点可能抓不到正文</span></div>'
            .'</form></div>'
            .'<div class="g-card"><h3>抓取之后怎么用</h3>'
            .'<ol style="margin:0;padding-left:20px;font-size:12.5px;color:#4b5563;line-height:1.9">'
            .'<li>下载合并好的 Markdown</li>'
            .'<li>去「内容资产 → 知识库 → 新建」，把内容粘进去（<b>必须走官方入口，系统才会自动切片和向量化</b>）</li>'
            .'<li>回到官网诊断复测，<code>数据溯源</code> 与 <code>知识一致性</code> 两项会明显改善</li>'
            .'</ol></div>'
            .'<script>(function(){var f=document.getElementById("kb-form");if(!f)return;'
            .'f.addEventListener("submit",function(){var b=document.getElementById("kb-submit");'
            .'if(b){b.disabled=true;b.style.opacity=".65";b.textContent="抓取中…";}'
            .'if(window.geoBusy){window.geoBusy("正在抓取网页…","逐个抓取并清洗正文，通常 5–20 秒，请勿关闭页面");}});})();</script>';

        return self::shell($body, '批量导入资料');
    }

    /** @param array<string,mixed> $result */
    public static function resultPage(array $result, string $adminBase): string
    {
        $rows = '';
        $okCount = 0;
        foreach ((array) ($result['pages'] ?? []) as $p) {
            $ok = (bool) ($p['ok'] ?? false);
            if ($ok) {
                $okCount++;
            }
            $rows .= '<tr>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 10px">'.($ok ? '<span class="g-pill g-pill--ok">成功</span>' : '<span class="g-pill g-pill--bad">失败</span>').'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 10px;max-width:320px;word-break:break-all">'.self::e((string) ($p['url'] ?? '')).'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 10px">'.self::e((string) ($p['title'] ?? '')).'</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 10px;white-space:nowrap">'.(int) ($p['chars'] ?? 0).' 字</td>'
                .'<td style="border:1px solid #e5e7eb;padding:8px 10px;color:#b91c1c">'.self::e((string) ($p['error'] ?? '')).'</td>'
                .'</tr>';
        }

        $md = (string) ($result['markdown'] ?? '');
        $mdEsc = self::e($md);

        $body = '<div class="g-card"><h3>抓取结果：'.$okCount.' / '.count((array) ($result['pages'] ?? [])).' 成功</h3>'
            .'<p class="g-cap">合并后共约 '.number_format((int) ($result['total_chars'] ?? 0)).' 字（已按来源分段并标注出处）</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:12.5px"><thead><tr>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">状态</th>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">网址</th>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">标题</th>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">字数</th>'
            .'<th style="border:1px solid #e5e7eb;padding:8px 10px;background:#f9fafb;text-align:left">失败原因</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .'<div class="g-card"><h3>下一步（两步）</h3>'
            .'<div class="g-row" style="margin-bottom:12px">'
            .'<a class="g-btn g-btn--primary" href="?download=1">下载 Markdown</a>'
            .'<a class="g-btn g-btn--soft" href="'.$adminBase.'/knowledge-bases/create">去新建知识库 →</a>'
            .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/kb-import">再抓一批</a></div>'
            .'<p class="g-cap" style="margin:0 0 10px">下载后把内容粘进「知识库 → 新建」，系统会自动切片与向量化（<b>不要直接改数据库，绕过切片会质检失效</b>）</p>'
            .'<details><summary style="cursor:pointer;font-size:12.5px;font-weight:650;color:#374151">预览合并结果</summary>'
            .'<pre style="margin-top:10px;max-height:420px;overflow:auto">'.$mdEsc.'</pre></details></div>';

        return self::shell($body, '批量导入资料');
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
