#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GEO 页面诊断器（纯规则引擎，零第三方依赖）
- 24 项诊断模块 / 6 个维度 / 总分与等级 / P0-P1-P2 行动路线
- 不调用任何大模型，结果可复现
用法：
    python geo_audit.py https://example.com
    python geo_audit.py https://example.com --json
"""
from __future__ import annotations

import argparse
import gzip
import io
import json
import re
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from html.parser import HTMLParser

UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)
TIMEOUT = 25

AUTHORITY_HINT = (
    "gov.cn", "edu.cn", "ac.cn", "arxiv.org", "doi.org", "wikipedia.org",
    "xinhuanet.com", "people.com.cn", "cctv.com", "gmw.cn", "chinadaily.com.cn",
    "36kr.com", "huxiu.com", "tmtpost.com", "caixin.com", "yicai.com",
    "nature.com", "science.org", "ieee.org", "acm.org", "who.int",
)
SOCIAL_HINT = (
    "weibo.com", "zhihu.com", "xiaohongshu.com", "douyin.com", "bilibili.com",
    "mp.weixin.qq.com", "toutiao.com", "kuaishou.com",
)
RECOMMENDED_SCHEMA = ("Organization", "WebSite", "Article", "FAQPage", "BreadcrumbList")


# --------------------------------------------------------------------------
# HTML 解析
# --------------------------------------------------------------------------
class PageParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.title = ""
        self.lang = ""
        self.meta: dict[str, str] = {}
        self.canonical = ""
        self.h1: list[str] = []
        self.h2: list[str] = []
        self.h3 = 0
        self.jsonld_raw: list[str] = []
        self.links: list[str] = []
        self.list_count = 0
        self.button_texts: list[str] = []
        self.paragraphs: list[str] = []
        self.text_parts: list[str] = []
        self._stack: list[str] = []
        self._buf: list[str] = []
        self._in_title = False
        self._in_script_ld = False
        self._in_button = False
        self._in_para = False
        self._para_buf: list[str] = []
        self._skip_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        a = {k.lower(): (v or "") for k, v in attrs}
        tag = tag.lower()
        if tag in ("script", "style", "noscript", "template"):
            if tag == "script":
                t = a.get("type", "").lower()
                if "ld+json" in t:
                    self._in_script_ld = True
                    self._stack.append("script-ld")
                    self._buf = []
                    return
            self._skip_depth += 1
            self._stack.append("skip")
            return

        if tag == "html":
            self.lang = a.get("lang", "")
        elif tag == "title":
            self._in_title = True
            self._buf = []
        elif tag == "meta":
            key = (a.get("property") or a.get("name") or "").lower()
            if key:
                self.meta[key] = a.get("content", "")
        elif tag == "link":
            rel = a.get("rel", "").lower()
            if "canonical" in rel:
                self.canonical = a.get("href", "")
        elif tag in ("ul", "ol"):
            self.list_count += 1
        elif tag == "a":
            href = a.get("href", "").strip()
            if href:
                self.links.append(href)
        elif tag == "button":
            self._in_button = True
            self._buf = []
        elif tag == "p":
            self._in_para = True
            self._para_buf = []
        elif tag in ("h1", "h2", "h3"):
            self._stack.append(tag)
            self._buf = []

    def handle_endtag(self, tag: str) -> None:
        tag = tag.lower()
        if tag in ("script", "style", "noscript", "template"):
            if self._stack and self._stack[-1] == "script-ld":
                self.jsonld_raw.append("".join(self._buf))
                self._in_script_ld = False
            self._stack and self._stack.pop()
            if self._skip_depth > 0:
                self._skip_depth -= 1
            return
        if tag == "title":
            self.title = "".join(self._buf).strip()
            self._in_title = False
        elif tag == "button":
            txt = "".join(self._buf).strip()
            if txt:
                self.button_texts.append(txt)
            self._in_button = False
        elif tag == "p":
            txt = "".join(self._para_buf).strip()
            if txt:
                self.paragraphs.append(txt)
            self._in_para = False
        elif tag in ("h1", "h2", "h3"):
            txt = "".join(self._buf).strip()
            if txt:
                if tag == "h1":
                    self.h1.append(txt)
                elif tag == "h2":
                    self.h2.append(txt)
                else:
                    self.h3 += 1
            if self._stack and self._stack[-1] == tag:
                self._stack.pop()

    def handle_data(self, data: str) -> None:
        if self._skip_depth > 0 and not self._in_script_ld:
            return
        if self._in_script_ld or self._in_title or self._in_button or self._stack and self._stack[-1] in ("h1", "h2", "h3") or self._in_para:
            self._buf.append(data) if not self._in_para else self._para_buf.append(data)
        if not self._skip_depth:
            t = data.strip()
            if t:
                self.text_parts.append(t)


@dataclass
class Module:
    chapter: str
    name: str
    score: int
    evidence: str
    suggestion: str = ""
    weight: int = 1

    @property
    def status(self) -> str:
        if self.score >= 90:
            return "pass"
        if self.score >= 70:
            return "warn"
        return "fail"


# --------------------------------------------------------------------------
# 抓取
# --------------------------------------------------------------------------
@dataclass
class FetchResult:
    url: str
    final_url: str = ""
    status: int = 0
    html: str = ""
    elapsed_ms: int = 0
    error: str = ""
    content_type: str = ""


def fetch(url: str) -> FetchResult:
    if not re.match(r"^https?://", url, re.I):
        url = "https://" + url
    res = FetchResult(url=url)
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": UA,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
            "Accept-Encoding": "gzip",
        },
    )
    started = time.time()
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT, context=ctx) as resp:
            raw = resp.read()
            res.status = resp.status
            res.final_url = resp.geturl()
            res.content_type = resp.headers.get("Content-Type", "")
            if resp.headers.get("Content-Encoding", "").lower() == "gzip":
                try:
                    raw = gzip.decompress(raw)
                except OSError:
                    pass
            charset = "utf-8"
            m = re.search(r"charset=([\w-]+)", res.content_type, re.I)
            if m:
                charset = m.group(1)
            else:
                head = raw[:2048].decode("ascii", "ignore")
                m = re.search(r'charset=["\']?([\w-]+)', head, re.I)
                if m:
                    charset = m.group(1)
            try:
                res.html = raw.decode(charset, "ignore")
            except LookupError:
                res.html = raw.decode("utf-8", "ignore")
    except urllib.error.HTTPError as exc:
        res.status = exc.code
        res.error = f"HTTP {exc.code}"
    except Exception as exc:  # noqa: BLE001
        res.error = f"{type(exc).__name__}: {exc}"
    res.elapsed_ms = int((time.time() - started) * 1000)
    return res


# --------------------------------------------------------------------------
# 诊断
# --------------------------------------------------------------------------
def _clamp(v: float) -> int:
    return max(0, min(100, int(round(v))))


def _band(value: float, table: list[tuple[float, int]], default: int = 0) -> int:
    """table 为 (阈值下限, 分数) 降序排列"""
    for threshold, score in table:
        if value >= threshold:
            return score
    return default


def extract_schema_types(blocks: list[str]) -> list[str]:
    types: set[str] = set()
    for raw in blocks:
        raw = raw.strip()
        if not raw:
            continue
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            for m in re.finditer(r'"@type"\s*:\s*"([A-Za-z]+)"', raw):
                types.add(m.group(1))
            continue
        stack = [data]
        while stack:
            node = stack.pop()
            if isinstance(node, dict):
                t = node.get("@type")
                if isinstance(t, str):
                    types.add(t)
                elif isinstance(t, list):
                    types.update(str(x) for x in t)
                stack.extend(node.values())
            elif isinstance(node, list):
                stack.extend(node)
    return sorted(types)


def audit(url: str) -> dict:
    fetched = fetch(url)
    if not fetched.html:
        return {
            "url": url,
            "ok": False,
            "error": fetched.error or f"HTTP {fetched.status}",
            "elapsed_ms": fetched.elapsed_ms,
        }

    parser = PageParser()
    try:
        parser.feed(fetched.html)
    except Exception:  # noqa: BLE001
        pass

    final = fetched.final_url or fetched.url
    text = " ".join(parser.text_parts)
    text_len = len(re.sub(r"\s+", "", text))
    schema_types = extract_schema_types(parser.jsonld_raw)

    title = parser.title or ""
    desc = parser.meta.get("description", "") or parser.meta.get("og:description", "")
    og_keys = [k for k in ("og:title", "og:description", "og:image", "og:type") if parser.meta.get(k)]
    tw = parser.meta.get("twitter:card", "")

    internal, external, authority, social = 0, 0, 0, 0
    host = urllib.parse.urlparse(final).netloc
    for href in parser.links:
        absu = urllib.parse.urljoin(final, href)
        h = urllib.parse.urlparse(absu).netloc
        if not h or h == host:
            internal += 1
            continue
        external += 1
        if any(a in h for a in AUTHORITY_HINT):
            authority += 1
        if any(s in h for s in SOCIAL_HINT):
            social += 1
    social += sum(1 for t in schema_types if t == "Organization")

    question_marks = len(re.findall(r"[？?]", text))
    first_para = parser.paragraphs[0] if parser.paragraphs else ""
    reading_min = max(0.1, text_len / 400)

    modules: list[Module] = []

    # ---- 一、抓取入口 ----
    modules.append(Module("一、抓取入口", "HTTPS 协议", 100 if final.lower().startswith("https") else 0,
                          "当前协议：" + ("HTTPS" if final.lower().startswith("https") else "HTTP"),
                          "改用 HTTPS，HTTP 站点会被部分 AI 抓取器降权"))
    if parser.canonical:
        modules.append(Module("一、抓取入口", "Canonical 规范",
                              100 if parser.canonical.lower().startswith("http") else 70,
                              parser.canonical[:80], "Canonical 建议写成绝对地址"))
    else:
        modules.append(Module("一、抓取入口", "Canonical 规范", 0, "未声明 canonical",
                              "补 <link rel=\"canonical\">，避免多 URL 稀释权重"))
    modules.append(Module("一、抓取入口", "Viewport 适配", 100 if "viewport" in parser.meta else 0,
                          "viewport=" + parser.meta.get("viewport", "缺失")[:40],
                          "补 viewport meta，移动端抓取语义更稳"))
    modules.append(Module("一、抓取入口", "Lang 声明", 100 if parser.lang else 0,
                          "html lang=" + (parser.lang or "缺失"),
                          "补 html lang=\"zh-CN\""))

    # ---- 二、预览元信息 ----
    tlen = len(title)
    modules.append(Module("二、预览元信息", "Title 完整度",
                          _band(tlen, [(15, 100), (10, 75), (1, 45)], 0),
                          f"{tlen} 字符：{title[:50]}",
                          "标题建议 15–40 字符，把核心实体放前面"))
    dlen = len(desc)
    modules.append(Module("二、预览元信息", "Meta Description",
                          _band(dlen, [(80, 100), (50, 78), (1, 45)], 0),
                          f"{dlen} 字符", "描述建议 80–160 字符，写成可摘录的一句话"))
    og_score = _band(len(og_keys), [(4, 100), (3, 82), (2, 62), (1, 35)], 0)
    modules.append(Module("二、预览元信息", "开放图谱 OG", og_score,
                          "已声明：" + (", ".join(og_keys) or "无"),
                          "补齐 og:title / og:description / og:image / og:type"))
    modules.append(Module("二、预览元信息", "社媒卡片", 100 if tw else 40,
                          "twitter:card=" + (tw or "缺失"),
                          "补 twitter:card=summary_large_image"))

    # ---- 三、结构化语义 ----
    covered = [t for t in RECOMMENDED_SCHEMA if t in schema_types]
    modules.append(Module("三、结构化语义", "Schema 覆盖率",
                          _clamp(len(covered) / len(RECOMMENDED_SCHEMA) * 100),
                          f"命中 {len(covered)}/{len(RECOMMENDED_SCHEMA)}：" + (", ".join(covered) or "无"),
                          "补 " + ", ".join(t for t in RECOMMENDED_SCHEMA if t not in covered)))
    modules.append(Module("三、结构化语义", "Organization 实体", 100 if "Organization" in schema_types else 0,
                          "Organization " + ("已声明" if "Organization" in schema_types else "缺失"),
                          "补 Organization，声明品牌名、logo、sameAs"))
    modules.append(Module("三、结构化语义", "FAQ 问答", 100 if "FAQPage" in schema_types else 0,
                          "FAQPage " + ("已声明" if "FAQPage" in schema_types else "缺失"),
                          "补 FAQPage，问答是 AI 最易摘录的结构"))
    modules.append(Module("三、结构化语义", "面包屑导航", 100 if "BreadcrumbList" in schema_types else 0,
                          "BreadcrumbList " + ("已声明" if "BreadcrumbList" in schema_types else "缺失"),
                          "补 BreadcrumbList，帮助机器理解站点层级"))

    # ---- 四、内容组织 ----
    h1n = len(parser.h1)
    modules.append(Module("四、内容组织", "H1 唯一性", 100 if h1n == 1 else 30,
                          f"H1 数量 {h1n}" + (f"：{parser.h1[0][:40]}" if h1n else ""),
                          "全页只保留一个 H1"))
    h2n = len(parser.h2)
    modules.append(Module("四、内容组织", "标题层级",
                          _band(h2n, [(2, 100), (1, 70)], 30),
                          f"H1×{h1n} / H2×{h2n} / H3×{parser.h3}",
                          "用 H2 拆章节，保证机器能切块"))
    modules.append(Module("四、内容组织", "正文深度",
                          _band(text_len, [(1500, 100), (800, 80), (400, 55)], 25),
                          f"正文约 {text_len} 字", "正文建议 1500 字以上，形成可摘录段落"))
    modules.append(Module("四、内容组织", "阅读时长",
                          100 if 3 <= reading_min <= 10 else (85 if reading_min > 10 else 55),
                          f"约 {reading_min:.1f} 分钟", "控制在 3–10 分钟阅读长度"))

    # ---- 五、答案表达 ----
    modules.append(Module("五、答案表达", "首段直答",
                          _band(len(first_para), [(80, 100), (40, 70), (1, 45)], 0),
                          f"首段 {len(first_para)} 字符", "首段直接给结论，别绕背景"))
    modules.append(Module("五、答案表达", "列表表达",
                          _band(parser.list_count, [(4, 100), (2, 80), (1, 55)], 25),
                          f"列表 {parser.list_count} 个", "用列表承载要点，便于 AI 拆分"))
    modules.append(Module("五、答案表达", "FAQ 语气",
                          _band(question_marks, [(5, 100), (2, 70), (1, 45)], 20),
                          f"问句 {question_marks} 处", "增加问答式小节，匹配用户真实提问"))
    cta = len([b for b in parser.button_texts if b.strip()]) + len(
        [h for h in parser.links if h and not h.startswith(("#", "javascript"))]
    )
    modules.append(Module("五、答案表达", "CTA 清晰度",
                          _band(cta, [(5, 100), (2, 75), (1, 55)], 35),
                          f"可点击项约 {cta} 个", "给出明确的下一步动作入口"))

    # ---- 六、引用可信 ----
    modules.append(Module("六、引用可信", "外部链接广度",
                          _band(external, [(8, 100), (4, 80), (1, 55)], 20),
                          f"外链 {external} 个", "增加外部权威引用，构建引用网络"))
    modules.append(Module("六、引用可信", "权威引用",
                          _band(authority, [(2, 100), (1, 70)], 30),
                          f"权威域名 {authority} 个", "引用政府/学术/行业权威来源"))
    modules.append(Module("六、引用可信", "站内支撑链接",
                          _band(internal, [(15, 100), (5, 80), (1, 55)], 25),
                          f"内链 {internal} 个", "补齐站内主题链路"))
    modules.append(Module("六、引用可信", "社交背书",
                          100 if social >= 1 else 40,
                          f"社交/组织信号 {social} 处", "补 sameAs 指向官方社媒"))

    # ---- 汇总 ----
    chapters: dict[str, list[Module]] = {}
    for m in modules:
        chapters.setdefault(m.chapter, []).append(m)
    dimension_scores = {ch: _clamp(sum(x.score for x in ms) / len(ms)) for ch, ms in chapters.items()}
    total = _clamp(sum(m.score for m in modules) / len(modules))
    grade = "优秀" if total >= 85 else "良好" if total >= 70 else "待优化" if total >= 55 else "亟需整改"

    ranked = sorted(modules, key=lambda m: (m.score, m.chapter))
    p0 = ranked[:4]
    p1 = ranked[4:8]
    p2 = [m for m in modules if m.score < 90 and m not in p0 and m not in p1]

    funnel = [
        ("抓取可达", dimension_scores.get("一、抓取入口", 0)),
        ("实体理解", dimension_scores.get("三、结构化语义", 0)),
        ("答案表达", dimension_scores.get("五、答案表达", 0)),
        ("信任构建", dimension_scores.get("六、引用可信", 0)),
        ("AI 引用准备", _clamp((dimension_scores.get("二、预览元信息", 0) + dimension_scores.get("四、内容组织", 0)) / 2)),
    ]

    return {
        "url": url,
        "final_url": final,
        "ok": True,
        "http_status": fetched.status,
        "elapsed_ms": fetched.elapsed_ms,
        "content_type": fetched.content_type,
        "total": total,
        "grade": grade,
        "dimension_scores": dimension_scores,
        "modules": [
            {
                "chapter": m.chapter, "name": m.name, "score": m.score,
                "status": m.status, "evidence": m.evidence, "suggestion": m.suggestion,
            }
            for m in modules
        ],
        "chapters": list(chapters.keys()),
        "funnel": funnel,
        "schema_found": schema_types,
        "schema_missing": [t for t in RECOMMENDED_SCHEMA if t not in schema_types],
        "stats": {
            "text_len": text_len,
            "h1": h1n, "h2": h2n, "h3": parser.h3,
            "external_links": external, "internal_links": internal,
            "authority_links": authority, "lists": parser.list_count,
            "questions": question_marks, "title_len": tlen, "desc_len": dlen,
        },
        "roadmap": {
            "P0": [{"name": m.name, "chapter": m.chapter, "score": m.score,
                    "suggestion": m.suggestion, "evidence": m.evidence} for m in p0],
            "P1": [{"name": m.name, "chapter": m.chapter, "score": m.score,
                    "suggestion": m.suggestion, "evidence": m.evidence} for m in p1],
            "P2": [{"name": m.name, "chapter": m.chapter, "score": m.score,
                    "suggestion": m.suggestion, "evidence": m.evidence} for m in p2],
        },
    }


# --------------------------------------------------------------------------
# Markdown 报告
# --------------------------------------------------------------------------
def render_markdown(r: dict) -> str:
    if not r.get("ok"):
        return f"# GEO 诊断失败\n\n- 目标：{r['url']}\n- 原因：{r.get('error')}\n"
    lines: list[str] = []
    lines.append(f"# GEO 页面诊断报告：{r['final_url']}")
    lines.append("")
    lines.append(f"**GEO 总分：{r['total']} / 100（{r['grade']}）**  ")
    lines.append(f"抓取耗时 {r['elapsed_ms']} ms · HTTP {r['http_status']} · 正文约 {r['stats']['text_len']} 字")
    lines.append("")
    lines.append("## 一、六维得分")
    lines.append("")
    lines.append("| 维度 | 得分 |")
    lines.append("|---|---|")
    for k, v in r["dimension_scores"].items():
        lines.append(f"| {k} | {v} |")
    lines.append("")
    lines.append("## 二、GEO 就绪漏斗")
    lines.append("")
    for name, score in r["funnel"]:
        bar = "█" * max(1, score // 5)
        lines.append(f"- **{name}** {score}% {bar}")
    lines.append("")
    lines.append("## 三、24 项模块明细")
    lines.append("")
    cur = ""
    for m in r["modules"]:
        if m["chapter"] != cur:
            cur = m["chapter"]
            lines.append("")
            lines.append(f"### {cur}")
            lines.append("")
            lines.append("| 模块 | 得分 | 状态 | 证据 | 建议 |")
            lines.append("|---|---|---|---|---|")
        mark = {"pass": "✅", "warn": "🟡", "fail": "🔴"}[m["status"]]
        lines.append(f"| {m['name']} | {m['score']} | {mark} | {m['evidence']} | {m['suggestion']} |")
    lines.append("")
    lines.append("## 四、结构化标记检测")
    lines.append("")
    lines.append(f"- 已检测到：{', '.join(r['schema_found']) or '无'}")
    lines.append(f"- 建议补充：{', '.join(r['schema_missing']) or '无'}")
    lines.append("")
    lines.append("## 五、行动路线")
    lines.append("")
    for phase in ("P0", "P1", "P2"):
        items = r["roadmap"][phase]
        if not items:
            continue
        lines.append(f"### {phase}")
        lines.append("")
        for it in items:
            lines.append(f"- **{it['chapter']} / {it['name']}**（{it['score']} 分）→ {it['suggestion']}")
        lines.append("")
    lines.append("---")
    lines.append("")
    lines.append("> 本报告由纯规则引擎生成（无大模型参与），结果可复现。")
    return "\n".join(lines)


def main() -> int:
    ap = argparse.ArgumentParser(description="GEO 页面诊断器（纯规则）")
    ap.add_argument("url", help="目标页面 URL")
    ap.add_argument("--json", action="store_true", help="输出 JSON")
    ap.add_argument("--md", metavar="FILE", help="把 Markdown 报告写入文件")
    args = ap.parse_args()

    result = audit(args.url)
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0 if result.get("ok") else 2
    md = render_markdown(result)
    if args.md:
        with open(args.md, "w", encoding="utf-8") as fh:
            fh.write(md)
        print(f"报告已写入 {args.md}")
    else:
        try:
            sys.stdout.reconfigure(encoding="utf-8")  # type: ignore[attr-defined]
        except Exception:  # noqa: BLE001
            pass
        print(md)
    return 0 if result.get("ok") else 2


if __name__ == "__main__":
    raise SystemExit(main())
