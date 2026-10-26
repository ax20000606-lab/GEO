<?php

namespace App\Support\GeoAudit;

/**
 * 统一设计令牌 + 通用组件样式
 *
 * 目的：诊断页、AI 解读页、方案页、历史页共用同一套颜色/圆角/间距/按钮/卡片，
 * 避免每个页面各自维护一份 CSS 导致视觉不一致。
 */
final class GeoUi
{
    /**
     * 设计令牌（CSS 变量）+ 通用组件类。
     * 变量挂在 .geo-ui 上，只有包了 .geo-ui 的容器才受影响，不污染后台壳。
     */
    public static function css(): string
    {
        return <<<'CSS'
.geo-ui{
  --g-brand:#4f46e5;--g-brand-2:#7c3aed;--g-brand-weak:#eef2ff;--g-brand-line:#e0e7ff;
  --g-txt:#111827;--g-txt-2:#374151;--g-muted:#6b7280;
  --g-bg:#f7f8fa;--g-card:#ffffff;--g-line:#e5e7eb;--g-line-2:#eef0f3;
  --g-ok:#10b981;--g-ok-bg:#ecfdf5;--g-ok-txt:#047857;
  --g-warn:#f59e0b;--g-warn-bg:#fffbeb;--g-warn-txt:#b45309;
  --g-bad:#ef4444;--g-bad-bg:#fef2f2;--g-bad-txt:#b91c1c;
  --g-r-lg:16px;--g-r-md:11px;--g-r-sm:9px;
  --g-sp-1:6px;--g-sp-2:10px;--g-sp-3:14px;--g-sp-4:20px;--g-sp-5:28px;
  --g-shadow:0 1px 2px rgba(16,24,40,.04);
  --g-font:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
}

/* ---------- 通用组件 ---------- */
.geo-ui .g-card{background:var(--g-card);border:1px solid var(--g-line);border-radius:var(--g-r-lg);padding:var(--g-sp-4) 24px;margin-bottom:var(--g-sp-3);box-shadow:var(--g-shadow)}
.geo-ui .g-card>h3{margin:0 0 4px;font-size:15px;font-weight:650;color:var(--g-txt)}
.geo-ui .g-cap{margin:0 0 var(--g-sp-3);font-size:12px;color:var(--g-muted)}

.geo-ui .g-row{display:flex;gap:var(--g-sp-2);flex-wrap:wrap;align-items:center}
.geo-ui .g-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:var(--g-card);border:1px solid var(--g-line);border-radius:var(--g-r-md);padding:10px 14px;margin-bottom:var(--g-sp-3)}
.geo-ui .g-toolbar .g-sep{font-size:12px;color:var(--g-muted);margin-left:4px}

.geo-ui .g-btn{display:inline-flex;align-items:center;gap:6px;text-decoration:none;cursor:pointer;border:0;
  border-radius:var(--g-r-sm);font-weight:650;font-size:13px;padding:9px 15px;line-height:1.2}
.geo-ui .g-btn--primary{color:#fff;background:linear-gradient(135deg,var(--g-brand),var(--g-brand-2))}
.geo-ui .g-btn--primary:hover{filter:brightness(1.07)}
.geo-ui .g-btn--ghost{color:var(--g-txt);background:#f3f4f6;border:1px solid #d1d5db}
.geo-ui .g-btn--ghost:hover{background:#e5e7eb}
.geo-ui .g-btn--soft{color:var(--g-brand);background:var(--g-brand-weak);border:1px solid var(--g-brand-line)}
.geo-ui .g-btn--soft:hover{background:#e6ecff}
.geo-ui .g-btn[disabled]{cursor:progress;opacity:.6;pointer-events:none}

.geo-ui .g-tag{display:inline-block;font-size:11.5px;color:var(--g-muted);background:#f3f4f6;border-radius:99px;padding:3px 10px}
.geo-ui .g-badge{display:inline-block;font-size:11.5px;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--g-brand),var(--g-brand-2));border-radius:99px;padding:3px 10px}
.geo-ui .g-pill{display:inline-block;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:700}
.geo-ui .g-pill--ok{background:var(--g-ok-bg);color:var(--g-ok-txt)}
.geo-ui .g-pill--warn{background:var(--g-warn-bg);color:var(--g-warn-txt)}
.geo-ui .g-pill--bad{background:var(--g-bad-bg);color:var(--g-bad-txt)}

.geo-ui .g-err{background:var(--g-bad-bg);border:1px solid #fecaca;color:#991b1b;border-radius:var(--g-r-md);padding:12px 14px;font-size:13px;margin-bottom:var(--g-sp-3)}
.geo-ui .g-note{background:var(--g-ok-bg);border:1px solid #a7f3d0;color:#065f46;border-radius:var(--g-r-md);padding:11px 14px;font-size:12.5px;margin-bottom:var(--g-sp-3)}

/* 全屏执行遮罩（统一） */
.geo-busy{position:fixed;inset:0;background:rgba(17,24,39,.42);z-index:99999;display:none;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
.geo-busy.is-on{display:flex}
.geo-busy .box{background:#fff;border-radius:18px;padding:26px 32px;text-align:center;box-shadow:0 16px 48px rgba(0,0,0,.20);max-width:360px;border:1px solid rgba(255,255,255,.6)}
.geo-busy .sp{width:32px;height:32px;margin:0 auto 14px;border:3px solid #e0e7ff;border-top-color:#4f46e5;border-radius:50%;animation:geoBusySpin .8s linear infinite}
.geo-busy b{display:block;font-size:15px;color:#111827;margin-bottom:6px}
.geo-busy span{font-size:12.5px;color:#6b7280;line-height:1.6}
@keyframes geoBusySpin{to{transform:rotate(360deg)}}

/* Markdown 正文统一排版 */
.geo-ui .g-md{font-size:14px;line-height:1.78;color:var(--g-txt)}
.geo-ui .g-md h2{font-size:19px;margin:22px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--g-line-2)}
.geo-ui .g-md h3{font-size:16px;margin:18px 0 8px}
.geo-ui .g-md h4{font-size:14.5px;margin:14px 0 6px;color:var(--g-txt-2)}
.geo-ui .g-md table{width:100%;border-collapse:collapse;margin:12px 0;font-size:12.5px}
.geo-ui .g-md th,.geo-ui .g-md td{border:1px solid var(--g-line);padding:8px 10px;text-align:left;vertical-align:top}
.geo-ui .g-md th{background:#f9fafb;font-weight:650}
.geo-ui .g-md ul,.geo-ui .g-md ol{padding-left:20px;margin:10px 0}
.geo-ui .g-md li{margin:4px 0}
.geo-ui .g-md p{margin:10px 0}
.geo-ui .g-md code{background:#f3f4f6;padding:1px 5px;border-radius:4px;font-size:12.5px}
.geo-ui .g-md pre{background:#0f172a;color:#e5e7eb;padding:14px;border-radius:var(--g-r-md);overflow:auto;font-size:12.5px}
.geo-ui .g-md blockquote{border-left:3px solid var(--g-brand-line);margin:12px 0;padding:2px 14px;color:#4b5563}

/* 统一表格（历史/对比） */
.geo-ui .g-table{width:100%;border-collapse:collapse;font-size:12.5px}
.geo-ui .g-table th,.geo-ui .g-table td{border:1px solid var(--g-line);padding:9px 11px;text-align:left}
.geo-ui .g-table th{background:#f9fafb;font-weight:650;color:var(--g-txt-2)}
.geo-ui .g-up{color:var(--g-ok-txt);font-weight:700}
.geo-ui .g-down{color:var(--g-bad-txt);font-weight:700}
CSS;
    }

    /** 全屏遮罩节点 + 通用脚本（各页面复用） */
    public static function busyOverlay(): string
    {
        return '<div class="geo-busy" id="geo-busy"><div class="box"><div class="sp"></div>'
            .'<b id="geo-busy-title">处理中…</b><span id="geo-busy-desc">正在调用模型，请勿关闭页面</span></div></div>'
            .'<script>(function(){window.geoBusy=function(title,desc){var b=document.getElementById("geo-busy");if(!b)return;'
            .'if(title){document.getElementById("geo-busy-title").textContent=title;}'
            .'if(desc){document.getElementById("geo-busy-desc").textContent=desc;}'
            .'b.classList.add("is-on");};})();</script>';
    }
}
