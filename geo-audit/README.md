# GEO 诊断器

纯规则 GEO 页面诊断器：输入网址 → 24 项模块检测 → 六维评分 → 行动路线。

## ⚠️ 现在的正式用法：不需要启动任何服务

诊断器已**内置进 GEOFlow 容器**（PHP 版，同源路由），随 Docker 一起常驻：

```
GEOFlow 后台 → AI 工作台 → 输入框粘贴网址 → 点「可见性诊断」
```

也可直接访问：
```
http://localhost:18080/geo_admin/geo-audit
http://localhost:18080/geo_admin/geo-audit?url=https://example.com&auto=1
```

实现文件（通过 docker volume 挂载，不改镜像）：

| 宿主文件 | 容器位置 |
|---|---|
| `D:\GEOFlow\overrides\web.php` | `routes/web.php`（末尾追加路由） |
| `D:\GEOFlow\overrides\GeoAuditor.php` | `app/Support/GeoAudit/GeoAuditor.php` |
| `D:\GEOFlow\overrides\GeoAuditReport.php` | `app/Support/GeoAudit/GeoAuditReport.php` |
| `D:\GEOFlow\overrides\ai-workspace-index.blade.php` | `resources/views/admin/ai-workspace/index.blade.php` |

## 本目录的 Python 版（保留作命令行工具 / 备用）

```bash
python geo_audit.py https://example.com            # 终端打印 Markdown 报告
python geo_audit.py https://example.com --json     # 输出 JSON
python geo_audit.py https://example.com --md out.md
```

需要网页版时才启动（可选，端口 8021）：双击 `启动诊断器.bat`。**日常不用碰。**

## 诊断维度（24 项 / 6 章）

| 章节 | 4 个模块 |
|---|---|
| 一、抓取入口 | HTTPS 协议 / Canonical 规范 / Viewport 适配 / Lang 声明 |
| 二、预览元信息 | Title 完整度 / Meta Description / 开放图谱 OG / 社媒卡片 |
| 三、结构化语义 | Schema 覆盖率 / Organization 实体 / FAQ 问答 / 面包屑导航 |
| 四、内容组织 | H1 唯一性 / 标题层级 / 正文深度 / 阅读时长 |
| 五、答案表达 | 首段直答 / 列表表达 / FAQ 语气 / CTA 清晰度 |
| 六、引用可信 | 外部链接广度 / 权威引用 / 站内支撑链接 / 社交背书 |

评分区间：≥85 优秀 / ≥70 良好 / ≥55 待优化 / <55 亟需整改

## 输出结构

- 总分 + 等级
- 六维得分（横向条形）
- GEO 就绪漏斗：抓取可达 → 实体理解 → 答案表达 → 信任构建 → AI 引用准备
- 24 项模块明细（得分 / 证据 / 修复建议）
- Schema 检测：已命中 @type 列表 + 建议补充列表
- 行动路线：按失分自动分 P0 / P1 / P2
- Markdown 报告下载

## 架构：规则负责事实，AI 负责研判

| 层 | 负责什么 | 是否用模型 | 耗时 |
|---|---|---|---|
| **规则层**（GeoAuditor / GeoAuditSchema） | 24 项检测、六维评分、**JSON-LD 骨架生成** | ❌ 不用 | 0.3 秒 |
| **AI 层**（GeoAuditAdvisor） | 诊断解读、内容结构建议、行动方案、可见性问答 | ✅ 用 | 10–20 秒 |

分界线：「看一眼就知道对错」的用规则；「要理解意图、要生成新内容」的用 AI。

**JSON-LD 为什么用规则而不是 AI**：它是确定性结构，给定品牌名/URL/标题就应该有唯一产物。
让模型写会带来两个必然问题——字段编造、输出被截断（实测 2400 token 时 JSON 部分直接被切掉）。
因此现在由规则生成骨架并做 `json_decode` 语法校验，AI 只负责说明「放哪、补哪些字段、怎么验证」。

## 已知限制（诚实说明）

- **只读 HTML 静态分析**，不执行 JS。纯前端渲染（CSR）站点首屏 HTML 可能没有正文，会低估「正文深度 / H1 / Schema」。
- 站点不可达、需要登录、或拒绝非浏览器 UA 时直接报错，不伪造结果。
- 权威域名判定用内置白名单（gov.cn / edu.cn / 学术站 / 主流媒体），不是实时权重库。
- **评分是等权平均（24 项各占 1/24）**，没有按重要性加权。因此「缺 canonical / viewport / lang / OG / JSON-LD」这类基础项全丢时，分数会被放大压低。
- **没有行业基准库**，49 分不代表「网站做得差」，只代表「机器可读性基础缺失较多」，不能与同行横向比较。
- **首页 ≠ 文章页**：部分规则（如「首段直答」「阅读时长 3–10 分钟」）更适合内容页；用在品牌首页会偏严。
- `twitter:card`、社交背书等项偏国际 SEO 口径，国内站点可视为加分项而非扣分项。
- JSON-LD 骨架中的 `logo` / `sameAs` / `datePublished` / FAQ 问答一律留占位符，**必须人工填真实值**；FAQ 的问答内容必须页面上真实存在。


### 一句话口径

> 诊断器抓的是**事实**（有没有 canonical、几个 H1、有没有 JSON-LD），这些是硬的；
> 但**分数**是我们自己的等权算法，用来排序和改进，不是行业标准。


## 文件说明

| 文件 | 作用 |
|---|---|
| `geo_audit.py` | Python 版引擎（命令行用） |
| `server.py` | Python 版 Web 服务（8021，可选用） |
| `启动诊断器.bat` / `停止诊断器.bat` | Python 版启停脚本（GBK 编码） |

