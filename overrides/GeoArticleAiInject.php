<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;

/**
 * 在官方「新建文章 / 编辑文章」页面注入「目标平台 + 按平台生成」区块。
 *
 * 为什么用注入而不是改 form.blade.php：
 *   那个文件 1600+ 行，还带 Vditor 编辑器和图片裁剪逻辑，整文件覆盖风险太高。
 *   这里只往 </body> 前塞一段独立脚本，官方模板一个字不动。
 *
 * 为什么生成后跳转而不是原地填充：
 *   Vditor 实例是 IIFE 里的局部变量，外部拿不到，没法 setValue；
 *   但正文存在 hidden textarea（name="content"）里，所以「生成 → 建草稿 → 跳到这篇的编辑页」
 *   打开时内容已经在里面了，效果等价且稳。
 */
final class GeoArticleAiInject
{
    /**
     * 生成注入脚本。
     *
     * @return string 要插到 </body> 前的 HTML/JS
     */
    public static function script(): string
    {
        $base = rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $endpoint = url($base.'/articles/ai-inline');

        $platforms = GeoPlatforms::all();
        // 默认"不限平台"：不是所有文章都为了发某个平台，通用稿也要能生成
        $opts = '<option value="">不限平台（通用写法）</option>';
        foreach ($platforms as $slug => $p) {
            $words = (array) ($p['words'] ?? [0, 0]);
            $range = ((int) ($words[0] ?? 0)).'–'.((int) ($words[1] ?? 0)).'字';
            $opts .= '<option value="'.htmlspecialchars((string) $slug, ENT_QUOTES, 'UTF-8').'">'
                .htmlspecialchars((string) ($p['name'] ?? $slug), ENT_QUOTES, 'UTF-8')
                .'（'.htmlspecialchars($range, ENT_QUOTES, 'UTF-8').'）</option>';
        }

        // 注意：这里要的是给 JS 用的字符串。之前误写成 HTML input 塞进 <script>，
        // 造成语法错误整段脚本不执行（页面看着"注入没生效"）。
        $token = '';
        try {
            $token = (string) csrf_token();
        } catch (\Throwable) {
        }

        $tokenJson = json_encode($token, JSON_UNESCAPED_UNICODE);

        // 「按平台生成」区块已下线：官方「正文提示词」里已经有各平台风格，
        // 用官方「AI 一键生成」选提示词即可，不需要第二个入口。
        return '';
    }

    /**
     * 在官方页面 HTML 里插入区块（插在 </body> 前）。
     */
    public static function injectInto(string $html, int $articleId = 0): string
    {
        if (strpos($html, 'id="geo-ai-bar"') !== false) {
            return $html;
        }
        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return $html;
        }

        // 新建页：放「GEO 质量评分卡」（自动评分，嵌在 AI 一键生成下面）
        // 编辑页：只放右下角小工具条（风格标注 + 体检入口）
        $block = $articleId <= 0 ? self::scoreCard() : self::scoreBar($articleId);

        return $block === '' ? $html : substr($html, 0, $pos).$block.substr($html, $pos);
    }

    /**
     * 生成后自动标注：在编辑页顶部显示这篇的十维评分 + 平台合规检查。
     *
     * 不做成独立页面（用户原来那版是单页 HTML），直接贴在文章里——
     * 文章和它的 GEO 体检结果应该在一起看，分开放又要多点一次。
     */
    /**
     * 编辑页顶部的「GEO 体检」入口按钮。
     *
     * 不再在页面里直接铺十维结果——那是体检页的事。
     * 编辑页只留一个入口，点进去看彩色报告。
     */
    public static function scoreBar(int $articleId): string
    {
        if ($articleId <= 0) {
            return '';
        }

        $base = url(rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/'));
        $hasData = false;
        $total = null;
        try {
            $raw = DB::table('articles')->where('id', $articleId)->value('geo_scoring');
            if ($raw !== null && $raw !== '') {
                $d = json_decode((string) $raw, true);
                if (is_array($d) && isset($d['scoring']['total'])) {
                    $hasData = true;
                    $total = (float) $d['scoring']['total'];
                }
            }
        } catch (\Throwable) {
        }

        $curPlatform = '';
        try {
            $curPlatform = (string) (DB::table('articles')->where('id', $articleId)->value('geo_platform') ?? '');
        } catch (\Throwable) {
        }

        $platOpts = '<option value="">GEO 风格</option>';
        foreach (GeoPlatforms::all() as $slug => $p) {
            $sel = $curPlatform === (string) $slug ? ' selected' : '';
            $platOpts .= '<option value="'.self::esc((string) $slug).'"'.$sel.'>'
                .self::esc((string) ($p['name'] ?? $slug)).'</option>';
        }

        $scoreLabel = $hasData ? number_format((float) $total, 1).' 分' : '未体检';
        $checkUrl = self::esc($base.'/articles/'.$articleId.'/geo-check');
        $setUrl = self::esc($base.'/articles/'.$articleId.'/set-platform');
        $token = '';
        try {
            $token = self::esc((string) csrf_token());
        } catch (\Throwable) {
        }

        // 服务端直出 + 右下角固定：不依赖任何 DOM 锚点，也不受 Vditor 影响。
        // 加折叠按钮，免得挡住右下角的保存按钮。
        return '<div id="geo-side-bar" style="position:fixed;right:16px;bottom:16px;z-index:9997;width:216px;'
            .'background:#fff;border:1px solid #ddd6fe;border-radius:12px;padding:11px 13px;'
            .'box-shadow:0 10px 28px rgba(79,70,229,.20);font-family:-apple-system,\"Microsoft YaHei\",sans-serif">'
            .'<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">'
            .'<b style="font-size:12px;color:#5b21b6">GEO 工具</b>'
            .'<button type="button" id="geo-sb-toggle" style="border:none;background:transparent;cursor:pointer;'
            .'color:#9ca3af;font-size:14px;line-height:1;padding:0">−</button>'
            .'</div>'
            .'<div id="geo-sb-body">'
            .'<label style="font-size:11px;color:#6b7280;display:block;margin-bottom:3px">本文风格</label>'
            .'<select id="geo-plat-sel" style="width:100%;padding:5px 7px;border:1px solid #d1d5db;border-radius:7px;'
            .'font-size:12px;background:#fff;margin-bottom:8px">'.$platOpts.'</select>'
            .'<a href="'.$checkUrl.'" style="display:flex;align-items:center;justify-content:center;gap:6px;'
            .'padding:7px 10px;border-radius:8px;background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;'
            .'font-size:12.5px;font-weight:600;text-decoration:none">◎ GEO 体检 · '.self::esc($scoreLabel).'</a>'
            .'<div id="geo-plat-tip" style="font-size:10.5px;color:#9ca3af;margin-top:6px;text-align:center">'
            .($curPlatform !== '' ? '已标注风格' : '标注风格后列表可区分').'</div>'
            .'</div></div>'
            .'<script>(function(){'
            .'var t=document.getElementById("geo-sb-toggle"),b=document.getElementById("geo-sb-body");'
            .'if(t&&b){t.addEventListener("click",function(){'
            .'var hide=b.style.display==="none";b.style.display=hide?"block":"none";t.textContent=hide?"−":"+";});}'
            .'var sel=document.getElementById("geo-plat-sel"),tip=document.getElementById("geo-plat-tip");'
            .'if(sel&&sel.addEventListener){sel.addEventListener("change",function(){'
            .'var fd=new FormData();fd.append("_token",'.json_encode($token, JSON_UNESCAPED_UNICODE).');'
            .'fd.append("platform",sel.value);if(tip){tip.textContent="保存中…";}'
            .'fetch("'.$setUrl.'",{method:"POST",body:fd,headers:{"X-Requested-With":"XMLHttpRequest"}})'
            .'.then(function(r){return r.json();})'
            .'.then(function(j){if(tip){tip.textContent=(j&&j.ok)?"已标注风格":"保存失败";'
            .'tip.style.color=(j&&j.ok)?"#047857":"#b91c1c";}})'
            .'.catch(function(){if(tip){tip.textContent="保存失败";tip.style.color="#b91c1c";}});'
            .'});}'
            .'})();</script>';
    }

    private static function esc(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 这个请求需不需要注入。
     */
    public static function shouldInject(string $path, string $html): bool
    {
        if (! preg_match('#/articles/(create|\d+/edit)$#', $path)) {
            return false;
        }

        return strpos($html, 'id="title"') !== false && strpos($html, 'content-textarea') !== false;
    }

    /** 新建页（还没有文章 id）只注入评分卡，不注入侧边条 */
    public static function isCreatePage(string $path): bool
    {
        return (bool) preg_match('#/articles/create$#', $path);
    }

    /**
     * 新建文章页的「GEO 质量评分卡」：嵌在「AI 一键生成」下面，正文一变化就自动评分。
     *
     * 为什么放新建页而不是编辑页：编辑页已经有官方的 AI 质量优化，
     * 再放一个体检没意义；而新建页是"生成完立刻看到质量"的最佳位置。
     * 评分是纯本地正则（不调模型、零成本、秒级），所以可以边写边算。
     */
    public static function scoreCard(): string
    {
        $url = self::esc(url(rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/').'/articles/geo-score-preview'));
        $token = '';
        try {
            $token = self::esc((string) csrf_token());
        } catch (\Throwable) {
        }

        $html = <<<HTML
<div id="geo-score-card" style="margin:0 0 16px;border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,.04)">
  <div style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:14px">
    <div style="display:flex;align-items:baseline;gap:3px">
      <span id="geo-sc-total" style="font-size:40px;font-weight:800;line-height:1;font-variant-numeric:tabular-nums">0</span>
      <span style="font-size:12px;color:#9ca3af">/100</span>
    </div>
    <div>
      <div style="font-size:12.5px;color:#6b7280;margin-bottom:3px">GEO 质量评分</div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <span id="geo-sc-grade" style="font-size:12px;font-weight:700;padding:2px 10px;border-radius:99px;background:#f3f4f6;color:#6b7280">待评分</span>
        <span id="geo-sc-meta" style="font-size:11.5px;color:#9ca3af"></span>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:10px">
      <span id="geo-sc-status" style="font-size:11.5px;color:#9ca3af"></span>
      <a href="javascript:;" id="geo-sc-detail" style="display:none;font-size:12.5px;font-weight:600;color:#4f46e5;text-decoration:none">查看详细诊断 →</a>
    </div>
  </div>
  <div id="geo-sc-dims" style="display:grid;grid-template-columns:1fr 1fr;gap:8px 22px">
    <div id="geo-sc-empty" style="grid-column:1/-1;font-size:12.5px;color:#9ca3af;text-align:center;padding:10px 0">
      生成文章后这里会自动出现十维评分（纯本地计算，不额外花钱）
    </div>
  </div>
</div>
<div id="geo-sc-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(17,24,39,.45);padding:24px;overflow:auto">
  <div style="max-width:1000px;margin:0 auto;background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.28);overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid #f3f4f6">
      <div><b style="font-size:15px">GEO 十维诊断</b>
        <span style="font-size:11.5px;color:#9ca3af;margin-left:8px">纯本地分析 · 不调模型</span></div>
      <button type="button" id="geo-sc-close" style="border:none;background:#f3f4f6;width:28px;height:28px;border-radius:8px;cursor:pointer;font-size:15px;color:#6b7280">×</button>
    </div>
    <div id="geo-sc-modal-body" style="padding:18px 22px;max-height:78vh;overflow:auto"></div>
  </div>
</div>
<script>(function(){
  var card=document.getElementById('geo-score-card');
  var ta=document.getElementById('content-textarea');
  if(!card||!ta)return;

  // 插到正文编辑器卡片前面 —— 也就是官方「AI 一键生成」的正下方
  var host=document.getElementById('article-content-editor');
  if(host&&host.parentNode){
    var box=host.closest('.bg-white.shadow,div');
    var anchor=(box&&box!==host)?box:host;
    anchor.parentNode.insertBefore(card,anchor);
  }else{
    ta.parentNode.insertBefore(card,ta);
  }

  var totalEl=document.getElementById('geo-sc-total');
  var gradeEl=document.getElementById('geo-sc-grade');
  var metaEl=document.getElementById('geo-sc-meta');
  var dimsEl=document.getElementById('geo-sc-dims');
  var statusEl=document.getElementById('geo-sc-status');
  var last='',timer=null,busy=false;

  var lastData=null;
  function render(d){
    lastData=d;
    var dt=document.getElementById('geo-sc-detail');
    if(dt){dt.style.display='inline';}
    card.style.display='block';
    totalEl.textContent=(d.total||0).toFixed(1);
    totalEl.style.color=d.grade_color||'#111827';
    gradeEl.textContent=d.grade||'';
    gradeEl.style.background=(d.grade_color||'#6b7280')+'1f';
    gradeEl.style.color=d.grade_color||'#6b7280';
    metaEl.textContent=d.chars+' 字 · '+(d.dims?d.dims.length:0)+' 维';
    var h='';
    (d.dims||[]).forEach(function(x){
      var pct=Math.max(0,Math.min(100,x.score));
      h+='<div style="display:flex;align-items:center;gap:8px">'
        +'<span style="flex-shrink:0;width:64px;font-size:11.5px;color:#6b7280">'+x.name+'</span>'
        +'<div style="flex:1;height:6px;background:#f3f4f6;border-radius:3px;overflow:hidden;min-width:40px">'
        +'<div style="width:'+pct+'%;height:100%;background:'+x.color+';border-radius:3px;transition:width .3s"></div></div>'
        +'<span style="flex-shrink:0;width:26px;text-align:right;font-size:11px;color:'+x.color+';font-weight:600">'+x.score+'</span>'
        +'</div>';
    });
    var empty=document.getElementById('geo-sc-empty');
    if(empty){empty.style.display='none';}
    dimsEl.innerHTML=h;
  }

  function score(v){
    if(busy)return;
    busy=true;statusEl.textContent='评分中…';
    var fd=new FormData();fd.append('_token',"{{TOKEN}}");fd.append('content',v);
    fetch("{{URL}}",{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json();})
      .then(function(j){
        busy=false;
        if(j&&j.ok){render(j);statusEl.textContent='';}
        else{statusEl.textContent=(j&&j.error)||'评分失败';}
      })
      .catch(function(){busy=false;statusEl.textContent='评分失败';});
  }

  var modal=document.getElementById('geo-sc-modal');
  var modalBody=document.getElementById('geo-sc-modal-body');
  var detailBtn=document.getElementById('geo-sc-detail');
  var closeBtn=document.getElementById('geo-sc-close');

  function openDetail(){
    if(!lastData)return;
    var d=lastData,h='';
    h+='<div style="display:flex;align-items:baseline;gap:6px;margin-bottom:4px">'
      +'<span style="font-size:52px;font-weight:800;line-height:1;color:'+(d.grade_color||'#111')+'">'+(d.total||0).toFixed(1)+'</span>'
      +'<span style="font-size:13px;color:#9ca3af">/100</span>'
      +'<span style="margin-left:10px;font-size:12.5px;font-weight:700;padding:3px 12px;border-radius:99px;background:'+(d.grade_color||'#6b7280')+'1f;color:'+(d.grade_color||'#6b7280')+'">'+(d.grade||'')+'</span>'
      +'<span style="margin-left:auto;font-size:12px;color:#9ca3af">'+d.chars+' 字 · 10 个维度</span></div>'
      +'<p style="font-size:12px;color:#6b7280;margin:0 0 16px">分低的维度就是最该改的地方。每维 0–100 分，按权重折算总分。</p>';
    (d.dims||[]).forEach(function(x){
      var pct=Math.max(0,Math.min(100,x.score));
      h+='<div style="padding:13px 0;border-bottom:1px solid #f3f4f6">'
        +'<div style="display:flex;align-items:center;gap:9px;margin-bottom:7px">'
        +'<span style="width:19px;height:19px;border-radius:5px;background:'+x.color+';color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0">'+(x.mark||'')+'</span>'
        +'<b style="font-size:13.5px">'+x.name+'</b>'
        +'<span style="font-size:11px;color:#9ca3af">权重 '+x.weight+'%</span>'
        +'<span style="margin-left:auto;font-size:19px;font-weight:800;color:'+x.color+'">'+x.score
        +'<i style="font-size:11px;font-weight:400;color:#9ca3af;font-style:normal">/100</i></span></div>'
        +'<div style="height:7px;background:#f3f4f6;border-radius:4px;overflow:hidden;margin-bottom:6px">'
        +'<div style="width:'+pct+'%;height:100%;background:'+x.color+';border-radius:4px"></div></div>'
        +'<div style="font-size:12px;color:#6b7280">'+(x.desc||'')+'</div>';
      if(x.examples&&x.examples.length){
        h+='<div style="margin-top:6px;font-size:11.5px;color:#6b7280">命中 '+x.hits+' 处：';
        x.examples.forEach(function(e){
          h+='<span style="display:inline-block;padding:2px 8px;border-radius:5px;margin:2px 4px 2px 0;font-size:11px;background:'+x.color+'1a;color:'+x.color+'">'+e+'</span>';
        });
        h+='</div>';
      }else{
        h+='<div style="margin-top:6px;font-size:11.5px;color:#b45309">这维没检测到有效信号——补具体成分/数字/来源能直接拉分</div>';
      }
      h+='</div>';
    });
    modalBody.innerHTML=h;
    modal.style.display='block';
  }

  if(detailBtn){detailBtn.addEventListener('click',openDetail);}
  if(closeBtn){closeBtn.addEventListener('click',function(){modal.style.display='none';});}
  if(modal){modal.addEventListener('click',function(e){if(e.target===modal)modal.style.display='none';});}

  // 轮询正文：Vditor 把它写进 hidden textarea，没有 input 事件可监听
  setInterval(function(){
    var v=ta.value||'';
    if(v.length<120||v===last)return;
    last=v;
    clearTimeout(timer);
    timer=setTimeout(function(){score(v);},2200);
  },1500);
})();</script>
HTML;
        return str_replace(['{{TOKEN}}', '{{URL}}'], [$token, $url], $html);
    }
}
