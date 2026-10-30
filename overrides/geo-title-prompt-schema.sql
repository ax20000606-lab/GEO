-- =============================================================================
-- 标题反推 AI 提问：DDL 留档（v3 / 22 格矩阵）
--
-- 这份文件不参与运行。真正建表由 GeoTitleQuestionStore::ensureSchema() 执行
-- （migrations 目录没挂进容器，靠 artisan migrate 跑不到新文件）。
--
-- 与 PHP 里的 DDL 必须保持一致。改这里之前先改 Store（CHECK 由枚举常量生成），
-- 再同步回本文件，否则下次有人照 .sql 手工建表会和代码分叉。
--
-- 幂等性：全部 IF NOT EXISTS，可以反复执行。
-- 执行方式（需要时）：
--   docker exec -i geoflow-postgres-prod psql -U geo_user -d geo_flow \
--     < D:\GEOFlow\overrides\geo-title-prompt-schema.sql
--
-- 顺序不能反（系统设计 R10）：
--   先改 DDL 文本 -> 再 DROP -> 最后访问页面让 ensureSchema() 用新 DDL 重建。
--   反了的话：DROP 完那一瞬间页面被刷新，旧 DDL 会把表按旧枚举重建出来，
--   之后所有写入被 CHECK 拒收，而 \d 看起来一切正常。
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 批次表：一次生成 = 一条
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS geo_title_prompt_batches (
  id                bigserial      PRIMARY KEY,
  -- 品牌快照：历史报告不能跟着品牌档案变
  brand_slug        varchar(64)    NOT NULL,
  brand_name        varchar(128),
  -- 标题原文 + 归一化哈希：同一标题重复生成时靠 title_hash 拉成一条时间线
  title             varchar(500)   NOT NULL,
  title_hash        char(64)       NOT NULL,
  industry_template varchar(32)    NOT NULL DEFAULT 'skincare',
  -- 版本化三要素：影响结果的三个可复现维度，缺一个历史就不可比
  prompt_version    varchar(24)    NOT NULL,
  model_id          varchar(128),
  model_name        varchar(128),
  -- DeepSeek 官方接口不收 seed，允许 null 才诚实
  seed              bigint,
  temperature       numeric(3,2)   NOT NULL DEFAULT 0.20,
  -- running / completed / degraded / failed
  status            varchar(16)    NOT NULL DEFAULT 'running',
  -- 主清单总条数（含 A 类）
  primary_count     int            NOT NULL DEFAULT 0,
  -- 其中「本篇射程」条数（排除 route=source_build），5<=x<=8 校验看这个
  scoped_count      int            NOT NULL DEFAULT 0,
  marginal_count    int            NOT NULL DEFAULT 0,
  angle_count       int            NOT NULL DEFAULT 0,
  stage_count       int            NOT NULL DEFAULT 0,
  -- {"A":1,"B":3,"C":2,"D":2}
  class_counts      jsonb,
  -- [{"cell":"pricing:S2","code":"missing_slot","detail":"缺{品类2}"}] 空转体检
  skipped_cells     jsonb,
  llm_calls         int            NOT NULL DEFAULT 0,
  prompt_tokens     int            NOT NULL DEFAULT 0,
  completion_tokens int            NOT NULL DEFAULT 0,
  elapsed_ms        int            NOT NULL DEFAULT 0,
  -- hard_invalid / insufficient_coverage / null
  retry_reason      varchar(32),
  -- 展示给运营的黄条
  warnings          jsonb,
  -- [{"q":"...","drop":"C1","detail":"..."}] 被淘汰的不落问题表，只留档
  rejected          jsonb,
  -- 模型原始输出，排查用
  raw_json          jsonb,
  -- 固定那一句「模型推测、非引擎实测」，批次级存一份
  declaration       text           NOT NULL,
  created_by        bigint,
  created_at        timestamp(0),
  updated_at        timestamp(0)
);

-- 报告页历史下拉：按品牌取最近 N 条
CREATE INDEX IF NOT EXISTS geo_tpb_brand_created_idx  ON geo_title_prompt_batches (brand_slug, created_at DESC);
-- 同一标题的时间线（历史下拉按 title_hash 分组）
CREATE INDEX IF NOT EXISTS geo_tpb_title_hash_idx     ON geo_title_prompt_batches (title_hash, created_at DESC);
-- 排查僵尸 running 批次
CREATE INDEX IF NOT EXISTS geo_tpb_status_created_idx ON geo_title_prompt_batches (status, created_at DESC);

-- -----------------------------------------------------------------------------
-- 问题表：一条候选 = 一行
-- 抢不到的问题「不输出、不进任何清单」-> 不进本表，只留在批次的 rejected
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS geo_title_prompt_questions (
  id              bigserial    PRIMARY KEY,
  batch_id        bigint       NOT NULL REFERENCES geo_title_prompt_batches(id) ON DELETE CASCADE,
  -- 反范式：按阶段/按类做品牌维度统计时不必回联批次表
  brand_slug      varchar(64)  NOT NULL,
  list_kind       varchar(8)   NOT NULL,   -- primary / marginal
  position        int          NOT NULL DEFAULT 0,  -- 按「投入产出比」排序后的展示序号
  question        varchar(255) NOT NULL,
  -- 六维标签
  angle           varchar(16)  NOT NULL,   -- definition/comparison/pricing/review/use_case/trust
  -- 7 值。S3 拆开：S3a=候选比较（有几个候选，在比谁好）
  --              S3b=风险验证（已倾向某品牌，在排雷：隐藏费用/售后/退款/可靠吗/真实评价/资质）
  -- S0 允许落库但正常不会出现（S0=只有情绪没有 query），留着是为了让脏数据可见
  stage           varchar(4)   NOT NULL,
  class           char(1)      NOT NULL,   -- A/B/C/D
  intent          varchar(16)  NOT NULL,   -- transactional/commercial/informational/navigational
  brand_relevance varchar(16)  NOT NULL,   -- own/competitor/both/none
  -- direct / sidelong / own_source（A+S3b 的自有信源页，计入射程）/ source_build
  route           varchar(16)  NOT NULL,
  -- 派生
  fit             varchar(8)   NOT NULL,   -- can / marginal；抢不到的不落库
  cell            varchar(24)  NOT NULL,   -- "review:S3a" / "trust:S3b"，22 格之一
  -- 强制：验收唯一抓手是「凭什么抢到」
  why             text         NOT NULL,
  -- ── 运营口径三件套：全部服务端确定性推导，模型不给 -> NOT NULL 安全 ----
  -- concern 是 1-2 个值。用「主值 + 次值」两列而不是 PG 数组：
  -- 拆两列后 WHERE concern='售后' 直接吃索引，CHECK 也写得干净，语义等价。
  concern         varchar(32)  NOT NULL,   -- 价格/质量/安全/效果/售后/真实性/时效性/其他
  concern_alt     varchar(32),             -- 次顾虑，无则 NULL
  expected        text         NOT NULL,   -- 预期答案，6-200 字，不含占位符
  -- 22 个 code，与 22 个有效格严格 1:1（content_type = F(cell)）
  content_type    varchar(32)  NOT NULL,
  -- 角度触及快照（jsonb 数组，含主角度）。生成时快照，落库后永不重算
  angles_touched  jsonb        NOT NULL,
  -- 模型原始标注 vs 服务端复算，用于发现「模型老把 S3b 标成 S3a」这类漂移
  model_angle     varchar(16),
  model_stage     varchar(8),
  model_class     char(1),
  corrected       boolean      NOT NULL DEFAULT false,
  created_at      timestamp(0),
  CONSTRAINT geo_tpq_stage_chk   CHECK (stage  IN ('S0','S1','S2','S3a','S3b','S4','S5')),
  CONSTRAINT geo_tpq_angle_chk   CHECK (angle  IN ('definition','comparison','pricing','review','use_case','trust')),
  CONSTRAINT geo_tpq_class_chk   CHECK (class  IN ('A','B','C','D')),
  CONSTRAINT geo_tpq_intent_chk  CHECK (intent IN ('transactional','commercial','informational','navigational')),
  CONSTRAINT geo_tpq_fit_chk     CHECK (fit    IN ('can','marginal')),
  CONSTRAINT geo_tpq_list_chk    CHECK (list_kind IN ('primary','marginal')),
  CONSTRAINT geo_tpq_route_chk   CHECK (route  IN ('direct','sidelong','own_source','source_build')),
  CONSTRAINT geo_tpq_relv_chk    CHECK (brand_relevance IN ('own','competitor','both','none')),
  CONSTRAINT geo_tpq_concern_chk CHECK (concern IN ('价格','质量','安全','效果','售后','真实性','时效性','其他')),
  -- concern_alt 要么 NULL，要么是合法值且与主值不同（同值重复没意义）
  CONSTRAINT geo_tpq_concern_alt_chk CHECK (
    concern_alt IS NULL
    OR (concern_alt IN ('价格','质量','安全','效果','售后','真实性','时效性','其他') AND concern_alt <> concern)
  ),
  -- 22 个 code 封闭词表，与 22 个有效格 1:1（规则文档 §2.6 原样照抄）
  CONSTRAINT geo_tpq_ctype_chk CHECK (content_type IN (
    'symptom_science','category_primer','ingredient_explainer','category_comparison',
    'brand_comparison','price_range_primer','price_comparison','price_transparency',
    'price_page','category_review_roundup','pros_cons_analysis','user_evidence_roundup',
    'longterm_review','scene_science','audience_buying_guide','audience_fit_comparison',
    'usage_guide','category_pitfall_guide','brand_reliability_comparison',
    'prospectus_and_policy','authentic_channel_guide','counterfeit_rights_guide'
  )),
  -- 4-20 字：规则文档 §3.6 要求 <=20；下限 4 = 基句(10)+「，重点讲」(4)+焦点(5)=19 的余量
  CONSTRAINT geo_tpq_expected_len_chk CHECK (char_length(expected) BETWEEN 4 AND 20),
  CONSTRAINT geo_tpq_touched_chk CHECK (
    jsonb_typeof(angles_touched) = 'array'
    AND CASE WHEN jsonb_typeof(angles_touched) = 'array' THEN jsonb_array_length(angles_touched) ELSE 0 END >= 1
    AND angles_touched <@ '["definition","comparison","pricing","review","use_case","trust"]'::jsonb)
);

-- 报告页唯一的读路径：按批次取两类清单并按展示序号排
CREATE INDEX IF NOT EXISTS geo_tpq_batch_list_idx   ON geo_title_prompt_questions (batch_id, list_kind, position);
-- 按阶段看品牌抢到什么
CREATE INDEX IF NOT EXISTS geo_tpq_brand_stage_idx  ON geo_title_prompt_questions (brand_slug, stage);
-- 按 A/B/C/D 看分布
CREATE INDEX IF NOT EXISTS geo_tpq_brand_class_idx  ON geo_title_prompt_questions (brand_slug, class);
-- 内容类型分布 / 顾虑分布（运营看「这批 S3b 占多少、要不要补资质内容」）
CREATE INDEX IF NOT EXISTS geo_tpq_brand_ctype_idx   ON geo_title_prompt_questions (brand_slug, content_type);
CREATE INDEX IF NOT EXISTS geo_tpq_brand_concern_idx ON geo_title_prompt_questions (brand_slug, concern);
-- 同批次内问题唯一：重复提交 / 重试写两遍不产生脏行
CREATE UNIQUE INDEX IF NOT EXISTS geo_tpq_batch_question_uidx ON geo_title_prompt_questions (batch_id, question);
