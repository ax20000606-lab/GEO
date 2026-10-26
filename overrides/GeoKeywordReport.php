<?php

namespace App\Support\GeoAudit;

/**
 * 关键词挖掘 —— 表单页 / 结果页
 */
final class GeoKeywordReport
{
    /** @param array<string,mixed> $old @param list<object> $libraries */
    public static function formPage(array $old = [], array $libraries = [], string $error = ''): string
    {
        $industry = self::e((string) ($old['industry'] ?? ''));
        $brand = self::e((string) ($old['brand'] ?? ''));
        $audience = self::e((string) ($old['audience'] ?? ''));
        $seed = self::e((string) ($old['seed'] ?? ''));
        $collect = (string) ($old['collect'] ?? '1') === '1';

        $libOptions = '';
        foreach ($libraries as $lib) {
            $libOptions .= '<option value="'.(int) $lib->id.'">'.self::e((string) $lib->name).'（现有 '.((int) ($lib->keyword_count ?? 0)).' 词）</option>';
        }

        $err = $error !== '' ? '<div class="g-err">'.self::e($error).'</div>' : '';

        $body = '<div class="g-card"><h3>关键词挖掘</h3>'
            .'<p class="g-cap">两步走：AI 挖掘用户真实问法 → 百度搜索框真实联想词 → 一起写进关键词库</p>'
            .$err
            .'<form method="get" action="" id="kw-form">'
            .'<input type="hidden" name="run" value="1">'
            .'<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">'
            .'<label style="font-size:12.5px;font-weight:600;color:#374151">行业 / 品类'
            .'<input type="text" name="industry" value="'.$industry.'" placeholder="例如：功效护肤 / 男士控油洁面" style="width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13.5px"></label>'
            .'<label style="font-size:12.5px;font-weight:600;color:#374151">品牌 / 产品（可选）'
            .'<input type="text" name="brand" value="'.$brand.'" placeholder="例如：冰溪" style="width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13.5px"></label>'
            .'<label style="font-size:12.5px;font-weight:600;color:#374151">目标受众（可选）'
            .'<input type="text" name="audience" value="'.$audience.'" placeholder="例如：敏感肌人群、25–35 岁男性" style="width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13.5px"></label>'
            .'<label style="font-size:12.5px;font-weight:600;color:#374151">起始关键词（可选）'
            .'<input type="text" name="seed" value="'.$seed.'" placeholder="例如：敏感肌护肤品" style="width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13.5px"></label>'
            .'</div>'
            .'<label style="display:flex;align-items:center;gap:8px;margin-top:16px;font-size:12.5px;font-weight:600;color:#374151">'
            .'<input type="checkbox" name="collect" value="1"'.($collect ? ' checked' : '').' style="accent-color:#4f46e5">'
            .'同时采集百度搜索框联想词（真实补全数据，约 1–2 秒/词）</label>'
            .'<div class="g-row" style="margin-top:18px">'
            .'<button type="submit" class="g-btn g-btn--primary" id="kw-submit">开始挖掘（约 15–25 秒）</button>'
            .'<span class="g-cap" style="margin:0">AI 给的是"用户会怎么问"的推测；联想词才是真实搜索数据</span></div>'
            .'</form></div>'
            .'<div class="g-card"><h3>数据来源与边界</h3>'
            .'<table class="g-table" style="font-size:12.5px"><thead><tr><th>来源</th><th>性质</th><th>能不能用</th></tr></thead><tbody>'
            .'<tr><td>AI 意图挖掘</td><td>模型推测（基于行业常识）</td><td>可以当选题方向，<b>不能当搜索量</b></td></tr>'
            .'<tr><td>百度联想词</td><td><b>真实补全数据</b>（公开接口）</td><td>可以当关键词依据</td></tr>'
            .'<tr><td>小红书 / 抖音联想词</td><td>需登录态与签名</td><td>服务端无法采集，<b>不伪造</b>，请人工到站内搜索框看</td></tr>'
            .'</tbody></table>'
            .'<p class="g-cap" style="margin:10px 0 0">本工具不生成截图：服务端没有浏览器环境。留痕以"采集时间 + 来源接口"为准。</p>'
            .'</div>'
            .'<script>(function(){var f=document.getElementById("kw-form");if(!f)return;'
            .'f.addEventListener("submit",function(){var b=document.getElementById("kw-submit");'
            .'if(b){b.disabled=true;b.style.opacity=".65";b.textContent="挖掘中…";}'
            .'if(window.geoBusy){window.geoBusy("正在挖掘关键词…","先由模型生成问题集，再逐个采集百度联想词，约 15–25 秒");}});})();</script>';

        return self::shell($body, '关键词挖掘');
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  list<object>  $libraries
     */
    public static function resultPage(array $result, array $libraries, string $adminBase): string
    {
        $questions = (array) ($result['questions'] ?? []);
        $suggests = (array) ($result['suggests'] ?? []);
        $flat = (array) ($result['flat'] ?? []);

        // 问题集按意图分组
        $byIntent = [];
        foreach ($questions as $q) {
            $byIntent[$q['intent']][] = $q;
        }
        $qBlocks = '';
        foreach ($byIntent as $intent => $items) {
            $lis = '';
            foreach ($items as $it) {
                $lis .= '<li style="margin:4px 0">'.self::e($it['question'])
                    .($it['audience'] !== '' ? ' <span class="g-tag">'.self::e($it['audience']).'</span>' : '').'</li>';
            }
            $qBlocks .= '<div style="margin-top:12px"><b style="font-size:13px">'.$intent.'（'.count($items).'）</b><ul style="margin:6px 0 0;padding-left:20px;font-size:12.5px;color:#374151">'.$lis.'</ul></div>';
        }

        // 联想词
        $sBlocks = '';
        foreach ($suggests as $seed => $words) {
            if ($words === []) {
                $sBlocks .= '<div style="margin-top:10px;font-size:12.5px"><b>'.self::e((string) $seed).'</b>：<span style="color:#b45309">未获取到联想词</span></div>';
                continue;
            }
            $chips = '';
            foreach ($words as $w) {
                $chips .= '<span class="g-tag" style="margin:3px 4px 0 0;display:inline-block">'.self::e((string) $w).'</span>';
            }
            $sBlocks .= '<div style="margin-top:10px"><b style="font-size:12.5px">'.self::e((string) $seed).'</b>（'.count($words).'）<div style="margin-top:4px">'.$chips.'</div></div>';
        }

        // 可写入的词（checkbox）
        $rows = '';
        foreach ($flat as $i => $word) {
            $rows .= '<tr>'
                .'<td style="border:1px solid #e5e7eb;padding:7px 9px;width:32px;text-align:center">'
                .'<input type="checkbox" class="kw-pick" value="'.$i.'" checked style="accent-color:#4f46e5"></td>'
                .'<td style="border:1px solid #e5e7eb;padding:7px 9px">'.self::e((string) $word).'</td></tr>';
        }

        $libOptions = '';
        foreach ($libraries as $lib) {
            $libOptions .= '<option value="'.(int) $lib->id.'">'.self::e((string) $lib->name).'（现有 '.((int) ($lib->keyword_count ?? 0)).' 词）</option>';
        }

        $body = '<div class="g-toolbar">'
            .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/keyword-miner">← 重新挖掘</a>'
            .'<span class="g-sep">模型 '.self::e((string) ($result['model'] ?? '')).' · 耗时 '.number_format(((int) ($result['elapsed_ms'] ?? 0)) / 1000, 1).' 秒 · tokens '.((int) ($result['usage']['total_tokens'] ?? 0)).'</span>'
            .'</div>'
            .'<div class="g-card"><h3>① AI 意图挖掘：'.count($questions).' 个问法</h3>'
            .'<p class="g-cap">模型推测的"用户会怎么问"，用于选题方向；<b>不是搜索量数据</b></p>'
            .($qBlocks !== '' ? $qBlocks : '<p class="g-cap">未生成问题</p>').'</div>'
            .'<div class="g-card"><h3>② 百度联想词采集</h3>'
            .'<p class="g-cap">真实补全数据，采集时间 '.date('Y-m-d H:i').'　来源：suggestion.baidu.com</p>'
            .($sBlocks !== '' ? $sBlocks : '<p class="g-cap">未采集（可能未勾选或接口无返回）</p>').'</div>'
            .'<div class="g-card"><h3>③ 写入关键词库：'.count($flat).' 个词</h3>'
            .'<p class="g-cap">勾选要入库的词（问题与联想词已合并去重），重复词会自动跳过</p>'
            .'<div style="max-height:320px;overflow:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px">'
            .'<thead><tr><th style="border:1px solid #e5e7eb;padding:7px 9px;background:#f9fafb"><input type="checkbox" id="kw-all" checked style="accent-color:#4f46e5"></th>'
            .'<th style="border:1px solid #e5e7eb;padding:7px 9px;background:#f9fafb;text-align:left">关键词</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table></div>'
            .'<div class="g-row" style="margin-top:16px">'
            .'<label style="font-size:12.5px;font-weight:600;color:#374151;display:flex;align-items:center;gap:8px">写入到'
            .'<select id="kw-lib" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:9px;font-size:13px">'.$libOptions.'</select></label>'
            .'<a class="g-btn g-btn--primary" id="kw-save" href="?save=1&picked=all&lib=0">写入关键词库</a>'
            .'</div>'
            .'<p class="g-cap" style="margin:10px 0 0">写入后可在「内容资产 → 关键词库」查看，也能被 AI 可见性采集直接使用</p></div>'
            .'<script>(function(){var all=document.getElementById("kw-all");var boxes=document.querySelectorAll(".kw-pick");'
            .'var btn=document.getElementById("kw-save");var lib=document.getElementById("kw-lib");'
            .'function sync(){var ids=[];for(var i=0;i<boxes.length;i++){if(boxes[i].checked){ids.push(boxes[i].value);}}'
            .'btn.setAttribute("href","?save=1&picked="+(ids.join(",")||"none")+"&lib="+(lib?lib.value:"0"));'
            .'btn.style.opacity=ids.length?"1":".5";}'
            .'if(all){all.addEventListener("change",function(){for(var i=0;i<boxes.length;i++){boxes[i].checked=all.checked;}sync();});}'
            .'for(var i=0;i<boxes.length;i++){boxes[i].addEventListener("change",sync);}'
            .'if(lib){lib.addEventListener("change",sync);}sync();})();</script>';

        return self::shell($body, '关键词挖掘结果');
    }

    /** @param list<string> $words */
    public static function savedPage(array $words, int $inserted, int $skipped, string $libName, string $adminBase): string
    {
        $chips = '';
        foreach (array_slice($words, 0, 60) as $w) {
            $chips .= '<span class="g-tag" style="margin:3px 4px 0 0;display:inline-block">'.self::e($w).'</span>';
        }

        $body = '<div class="g-card"><h3>已写入关键词库</h3>'
            .'<p class="g-cap">目标词库：'.self::e($libName).'　新增 '.$inserted.' 条，跳过重复 '.$skipped.' 条</p>'
            .'<div class="g-row" style="margin:12px 0">'
            .'<a class="g-btn g-btn--primary" href="'.$adminBase.'/keyword-libraries">去关键词库查看</a>'
            .'<a class="g-btn g-btn--soft" href="'.$adminBase.'/keyword-miner">继续挖掘</a>'
            .'<a class="g-btn g-btn--ghost" href="'.$adminBase.'/analytics/ai-visibility">用这些词做 AI 可见性采集</a></div>'
            .($chips !== '' ? '<p class="g-cap" style="margin-top:14px">本次写入的词：</p><div>'.$chips.'</div>' : '')
            .'</div>';

        return self::shell($body, '关键词已入库');
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
