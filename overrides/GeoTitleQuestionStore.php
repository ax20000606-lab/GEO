<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 标题反推提问：数据层（两张表 + 全部读写）。
 *
 * 为什么 DDL 写在 PHP 里懒执行，而不是只留 migration：
 *   docker 的 migrations 目录没有挂载，`php artisan migrate` 跑不进容器内的新文件；
 *   手工上服务器建表又容易漏索引 / 漏 CHECK。折中做法与
 *   `2026_10_27_010000_create_ai_visibility_batches.php` 完全一致：
 *     · migration + .sql 只用于留档（人看得懂）
 *     · 真正执行靠 ensureSchema()，每次页面/接口入口调一次，全部 IF NOT EXISTS
 *   这样任何一台新环境只要打开页面就自动建好表，且重复执行无报错。
 *
 * 为什么 created_at 用 date() 而不是 now()：
 *   与 ai_visibility_batches 保持一致。now() 走 Carbon，受 APP_TIMEZONE 影响；
 *   date() 走 PHP 本地时区，同一份代码在不同容器里得到的时间口径相同，
 *   历史报告之间可比。混用 two 种写法会出现「同一秒写入的两个批次时间差 8 小时」。
 */
final class GeoTitleQuestionStore
{
    /** 批次表：一次推导 = 一条 */
    public const TABLE_BATCHES = 'geo_title_prompt_batches';

    /** 问题表：一条候选 = 一行（抢不到的进批次表 rejected，不进这里） */
    public const TABLE_QUESTIONS = 'geo_title_prompt_questions';

    /**
     * declaration 兜底文本。
     *
     * 为什么数据层自己留一份（而不是引用 GeoTitleQuestionIndustry::DECLARATION）：
     *   composer 是**权威 classmap**（autoload_real.php 里 setClassMapAuthoritative(true)），
     *   新加的 overrides 文件不在 classmap 里，只能靠 web.php 显式 require_once。
     *   如果 Store 反过来依赖 Industry，那 require 顺序就被绑死了 ——
     *   将来谁先 require 谁就先炸，且报错是「class not found」这种毫无线索的。
     *   两处文本必须一致，自测里有一条断言钉住（Selftest #19）。
     */
    public const DECLARATION = '以下问题由模型基于标题推测，未经引擎实测，不代表 AI 引擎真实发起过的查询。';

    // =========================================================================
    // 枚举常量（**唯一数据源**）
    //
    // 为什么枚举放在数据层而不是行业配置层：
    //   这些值是**数据库列的定义域**。CHECK 约束写在 DDL 里，DDL 由本类执行。
    //   如果枚举放在 Industry，Store 就要反过来依赖 Industry，require 顺序被绑死
    //   （见 DECLARATION 那段注释）。放这里，Store 依然零依赖。
    //   Industry 只负责中文标签和模板，Rules/Slots 通过本类常量做合法性校验。
    //
    // ⚠️ 改枚举的唯一正确做法：改这里的常量 → DROP 两张表 → 重新触发
    //    ensureSchema()。CHECK 语句由 checkIn() 从常量生成，不会漏改。
    // =========================================================================

    /**
     * 阶段枚举（DB 定义域，含 S0）。
     *
     * S0 保留在分类枚举里是因为「下游派活要用」（规则文档 §1.0），
     * 但推导矩阵里 S0 是 0 行 —— 那部分看 MATRIX_STAGES。
     *
     * 2026-11-02 用户确认：S3 拆成 S3a 候选比较 / S3b 风险验证，**不保留裸 S3**。
     * 当前表零数据，没有历史行会带 S3，所以不留兼容别名 ——
     * 留一个「这个值是什么意思」的坑，比省一次 DROP 更贵。
     */
    public const STAGES = ['S0', 'S1', 'S2', 'S3a', 'S3b', 'S4', 'S5'];

    /** 真正会产出格子的阶段（S0 整列不推导，所以矩阵里没有 S0） */
    public const MATRIX_STAGES = ['S1', 'S2', 'S3a', 'S3b', 'S4', 'S5'];

    /**
     * 角度枚举（6 值）。
     *
     * 2026-11-02 用户确认加第六个角度 trust（信任）：售后政策 / 资质认证 /
     * 靠不靠谱这些在五角度里没有落点，硬塞进「评测」会把「有没有隐藏费用」
     * 和「好不好用」混成一类，下游派活会派错。
     */
    public const ANGLES = ['definition', 'comparison', 'pricing', 'review', 'use_case', 'trust'];

    public const CLASSES = ['A', 'B', 'C', 'D'];

    public const INTENTS = ['transactional', 'commercial', 'informational', 'navigational'];

    /**
     * 路由枚举。
     *
     * `own_source` 是 v3 新增（规则文档 §2.4 R-1 修订）：A + S3b 的答案是
     * 资质页/售后政策页/用户证言，媒体和百科没有这类页面 → 官网独占，
     * 按软文处理是错的。它**计入本篇射程**，所以不能复用 source_build。
     */
    public const ROUTES = ['direct', 'sidelong', 'own_source', 'source_build'];

    public const LIST_KINDS = ['primary', 'marginal'];

    /** 判定档位（抢不到 = null，不入库，所以不在这里） */
    public const FITS = ['can', 'marginal'];

    public const BRAND_RELEVANCES = ['own', 'competitor', 'both', 'none'];

    /**
     * 顾虑枚举（8 值，**存中文**）。
     *
     * 为什么这个字段存中文而 content_type 存 code：8 个值、分类稳定、
     * 运营真的会直接查库（`WHERE concern='售后'`），中文比 after_sales 更贴近
     * 运营语汇；而且它不参与任何代码分支。22 值的 content_type 存中文则是
     * 在库里埋 22 个易漂移字符串，维护成本超过收益（系统设计 §1.2 表）。
     */
    public const CONCERNS = ['价格', '质量', '安全', '效果', '售后', '真实性', '时效性', '其他'];

    /**
     * 内容类型枚举（22 个 code，**与 22 个有效格严格 1:1 双射**）。
     *
     * 规则文档 §2.6 原样照抄。硬规矩：一个 code 没格产出就是死值，
     * 一个新格必须配一个新 code。Selftest 有一条断言做双向校验。
     */
    public const CONTENT_TYPES = [
        'symptom_science', 'category_primer', 'ingredient_explainer', 'category_comparison',
        'brand_comparison', 'price_range_primer', 'price_comparison', 'price_transparency',
        'price_page', 'category_review_roundup', 'pros_cons_analysis', 'user_evidence_roundup',
        'longterm_review', 'scene_science', 'audience_buying_guide', 'audience_fit_comparison',
        'usage_guide', 'category_pitfall_guide', 'brand_reliability_comparison',
        'prospectus_and_policy', 'authentic_channel_guide', 'counterfeit_rights_guide',
    ];

    /**
     * 幂等标记。ensureSchema() 在一次请求里有页面渲染 + 接口入口两个调用点，
     * 没有这个标记就会把 12 条 DDL 各跑两遍（PG 上是 12 次系统表扫描）。
     */
    private static bool $schemaReady = false;

    /** 批次表可写列白名单（防止上层把模型原始字段直接塞进 SQL） */
    private const BATCH_COLUMNS = [
        'brand_slug', 'brand_name', 'title', 'title_hash', 'industry_template',
        'prompt_version', 'model_id', 'model_name', 'seed', 'temperature',
        'status', 'primary_count', 'scoped_count', 'marginal_count',
        'angle_count', 'stage_count', 'class_counts', 'skipped_cells',
        'llm_calls', 'prompt_tokens', 'completion_tokens', 'elapsed_ms',
        'retry_reason', 'warnings', 'rejected', 'raw_json', 'declaration',
        'created_by',
    ];

    /** 批次表里的 jsonb 列（写库前 json_encode，读库后 json_decode） */
    private const BATCH_JSON_COLUMNS = [
        'class_counts', 'skipped_cells', 'warnings', 'rejected', 'raw_json',
    ];

    /** 问题表里的 jsonb 列（目前只有角度触及快照） */
    private const QUESTION_JSON_COLUMNS = ['angles_touched'];

    /** 问题表可写列白名单 */
    private const QUESTION_COLUMNS = [
        'batch_id', 'brand_slug', 'list_kind', 'position', 'question',
        'angle', 'stage', 'class', 'intent', 'brand_relevance', 'route',
        'fit', 'cell', 'why', 'concern', 'concern_alt', 'expected', 'content_type',
        'angles_touched', 'model_angle', 'model_stage', 'model_class',
        'corrected', 'created_at',
    ];

    // =========================================================================
    // DDL
    // =========================================================================

    /**
     * 首次调用时建表建索引。全部 IF NOT EXISTS / 存在性检查，可重复执行。
     *
     * 为什么要判断索引存在性而不只是 CREATE INDEX IF NOT EXISTS：
     *   表可能是「上一版代码建的」（少两个索引），此时 hasTable 为 true，
     *   只靠 IF NOT EXISTS 会把索引补齐 —— 这个分支本身没问题，但如果将来
     *   某个索引要改名，IF NOT EXISTS 会静默留下两个同类索引。显式查
     *   pg_indexes 后建，至少日志能看出「这次补了哪几个」。
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        if (! Schema::hasTable(self::TABLE_BATCHES)) {
            DB::statement(self::ddlBatches());
        }
        if (! Schema::hasTable(self::TABLE_QUESTIONS)) {
            DB::statement(self::ddlQuestions());
        }

        // 列级补丁：表存在但缺列时补列，而不是重建表。
        //
        // 为什么必须加（系统设计 §1.8 / R10）：上面用的是 hasTable() 判断，
        // 「表在就整体跳过」。这意味着**每加一个字段都要人肉 DROP 一次**，
        // 忘了 DROP 的表现是 insert 报「column not found」，而 `\d` 看起来
        // 一切正常（表在、索引在、页面能开）。
        self::patchQuestionColumns();

        foreach (self::indexDefinitions() as $index => $definition) {
            [$table, $columns] = $definition;
            if (! self::indexExists($table, $index)) {
                DB::statement('CREATE INDEX '.$index.' ON '.$table.' ('.$columns.')');
            }
        }

        // 唯一索引单独一遍：CREATE UNIQUE INDEX 与 CREATE INDEX 语法不同，
        // 上面那圈统一加 INDEX 关键字会在唯一索引上直接语法错误。
        if (! self::indexExists(self::TABLE_QUESTIONS, 'geo_tpq_batch_question_uidx')) {
            DB::statement(
                'CREATE UNIQUE INDEX geo_tpq_batch_question_uidx ON '.self::TABLE_QUESTIONS
                .' (batch_id, question)'
            );
        }

        self::$schemaReady = true;
    }

    /** 强制下一次 ensureSchema() 真的去查库（自测 / 手工补表时用） */
    public static function resetSchemaFlag(): void
    {
        self::$schemaReady = false;
    }

    /**
     * 列级补丁：表已存在但缺列时补列（不是重建表）。
     *
     * 为什么需要它：ensureSchema() 的入口判断是 `Schema::hasTable()`——
     * 表存在就整体跳过 DDL。所以「加字段」这件事在旧环境里会静默失效：
     * `\d` 看着正常（表在、索引在、页面能开），只有 insert 时才报
     * 「column "concern" of relation ... does not exist」。
     * 这一轮就是踩了这个坑，才决定把它一起修掉（系统设计 §1.8 / R10）。
     *
     * 为什么 ALTER 时带 DEFAULT 再留 DEFAULT：
     * 旧表可能有行（哪怕理论上 0 行），`ADD COLUMN ... NOT NULL` 不给 DEFAULT
     * 在非空表上会直接失败。给一个中庸默认值让 ALTER 永远成功，
     * 反正正常链路写进来的都是服务端推导值，DEFAULT 只是兜底。
     */
    private static function patchQuestionColumns(): void
    {
        if (! Schema::hasTable(self::TABLE_QUESTIONS)) {
            return;
        }

        $patches = [
            'concern' => "ALTER TABLE ".self::TABLE_QUESTIONS." ADD COLUMN concern varchar(32) NOT NULL DEFAULT '其他'",
            'concern_alt' => "ALTER TABLE ".self::TABLE_QUESTIONS." ADD COLUMN concern_alt varchar(32)",
            'expected' => "ALTER TABLE ".self::TABLE_QUESTIONS." ADD COLUMN expected text NOT NULL DEFAULT '（待补充预期答案）'",
            'content_type' => "ALTER TABLE ".self::TABLE_QUESTIONS." ADD COLUMN content_type varchar(32) NOT NULL DEFAULT 'symptom_science'",
        ];

        foreach ($patches as $column => $sql) {
            if (! Schema::hasColumn(self::TABLE_QUESTIONS, $column)) {
                DB::statement($sql);
            }
        }
    }

    private static function ddlBatches(): string
    {
        return 'CREATE TABLE IF NOT EXISTS '.self::TABLE_BATCHES.' ('
            .'id                bigserial      PRIMARY KEY,'
            // 品牌快照：历史报告不能跟着品牌档案变（同 ai_visibility_batches.has_site 的理由）
            .'brand_slug        varchar(64)    NOT NULL,'
            .'brand_name        varchar(128),'
            // title_hash 让「同一标题重复推导」拉成一条时间线，不必多建一张标题注册表
            .'title             varchar(500)   NOT NULL,'
            .'title_hash        char(64)       NOT NULL,'
            .'industry_template varchar(32)    NOT NULL DEFAULT \'skincare\','
            // 版本化三要素：规则改了 / 换模型了 / seed 变了，同标题结果就不可直接比
            .'prompt_version    varchar(24)    NOT NULL,'
            .'model_id          varchar(128),'
            .'model_name        varchar(128),'
            // DeepSeek 官方接口不收 seed，允许 null 才诚实；记 0 反而骗人
            .'seed              bigint,'
            .'temperature       numeric(3,2)   NOT NULL DEFAULT 0.20,'
            .'status            varchar(16)    NOT NULL DEFAULT \'running\','
            // primary_count 含 A 类；scoped_count 才是「本篇射程」，5≤x≤8 校验看后者。
            // 一个字段兼两义会被 A 类污染（决策 2）。
            .'primary_count     int            NOT NULL DEFAULT 0,'
            .'scoped_count      int            NOT NULL DEFAULT 0,'
            .'marginal_count    int            NOT NULL DEFAULT 0,'
            .'angle_count       int            NOT NULL DEFAULT 0,'
            .'stage_count       int            NOT NULL DEFAULT 0,'
            .'class_counts      jsonb,'
            // 空转体检：哪些格因为缺占位符没出题。没有这个字段，
            // 「矩阵退化成只有 B 类」只能靠运营自己发现。
            .'skipped_cells     jsonb,'
            .'llm_calls         int            NOT NULL DEFAULT 0,'
            .'prompt_tokens     int            NOT NULL DEFAULT 0,'
            .'completion_tokens int            NOT NULL DEFAULT 0,'
            .'elapsed_ms        int            NOT NULL DEFAULT 0,'
            .'retry_reason      varchar(32),'
            .'warnings          jsonb,'
            // 抢不到的问题规则上「不输出」→ 不进问题表；但完全不记又无法复盘，
            // 所以按批次存一份 jsonb 折中。
            .'rejected          jsonb,'
            .'raw_json          jsonb,'
            // 固定那一句「模型推测、非引擎实测」，批次级存一份（8 条重复存没意义）
            .'declaration       text           NOT NULL,'
            .'created_by        bigint,'
            .'created_at        timestamp(0),'
            .'updated_at        timestamp(0)'
            .')';
    }

    private static function ddlQuestions(): string
    {
        return 'CREATE TABLE IF NOT EXISTS '.self::TABLE_QUESTIONS.' ('
            .'id              bigserial    PRIMARY KEY,'
            .'batch_id        bigint       NOT NULL REFERENCES '.self::TABLE_BATCHES.'(id) ON DELETE CASCADE,'
            // 反范式两列：按阶段/按类做品牌维度统计时不必回联批次表
            .'brand_slug      varchar(64)  NOT NULL,'
            .'list_kind       varchar(8)   NOT NULL,'
            .'position        int          NOT NULL DEFAULT 0,'
            .'question        varchar(255) NOT NULL,'
            .'angle           varchar(16)  NOT NULL,'
            .'stage           varchar(4)   NOT NULL,'
            .'class           char(1)      NOT NULL,'
            .'intent          varchar(16)  NOT NULL,'
            .'brand_relevance varchar(16)  NOT NULL,'
            .'route           varchar(16)  NOT NULL,'
            .'fit             varchar(8)   NOT NULL,'
            .'cell            varchar(24)  NOT NULL,'
            // why 强制 NOT NULL：验收唯一抓手是「凭什么抢到」。
            // DB 层再兜一道，防止代码漏判后写进一条空 why。
            .'why             text         NOT NULL,'
            // ── 运营口径三件套：全部**服务端确定性推导**，模型不给 → NOT NULL 安全 ----
            // concern 是 1–2 个值（规则文档 §3.6）。用「主值 + 次值」两列而不是 PG 数组：
            // 1–2 个定长值用数组，查询要 unnest 才能按值过滤，运营直接看也是一坨
            // {"安全","价格"}；拆两列后 WHERE concern='售后' 直接吃索引，CHECK 也写得干净，
            // 语义（有序取前 2）完全等价。（系统设计 §1.2 / C8）
            .'concern         varchar(32)  NOT NULL,'
            .'concern_alt     varchar(32),'
            // 预期答案：格子模板的一句话展开，6–20 字，不含占位符（§3.6）
            .'expected        text         NOT NULL,'
            // 内容类型：22 个 code，与 22 格 1:1 双射，一次查表落地（§2.6）
            .'content_type    varchar(32)  NOT NULL,'
            // 角度触及快照（jsonb 数组，含主角度）。用于「一条能否同时抢两个问题」的派活排序。
            .'angles_touched  jsonb        NOT NULL,'
            // 模型标注 vs 服务端复算，用来统计「模型老把 S5 标成 S3」这类漂移
            .'model_angle     varchar(16),'
            .'model_stage     varchar(8),'
            .'model_class     char(1),'
            .'corrected       boolean      NOT NULL DEFAULT false,'
            .'created_at      timestamp(0),'
            // 8 条 CHECK 全部由 checkIn() 从上面的枚举常量拼出来。
            // 为什么不在 DDL 里手写枚举字面量：手写必然会出现「代码改了、SQL 忘了」
            // 的分叉，而分叉的表现是运行期 insert 报 constraint violation ——
            // 排查路径很长（页面只看到 500）。从常量生成，改一处就全对。
            .self::checkIn('geo_tpq_stage_chk', 'stage', self::STAGES).','
            .self::checkIn('geo_tpq_angle_chk', 'angle', self::ANGLES).','
            .self::checkIn('geo_tpq_class_chk', 'class', self::CLASSES).','
            .self::checkIn('geo_tpq_intent_chk', 'intent', self::INTENTS).','
            .self::checkIn('geo_tpq_fit_chk', 'fit', self::FITS).','
            .self::checkIn('geo_tpq_list_chk', 'list_kind', self::LIST_KINDS).','
            .self::checkIn('geo_tpq_route_chk', 'route', self::ROUTES).','
            .self::checkIn('geo_tpq_relv_chk', 'brand_relevance', self::BRAND_RELEVANCES).','
            .self::checkIn('geo_tpq_concern_chk', 'concern', self::CONCERNS).','
            // concern_alt 要么 NULL，要么是合法值且与主值不同
            // （同值重复没意义，直接判非法逼代码里去重）
            .'CONSTRAINT geo_tpq_concern_alt_chk CHECK (concern_alt IS NULL OR ('
            .self::checkInBody('concern_alt', self::CONCERNS).' AND concern_alt <> concern)),'
            .self::checkIn('geo_tpq_ctype_chk', 'content_type', self::CONTENT_TYPES).','
            // 4–20 字。来源：规则文档 §3.6「expected ≤20 字」；
            // 下限 4 = 基句(≤10) + 「，重点讲」(4) + 焦点(≤5) = 19 的最短可能余量。
            // ⚠️ 曾一度写成 6–60（给"以后改长"留余量）—— 那是错的：expected 不是
            // 自由文本，它由「格子基句 + 顾虑焦点」拼装，长度完全可控。
            // 放到 60 只会放行 21–60 字的脏数据，而且不报错。
            .'CONSTRAINT geo_tpq_expected_len_chk CHECK (char_length(expected) BETWEEN 4 AND 20),'
            // angles_touched：这条问题**可能**归到哪些角度（1..N，含主角度）。
            // 生成时快照、落库后永不重算 —— 重算会让「信任被触及 5 次」随词表变动，
            // 用户只会觉得「数字会自己变」，报告可信度归零。
            .'CONSTRAINT geo_tpq_touched_chk CHECK ('
            .'jsonb_typeof(angles_touched) = \'array\''
            .' AND CASE WHEN jsonb_typeof(angles_touched) = \'array\' THEN jsonb_array_length(angles_touched) ELSE 0 END >= 1'
            .' AND angles_touched <@ \'["definition","comparison","pricing","review","use_case","trust"]\'::jsonb)'
            .')';
    }

    /**
     * 大小写不敏感地把外部输入归一化到枚举原值（命不中返回 null）。
     *
     * 为什么需要：「校验」和「归一化」必须合成一步。分开写就会出现
     * 「用归一化后的值校验、把原始值传给下游」这种漂移 —— 而且极难发现。
     * 这个坑真实踩过两次：generator 里 `strtoupper('S3a')` → 'S3A' 被判非法，
     * 以及 judge() 里同样的写法让模型给的合法 stage 全部落成 NO_STAGE。
     *
     * @param  list<string>  $allowed
     */
    public static function normalizeEnum(string $value, array $allowed): ?string
    {
        $needle = mb_strtolower(trim($value));
        if ($needle === '') {
            return null;
        }
        foreach ($allowed as $candidate) {
            if (mb_strtolower((string) $candidate) === $needle) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * 输出给模型 JSON Schema 用的枚举值（T03 的 Prompt::schemaJson() 直接取这里）。
     *
     * 为什么单独开一个方法而不是让 T03 各自去抄常量：
     *   模型**确实输出 `stage` 和 `angle`**（表里有 model_stage 列、有 corrected 标记、
     *   H4 校验也写着「stage 在枚举内」）。如果 Schema 里 stage 还是 v1 的
     *   ['S1'..'S5']，模型回 'S3a' 会被 H4 判非法 → **12 条候选一条都留不下**，
     *   而且不报错、只把 status 打成 failed。这类「静默丢数据」比 DDL 报错难查得多。
     *   把枚举收敛到这里，Schema 与 CHECK 就不可能分叉。
     *
     * 注意：**`cell` 不要出现在模型 Schema 里**。cell 是 (angle, stage) 的坐标，
     * 让模型输出等于让它把同一个推导再做一遍，只会多一个漂移源（系统设计 C10）。
     *
     * @return array{stage:list<string>, angle:list<string>}
     */
    public static function jsonSchemaEnums(): array
    {
        // S0 不进 Schema：S0 的定义就是「不会主动搜」，它不会成为一个问句。
        // 允许它进 Schema 只会诱导模型多编一列脏数据。
        //
        // 只返回两个键：cell 由服务端按 (angle, stage) 反查，**不进模型 Schema**
        // （系统设计 C10），所以这里也不给 cell 的枚举，免得有人顺手加进 Schema。
        return [
            'stage' => self::MATRIX_STAGES,
            'angle' => self::ANGLES,
        ];
    }

    /**
     * 由枚举常量生成一条命名 CHECK 约束。
     * 为什么用 IN (...) 而不是 CHECK (col = ANY(ARRAY[...]))：
     *   IN 展开成字面量列表，PG 能在建表时把约束存成最简形式，
     *   `\d` 出来是 `CHECK (stage IN ('S1','S2',...))`，人一眼能核对；
     *   ANY(ARRAY[...]) 在旧版本里 `\d` 会显示成一长串 ::character varying 转型，
     *   核对时要在噪音里找关键词。
     *
     * 单引号转义：枚举值理论上不该带引号，但生成 SQL 的代码必须假设输入脏，
     * 否则将来有人加一个 `O'Neil` 之类的品牌枚举就把整条 DDL 拼坏了。
     *
     * @param  list<string>  $values
     */
    private static function checkIn(string $constraint, string $column, array $values): string
    {
        return 'CONSTRAINT '.$constraint.' CHECK ('.self::checkInBody($column, $values).')';
    }

    /**
     * 生成 `col IN ('a','b')` 这一段（不带 CONSTRAINT 前缀）。
     *
     * 单独拆出来是给 concern_alt 用的：它的约束是复合条件
     * `concern_alt IS NULL OR (concern_alt IN (...) AND concern_alt <> concern)`，
     * 整条塞不进 checkIn()。
     *
     * @param  list<string>  $values
     */
    private static function checkInBody(string $column, array $values): string
    {
        $quoted = [];
        foreach ($values as $value) {
            $quoted[] = "'".str_replace("'", "''", (string) $value)."'";
        }

        return $column.' IN ('.implode(',', $quoted).')';
    }

    /**
     * 普通索引表：index => [table, columns]。
     *
     * 每个索引对应一条真实查询路径，不是「先建着」：
     *   brand_created  → 报告页历史下拉「按品牌取最近 N 批」
     *   title_hash     → 同一标题的时间线（T04 historyBar 必须按 hash 分组）
     *   status_created → 排查僵尸 running 批次
     *   batch_list     → 报告页按批次顺序取两类清单（唯一的读路径）
     *   brand_stage    → 「按阶段看这个品牌抢到什么」统计
     *   brand_class    → 「按 A/B/C/D 看分布」统计
     *
     * @return array<string, array{0:string, 1:string}>
     */
    private static function indexDefinitions(): array
    {
        return [
            'geo_tpb_brand_created_idx' => [self::TABLE_BATCHES, 'brand_slug, created_at DESC'],
            'geo_tpb_title_hash_idx' => [self::TABLE_BATCHES, 'title_hash, created_at DESC'],
            'geo_tpb_status_created_idx' => [self::TABLE_BATCHES, 'status, created_at DESC'],
            'geo_tpq_batch_list_idx' => [self::TABLE_QUESTIONS, 'batch_id, list_kind, position'],
            'geo_tpq_brand_stage_idx' => [self::TABLE_QUESTIONS, 'brand_slug, stage'],
            'geo_tpq_brand_class_idx' => [self::TABLE_QUESTIONS, 'brand_slug, class'],
            // v2 新增：内容类型分布 / 顾虑分布。
            // 运营要能问「这批 S3b 占多少、是不是该补资质内容」「售后类顾虑有几条」，
            // 没有这两条索引就得全表扫。
            'geo_tpq_brand_ctype_idx' => [self::TABLE_QUESTIONS, 'brand_slug, content_type'],
            'geo_tpq_brand_concern_idx' => [self::TABLE_QUESTIONS, 'brand_slug, concern'],
        ];
    }

    /** PG 专用索引存在性检查（不依赖 Doctrine DBAL） */
    private static function indexExists(string $table, string $index): bool
    {
        try {
            $row = DB::selectOne(
                'select 1 as ok from pg_indexes where schemaname = current_schema() and tablename = ? and indexname = ? limit 1',
                [$table, $index]
            );
        } catch (\Throwable $e) {
            // 非 PG 驱动不支持 pg_indexes：当作不存在，让上层没机会建索引（该驱动下也建不了）
            return false;
        }

        return $row !== null;
    }

    // =========================================================================
    // 标识
    // =========================================================================

    /**
     * 标题归一化哈希。
     *
     * 为什么不是直接 hash 原文：运营会把「冰溪修护霜测评」写成
     * 「 冰溪 修护霜 测评：」「冰溪修护霜测评（2026）」—— 这些是同一篇，
     * 不归一化的话历史下拉里会出现一堆看起来不同的「新标题」。
     *
     * 归一化只做两件事：转小写 + 去掉空白与标点符号。
     * 不动全半角数字（「２０２６」与「2026」的差异属于原文差异，不该被抹掉）。
     */
    public static function titleHashOf(string $title): string
    {
        $normalized = mb_strtolower(trim($title));
        // \p{P} 标点、\p{S} 符号（含 ·、＋、emoji）、\s 空白，全部剥掉
        $stripped = preg_replace('/[\s\p{P}\p{S}]+/u', '', $normalized);

        return hash('sha256', $stripped === null ? $normalized : $stripped);
    }

    /** 与 ai_visibility_batches 一致：本地时区字符串，不用 now() */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // =========================================================================
    // 写
    // =========================================================================

    /**
     * 建批次（先建 running 批次拿 id，页面立刻可查；再生成、再回填）。
     *
     * @param  array<string,mixed>  $row
     * @return int 新批次 id
     */
    public static function createBatch(array $row): int
    {
        self::ensureSchema();

        $row = self::only($row, self::BATCH_COLUMNS);
        if (! isset($row['title_hash']) && isset($row['title'])) {
            $row['title_hash'] = self::titleHashOf((string) $row['title']);
        }
        if (! isset($row['declaration']) || trim((string) $row['declaration']) === '') {
            // declaration 落库为 NOT NULL，代码层再兜一次；
            // 页面渲染时另有一份常量兜底（DB 行缺失也不至于漏掉这句话）
            $row['declaration'] = self::DECLARATION;
        }

        $now = self::now();
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        $row = self::encodeJson($row, self::BATCH_JSON_COLUMNS);

        return (int) DB::table(self::TABLE_BATCHES)->insertGetId($row);
    }

    /**
     * 批量写问题。
     *
     * 用 insertOrIgnore 而不是 insert：唯一索引 (batch_id, question) 是最后一道去重，
     * 前端重复点提交 / 网络重试导致同一批写两遍时，不能 500。
     *
     * @param  list<array<string,mixed>>  $rows
     * @return int 实际写入行数（被唯一索引挡掉的不计）
     */
    public static function insertQuestions(int $batchId, array $rows): int
    {
        if ($batchId <= 0 || $rows === []) {
            return 0;
        }
        self::ensureSchema();

        // brand_slug 在问题表上是 NOT NULL（反范式，为了让「按品牌看阶段分布」
        // 不必回联批次表）。上层可能忘了带，而漏了就是整批 insert 报
        // not-null violation —— 一条都不进库。这里从批次补，只查一次。
        $batchBrandSlug = null;

        $now = self::now();
        $clean = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row['batch_id'] = $batchId;
            if (! isset($row['brand_slug']) || trim((string) $row['brand_slug']) === '') {
                if ($batchBrandSlug === null) {
                    $batchBrandSlug = (string) (DB::table(self::TABLE_BATCHES)
                        ->where('id', $batchId)
                        ->value('brand_slug') ?? '');
                }
                $row['brand_slug'] = $batchBrandSlug;
            }
            $row = self::only($row, self::QUESTION_COLUMNS);
            if (! isset($row['created_at'])) {
                $row['created_at'] = $now;
            }
            // why 是 NOT NULL：上层漏给时写一个显式的占位理由，
            // 让它在页面上"难看"而不是让整批插入失败（失败会丢掉全部结论）。
            if (! isset($row['why']) || trim((string) $row['why']) === '') {
                $row['why'] = '（why 缺失：该条由代码兜底写入，需人工复核）';
            }
            // angles_touched 是 NOT NULL jsonb：漏给时用主角度兜底（数组至少 1 个元素，
            // 否则会撞 geo_tpq_touched_chk）。这是最后一道防线，正常应由 makeItem 填好。
            if (! isset($row['angles_touched']) || $row['angles_touched'] === [] || $row['angles_touched'] === null) {
                $row['angles_touched'] = [(string) ($row['angle'] ?? 'use_case')];
            }
            $row = self::encodeJson($row, self::QUESTION_JSON_COLUMNS);
            $clean[] = $row;
        }
        if ($clean === []) {
            return 0;
        }

        $inserted = 0;
        // 分块：12 条以内其实一块就够，但重试路径可能一次写 20+ 条，
        // 参数个数上限（PG 65535）在 19 列 × 20 行时还很安全，留 100 行/块足够。
        foreach (array_chunk($clean, 100) as $chunk) {
            $inserted += (int) DB::table(self::TABLE_QUESTIONS)->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * 回填批次。
     *
     * 任何异常路径都必须调到这里写 status=failed，否则历史下拉里会留下
     * 永远 running 的僵尸批次（页面会把它当成"还在跑"）。
     *
     * @param  array<string,mixed>  $patch
     */
    public static function updateBatch(int $batchId, array $patch): void
    {
        if ($batchId <= 0 || $patch === []) {
            return;
        }
        self::ensureSchema();

        $patch = self::only($patch, self::BATCH_COLUMNS);
        if ($patch === []) {
            return;
        }
        $patch = self::encodeJson($patch, self::BATCH_JSON_COLUMNS);
        $patch['updated_at'] = self::now();

        DB::table(self::TABLE_BATCHES)->where('id', $batchId)->update($patch);
    }

    // =========================================================================
    // 读
    // =========================================================================

    /** @return array<string,mixed>|null */
    public static function findBatch(int $batchId): ?array
    {
        if ($batchId <= 0) {
            return null;
        }
        self::ensureSchema();

        $row = DB::table(self::TABLE_BATCHES)->where('id', $batchId)->first();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;

        return self::decodeJson($row, self::BATCH_JSON_COLUMNS);
    }

    /**
     * 一个批次的全部问题，按 list_kind + position 排序。
     *
     * @return list<array<string,mixed>>
     */
    public static function questionsOfBatch(int $batchId): array
    {
        if ($batchId <= 0) {
            return [];
        }
        self::ensureSchema();

        $rows = DB::table(self::TABLE_QUESTIONS)
            ->where('batch_id', $batchId)
            ->orderBy('list_kind')
            ->orderBy('position')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    /**
     * 按品牌取最近 N 个批次（报告页历史下拉）。
     *
     * @return list<array<string,mixed>>
     */
    public static function recentBatches(string $brandSlug, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        self::ensureSchema();

        $q = DB::table(self::TABLE_BATCHES)->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit($limit);
        if (trim($brandSlug) !== '') {
            $q->where('brand_slug', trim($brandSlug));
        }

        return array_map(static fn ($r): array => self::decodeJson((array) $r, self::BATCH_JSON_COLUMNS), $q->get()->all());
    }

    /**
     * 同一标题（title_hash）的全部历史批次，新的在前。
     *
     * @return list<array<string,mixed>>
     */
    public static function batchesByTitleHash(string $hash, int $limit = 50): array
    {
        $hash = trim($hash);
        if ($hash === '') {
            return [];
        }
        $limit = max(1, min(200, $limit));
        self::ensureSchema();

        $rows = DB::table(self::TABLE_BATCHES)
            ->where('title_hash', $hash)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        return array_map(static fn ($r): array => self::decodeJson((array) $r, self::BATCH_JSON_COLUMNS), $rows->all());
    }

    /**
     * 按标题分组的历史（T04 historyBar 用）。
     *
     * 为什么按 title_hash 分组而不是按 brand：
     *   「同一标题重复推导怎么对比」才是历史下拉要解决的问题；
     *   按品牌分组只回答了「我跑过几篇」，回答不了「这篇上次是什么结论」。
     *
     * @return list<array{title_hash:string,title:string,last_at:string,runs:int}>
     */
    public static function recentTitleGroups(string $brandSlug, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        self::ensureSchema();

        $q = DB::table(self::TABLE_BATCHES)
            ->selectRaw('title_hash, min(title) as title, max(created_at) as last_at, count(*) as runs')
            ->groupBy('title_hash')
            ->orderByRaw('max(created_at) desc')
            ->limit($limit);
        if (trim($brandSlug) !== '') {
            $q->where('brand_slug', trim($brandSlug));
        }

        $out = [];
        foreach ($q->get() as $row) {
            $a = (array) $row;
            $out[] = [
                'title_hash' => (string) ($a['title_hash'] ?? ''),
                'title' => (string) ($a['title'] ?? ''),
                'last_at' => (string) ($a['last_at'] ?? ''),
                'runs' => (int) ($a['runs'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * 角度触及分布（页面的 ⑥ angles_touched）。
     *
     * 从库里聚合 `jsonb_array_elements_text(angles_touched)`，**不要用
     * inferAngles() 重算** —— 那是生成时快照。重算会让「信任被触及 5 次」
     * 在给词表加了信号词后变成 8 次，用户不会想「规则变了」，只会觉得
     * 「这系统的数字会自己变」，报告可信度直接归零。
     *
     * @return array<string,int> angle => 触及次数
     */
    public static function statsByTouched(int $batchId): array
    {
        if ($batchId <= 0) {
            return [];
        }
        self::ensureSchema();

        $rows = DB::select(
            'select angle, count(*) as c'
            .' from '.self::TABLE_QUESTIONS.', jsonb_array_elements_text(angles_touched) as angle'
            .' where batch_id = ? group by angle order by c desc',
            [$batchId]
        );

        $out = [];
        foreach ($rows as $row) {
            $a = (array) $row;
            $out[(string) ($a['angle'] ?? '')] = (int) ($a['c'] ?? 0);
        }

        return $out;
    }

    /**
     * 品牌维度的阶段分布（主清单，排除转信源建设的 A 类）。
     *
     * 排除 source_build 的理由：A 类不做本篇射程，混进阶段分布会把
     * 「这个品牌在 S3 抢到多少」的分子算大。
     *
     * @return array<string,int> ['S1'=>..,'S2'=>..,'S3'=>..,'S4'=>..,'S5'=>..]
     */
    public static function statsByStage(string $brandSlug): array
    {
        $out = ['S0' => 0, 'S1' => 0, 'S2' => 0, 'S3a' => 0, 'S3b' => 0, 'S4' => 0, 'S5' => 0];
        if (trim($brandSlug) === '') {
            return $out;
        }
        self::ensureSchema();

        $rows = DB::table(self::TABLE_QUESTIONS)
            ->selectRaw('stage, count(*) as c')
            ->where('brand_slug', trim($brandSlug))
            ->where('list_kind', 'primary')
            ->where('route', '<>', 'source_build')
            ->groupBy('stage')
            ->get();

        foreach ($rows as $row) {
            $a = (array) $row;
            $stage = (string) ($a['stage'] ?? '');
            if (array_key_exists($stage, $out)) {
                $out[$stage] = (int) ($a['c'] ?? 0);
            }
        }

        return $out;
    }

    // =========================================================================
    // 内部工具
    // =========================================================================

    /**
     * 只保留白名单列。
     *
     * @param  array<string,mixed>  $row
     * @param  list<string>  $allowed
     * @return array<string,mixed>
     */
    private static function only(array $row, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $row)) {
                $out[$col] = $row[$col];
            }
        }

        return $out;
    }

    /**
     * jsonb 列落库前编码。null 保持 null（PG jsonb 可空，别写成 'null' 字符串）。
     *
     * @param  array<string,mixed>  $row
     * @param  list<string>  $jsonColumns
     * @return array<string,mixed>
     */
    private static function encodeJson(array $row, array $jsonColumns): array
    {
        foreach ($jsonColumns as $col) {
            if (! array_key_exists($col, $row)) {
                continue;
            }
            $v = $row[$col];
            if ($v === null || is_string($v)) {
                continue;
            }
            $row[$col] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $row;
    }

    /**
     * jsonb 列读出后解码。PG 驱动在不同版本下可能返回 string 或 array，
     * 两种都要能吃（否则页面拿到字符串渲染成 "[object]"）。
     *
     * @param  array<string,mixed>  $row
     * @param  list<string>  $jsonColumns
     * @return array<string,mixed>
     */
    private static function decodeJson(array $row, array $jsonColumns): array
    {
        foreach ($jsonColumns as $col) {
            if (! array_key_exists($col, $row)) {
                continue;
            }
            $v = $row[$col];
            if (! is_string($v) || $v === '') {
                continue;
            }
            $decoded = json_decode($v, true);
            $row[$col] = $decoded === null && strtolower($v) !== 'null' ? $v : $decoded;
        }

        return $row;
    }
}
