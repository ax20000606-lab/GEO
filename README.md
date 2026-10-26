# GEOFlow 本地部署与二次开发

> 基于开源项目 [GEOFlow](https://github.com/yaojingang/GEOFlow) v3.1.0 的本地化部署，以及在此之上的功能改造与缺陷修复。
>
> 本仓库记录的是**部署 + 改造**，不是上游源码的镜像。上游代码位于 `src/`，我们的补丁集中在 `overrides/`。

---

## 一、这是什么

GEOFlow 是一套 **GEO（Generative Engine Optimization，生成式引擎优化）** 工作台，用来回答一个具体问题：

> 我的品牌/产品在 AI 搜索引擎（ChatGPT、豆包、Kimi、DeepSeek 等）里被提到吗？排第几？被谁压住了？该怎么改？

它把这件事拆成四个可测量的环节：

| 环节 | 做什么 | 对应模块 |
|---|---|---|
| **测** | 按关键词批量问 AI，记录回答和信源 | AI 可见性 / 覆盖核查 |
| **诊** | 检查自家页面能不能被 AI 抓取和引用 | 官网诊断 |
| **改** | 根据缺口生成内容任务、优化页面 | 内容任务 / 文章生成 |
| **验** | 30 天后复测，看覆盖率有没有涨 | 发布追踪 / 声量对标 |

---

## 二、目录结构

```
GEOFlow/
├── src/                       上游源码（Laravel 11 + Vite）
│   ├── app/                   业务代码
│   ├── database/migrations/   数据库迁移
│   ├── resources/             前端源码
│   └── public/                构建产物与静态资源
├── overrides/                 ★ 我们的补丁（不修改 src，全部旁挂）
│   ├── *.php                  覆盖上游同名文件的补丁
│   ├── AppServiceProvider.php
│   └── GeoAudit/              覆盖核查相关
├── geo-audit/                 GEO 审计工具（Python）
│   ├── geo_audit.py           单页审计脚本
│   ├── server.py              审计服务
│   └── coverage-gap-fixtures/ 覆盖核查测试夹具
├── bge-embedding/             本地向量化服务（bge-small-zh）
├── 启动GEOFlow.bat            一键启动
└── 部署说明-GEOFlow本地部署.md  完整部署文档
```

### 为什么要 `overrides/` 而不是直接改 `src/`

上游用**预构建镜像**（`ghcr.io/yaojingang/geoflow-app:3.1.0`）部署，容器里的 PHP 代码是烧进镜像的。直接改 `src/` 不会被容器读到。

所以所有改动都放在 `overrides/`，通过 `docker-compose.local-override.yml` 以 **bind mount** 的方式旁挂进容器。好处是：

- 上游代码保持原样，`git pull` 不会冲突
- 补丁文件独立可见，一眼能看出改了什么
- 单个文件出问题，删掉对应挂载即可回滚

**代价**：`overrides/` 里每个文件都是**整份复制**的（不是 patch），上游升级时要人工比对合并。

---

## 三、快速启动

### 前置条件

- Docker Desktop（Windows）
- 至少 8GB 可用内存
- 端口 18080 未被占用

### 步骤

```bash
# 1. 配置环境变量（从模板复制，然后填入真实值）
cp src/.env.prod.example src/.env.prod

# 2. 启动（两个 -f 都不能少）
cd src
docker compose --env-file .env.prod \
  -f docker-compose.prebuilt.yml \
  -f docker-compose.local-override.yml \
  up -d
```

Windows 下可以直接双击 `启动GEOFlow.bat`。

### 访问

| 入口 | 地址 | 说明 |
|---|---|---|
| 前台 | http://localhost:18080 | **必须用 localhost**，不能用 127.0.0.1 |
| 后台 | http://localhost:18080/geo_admin/login | 账号见 `.env.prod` 的 `GEOFLOW_ADMIN_*` |

> ⚠️ **必须用 `localhost`**：nginx 按 Host 头匹配虚拟主机（`GEOFLOW_NGINX_PRIMARY_HOST=localhost`），用 IP 访问会返回空响应。

---

## 四、我们的改造清单

以下改动全部在 `overrides/`，共 20 余个补丁文件。

### 4.1 功能性新增

| 改动 | 文件 | 说明 |
|---|---|---|
| **引用样本录入页** | `overrides/` + `web.php` | 新增 `/geo_admin/citation-record`，手工录入「AI 回答里引用了谁」，支持 E 泛需求词层 |
| **覆盖核查按品类分块** | `GeoAudit/GeoCoverageReport.php` | 报告按 A/B/C/D 四类分开呈现，A 类换用「官网占位率 / 首位归属 / 缺位替身榜」三个指标 |
| **信源缺口清单** | 同上 + `web.php` | A 类（品牌认知）从「生成文章任务」出口拆出，改为只读清单，因为这类要抢媒体位而非写软文 |

### 4.2 缺陷修复

| 问题 | 根因 | 修复 |
|---|---|---|
| 标题生成长文被截断 | 输出 token 限制 300 | 放开限制 |
| 标题生成全部失败 | 容器内 DNS 解析国内域名失败 | 出站解析改用系统解析器 |
| 质检日志风暴（一天 2117 条） | 脏配置任务被 `everyMinute` 放大 | job 层配置类异常降级 `warning` + 小时级去重 |
| 健康检查每 25 分钟误报失败 | worker 自回收时主动删心跳行，产生 1.2 秒空窗，恰好被采样撞上 | 退出时不删心跳行，让它自然过期；`degraded` 不再 exit 1 |
| 官网诊断的三个操作全部失效 | 字段名不匹配 / CSRF | 逐个修复并补日志 |
| 删除操作走 GET（CSRF 风险） | — | 改 POST + token |

### 4.3 数据库约束加固

`tasks` 表原来允许「质检开关打开但 prompt 为空」这种脏数据写入，由此引发的日志风暴持续了一整天。补了三条约束：

```sql
-- 1. 开着质检就必须有方案
ALTER TABLE tasks ADD CONSTRAINT tasks_ai_quality_prompt_required
  CHECK (NOT ai_quality_enabled OR ai_quality_prompt_id IS NOT NULL);

-- 2. 方案必须是「质检类型」，不能是内容类型的 prompt
ALTER TABLE prompts ADD CONSTRAINT prompts_id_type_unique UNIQUE (id, type);
ALTER TABLE tasks ADD COLUMN ai_quality_prompt_expected_type varchar(50)
  GENERATED ALWAYS AS ('quality_check') STORED;
ALTER TABLE tasks ADD CONSTRAINT tasks_ai_quality_prompt_type_fk
  FOREIGN KEY (ai_quality_prompt_id, ai_quality_prompt_expected_type)
  REFERENCES prompts (id, type);

-- 3. 删方案时不允许静默置空（改为拒绝）
ALTER TABLE tasks DROP CONSTRAINT tasks_ai_quality_prompt_id_foreign;
ALTER TABLE tasks ADD CONSTRAINT tasks_ai_quality_prompt_id_foreign
  FOREIGN KEY (ai_quality_prompt_id) REFERENCES prompts (id) ON DELETE RESTRICT;
```

对应迁移文件在 `src/database/migrations/`。

> ⚠️ **迁移不会自动跑**：容器的 `migrations` 目录没有挂载，容器里用的是镜像自带代码。这些迁移要等下次构建镜像才生效，当前线上是靠手工 SQL 打的。

---

## 五、配置说明

### 环境变量

真实配置在 `src/.env.prod`（**不进版本库**）。从 `.env.prod.example` 复制后至少改这几项：

```ini
APP_KEY=                      # php artisan key:generate 生成
DB_PASSWORD=
REDIS_PASSWORD=
GEOFLOW_ADMIN_PASSWORD=       # 后台登录密码
```

### 品牌档案

`src/storage/app/geo-brands.json` 定义要跟踪的品牌（含站点、别名、竞品）。新增品牌时：

- `site_url` 决定「自有页面」的判定（域名匹配）
- `aliases` 用于「AI 有没有提到我」的判定，**写漏会直接导致统计算错**
- 页面加 `?brand=<slug>` 切换统计口径

---

## 六、技术栈

| 层 | 技术 |
|---|---|
| 后端 | Laravel 11 / PHP 8.3 / PostgreSQL 16 / Redis |
| 队列 | Laravel Queue（front / backfill / optimization / knowledge 四车道） |
| 前端 | Vite + Blade + 原生 JS |
| 向量化 | bge-small-zh（本地，`bge-embedding/`） |
| 部署 | Docker Compose，11 个容器 |

---

## 七、已知限制

1. **`overrides/` 是整份文件复制**，上游升级需人工比对合并，`AppServiceProvider.php` 这类框架核心文件尤其要小心。
2. **迁移不自动生效**（见 4.3 的提醒）。
3. **覆盖核查的类目判定依赖 `keywords.category` 标注**，标注错了 A/B/C/D 会串。
4. 首次启动会拉镜像，约 3GB，国内建议配镜像加速。

---

## 八、致谢

核心功能由 [GEOFlow](https://github.com/yaojingang/GEOFlow) 开源项目提供，本仓库仅包含本地部署配置、缺陷修复与功能扩展。
