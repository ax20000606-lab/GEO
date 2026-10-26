#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GEO 诊断器 Web 服务（零第三方依赖，标准库实现）
- GET  /                 输入框页面；带 ?url=xxx&auto=1 会自动诊断
- POST /audit            表单提交诊断
- GET  /report.md?url=   下载 Markdown 报告
默认端口 8021（可用环境变量 GEOAUDIT_PORT 覆盖）
"""
from __future__ import annotations

import html
import json
import os
import sys
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from geo_audit import audit, render_markdown  # noqa: E402

PORT = int(os.environ.get("GEOAUDIT_PORT", "8021"))
MAX_INPUT = 300

STYLE = """
:root{--bg:#f7f8fa;--card:#fff;--line:#e5e7eb;--txt:#111827;--muted:#6b7280;
--pass:#10b981;--warn:#f59e0b;--fail:#ef4444;--brand:#4f46e5;--brand2:#7c3aed}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);
font:14px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:1080px;margin:0 auto;padding:28px 20px 64px}
.head{display:flex;align-items:center;gap:10px;margin-bottom:22px}
.logo{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,var(--brand),var(--brand2));
display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px}
.head h1{font-size:17px;margin:0;font-weight:650}
.head .badge{font-size:11px;color:#6366f1;background:#eef2ff;border:1px solid #e0e7ff;
padding:2px 8px;border-radius:99px;font-weight:600}
.hero{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:30px;
margin-bottom:20px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.hero h2{margin:0 0 8px;font-size:22px;letter-spacing:-.3px}
.hero p.sub{margin:0 0 20px;color:var(--muted);font-size:13px}
form{display:flex;gap:10px;flex-wrap:wrap}
input[type=url],input[type=text]{flex:1;min-width:260px;padding:13px 15px;border:1px solid #d1d5db;
border-radius:11px;font-size:14px;outline:none;background:#fff}
input:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(79,70,229,.12)}
button{padding:13px 22px;border:0;border-radius:11px;background:var(--brand);color:#fff;
font-size:14px;font-weight:600;cursor:pointer}
button:hover{background:#4338ca}
.hint{margin-top:12px;font-size:12px;color:var(--muted)}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px 24px;margin-bottom:18px}
.card h3{margin:0 0 4px;font-size:15px;font-weight:650}
.card .cap{color:var(--muted);font-size:12px;margin:0 0 16px}
.score-row{display:flex;gap:26px;align-items:center;flex-wrap:wrap}
.score-big{font-size:52px;font-weight:750;letter-spacing:-2px;line-height:1}
.score-big small{font-size:16px;color:var(--muted);font-weight:500;letter-spacing:0}
.pill{display:inline-block;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:700}
.pill.pass{background:#ecfdf5;color:#047857}.pill.warn{background:#fffbeb;color:#b45309}
.pill.fail{background:#fef2f2;color:#b91c1c}
.bar{height:9px;border-radius:99px;background:#eef0f3;overflow:hidden;margin-top:7px}
.bar>i{display:block;height:100%;border-radius:99px}
.dim{display:grid;grid-template-columns:150px 46px 1fr;gap:12px;align-items:center;margin-bottom:13px}
.dim .n{font-size:13px;color:#374151}
.dim .v{font-size:13px;font-weight:700;text-align:right}
.funnel{display:flex;gap:8px;flex-wrap:wrap}
.fstep{flex:1;min-width:150px;border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:#fcfcfd}
.fstep .t{font-size:12px;color:var(--muted)}
.fstep .s{font-size:20px;font-weight:750;margin-top:2px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(232px,1fr));gap:12px}
.mod{border:1px solid var(--line);border-radius:12px;padding:13px 14px;background:#fff}
.mod .top{display:flex;justify-content:space-between;align-items:center;gap:8px}
.mod .nm{font-size:13px;font-weight:600}
.mod .sc{font-size:13px;font-weight:750}
.mod .ev{font-size:11.5px;color:var(--muted);margin-top:6px;word-break:break-all}
.mod .sg{font-size:11.5px;color:#4b5563;margin-top:6px;padding-top:6px;border-top:1px dashed #eef0f3}
.chap{margin:22px 0 10px;font-size:13px;font-weight:700;color:#374151;
display:flex;align-items:center;gap:8px}
.chap:before{content:"";width:3px;height:14px;border-radius:2px;background:var(--brand)}
.chips{display:flex;gap:7px;flex-wrap:wrap}
.chip{font-size:12px;padding:4px 10px;border-radius:99px;background:#f3f4f6;color:#374151;font-family:ui-monospace,monospace}
.chip.ok{background:#ecfdf5;color:#047857}.chip.miss{background:#fef2f2;color:#b91c1c}
.phase{border-left:3px solid var(--brand);padding-left:14px;margin:14px 0}
.phase b{font-size:13px}
.phase ul{margin:8px 0 0;padding-left:18px}
.phase li{font-size:12.5px;color:#374151;margin-bottom:5px}
.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;
padding:14px 16px;font-size:13px;margin-bottom:18px}
.dl{display:inline-block;margin-top:6px;font-size:12.5px;color:var(--brand);text-decoration:none;
border:1px solid #e0e7ff;background:#eef2ff;padding:7px 13px;border-radius:9px;font-weight:600}
.foot{color:var(--muted);font-size:12px;text-align:center;margin-top:26px}
@media(max-width:640px){.dim{grid-template-columns:110px 40px 1fr}.score-big{font-size:40px}}
"""

STATUS_PILL = {"pass": ("pass", "达标"), "warn": ("warn", "待优化"), "fail": ("fail", "亟需修复")}


def color_for(score: int) -> str:
    if score >= 90:
        return "var(--pass)"
    if score >= 70:
        return "var(--warn)"
    return "var(--fail)"


def esc(v) -> str:
    return html.escape(str(v), quote=True)


def page(body: str, title: str = "GEO 诊断器") -> bytes:
    return f"""<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{esc(title)}</title><style>{STYLE}</style></head><body>
<div class="wrap">
<div class="head"><div class="logo">G</div><h1>GEO 诊断器</h1>
<span class="badge">纯规则 · 24 模块 · 6 维</span></div>
{body}
<div class="foot">GEO 诊断器 · 规则引擎本地运行，不调用任何大模型 · 结果可复现</div>
</div></body></html>""".encode("utf-8")


def render_form(url: str = "", error: str = "") -> bytes:
    body = f"""
<div class="hero">
  <h2>输入网址，生成 GEO 诊断报告</h2>
  <p class="sub">检查抓取入口、预览元信息、结构化语义、内容组织、答案表达、引用可信六个维度，共 24 项模块。</p>
  {f'<div class="err">{esc(error)}</div>' if error else ''}
  <form method="post" action="/audit">
    <input type="text" name="url" value="{esc(url)}" placeholder="例如：https://www.haiyangzhizun.com" autofocus>
    <button type="submit">开始诊断</button>
  </form>
  <div class="hint">支持 https/http；诊断只读取页面 HTML，不提交任何数据到第三方。耗时通常 1–3 秒。</div>
</div>"""
    return page(body)


def render_report(r: dict) -> bytes:
    if not r.get("ok"):
        body = f"""<div class="hero"><h2>诊断失败</h2>
        <div class="err">目标：{esc(r.get('url'))}<br>原因：{esc(r.get('error'))}</div>
        <form method="post" action="/audit"><input type="text" name="url" value="{esc(r.get('url'))}">
        <button type="submit">重试</button></form>
        <div class="hint">常见原因：站点不可达 / 需要登录 / 拒绝非浏览器 UA / 只在特定网络可访问。</div></div>"""
        return page(body, "诊断失败")

    s = r["stats"]
    dims = "".join(
        f'<div class="dim"><div class="n">{esc(k)}</div><div class="v">{v}</div>'
        f'<div><div class="bar"><i style="width:{v}%;background:{color_for(v)}"></i></div></div></div>'
        for k, v in r["dimension_scores"].items()
    )
    funnel = "".join(
        f'<div class="fstep"><div class="t">{esc(n)}</div>'
        f'<div class="s" style="color:{color_for(v)}">{v}%</div></div>'
        for n, v in r["funnel"]
    )

    chapters: dict[str, list[dict]] = {}
    for m in r["modules"]:
        chapters.setdefault(m["chapter"], []).append(m)
    blocks = []
    for chap, mods in chapters.items():
        cards = "".join(
            f'<div class="mod"><div class="top"><span class="nm">{esc(m["name"])}</span>'
            f'<span class="sc" style="color:{color_for(m["score"])}">{m["score"]}</span></div>'
            f'<div class="ev">{esc(m["evidence"])}</div>'
            f'<div class="sg">{esc(m["suggestion"])}</div></div>'
            for m in mods
        )
        blocks.append(f'<div class="chap">{esc(chap)}</div><div class="grid">{cards}</div>')

    found = "".join(f'<span class="chip ok">{esc(t)}</span>' for t in r["schema_found"]) or '<span class="chip">无</span>'
    missing = "".join(f'<span class="chip miss">{esc(t)}</span>' for t in r["schema_missing"]) or '<span class="chip">无</span>'

    phases = []
    for ph in ("P0", "P1", "P2"):
        items = r["roadmap"][ph]
        if not items:
            continue
        lis = "".join(
            f'<li><b>{esc(i["chapter"])} / {esc(i["name"])}</b>（{i["score"]} 分）→ {esc(i["suggestion"])}</li>'
            for i in items
        )
        label = {"P0": "P0 · 立即修复", "P1": "P1 · 本轮补齐", "P2": "P2 · 持续优化"}[ph]
        phases.append(f'<div class="phase"><b>{label}</b><ul>{lis}</ul></div>')

    md_link = "/report.md?url=" + urllib.parse.quote(r["final_url"])
    body = f"""
<div class="hero">
  <form method="post" action="/audit">
    <input type="text" name="url" value="{esc(r['final_url'])}">
    <button type="submit">重新诊断</button>
  </form>
  <div class="hint">目标：{esc(r['final_url'])} · 抓取 {r['elapsed_ms']} ms · HTTP {r['http_status']} ·
  正文约 {s['text_len']} 字 · Schema 命中 {len(r['schema_found'])} 类</div>
</div>

<div class="card">
  <div class="score-row">
    <div class="score-big">{r['total']}<small> / 100</small></div>
    <div><span class="pill {STATUS_PILL.get('pass' if r['total']>=85 else 'warn' if r['total']>=70 else 'fail')[0]}">
    GEO 评分：{esc(r['grade'])}</span></div>
    <div style="flex:1;min-width:220px">
      <a class="dl" href="{md_link}">下载 Markdown 报告</a>
    </div>
  </div>
</div>

<div class="card"><h3>六维能力</h3><p class="cap">从抓取入口到权威引用的就绪度</p>{dims}</div>

<div class="card"><h3>GEO 就绪漏斗</h3><p class="cap">页面从被抓取到被引用的推进链</p>
<div class="funnel">{funnel}</div></div>

<div class="card"><h3>24 项诊断模块</h3><p class="cap">6 个章节 × 4 个模块，逐项打分与修复建议</p>{''.join(blocks)}</div>

<div class="card"><h3>结构化标记检测</h3><p class="cap">从页面 JSON-LD 提取 @type</p>
<p style="font-size:12.5px;margin:0 0 8px"><b>已检测到</b></p><div class="chips">{found}</div>
<p style="font-size:12.5px;margin:14px 0 8px"><b>建议补充</b></p><div class="chips">{missing}</div></div>

<div class="card"><h3>行动路线</h3><p class="cap">按失分排序自动分档</p>{''.join(phases)}</div>
"""
    return page(body, f"GEO 诊断 · {r['total']} 分 · {r['final_url']}")


class Handler(BaseHTTPRequestHandler):
    server_version = "GEOAudit/1.0"

    def log_message(self, fmt: str, *args) -> None:  # noqa: A003
        sys.stderr.write("[%s] %s\n" % (self.log_date_time_string(), fmt % args))

    def _send(self, data: bytes, ctype: str = "text/html; charset=utf-8", code: int = 200,
              extra: dict | None = None) -> None:
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        # 允许 GEOFlow 后台页面（不同端口）做健康检查
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        try:
            self.wfile.write(data)
        except BrokenPipeError:
            pass

    def do_OPTIONS(self) -> None:  # noqa: N802
        self._send(b"", "text/plain; charset=utf-8", 204)

    def do_GET(self) -> None:  # noqa: N802
        parsed = urllib.parse.urlparse(self.path)
        qs = urllib.parse.parse_qs(parsed.query or "")
        if parsed.path in ("/", "/diagnostic"):
            url = (qs.get("url") or [""])[0][:MAX_INPUT]
            auto = (qs.get("auto") or ["0"])[0] == "1"
            if url and auto:
                return self._run(url)
            return self._send(render_form(url))
        if parsed.path == "/report.md":
            url = (qs.get("url") or [""])[0][:MAX_INPUT]
            if not url:
                return self._send(b"missing url", "text/plain; charset=utf-8", 400)
            r = audit(url)
            md = render_markdown(r)
            fname = "geo-audit-report.md"
            return self._send(md.encode("utf-8"), "text/markdown; charset=utf-8",
                              extra={"Content-Disposition": f'attachment; filename="{fname}"'})
        if parsed.path == "/healthz":
            return self._send(json.dumps({"ok": True}).encode(), "application/json")
        self._send(b"not found", "text/plain; charset=utf-8", 404)

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(min(length, 8192)).decode("utf-8", "ignore")
        form = urllib.parse.parse_qs(raw)
        url = (form.get("url") or [""])[0].strip()[:MAX_INPUT]
        if not url:
            return self._send(render_form("", "请输入要诊断的网址"))
        self._run(url)

    def _run(self, url: str) -> None:
        try:
            result = audit(url)
        except Exception as exc:  # noqa: BLE001
            return self._send(render_form(url, f"诊断过程异常：{type(exc).__name__}: {exc}"))
        self._send(render_report(result))


def main() -> int:
    server = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    print(f"GEO 诊断器已启动： http://localhost:{PORT}")
    print("按 Ctrl+C 停止")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n已停止")
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
