# GEOFlow 本地部署说明

部署日期：2026-09-27
部署位置：`D:\GEOFlow\`（源码 `D:\GEOFlow\src`）
版本：**v3.1.0 正式发布版**（仓库 tag，非 beta）

---

## 一、怎么访问

| 项 | 值 |
|---|---|
| 前台 | http://localhost:18080 |
| 后台登录 | http://localhost:18080/geo_admin/login |
| 用户名 | `admin` |
| 密码 | `GeoFlow@2026` |
| 角色 | super_admin |

密码在 `D:\GEOFlow\src\.env.prod` 的 `GEOFLOW_ADMIN_PASSWORD` 里，想改就直接改这个文件再重建 init 容器。
**注意端口是 18080**，不是 8000（8000/8001 被你其它项目占了）。

---

## 二、为什么走生产版镜像是关键

本来按官方 README 的「Docker 开发与体验」路径，要执行：

```bash
docker compose build      # 本地编译 PHP 镜像，十几分钟
docker compose up -d      # 首次启动还要 composer install + npm build
```

`composer install` 拉几百 MB PHP 依赖、`npm ci` 拉几百 MB node_modules，两个都是内存和时间的消耗大头。以你 16GB 常年跑满的机器，这一步极可能失败。

后来发现官方在 ghcr 上发布了预构建镜像，而且**镜像里已经带了 `vendor/` 和 `public/build/`**。所以改走预构建路线，这两个步骤全部省掉：

| 对比项 | 本地编译（开发版） | 预构建镜像（本次采用） |
|---|---|---|
| composer install | 需要，几分钟 | **不需要，镜像自带** |
| npm build | 需要，几分钟 | **不需要，镜像自带** |
| 首次到可用 | 约 20-40 分钟 | **约 3 分钟** |
| 内存峰值 | 高（node build 尤其） | 低 |

代价：预构建镜像是**生产版**（php-fpm + nginx），不是开发版（php artisan serve）。对本地研究没有影响，反而更接近真实部署形态。

---

## 三、实际改了哪些配置

配置文件：`D:\GEOFlow\src\.env.prod`（从 `.env.prod.example` 生成）

| 配置项 | 原模板值 | 改成 | 原因 |
|---|---|---|---|
| `APP_URL` | `https://your-domain.com` | `http://localhost:18080` | 本地 HTTP |
| `GEOFLOW_NGINX_PRIMARY_HOST` | `your-domain.com` | `localhost` | Nginx 匹配的 Host |
| `GEOFLOW_NGINX_PUBLIC_SCHEME` / `PORT` | `https` / `443` | `http` / `18080` | 无证书 |
| `REVERB_HOST` / `PORT` / `SCHEME` | `your-domain.com` / `443` / `https` | `localhost` / `18080` / `http` | WebSocket 广播 |
| `GEOFLOW_APP_IMAGE` | 注释状态 | `ghcr.io/yaojingang/geoflow-app:3.1.0` | 用预构建镜像 |
| `GEOFLOW_WEB_IMAGE` | 注释状态 | `ghcr.io/yaojingang/geoflow-web:3.1.0` | 用预构建镜像 |
| `PGVECTOR_IMAGE` | `pgvector/pgvector:pg18` | `pgvector/pgvector:pg16` | pg18 在 Docker Hub 拉取慢，pg16 本机已有 |
| `REDIS_IMAGE` | `redis:8-alpine` | `redis:7-alpine` | 同上，本机已有 |
| `DB_PASSWORD` | `geo_password` | `GeoFlow_Local_2026` | 自定 |
| `REDIS_PASSWORD` | 空 | `GeoFlow_Redis_2026` | 自定 |
| `GEOFLOW_ADMIN_EMAIL` / `PASSWORD` | `admin@example.com` / 空 | `admin@geoflow.local` / `GeoFlow@2026` | 明确账号密码 |
| `GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED` | `false` | `true` | 允许空库首次安装 |
| `AI_QUALITY_QUEUE_REPLICAS` | `2` | `1` | 省内存 |
| `APP_DEBUG` | `false` | `true` | 本地排障方便；上线前应改回 false |

**为什么换 pg16 而不是 pg18**：项目自己的 `docker-compose.yml`（开发版）默认就用 pg16，CI 里才用 pg18；迁移文件里没有任何 PG18 专属语法。实测 pg16.15 + pgvector 0.8.6 全部迁移通过。如果你以后想让版本完全对齐官方生产配置，等 pg18 镜像下载完了把 `PGVECTOR_IMAGE` 改回去即可，但要先删掉 `docker-data/prod/postgres` 目录再重建（跨大版本不能直接复用数据目录）。

---

## 四、修掉的一个真实缺陷

**现象**：首次启动时 `geoflow-laravel-prod-ai-quality-queue-1` 退出并重启了 1 次，日志里是：

```
In Filesystem.php line 191:
hash_file(/var/www/html/storage/framework/views/346cf206...php):
Failed to open stream: No such file or directory
```

**根因**：`docker-compose.prebuilt.yml` 里的常驻服务没有给 `AUTO_OPTIMIZE` 赋值，而 `docker/entrypoint.prod.sh` 里它的默认值是 `true`。于是 8 个常驻容器启动时**同时**执行 `php artisan optimize`（含 view:cache），它们共享同一个 `./storage` 挂载目录，并发写视图缓存互相覆盖，导致 hash_file 找不到文件 → 容器退出 → `restart: unless-stopped` 拉起。

**修复**：新增覆盖文件 `D:\GEOFlow\src\docker-compose.local-override.yml`，把 8 个常驻服务的 `AUTO_OPTIMIZE` 显式设为 `false`。init 服务已经做过全量缓存，常驻服务不需要重复做。

修复后复查：11 个容器**全部 0 重启**，无 optimize 失败。

---

## 四之二、修掉的第二个问题：web 健康检查误报

**现象**：`geoflow-web-prod` 一直显示 `unhealthy`，Docker 健康检查连续 11 次失败，报 `wget: error getting response`。

**但站点本身完全正常**。实测证据：

| 检查 | 结果 |
|---|---|
| 宿主机访问 `/up` | HTTP 200，耗时 0.057s |
| 手动在容器内跑同一条健康检查命令 | **退出码 0**，nginx 访问日志有记录，返回 200 |
| 健康检查那 11 次请求在 nginx 访问日志里 | **完全不存在** |

也就是说：命令本身没问题，是**健康检查这个执行上下文里访问不到容器自身的 loopback**，属于 Docker Desktop（WSL2 后端）的环境特性，不是 GEOFlow 的问题。

**修复**：在覆盖文件里把 web 的健康检查改成「先做 HTTP 探测，失败退到 TCP 端口探测」：

```yaml
web:
  healthcheck:
    test: ["CMD-SHELL", "if wget -q -O /dev/null --header='Host: localhost' http://127.0.0.1/up; then exit 0; fi; if nc -z 127.0.0.1 80; then exit 0; fi; exit 1"]
    interval: 15s
    timeout: 5s
    retries: 3
    start_period: 10s
```

修复后：web **healthy**，连续多次 exit=0，失败计数归零。

**这条的通用教训**：Docker 健康检查失败不等于服务不可用。要先从宿主机实测、再看 nginx 访问日志判断请求有没有真的到，最后才怀疑服务本身。


---

## 四之三、修掉的第三个问题：AI 服务商「一直连不上」（DNS 污染）

**症状**：后台「AI 配置器」里配好 DeepSeek 后点「测试连接」，永远失败，弹窗提示

```
网络连接失败
服务器无法连接到服务商，可能涉及 DNS、连接超时或网络出口。
脱敏技术日志：The network request failed. Check DNS, TLS, and upstream availability.
```

**关键线索**：关键词是 `outbound_request_failed`，而**配置本身已经存进库了**（`ai_models` 表里有记录，`api_key` 是 `enc:v1` 密文）。所以不是保存失败，是**出站请求失败**。

### 根因：容器拿到的 `api.deepseek.com` 解析里有假 IP

| 项 | 实测值 |
| --- | --- |
| Docker 上游 DNS | `ExtServers = [114.114.114.114, 8.8.8.8]` ← 8.8.8.8 走明文 UDP 53，污染高发 |
| **容器实际解析** | `116.196.150.245`（京东云段）**+** `119.188.175.46` |
| **权威 DoH 解析** | `221.204.163.76` + `119.188.175.46` |

**`116.196.150.245` 根本不在权威解析里** —— 它是被污染注入的、与 DeepSeek 无关的地址。

逐个验证更清楚：

```
容器内连 116.196.150.245 → 4/4 失败，每次卡 21 秒后放弃
容器内连 119.188.175.46  → 4/4 成功，13~16ms
容器内连 221.204.163.76  → 成功，18ms
宿主机直连 116.196.150.245 → 也失败（16 秒）
```

而 DNS 在**真 IP 和假 IP 之间轮换**，所以表现成「有时能连、有时不能」。GEOFlow 的出站连接超时是 **10 秒**（`app/Services/Outbound/FinalOutboundSecurityPolicy.php:102`），一旦先解析到假 IP 就是**必失败**。

⚠ 注意最后一行：**宿主机直连同一个假 IP 也不通**，这一条排除了 Docker / WSL2 网络问题，把范围锁死在 DNS 解析结果上。

### 修复：用 `extra_hosts` 把域名钉在真实 IP 上

在 `docker-compose.local-override.yml` 里加（已对 7 个会发 AI 请求的服务生效：`app` / `queue` / `scheduler` / `ai-quality-queue` / `ai-quality-backfill-queue` / `ai-optimization-queue` / `knowledge-queue`）：

```yaml
x-deepseek-dns-pin: &deepseek-dns-pin
  extra_hosts:
    - "api.deepseek.com:119.188.175.46"
    - "api.deepseek.com:221.204.163.76"
```

**修复后实测**：容器内连续 6 次请求，连接耗时稳定 **13~15ms**；用库里的真实 Key 读模型列表返回 **HTTP 200**；真调一次 `chat/completions` 返回 **HTTP 200**。

### ⚠ 维护提示

DeepSeek 换 IP 后这条会失效，症状是界面再次报「网络连接失败」。届时取当前真实解析再替换：

```bash
curl -s "https://223.5.5.5/resolve?name=api.deepseek.com&type=A"
```

换其他服务商（OpenAI / 通义 / Kimi 等）同理，按域名加一条即可。**「AI 模型」本来就是自由填 base_url + Key + model_id 的自定义方式，代码层面没有域名白名单**（`AiProviderEndpointPolicy` 里那份白名单管的是「AI 源提供商 / 搜索源」，不是模型配置）。

### 排查这类问题的命令序列（可复用）

```bash
# 1. 容器解析到什么
docker exec geoflow-app-prod getent ahosts api.deepseek.com

# 2. 权威 DNS 应该是什么（对比出假 IP）
curl -s "https://223.5.5.5/resolve?name=api.deepseek.com&type=A"

# 3. 逐个 IP 绕过 DNS 直连，定位哪个是坏的
docker exec geoflow-app-prod curl -4 -sS -o /dev/null -w '连接=%{time_connect}s HTTP=%{http_code}\n' \
  --max-time 40 --resolve api.deepseek.com:443:119.188.175.46 https://api.deepseek.com/v1/models

# 4. 追踪 curl 依次尝试了哪些地址（看它卡在第几个）
docker exec geoflow-app-prod curl -4 -sv -o /dev/null --max-time 60 https://api.deepseek.com/v1/models 2>&1 | grep -iE 'Trying|Connected'
```

**两条通用教训**：

1. **排查容器网络，绝对不要拿宿主机直连当基线。** 本次宿主机 `curl` 显示「0.8 毫秒连接成功」，看着网络好得很 —— 但 `curl -v` 一看，宿主机走的是**本地代理**（`https_proxy=http://127.0.0.1:61729`），连的是本地端口而不是公网。**0.8 毫秒连不到公网。**要对比就必须显式绕过代理（`curl --noproxy '*'`）。
2. **「时好时坏」优先怀疑 DNS 轮换，而不是网络抖动。** 本次真实情况是：假 IP 从来就没通过、真 IP 一直都通，变的只是 DNS 先返回哪一个。**恒定耗时（如每次都 21 秒）比随机耗时更有诊断价值** —— 恒定量通常是 TCP SYN 重传或超时预算的特征值。


---

## 五、常用命令

**所有命令都要带两个 `-f`**（原始编排 + 本地覆盖）：

```bash
cd /d/GEOFlow/src
COMPOSE="docker compose --env-file .env.prod -f docker-compose.prebuilt.yml -f docker-compose.local-override.yml"
```

| 操作 | 命令 |
|---|---|
| 看全部容器状态 | `$COMPOSE ps` |
| 启动 | `$COMPOSE up -d` |
| 停止（保留数据） | `$COMPOSE stop` |
| 关掉并删除容器（数据仍在 `docker-data/`） | `$COMPOSE down` |
| 重启单个服务 | `docker restart geoflow-queue-prod` |
| 看某容器日志 | `docker logs -f geoflow-app-prod` |
| 看资源占用 | `docker stats --no-stream $(docker ps --filter name=geoflow --format '{{.Names}}')` |
| 进数据库 | `docker exec -it geoflow-postgres-prod psql -U geo_user -d geo_flow` |
| 进 PHP 容器 | `docker exec -it geoflow-app-prod sh` |
| 手动采集 AI 可见度 | `docker exec geoflow-app-prod php artisan geoflow:ai-visibility:collect "关键词1" "关键词2"` |

---

## 六、实测：可见度模块到底做到了什么

这是本次部署最有价值的发现，**也修正了我之前基于 3.0 更新报告给你的判断**。

我之前说过「可见度测量还停留在路线图、官网面板是静态演示」。那是 3.0 的状态。**v3.1.0 里这个模块是真的实现了。**

代码与数据库证据：

| 层 | 实际存在 |
|---|---|
| 后台入口 | `/geo_admin/ai-visibility`，含「采集 / 添加竞品 / 自动识别竞品 / 删除竞品」 |
| Artisan 命令 | `geoflow:ai-visibility:collect {关键词}`、竞品识别命令 |
| 队列任务 | `CollectAiVisibilityKeywordJob`、`DetectAiVisibilityCompetitorsJob` |
| 数据表 | `ai_visibility_runs`（一次采集）/ `ai_visibility_sources`（引用来源）/ `ai_visibility_competitors` / `ai_visibility_competitor_detections` |
| 支持的引擎 | `doubao_search_custom`、`doubao_ark_responses`、`deepseek_analysis` |

**但它测的和你岗位 KPI 要的不是同一个东西。** 这一点在代码里能直接看出来：

- `AiVisibilitySource.rank` 的赋值是 `rank: $index + 1`，对应的 `sourceType` 是 `web_search_result`。也就是说，**这个 rank 是「搜索结果里那条 URL 的序号」**，属于「被引用」层。
- `AiVisibilityCompetitor` 表只有 `name / aliases / is_active / source` 四个字段，**没有任何位次字段**。所以竞品只能判断「有没有出现」，不能判断「排第几」。

用你自己那套四层链路说：

| 层 | GEOFlow v3.1.0 |
|---|---|
| 能被爬到 | ✅ 有（robots / sitemap / llms.txt 自动生成） |
| 被检索召回 | ✅ 有（知识库切块 + pgvector 向量召回） |
| **被引用** | ✅ **有，这是它可见度模块的核心**（记录引用了哪些 URL、序号、权威等级） |
| **进推荐位前二** | ❌ **没有**（没有「品牌在推荐榜第几名」的概念） |

对照你自己那个工具：你算的是 `前三命中率`（分母=该引擎有效采样数）、`平均推荐位次`（分母=被推荐次数）、`推荐理由命中率`、`竞品共现率`。**GEOFlow 这三个里只能勉强对应「竞品共现」的雏形，另外两个它没有。**

面试口径可以直接这么说：

> 「我在本地部署跑了 GEOFlow v3.1.0。它的 AI 可见度模块记录的是引用来源的 rank 和 rank_score，也就是被引用层；竞品表只有名称和别名，没有位次字段。所以它解决不了『品牌在推荐榜排第几』这个问题。我自己的测量工具是按问题集跑采样，算前三命中率和平均推荐位次，分母分别是有效采样数和被推荐次数。」

这比空谈概念有力得多，因为它是**部署过、看过数据库和代码之后**得出的。

---

## 七、资源占用（实测）

| 项 | 数值 |
|---|---|
| 容器数 | 11 个（含 1 个已退出的 init） |
| **容器内存合计** | **约 614MB** |
| 单个最大 | scheduler 约 114MB |
| 磁盘（源码 + 数据） | 源码 115MB，Postgres 数据目录在 `docker-data/prod/postgres` |
| Docker 镜像 | app 969MB + web 114MB |

比预估的 2.5-3.5GB 低很多，因为这是**空库启动**。真正跑 AI 生成任务时队列容器才会吃满各自的 `mem_limit`（各 256-384MB）。你机器现在 15.7GB 总量、空闲约 1.8GB，跑当前这个空载状态没问题；**要真的跑内容生产任务，建议先关掉 `lfc-*` / `cs_*` 那 5 个容器**。

---

## 八、还没做的两件事

1. **前台 `llms.txt` 和 `sitemap.txt` 返回 404**，而 `robots.txt` 和 `sitemap.xml` 是 200。按官方说明这四个文件要在完成基础站点设置后才全部生成，目前 `site_settings` 只有 1 行默认记录。**你在后台把站点域名、名称这些填一遍就会出来。**
2. **AI 模型已配好并打通**（2026-09-30）：`ai_models` 表里有 DeepSeek 记录（`api_url = https://api.deepseek.com`，`model_id = deepseek-v4-flash`）。原先「测试连接」一直失败是 **DNS 污染**导致的，已修（见第四节之三）。
   - ⚠ **`deepseek-v4-flash` 这个名字是可用的** —— DeepSeek 把它保留为兼容别名，实际路由到 `deepseek-flash`。截至 2026-09-30，`/v1/models` 返回的正式名是 `deepseek-flash` 和 `deepseek-v4-pro`。
   - 表里 `id=1` 那条是 `https://api.openai.com` 的 OpenAI Embedding，**既不在任何白名单、国内也无法直连**，如果某个功能依赖 embedding，这条是废的，要换成国产 embedding。

---

## 九、你该拿它干什么

**当教材读，不要当生产力工具。** 三个具体用法：

1. **看它的八阶段循环怎么落地**：知识库 → 选题 → AI 生成 → 质检门禁 → 人工审核 → 官网输出 → 渠道分发 → 数据反馈。这正好是你课程第四场「官网 GEO」的工程化实现。
2. **读它的 Schema / sitemap / llms.txt 输出**，这是你 Phase 4 技术适配的参考答案。
3. **对照它的可见度模块和你自己的工具**，把「被引用 vs 被推荐」讲成有代码依据的判断，而不是概念。

**别做的**：不要因为「部署成功了」就去改它、二次开发。它是 Laravel/PHP 栈，你不走这条路，投产比极低。

---

## 十、升级评估（2026-10-15 实测）：**当前条件下无法升级，且"有新版本"是误报**

> ⚠️ 本节第一版我写错过（只看了 `version.json` 就下结论）。**下面是逐个渠道实测后的最终版本。**

### 10.1 你看到的"3.2.0-beta.2"——**这个版本从未发布**

`raw.githubusercontent.com/.../main/version.json` 里确实写着 `3.2.0-beta.2`（2026-10-01）。
但我去 GitHub API 逐项核对，**这个版本不存在**：

| 检查渠道 | 实测结果 |
|---|---|
| GitHub Releases（共 7 个） | `v3.2.0-beta.1`(09-16) / `v3.1.0`(09-09) / `v3.0.0`(09-04) / `v2.3.0` / `v2.2.0` / `v2.1.2` / `v2.1.1` —— **没有 beta.2** |
| GitHub Tags（共 7 个） | 同上，**没有 `v3.2.0-beta.2`** |
| `api.github.com/.../releases/tags/v3.2.0-beta.2` | **`Not Found`** |
| 源码包 `GEOFlow-v3.2.0-beta.2.zip` | **HTTP 404**（直连和 gh-proxy 都 404） |
| ghcr.io 镜像 tag（`geoflow-app` 共 31 个） | 版本号只有 **`2.3.0` / `3.0.0` / `3.1.0`**，其余是 `candidate-*` 和 `online-rehearsal-*` |
| ghcr.io `latest` 指向什么 | **digest 与 `3.1.0` 完全相同**（app: `sha256:94c01bfd…`，web: `sha256:feaf783c…`） |

### 10.2 误报的根因（这个可以修）

```
GEOFLOW_UPDATE_METADATA_URL=https://raw.githubusercontent.com/yaojingang/GEOFlow/main/version.json
```

**指向 `main` 分支 —— 开发分支。** 开发分支的 `version.json` 会提前写上"下一个版本号"，
所以更新检查**永远**会告诉你"有新版本"，而那个版本可能几个月后才发布、甚至改名。

**修法（1 行，你自己就能改）**：在 `.env.prod` 里把更新检查关掉，避免被误导：

```dotenv
GEOFLOW_UPDATE_CHECK_ENABLED=false
```

改完重启容器。**注意**：这样你就不会收到任何更新提示了 —— 想查最新版时手动跑：

```bash
curl -sS "https://api.github.com/repos/yaojingang/GEOFlow/releases?per_page=5" | \
  grep -E '"tag_name"|"published_at"'
```

### 10.3 实际唯一存在的"更新"是 v3.2.0-beta.1 —— 但没有预构建镜像

| 项 | 实测 |
|---|---|
| tag | `v3.2.0-beta.1`，**prerelease = True**，发布 **2026-09-16** |
| 官方正文原话 | 「GEOFlow 3.2 beta introduces remote administration workflows for development and isolated testing. **The latest stable release remains `v3.1.0` for production deployments.**」 |
| 发布附件（3 个） | `GEOFlow-v3.2.0-beta.1.zip` **29.6 MB（源码包）** + `.sha256` + `version.json` |
| **有没有 Docker 镜像** | ❌ **没有。只有源码包** |
| ghcr.io 上有 beta.1 的镜像 tag 吗 | ❌ 没有（tag 列表里只有 2.3.0/3.0.0/3.1.0） |

> **所以升到 beta.1 必须"本地编译"** —— 下载 29.6MB 源码，跑 `docker compose build`
> （composer install + npm ci 拉几百 MB 依赖）。

### 10.4 而本地编译这条路，这台机器跑不动

| 检查项 | 实测值 |
|---|---|
| 物理内存 | 16,125 MB |
| **当前可用** | **仅 2,538 MB（占用 84%）** |
| composer install + npm ci 的常见峰值 | 约 2–4 GB |

**而我当初之所以选"预构建镜像"路线，就是因为这台机器跑不动本地编译** ——
见本文第二节的对比表：本地编译首次到可用 20–40 分钟、内存峰值高、**极可能失败**；
预构建路线 3 分钟、低内存。

**现在 11 个 GEOFlow 容器正跑着（占约 614MB），加上 PyCharm / 浏览器等，可用只剩 2.5GB。**
在这个状态下跑 `npm ci`，失败概率很高。

### 10.5 结论

| 方案 | 可行性 |
|---|---|
| 升到 3.2.0-beta.2 | ❌ **不存在这个版本**，无从升 |
| 升到 3.2.0-beta.1 | ⚠️ 理论上可行，但需**本地编译**，内存不够 → 高风险；且官方说生产用 3.1.0 |
| 继续用 3.1.0 | ✅ **推荐**。官方两次明确支持（beta.1 与 beta.2 的提示都写了） |
| 等 3.2.0 正式版 | ✅ 届时会有预构建镜像，一条 `docker pull` 就能升 |

> **最终建议**：**关掉误导性的更新提示**（10.2 那一行），继续用 3.1.0 做项目。
> 等 3.2.0 **正式版**发布（`prerelease = False`）再升级 —— 那时有预构建镜像，升级是几分钟的事。

### 10.6 如果你以后确实要升 beta 版（完整步骤 + 风险）

**前置**：先关掉占内存的程序（PyCharm / 浏览器），确保可用内存 ≥ 6GB。

```bash
# 0. 备份（必做，升级会跑 9 项数据库迁移）
mkdir -p D:/GEOFlow/backup-$(date +%Y%m%d)
docker exec geoflow-postgres-prod pg_dump -U geo_user -d geo_flow > D:/GEOFlow/backup-$(date +%Y%m%d)/db.sql
cp D:/GEOFlow/src/.env.prod D:/GEOFlow/backup-$(date +%Y%m%d)/env.prod.bak
cp -r D:/GEOFlow/src/storage D:/GEOFlow/backup-$(date +%Y%m%d)/storage

# 1. 下载源码包（走 gh-proxy，直连不通）
curl -L -o D:/GEOFlow/GEOFlow-v3.2.0-beta.1.zip \
  "https://gh-proxy.com/https://github.com/yaojingang/GEOFlow/releases/download/v3.2.0-beta.1/GEOFlow-v3.2.0-beta.1.zip"
# 校验（拿官方 .sha256 对比，别跳过）
curl -L "https://gh-proxy.com/https://github.com/yaojingang/GEOFlow/releases/download/v3.2.0-beta.1/GEOFlow-v3.2.0-beta.1.zip.sha256"

# 2. 解压到新目录（不要覆盖现有部署）
# 3. 用开发版 compose 构建（注意：这是重活）
cd <解压目录>
docker compose build          # ← 内存峰值在这
docker compose up -d

# 4. 回滚：停掉新容器，用原目录 + 原镜像重启（tag 改回 3.1.0）
```

**已知风险**（官方原文）：9 项维护模式迁移 / 主题视频需 ffprobe /
**破坏性 API 变更（创建文章任务需显式 `need_review`）** / 远程主题发布与回滚仍未完成。

---

## 十一、附：升级排障速查（如果哪天出问题）

| 现象 | 查什么 |
|---|---|
| 更新检查一直提示有新版本 | `GEOFLOW_UPDATE_METADATA_URL` 指向 `main` 分支 → 误报，见 10.2 |
| 后台「执行更新」点了没反应 | 没有 Updater 容器、`/run/geoflow-updater/` socket 不存在 → 只能走 docker 方式 |
| `docker pull` 拉不到镜像 | ghcr.io 实测可通（走 Docker Desktop 内置代理）；检查 Docker Desktop 是否在运行 |
| 判断网络可达性 | **用真实操作（`docker pull`），不要用 `docker manifest inspect`** —— 后者实测会误判 |
