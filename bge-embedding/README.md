# 本地 bge 向量化服务

把本地 `bge-small-zh-v1.5` 模型包成一个 **OpenAI 兼容的 `/embeddings` 接口**，供 GEOFlow 知识库使用。

> **背景**：GEOFlow 的知识库要做"切片 + 向量化"，但它库里唯一配的 embedding 模型是
> `OpenAI text-embedding-3-small`（`api.openai.com`，国内连不上）→ 导致知识库只能走
> **无向量的降级模式**，语义检索能力弱。本服务把本地 bge 接上去，解决这个问题。

**状态：2026-10-15 已部署并端到端验证通过。**

---

## 一、怎么启动 / 停止

| 操作 | 怎么做 |
|---|---|
| **启动** | 双击 **`启动bge服务.bat`** |
| **停止** | 双击 **`停止bge服务.bat`** |
| 看实时日志 | `docker logs -f bge-embed` |
| 看健康状态 | `docker exec bge-embed python -c "import urllib.request,json;print(json.load(urllib.request.urlopen('http://127.0.0.1:8000/health')))"` |

启动脚本会自动：检查镜像/模型/网络 → 建容器 → 等模型加载 → 健康检查 → 打印要填进 GEOFlow 的参数。

> 容器设了 `--restart unless-stopped`，所以**开机后会自动启动**（除非你手动 stop 过）。

---

## 二、要填进 GEOFlow 的参数（已实测可用）

GEOFlow 后台 → **AI 配置器** → 新增模型：

| 字段 | 值 |
|---|---|
| 名称 | `本地 bge-small-zh` |
| **类型** | **`embedding`** |
| **api_url** | **`http://10.88.0.50:8000`** ← **必须用 IP，不要用域名** |
| **model_id** | **`bge-small-zh-v1.5`** |
| **api_key** | `bge-local-any-string`（本服务不校验，非空即可） |
| 每日上限 | 随便（本地服务不消耗额度） |

> ⚠️ **api_url 不要带 `/v1`** —— GEOFlow 自己会拼 `{api_url}/embeddings`
> （不过本服务同时挂了 `/embeddings` 和 `/v1/embeddings`，填带 `/v1` 的也能用，算容错。）

---

## 三、⚠️ 一个必须配的 GEOFlow 开关（否则请求会被拦截）

GEOFlow 有统一的**出站安全策略**，默认**会拦截所有私网地址**。
不加白名单，调用会直接抛：

```
App\Services\Outbound\OutboundRequestBlockedException
Outbound request blocked by security policy.
```

**改 `D:\GEOFlow\src\.env.prod` 第 140 行**：

```dotenv
GEOFLOW_OUTBOUND_PRIVATE_TARGETS=10.88.0.50:8000
```

然后重启 GEOFlow 让它生效：

```bash
cd /d/GEOFlow/src
docker compose --env-file .env.prod -f docker-compose.prebuilt.yml -f docker-compose.local-override.yml up -d
```

> **格式说明**（读源码 `SafeOutboundHttpClient::privateTargetAllowed()` 得到）：
> 必须写 **`host:port`** 精确匹配（`strtolower($host).':'.$port`）。
> **不支持通配符 `*`，也不支持 CIDR `/`** —— 源码里显式排除了这两种形式。
> 多个目标用**逗号**分隔。

**这一步 2026-10-15 已经做过了**，所以你现在直接配模型就行。

---

## 四、端到端验证记录（2026-10-15）

### 4.1 服务自身

```
GET /health  →  {"status":"ok","model":"bge-small-zh-v1.5","dim":512,"device":"cpu"}
```
模型加载耗时 **0.2 秒**（本地磁盘，无需联网）。

### 4.2 接口格式（对上 GEOFlow 的期望）

请求 `{"model":"bge-small-zh-v1.5","input":["文本1","文本2"]}`，返回：

| 检查项 | 结果 |
|---|---|
| `object` | `list` ✅ |
| `data` 条数 | 2 ✅ |
| `data[].index` | 0 / 1 ✅（GEOFlow 按 index 排序取值） |
| `data[].embedding` 维度 | **512** ✅ |
| **L2 范数** | **1.0** ✅（已归一化，余弦相似度可用） |

### 4.3 语义有效性（证明模型真在工作，不是哈希）

查询「瑞贝卡假发怎么样」，与三条文档算余弦相似度：

| 相似度 | 文档 | 判断 |
|---|---|---|
| **+0.7045** | Rebecca假发口碑好不好 | 同义改写，**最高** ✅ |
| +0.5991 | 假发应该怎么清洗保养 | 同领域，中等 |
| **+0.4159** | 人发和化纤有什么区别 | 主题不同，**最低** ✅ |

**排序完全符合预期** —— 这是哈希向量做不到的。

### 4.4 走 GEOFlow 真实出站网关的端到端测试

用 GEOFlow 自己的 `SafeOutboundHttpClient`（带安全策略）发请求：

```
>>> HTTP 200
>>> data 条数   : 2
>>> 第一条维度   : 512
>>> 前 3 个值    : -0.0701, -0.0002, -0.079
```

**通过。** 与直连测试的数值完全一致。

---

## 五、为什么用 `ai-app` 镜像（而不是新建 Python 环境）

本服务直接复用你 **lfc 客服项目**的镜像 `ai-app`，因为它已经装了：

| 包 | 版本 |
|---|---|
| `torch` | **2.14.0+cpu**（CPU 版，不占显存） |
| `sentence_transformers` | 6.0.1 |
| `transformers` | 5.17.0 |
| `fastapi` / `uvicorn` | 0.141.1 / 0.53.0 |

**好处：一个包都不用下。** 你这台机器内存本来就紧、下载也慢，复用是最省事的路径。

（宿主 venv `.venv` 里**没有**这些依赖 —— 那是轻量开发环境，重依赖只在镜像里。）

---

## 六、技术要点（写代码时注意的几件事）

| 点 | 说明 |
|---|---|
| **模型加载一次** | 在 FastAPI 的 `lifespan` 里加载，进程内常驻。**别每次请求都 load**（会慢几百倍） |
| **归一化** | `normalize_embeddings=True` —— bge 官方建议，配合余弦相似度 |
| **文档侧不加前缀** | bge 的规范是**只有查询侧加** `为这个句子生成表示以用于检索相关文章：`。<br>GEOFlow 送过来的是**文档 chunk**，所以**不加前缀**是对的 |
| **`input` 是数组** | GEOFlow 每批送 32 条（`GEOFLOW_KNOWLEDGE_EMBEDDING_JOB_SIZE=32`） |
| **响应要快** | GEOFlow 设了 `connectTimeout(8)` / `timeout(45)`。bge-small CPU 编码 32 条约 1–3 秒，够 |
| **响应体大小** | GEOFlow 上限 8MB；32×512 float ≈ 100KB，绰绰有余 |
| **不用 token** | GEOFlow 会发 `Authorization: Bearer <api_key>`，本服务接受但不校验 |

---

## 七、文件说明

| 文件 | 作用 |
|---|---|
| `bge_server.py` | 服务本体（FastAPI，约 150 行） |
| `启动bge服务.bat` | 启动脚本（**GBK 编码**，双击运行） |
| `停止bge服务.bat` | 停止脚本（**GBK 编码**） |
| `README.md` | 本文 |

> **为什么 .bat 用 GBK 编码**：cmd.exe 用系统 ANSI 码页（中文 Windows 是 GBK）解析批处理文件。
> 存成 UTF-8 会把中文解成乱码，**连 ASCII 行都会被带崩**。踩过一次，记在这里。

---

## 八、排障

| 现象 | 原因 / 处理 |
|---|---|
| GEOFlow 报 `Outbound request blocked by security policy` | `.env.prod` 的 `GEOFLOW_OUTBOUND_PRIVATE_TARGETS` 没配或没重启 → 见第三节 |
| 容器起来但健康检查一直不 ok | 看日志 `docker logs bge-embed`；模型加载只要 0.2s，超过 30s 就有问题 |
| 报 `找不到 Docker 网络 geoflow-prod-net` | GEOFlow 没启动。先双击 `D:\GEOFlow\启动GEOFlow.bat` |
| 报 `找不到镜像 ai-app` | lfc 项目的镜像被删了。用 `docker images \| findstr ai-app` 确认 |
| 端口 8000 冲突 | 8000 是**容器内部**端口，不占宿主机端口，不会和你的 8000 冲突 |
| 重启 Docker 后服务没自启 | 检查 `docker inspect bge-embed --format '{{.HostConfig.RestartPolicy.Name}}'` 应为 `unless-stopped` |
| 内存不够 | 本服务约占用 0.5–1GB。**绝对不要同时加载 bge-reranker**（你自己注释里写它要 2.1GB） |

---

## 九、下一步（还没做的）

配好模型之后要做的：**让 GEOFlow 的知识库用上这个 embedding 重新切片**。

1. 在 AI 配置器里新建上面那条 embedding 模型
2. 把知识库的 embedding 模型绑定到它（或重新触发切片同步）
3. 验证：知识库的 `chunk_embedding_provider` / `chunk_embedding_dimensions` 不再是空，
   且 `chunk_embedding_dimensions = 512`

**具体怎么在界面上操作，等我查清后再给你步骤**（不猜界面）。
