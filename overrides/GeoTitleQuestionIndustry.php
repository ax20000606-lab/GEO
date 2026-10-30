<?php

namespace App\Support\GeoAudit;

/**
 * 标题反推提问：护肤品行业静态数据（22 格矩阵 + 词表 + 动作覆盖集 + 推导表）。
 *
 * 这个类里只有「数据和纯查表函数」，没有判定逻辑 —— 判定在
 * GeoTitleQuestionRules，解析在 GeoTitleQuestionSlots。
 * 分开的理由：行业模板将来要支持 B2B SaaS（规则文档 §1.4 明确要求可配置），
 * 到那天只需要再写一个 XxxIndustry，Slots / Rules 一行不改。
 *
 * 所有词表都是「硬信号」而不是「语义理解」。刻意不做同义词扩展，
 * 因为判定是硬判据（C2 判错就直接把一条问题丢掉），
 * 宁可漏判让模型去补，也不要错判把不自洽的问题放进主清单。
 *
 * ⚠️ 矩阵口径：六角度 × 七阶段 = 42 格，**有效 22 格**（规则文档 v3 §1.3）。
 *    22 个有效格与 22 个 content_type code **严格 1:1 双射**（§2.6）。
 *    其中 价格×S2 / 对比×S2 两格仍然实现（enabled=true），但不喂给模型
 *    （prompt_enabled=false）→ 写进 prompt 的是 20 格。
 */
final class GeoTitleQuestionIndustry
{
    /** 首版只做护肤品（决策 5）。B2B 那套没有真实数据校准，先不填。 */
    public const TEMPLATE_KEY = 'skincare';

    /**
     * Prompt 版本号。
     *
     * v3：22 格（trust 5 格）/ 22 个 content_type code / concern 拆两列 /
     *     expected 改「格子基句」/ `cell` 不再由模型输出 / 22 格全进 prompt。
     * v4：prompt 加「stage 边界速查」三条 + why 两条硬标准（引用原词 / 禁止注水收尾）
     *     + 4 条 why 反例；max_tokens 2600→4000。**模型看到的输入又变了 → 必须再 bump。**
     * **模型看到的输入完全不同 → 输出不可比 → 必须 bump。**
     * 不 bump 的后果：历史下拉里 v1 和 v2 的结果被当成「同一把尺子的两次测量」。
     */
    public const PROMPT_VERSION = 'tq-v4';

    /**
     * declaration 的常量兜底。
     *
     * 为什么代码里还要留一份（DB 里已经存了一份）：
     * 历史报告如果因为归档/迁移丢了 declaration 列，页面也不能漏掉这句话 ——
     * 它是「别把推导结果当成实测结果」的唯一提示。
     */
    public const DECLARATION = '以下问题由模型基于标题推测，未经引擎实测，不代表 AI 引擎真实发起过的查询。';

    // 枚举常量**不在这里**：它们是数据库列的定义域，权威副本放在
    // GeoTitleQuestionStore（STAGES / MATRIX_STAGES / ANGLES / CLASSES /
    // INTENTS / ROUTES / LIST_KINDS / FITS / BRAND_RELEVANCES / CONCERNS /
    // CONTENT_TYPES），由它直接生成 CHECK 约束。本类只放
    // 「中文标签 + 矩阵格子 + 词表」，避免同一份枚举在数据层和配置层各写一遍。

    /** 角度中文名（页面表头 / 下拉用） */
    public const ANGLE_LABELS = [
        'definition' => '定义',
        'comparison' => '对比',
        'pricing' => '价格',
        'review' => '评测',
        'use_case' => '场景',
        'trust' => '信任',
    ];

    /** 阶段中文名（S3 已按用户裁决拆成 S3a / S3b，不保留裸 S3） */
    public const STAGE_LABELS = [
        'S0' => 'S0 无知（不推导）',
        'S1' => 'S1 需求觉醒',
        'S2' => 'S2 方案探索',
        'S3a' => 'S3a 候选比较',
        'S3b' => 'S3b 风险验证',
        'S4' => 'S4 行动准备',
        'S5' => 'S5 使用后',
    ];

    /** A/B/C/D 中文名 */
    public const CLASS_LABELS = [
        'A' => 'A 品牌认知',
        'B' => 'B 品类场景',
        'C' => 'C 产品深挖',
        'D' => 'D 竞品对比',
    ];

    /** 意图中文名 */
    public const INTENT_LABELS = [
        'transactional' => '交易型',
        'commercial' => '商业调查型',
        'informational' => '信息型',
        'navigational' => '导航型',
    ];

    /**
     * 路由中文名。
     *
     * `own_source` 是 v3 新增：规则文档 §2.4 R-1 修订后，A + S3b 不走软文，
     * 走「自有信源页」（资质公示 / 售后政策 / 用户证言 / 争议说明），
     * **并且计入本篇射程**。所以它不能复用 source_build（那样会被排除出射程），
     * 也不能复用 direct（那样运营会照软文写）。
     */
    public const ROUTE_LABELS = [
        'direct' => '本篇直接覆盖',
        'sidelong' => '写的时候顺带覆盖',
        'own_source' => '自有信源页（资质/售后/证言），非软文，计入射程',
        'source_build' => '转信源建设（品牌词条/百科/新闻露出），不计入本篇射程',
    ];

    /** 品牌相关度中文名 */
    public const BRAND_RELEVANCE_LABELS = [
        'own' => '自家品牌',
        'competitor' => '仅竞品',
        'both' => '自家+竞品',
        'none' => '无品牌',
    ];

    /** 标题类型中文名 */
    public const TITLE_TYPE_LABELS = [
        'category' => '纯品类词',
        'brand_product' => '品牌/产品词',
        'competitor_compare' => '竞品对比',
    ];

    /**
     * 22 个 content_type code 的中文名（**仅展示用，落库只存 code**）。
     *
     * 规则文档 §2.6 约定 2：模型输出中文名 → 按不合法处理（打 warnings），
     * 不猜、不映射。所以这张表只给页面/导出读。
     */
    public const CONTENT_TYPE_LABELS = [
        'symptom_science' => '症状科普',
        'category_primer' => '品类科普',
        'ingredient_explainer' => '成分原理解析',
        'category_comparison' => '品类横评',
        'brand_comparison' => '品牌对比',
        'price_range_primer' => '价格区间科普',
        'price_comparison' => '价格对比',
        'price_transparency' => '价格透明度说明',
        'price_page' => '价格页',
        'category_review_roundup' => '品类口碑盘点',
        'pros_cons_analysis' => '优缺点对比',
        'user_evidence_roundup' => '真实评价汇总',
        'longterm_review' => '长期效果复盘',
        'scene_science' => '场景化科普',
        'audience_buying_guide' => '品类选购指南',
        'audience_fit_comparison' => '适用人群对比',
        'usage_guide' => '使用教程',
        'category_pitfall_guide' => '品类避坑指南',
        'brand_reliability_comparison' => '品牌可靠性对比',
        'prospectus_and_policy' => '资质公示与售后政策',
        'authentic_channel_guide' => '正品渠道说明',
        'counterfeit_rights_guide' => '假货维权指南',
    ];

    /**
     * `prospectus_and_policy` 的固定提示（规则文档 §2.6 / 决策 20）。
     *
     * 为什么必须有这句：这一格的答案是官方资质与政策，**没有页面就不存在
     * 可被引用的信源**。只出问题不提示，运营会照着写软文 —— 海洋至尊 A 类
     * 11 次缺位全是媒体占位，写软文一次都没抢到，这个坑已经踩过。
     * 只展示，不影响计数与排序。
     */
    public const PROSPECTUS_HINT = '这类问题需要资质页 / 售后政策页支撑，写软文解决不了。';

    /**
     * 单一格子优先级表（22 格全覆盖）。**任何配额裁剪都查这一张表。**
     *
     * 为什么必须只有一张：这套系统有两条独立的裁剪路径 ——
     *   · A 类名额（A ≤ 2，只给 A+S3b）
     *   · D 配额（comparison:S3a 优先，trust:S3a 补位）
     * 如果各用一张表，以后调一个、另一个不同步，就会出现「按 A 裁的顺序」
     * 和「按 D 裁的顺序」不一致，而且**没人会发现，因为两条路径不交叉**。
     *
     * 数字越小越优先。取值理由：
     *   1  trust:S3b      本轮核心格：已倾向、只差敢不敢买；答案是资质页/政策页，官网独占
     *   1  comparison:S3a D 类主出口，答用户真实主问题「A 和 B 哪个好」
     *   2  trust:S3a      D 类补位，答细分维度（售后/资质/口碑）
     *   2  use_case:S2    候选池唯一系统性入口（B 类最高价值）
     *   3  review:S3b     证言可信度（与 trust:S3b 分工，但不与资质页争名额）
     *   3  review:S3a     单品评估主格
     *   4  review:S2      品类口碑盘点
     *   5  其余
     */
    public const CELL_PRIORITY = [
        'trust:S3b' => 1,
        'comparison:S3a' => 1,
        'trust:S3a' => 2,
        'use_case:S2' => 2,
        'pricing:S3b' => 2,
        // 定义×S2 排 4：它和 场景×S2 同阶段同类，但规则文档 §5 的价值排序里
        // 场景×S2 是第 4（候选池唯一入口）、定义×S2 不在前 5。
        // 两者同值时靠输入顺序，Definition 会先占位，把「敏感肌用什么修护霜」挤掉。
        'definition:S2' => 4,
        'review:S3b' => 3,
        'review:S3a' => 3,
        'pricing:S3a' => 3,
        'use_case:S3a' => 3,
        'trust:S4' => 3,
        'trust:S5' => 3,
        'pricing:S4' => 3,
        'definition:S3a' => 4,
        'review:S2' => 4,
        'definition:S1' => 5,
        'use_case:S1' => 5,
        'pricing:S2' => 5,
        'comparison:S2' => 5,
        'review:S5' => 5,
        'use_case:S5' => 5,
        'trust:S2' => 5,
    ];

    // =========================================================================
    // 22 格注册表
    // =========================================================================

    /**
     * 规则文档 v3 §1.2 矩阵里的 22 个有效格。
     *
     * 每格结构：
     *   key            "angle:stage"，同时用作 DB 的 cell 列、content_type 反查键
     *   angle/stage    展示与统计用
     *   enabled        实现开关（22 格全开）
     *   prompt_enabled 是否喂给模型（价格×S2 / 对比×S2 为 false → prompt 里 20 格）
     *   content_type   22 个 code 之一，与 key **1:1 双射**（§2.6）
     *   expected       一句话预期答案，6–20 字，不含占位符（§3.6 / 系统设计 §1.6）
     *   priority       裁剪顺序：数字小的先保
     *   templates[]    每套模板带自己的 required slots / 默认类 / 默认意图。
     *                  **单元格可用性按模板逐条判**，只要有一套模板占位符全取到值，
     *                  这格就可用 —— 按格取并集的话，「{品类}是什么 有必要用吗」
     *                  会因为「{品类}和{品类2}有什么区别」缺 {品类2} 而整格消失。
     *   slots          该格全部模板占位符的并集，仅用于展示/提示，不参与判定
     *   note           为什么这一格存在（运营看得懂的一句话）
     *
     * ⚠️ 硬规则（§1.1 硬规则 2）：**class=D 的模板必须含 `{品牌}`**。
     *    Selftest 有一条静态断言盯着，违反直接报错。
     *
     * @return list<array<string,mixed>>
     */
    public static function cells(): array
    {
        $cells = [
            // ---------------- 定义 ----------------
            [
                'key' => 'definition:S1',
                'angle' => 'definition',
                'stage' => 'S1',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'symptom_science',
                'expected' => '先讲症状成因，再给要不要就医的边界',
                'note' => 'S1b 原因归因：用户刚开始觉得「这不正常」，还没想到买东西',
                'slots' => ['{症状}', '{场景}'],
                'templates' => [
                    ['tpl' => '{症状}是什么原因', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{症状}']],
                    ['tpl' => '{症状}正常吗 是不是过敏', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{症状}']],
                    ['tpl' => '为什么一到{场景}就{症状}', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{场景}', '{症状}']],
                ],
            ],
            [
                'key' => 'definition:S2',
                'angle' => 'definition',
                'stage' => 'S2',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'category_primer',
                'expected' => '这个品类解决什么、什么时候才需要',
                'note' => '补概念：品类是什么、成分是干什么的。进候选池之前的概念准备',
                'slots' => ['{品类}', '{成分}', '{品类2}'],
                'templates' => [
                    ['tpl' => '{品类}是什么 有必要用吗', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{品类}']],
                    ['tpl' => '{成分}是干什么的', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{成分}']],
                    ['tpl' => '{品类}和{品类2}有什么区别', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{品类}', '{品类2}']],
                ],
            ],
            [
                'key' => 'definition:S3a',
                'angle' => 'definition',
                'stage' => 'S3a',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'ingredient_explainer',
                'expected' => '这个品牌的定位与主打线，值不值得考虑',
                'note' => '已进候选阶段还在补概念（是什么牌子 / 里面含什么）',
                'slots' => ['{品牌}', '{产品}', '{成分}', '{成分2}'],
                'templates' => [
                    ['tpl' => '{品牌}是什么牌子', 'class' => 'A', 'intent' => 'informational', 'slots' => ['{品牌}']],
                    ['tpl' => '{产品}里含{成分}吗', 'class' => 'C', 'intent' => 'informational', 'slots' => ['{产品}', '{成分}']],
                    ['tpl' => '{成分}和{成分2}哪个更温和', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{成分}', '{成分2}']],
                ],
            ],

            // ---------------- 对比 ----------------
            [
                'key' => 'comparison:S2',
                'angle' => 'comparison',
                'stage' => 'S2',
                'enabled' => true,
                // team-lead R14 拍板：22 格全进 prompt，本轮不精简。
                // 精简的两条理由都是推理、一次没实测过；先跑真实数据再决定。
                // 字段保留，以后要精简改这里即可，不动 cells() / DDL / 校验。
                'prompt_enabled' => true,
                'content_type' => 'category_comparison',
                'expected' => '两个品类的适用差异，按肤质给结论',
                'note' => '品类之间的横向对比。不喂模型（PM 建议砍），但保留实现',
                'slots' => ['{品类}', '{品类2}', '{肤质}'],
                'templates' => [
                    ['tpl' => '{品类}和{品类2}哪个更适合{肤质}', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{品类}', '{品类2}', '{肤质}']],
                    ['tpl' => '平价{品类}和大牌{品类}差在哪', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{品类}']],
                ],
            ],
            [
                'key' => 'comparison:S3a',
                'angle' => 'comparison',
                'stage' => 'S3a',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'brand_comparison',
                'expected' => '两个品牌的横向结论，各自更适合谁',
                'note' => '具名对具名，D 类主出口（4 个模板，全部含 {品牌}）',
                'slots' => ['{品牌}', '{竞品}', '{产品}', '{竞品产品}'],
                'templates' => [
                    ['tpl' => '{品牌}和{竞品}哪个好', 'class' => 'D', 'intent' => 'commercial', 'slots' => ['{品牌}', '{竞品}']],
                    ['tpl' => '{品牌}{产品}和{竞品产品}有什么区别', 'class' => 'D', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}', '{竞品产品}']],
                    ['tpl' => '{品牌}是{竞品}的平替吗', 'class' => 'D', 'intent' => 'commercial', 'slots' => ['{品牌}', '{竞品}']],
                    // v3 新增第 4 个模板：不依赖额外占位符（只要 品牌/产品/竞品产品），
                    // 作用是把 D 的模板总数顶到 8，让「D ≥ 3 条」在射程 8 时可达
                    ['tpl' => '{品牌}{产品}和{竞品产品}哪个更值得买', 'class' => 'D', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}', '{竞品产品}']],
                ],
            ],

            // ---------------- 价格 ----------------
            [
                'key' => 'pricing:S2',
                'angle' => 'pricing',
                'stage' => 'S2',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'price_range_primer',
                'expected' => '这个品类的价格区间与价差原因',
                'note' => '还不知道买什么品类时先问价。不喂模型（PM 建议砍），但保留实现',
                'slots' => ['{品类}'],
                'templates' => [
                    ['tpl' => '{品类}一般多少钱', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{品类}']],
                    ['tpl' => '{品类}什么价位算正常', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{品类}']],
                ],
            ],
            [
                'key' => 'pricing:S3a',
                'angle' => 'pricing',
                'stage' => 'S3a',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'price_comparison',
                'expected' => '这个产品的价格位置，贵在哪、值不值',
                'note' => '价值判断（贵不贵/值不值/性价比），不带购买动作 → 商业调查不是交易',
                'slots' => ['{品牌}', '{产品}'],
                'templates' => [
                    ['tpl' => '{品牌}{产品}贵不贵', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}为什么这么贵', 'class' => 'A', 'intent' => 'commercial', 'slots' => ['{品牌}']],
                    ['tpl' => '{产品}性价比高吗', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{产品}']],
                ],
            ],
            [
                'key' => 'pricing:S3b',
                'angle' => 'pricing',
                'stage' => 'S3b',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'price_transparency',
                'expected' => '有没有隐藏费用和二次消费，总花费多少',
                'note' => '「有没有隐藏费用」是唯一能把价格顾虑落到自有收费页的格子',
                'slots' => ['{品牌}', '{产品}'],
                'templates' => [
                    ['tpl' => '{品牌}有没有隐藏费用 隐形消费', 'class' => 'A', 'intent' => 'commercial', 'slots' => ['{品牌}']],
                    ['tpl' => '{品牌}{产品}后续还要额外花钱吗', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}有绑定消费 自动续费吗', 'class' => 'A', 'intent' => 'commercial', 'slots' => ['{品牌}']],
                ],
            ],
            [
                'key' => 'pricing:S4',
                'angle' => 'pricing',
                'stage' => 'S4',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'price_page',
                'expected' => '能下单的价格、渠道、活动与规格',
                'note' => '交易型唯一出口。全网占比只有 0.69%，但最值钱，限 1 条',
                'slots' => ['{品牌}', '{产品}'],
                'templates' => [
                    ['tpl' => '{品牌}{产品}多少钱', 'class' => 'C', 'intent' => 'transactional', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}{产品}哪里买便宜', 'class' => 'C', 'intent' => 'transactional', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}有小样吗 有活动吗', 'class' => 'A', 'intent' => 'transactional', 'slots' => ['{品牌}']],
                ],
            ],

            // ---------------- 评测 ----------------
            [
                'key' => 'review:S2',
                'angle' => 'review',
                'stage' => 'S2',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'category_review_roundup',
                'expected' => '这个品类的口碑梯队与常见踩雷点',
                // 优先级 2：同类里让 use_case:S2（候选池唯一入口）先吃配额。
                // 规则文档 §5 的价值排序里 场景×S2 是第 4、评测×S2 是第 7。
                'note' => '「{品类}哪个牌子好用」是 S2 里转化最直接的一类',
                'slots' => ['{品类}'],
                'templates' => [
                    ['tpl' => '{品类}哪个牌子好用', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{品类}']],
                    ['tpl' => '{品类}口碑最好的是哪个', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{品类}']],
                    ['tpl' => '{品类}踩雷的多吗', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{品类}']],
                ],
            ],
            [
                'key' => 'review:S3a',
                'angle' => 'review',
                'stage' => 'S3a',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'pros_cons_analysis',
                'expected' => '真实肤感、见效周期、缺点与不适合谁',
                'note' => '本功能最核心的评估格：搜的是品牌名，官网有天然优势',
                'slots' => ['{品牌}', '{产品}'],
                'templates' => [
                    ['tpl' => '{品牌}{产品}好用吗', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}{产品}真实测评', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}']],
                    // v3：「差评」已划归 S3b（证言可信度）。
                    // 留在 S3a 会让同一条问题两处产出，违反 §2.5⑦「S3a/S3b 不双出」
                    ['tpl' => '{品牌}{产品}有什么缺点', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}']],
                ],
            ],
            [
                'key' => 'review:S3b',
                'angle' => 'review',
                'stage' => 'S3b',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'user_evidence_roundup',
                'expected' => '可核验的评价来源，差评集中在哪',
                // 优先级 3 而不是 1：A 类名额（A ≤ 2）要先给 trust:S3b。
                // 两格都有 A 类模板（证言 vs 资质），但资质页是官网独占的高价值位，
                // 让 review:S3b 的 A 抢占名额会把最有价值的一格挤进副清单。
                'note' => '只出「证言可信度」题（差评/有托/差评集中在哪）。'
                    .'资质/售后/正品归 trust×S3b，两格不合并（v3 裁决）',
                'slots' => ['{品牌}', '{产品}'],
                'templates' => [
                    ['tpl' => '{品牌}差评多吗', 'class' => 'A', 'intent' => 'commercial', 'slots' => ['{品牌}']],
                    ['tpl' => '{品牌}的评价是真的吗 有托吗', 'class' => 'A', 'intent' => 'commercial', 'slots' => ['{品牌}']],
                    ['tpl' => '{品牌}{产品}差评集中在哪', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}']],
                ],
            ],
            [
                'key' => 'review:S5',
                'angle' => 'review',
                'stage' => 'S5',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'longterm_review',
                'expected' => '长期效果与停用反弹，值不值得回购',
                'note' => '长尾、竞争小：长期效果 / 停用反弹 / 值不值得回购',
                'slots' => ['{品牌}', '{产品}'],
                'templates' => [
                    ['tpl' => '{品牌}{产品}长期用效果怎么样', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}{产品}停用会反弹吗', 'class' => 'C', 'intent' => 'informational', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}{产品}值得回购吗', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}']],
                ],
            ],

            // ---------------- 场景 ----------------
            [
                'key' => 'use_case:S1',
                'angle' => 'use_case',
                'stage' => 'S1',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'scene_science',
                'expected' => '这个症状怎么缓解，哪些做法会加重',
                'note' => 'S1a 症状识别 + 求解。最上游，被顺带提及的成本最低',
                'slots' => ['{症状}', '{场景}'],
                'templates' => [
                    ['tpl' => '{症状}怎么办', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{症状}']],
                    ['tpl' => '{场景}{症状}怎么缓解', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{场景}', '{症状}']],
                    ['tpl' => '{症状}不管会自己好吗', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{症状}']],
                ],
            ],
            [
                'key' => 'use_case:S2',
                'angle' => 'use_case',
                'stage' => 'S2',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'audience_buying_guide',
                'expected' => '按肤质人群场景给品类选择建议',
                'note' => '实测 B 类覆盖 0%，这是候选池唯一系统性入口',
                'slots' => ['{肤质}', '{人群}', '{品类}', '{场景}'],
                'templates' => [
                    ['tpl' => '{肤质}用什么{品类}', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{肤质}', '{品类}']],
                    ['tpl' => '{人群}可以用{品类}吗', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{人群}', '{品类}']],
                    ['tpl' => '{场景}适合用什么{品类}', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{场景}', '{品类}']],
                ],
            ],
            [
                'key' => 'use_case:S3a',
                'angle' => 'use_case',
                'stage' => 'S3a',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'audience_fit_comparison',
                'expected' => '这个产品在指定肤质场景能不能用',
                'note' => 'C 类里唯一能落到具体产品页的格子',
                'slots' => ['{品牌}', '{产品}', '{肤质}', '{场景}', '{人群}'],
                'templates' => [
                    ['tpl' => '{品牌}{产品}适合{肤质}吗', 'class' => 'C', 'intent' => 'commercial', 'slots' => ['{品牌}', '{产品}', '{肤质}']],
                    ['tpl' => '{品牌}{产品}{场景}能用吗', 'class' => 'C', 'intent' => 'informational', 'slots' => ['{品牌}', '{产品}', '{场景}']],
                    ['tpl' => '{品牌}{产品}{人群}能用吗', 'class' => 'C', 'intent' => 'informational', 'slots' => ['{品牌}', '{产品}', '{人群}']],
                ],
            ],
            [
                'key' => 'use_case:S5',
                'angle' => 'use_case',
                'stage' => 'S5',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'usage_guide',
                'expected' => '用量、顺序、能否叠加与不良反应处理',
                'note' => '使用兼容性（能不能叠加 / 一起用 / 一罐用多久）',
                'slots' => ['{品牌}', '{产品}', '{成分}', '{其他产品}'],
                'templates' => [
                    ['tpl' => '{品牌}{产品}能叠加{成分}吗', 'class' => 'C', 'intent' => 'informational', 'slots' => ['{品牌}', '{产品}', '{成分}']],
                    ['tpl' => '{品牌}{产品}和{其他产品}能一起用吗', 'class' => 'C', 'intent' => 'informational', 'slots' => ['{品牌}', '{产品}', '{其他产品}']],
                    // v3：把 v1 的「用了刺痛正常吗」换成「一罐能用多久」。
                    // 前者其实是安全性事故（归 信任×S3b 的「会不会过敏」），
                    // 后者才是纯使用时长问题，且只依赖 {品牌}{产品}，更容易成立
                    ['tpl' => '{品牌}{产品}一罐能用多久', 'class' => 'C', 'intent' => 'informational', 'slots' => ['{品牌}', '{产品}']],
                ],
            ],

            // ---------------- 信任（v2 新增角度） ----------------
            [
                'key' => 'trust:S2',
                'angle' => 'trust',
                'stage' => 'S2',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'category_pitfall_guide',
                'expected' => '这个品类有没有假货，怎么辨别正品',
                'note' => '避坑天然含防假货：还不知道买哪个牌子时的信任顾虑',
                'slots' => ['{品类}'],
                'templates' => [
                    ['tpl' => '{品类}有假货吗 怎么辨别正品', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{品类}']],
                    ['tpl' => '{品类}是不是智商税', 'class' => 'B', 'intent' => 'informational', 'slots' => ['{品类}']],
                    ['tpl' => '{品类}去哪买不会买到假货', 'class' => 'B', 'intent' => 'commercial', 'slots' => ['{品类}']],
                ],
            ],
            [
                'key' => 'trust:S3a',
                'angle' => 'trust',
                'stage' => 'S3a',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'brand_reliability_comparison',
                'expected' => '两家售后、资质、口碑可信度的对照',
                'note' => 'D 类第二出口（4 个纯 D 模板）。没有它 D ≥ 3 条在射程 8 时不可达',
                'slots' => ['{品牌}', '{竞品}'],
                'templates' => [
                    ['tpl' => '{品牌}和{竞品}哪个售后更好', 'class' => 'D', 'intent' => 'commercial', 'slots' => ['{品牌}', '{竞品}']],
                    ['tpl' => '{品牌}和{竞品}哪个更靠谱', 'class' => 'D', 'intent' => 'commercial', 'slots' => ['{品牌}', '{竞品}']],
                    ['tpl' => '{品牌}和{竞品}哪家资质更全', 'class' => 'D', 'intent' => 'commercial', 'slots' => ['{品牌}', '{竞品}']],
                    ['tpl' => '{品牌}和{竞品}哪个口碑更真实', 'class' => 'D', 'intent' => 'commercial', 'slots' => ['{品牌}', '{竞品}']],
                ],
            ],
            [
                'key' => 'trust:S3b',
                'angle' => 'trust',
                'stage' => 'S3b',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'prospectus_and_policy',
                'expected' => '可核查的资质、认证与售后政策条款',
                'note' => '最有价值的一格：已倾向、只差「敢不敢买」，答案是资质页/政策页，官网独占。'
                    .'也是「测评」型标题能凑够 5 条的关键',
                'slots' => ['{品牌}', '{产品}'],
                'templates' => [
                    ['tpl' => '{品牌}靠谱吗 正规吗', 'class' => 'A', 'intent' => 'commercial', 'slots' => ['{品牌}']],
                    ['tpl' => '{品牌}有资质认证吗', 'class' => 'A', 'intent' => 'informational', 'slots' => ['{品牌}']],
                    ['tpl' => '{品牌}售后怎么样', 'class' => 'A', 'intent' => 'commercial', 'slots' => ['{品牌}']],
                    ['tpl' => '{品牌}是正品吗 有授权吗', 'class' => 'A', 'intent' => 'commercial', 'slots' => ['{品牌}']],
                ],
            ],
            [
                'key' => 'trust:S4',
                'angle' => 'trust',
                'stage' => 'S4',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'authentic_channel_guide',
                'expected' => '退换条件、正品与发票、售后响应方式',
                'note' => '下单前的保障条款（退换/正品/发票/响应时限），不是 S3b 的政策公示',
                'slots' => ['{品牌}', '{产品}'],
                'templates' => [
                    ['tpl' => '{品牌}{产品}支持七天无理由吗', 'class' => 'C', 'intent' => 'transactional', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}{产品}退款多久到账', 'class' => 'C', 'intent' => 'transactional', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}发的货是正品吗', 'class' => 'A', 'intent' => 'transactional', 'slots' => ['{品牌}']],
                ],
            ],
            [
                'key' => 'trust:S5',
                'angle' => 'trust',
                'stage' => 'S5',
                'enabled' => true,
                'prompt_enabled' => true,
                'content_type' => 'counterfeit_rights_guide',
                'expected' => '用出问题或买到假货后的维权路径',
                'note' => '买后维权（用过过敏能不能退 / 买到假货怎么维权）',
                'slots' => ['{品牌}', '{产品}'],
                'templates' => [
                    ['tpl' => '{品牌}{产品}用了过敏能退吗', 'class' => 'C', 'intent' => 'informational', 'slots' => ['{品牌}', '{产品}']],
                    ['tpl' => '{品牌}买到假货怎么维权', 'class' => 'A', 'intent' => 'informational', 'slots' => ['{品牌}']],
                ],
            ],
        ];

        // priority 单一来源：从 CELL_PRIORITY 派生，不在每格里手写。
        // 手写就会有「22 处数字」和「一张常量表」两份数据，必然漂。
        foreach ($cells as $i => $cell) {
            $cells[$i]['priority'] = self::CELL_PRIORITY[(string) $cell['key']] ?? 5;
        }

        return $cells;
    }

    /**
     * 按 key 索引的格子表（内部高频用，避免每次线性扫 22 项）。
     *
     * @return array<string, array<string,mixed>>
     */
    public static function cellsByKey(): array
    {
        $out = [];
        foreach (self::cells() as $cell) {
            $out[(string) $cell['key']] = $cell;
        }

        return $out;
    }

    /** 当前启用的格子 key 列表（运营关掉某格后，可用格计算从这里开始） */
    public static function enabledCellKeys(): array
    {
        $out = [];
        foreach (self::cells() as $cell) {
            if (($cell['enabled'] ?? false) === true) {
                $out[] = (string) $cell['key'];
            }
        }

        return $out;
    }

    /**
     * 喂给模型的格子 key（20 格）。
     *
     * 22 格实现 - 价格×S2 - 对比×S2 = 20（规则文档 §5「建议砍掉的 2 格」）。
     * 为什么实现但不喂：这两格运营手工补题时还有价值，砍实现等于砍能力；
     * 而喂给模型会让 8 条射程被「{品类}一般多少钱」这类拿不到引用的题占掉。
     */
    public static function promptCellKeys(): array
    {
        $out = [];
        foreach (self::cells() as $cell) {
            if (($cell['enabled'] ?? false) === true && ($cell['prompt_enabled'] ?? false) === true) {
                $out[] = (string) $cell['key'];
            }
        }

        return $out;
    }

    /**
     * cell → content_type code 映射（22 项）。
     *
     * 由 cells() 派生，**不手写第二份** —— 手写两份一定会漂（系统设计 §1.4.4）。
     *
     * @return array<string,string>
     */
    public static function contentTypeByCell(): array
    {
        $out = [];
        foreach (self::cells() as $cell) {
            $out[(string) $cell['key']] = (string) $cell['content_type'];
        }

        return $out;
    }

    /**
     * cell → expected 文案（22 项）。同样由 cells() 派生。
     *
     * @return array<string,string>
     */
    public static function expectedByCell(): array
    {
        $out = [];
        foreach (self::cells() as $cell) {
            $out[(string) $cell['key']] = (string) $cell['expected'];
        }

        return $out;
    }

    /** content_type code → 中文名 */
    public static function contentTypeLabel(string $code): string
    {
        return self::CONTENT_TYPE_LABELS[$code] ?? $code;
    }

    /** 角度 → 中文名 */
    public static function angleLabel(string $angle): string
    {
        return self::ANGLE_LABELS[$angle] ?? $angle;
    }

    /**
     * 阶段 → 中文名。
     *
     * 未知值不返回空串（团队要求：万一将来出现脏值也不能显示空白）。
     */
    public static function stageLabel(string $stage): string
    {
        if ($stage === '') {
            return '—';
        }

        return self::STAGE_LABELS[$stage] ?? $stage.'（未知阶段）';
    }

    /** 类 → 中文名 */
    public static function classLabel(string $class): string
    {
        return self::CLASS_LABELS[$class] ?? $class;
    }

    /** 意图 → 中文名 */
    public static function intentLabel(string $intent): string
    {
        return self::INTENT_LABELS[$intent] ?? $intent;
    }

    /** 路由 → 中文名 */
    public static function routeLabel(string $route): string
    {
        return self::ROUTE_LABELS[$route] ?? $route;
    }

    /** 品牌相关度 → 中文名 */
    public static function relevanceLabel(string $relevance): string
    {
        return self::BRAND_RELEVANCE_LABELS[$relevance] ?? $relevance;
    }

    /** 标题类型 → 中文名 */
    public static function titleTypeLabel(string $type): string
    {
        return self::TITLE_TYPE_LABELS[$type] ?? $type;
    }

    // =========================================================================
    // 词表
    // =========================================================================

    /**
     * 词表。来源：规则文档 §1.1（占位符）、§3.1（阶段硬信号）、§3.3（意图）。
     *
     * 契约固定为 `array<string, list<string>>`（扁平词组）—— `words()` 依赖它。
     * concern 的嵌套词表**不要**塞进来，它有自己的方法 `concernSignals()`
     * （系统设计 §1.5.2 明确要求分开）。
     *
     * 几个刻意的取舍：
     *   · skin_type 必须排在 symptom 之前扫，否则「敏感肌」会被「敏感」吃掉。
     *   · scene 必须排在 symptom 之前扫，否则「烂脸期」会被「烂脸」吃掉。
     *   · usage_reaction（刺痛/闷痘/过敏…）不算使用动作词，它要在「有具名产品」
     *     的前提下才算 S5 —— 否则「脸刺痛是不是过敏」会被误判成使用后阶段。
     *
     * @return array<string, list<string>>
     */
    public static function lexicon(): array
    {
        return [
            'category' => [
                // 【实测新增】「护肤品」「化妆品」是最高频的品类词，之前漏了。
                // 后果是「敏感肌护肤品怎么选」这类纯品类标题 0 个可用格 ——
                // 生成器直接拒绝，一次模型调用都没发出去（batch 14）。
                '护肤品', '护肤', '化妆品',
                '修护霜', '面霜', '乳液', '精华液', '精华', '水乳', '面膜', '洁面',
                '洗面奶', '防晒霜', '防晒', '眼霜', '身体乳', '化妆水', '爽肤水',
                '原液', '喷雾', '卸妆油', '卸妆水', '护肤油', '凝露', '啫喱',
            ],
            // 成分词。'酸' 单字放最后：既能让「能叠加酸吗」取到 {成分}，
            // 又因为最长优先不会把「水杨酸」切碎
            'ingredient' => [
                '神经酰胺', '青刺果油', '烟酰胺', '透明质酸', '玻尿酸', '积雪草',
                '马齿苋', '泛醇', '角鲨烷', '水杨酸', '果酸', '视黄醇', '胜肽',
                '维生素C', '维C', '虾青素', '依克多因', '红没药醇', '二裂酵母',
                '海藻糖', '尿素', '甘油', '牛油果', '传明酸', '熊果苷', '酸',
            ],
            'symptom' => [
                '起皮', '脱皮', '蜕皮', '泛红', '红血丝', '刺痛', '发痒', '干痒', '痒',
                '烂脸', '爆痘', '长痘', '闭口', '粗糙', '暗沉', '干燥', '紧绷',
                '过敏', '敏感', '红肿', '痘印', '卡粉', '搓泥', '脱妆',
            ],
            'scene' => [
                '烂脸期', '换季', '刷酸后', '医美后', '孕期', '哺乳期', '熬夜后',
                '熬夜', '生理期', '秋冬', '冬天', '夏天', '春天', '军训',
                '暴晒后', '卸妆后', '做面膜后', '空调房', '出差', '高原',
            ],
            'skin_type' => [
                '油痘肌', '外油内干', '混合肌', '混油皮', '混干皮', '敏感肌',
                '中性肌', '大干皮', '油皮', '干皮', '混油', '混干', '痘肌',
            ],
            'crowd' => [
                '孕妇', '哺乳期妈妈', '学生党', '学生', '男士', '女生', '男生',
                '儿童', '宝宝', '婴儿', '宝妈', '老人', '熬夜党', '上班族', '新手',
            ],
            // 交易词（§3.3 第 1 步）。只收「能直接产生下单动作」的词。
            // 贵不贵 / 值不值 / 性价比 不在里面（BC-6）
            'transaction' => [
                '买哪个', '哪里买', '在哪买', '官网购买', '多少钱', '价格', '优惠',
                '折扣', '活动', '满减', '小样', '试用装', '正装', '链接', '旗舰店',
                '正品', '发货', '断货', '囤货', '囤', '几瓶', '包邮', '代购', '买',
                '下单', '付款', '到货', '补货',
            ],
            // 强购买动词（§3.1 S4）。与 transaction 的区别：这里的词出现就一定判 S4，
            // 价值判断词压不住它
            'purchase_strong' => [
                '多少钱', '哪里买', '在哪买', '买哪个', '优惠', '折扣', '小样',
                '链接', '正品', '囤', '包邮', '代购', '旗舰店', '发货', '断货',
                '满减', '试用装', '官网购买', '几瓶', '下单', '付款', '到货', '补货',
            ],
            // 具体售后条款词（§3.1 S4 硬信号 ②）。
            // 这些是「问条款」→ S4；「好退吗/退款难吗」是泛问 → S3b
            'after_sales_terms' => [
                '七天无理由', '退款多久到账', '多久退款', '退换货政策', '支持退货吗',
                '支持换货吗', '质保几年', '保修多久', '三包',
            ],
            // 评估词（§3.1 S3a 硬信号 ②）
            'evaluation' => [
                '好用吗', '好用', '怎么样', '咋样', '值不值', '贵不贵', '性价比',
                '哪个好', '哪个更', '更温和', '区别', '差在哪', '测评', '实测',
                '踩雷', '适合', '缺点', '平替', '浓度', '里含', '含',
                '闷痘', '值得买', '值得回购', '靠谱', '可靠', '真的有用', '效果',
                '优势', '劣势', '对比', '排名', '排行', '什么牌子', '成分',
                '能用吗', '能不能用', '可以用吗', '正常吗', '更值得',
            ],
            // 商业调查词（§3.3 第 2 步）。刻意与 evaluation 分开：
            // evaluation 是「S3 阶段信号」，里面有权重低的词（含 / 里含 / 什么牌子 / 正常吗）
            'commercial' => [
                'vs', '哪个好', '哪个更', '哪个', '对比', '区别', '差在哪', '最好',
                '推荐', '排行', '测评', '实测', '值不值', '性价比', '平替', '踩雷',
                '优缺点', '缺点', '差评', '贵不贵', '怎么样', '咋样', '口碑',
                '更温和', '适合', '更值得',
            ],
            // 价值判断词（S4 反信号）
            'value_judgment' => [
                '贵不贵', '值不值', '性价比', '值得买', '值得回购', '值得吗',
                '为什么这么贵', '好不好', '便宜', '贵', '划算', '溢价',
            ],
            'selection' => [
                '推荐', '哪个牌子', '什么牌子', '哪个', '怎么选', '有必要',
                '一般多少钱', '什么价位', '口碑', '区别', '排行', '测评', '攻略',
                '选购', '避坑', '选哪个', '哪个好', '清单', '红黑榜',
                '用什么', '用哪个', '差在哪',
            ],
            'question' => [
                '怎么', '为什么', '能不能', '值不值', '真的吗', '是什么', '原因',
                '原理', '怎么办', '如何', '吗', '呢', '会不会', '要不要', '需不需要',
                '正常吗', '是不是',
            ],
            // 站点指向词（§3.3 第 3 步，必须与品牌名同时出现才算导航型）
            'site_pointing' => [
                '官网', '旗舰店', '官方', '店铺', '登录', 'APP', '客服电话',
                '公众号', '小程序',
            ],
            // 渠道动作词。用于「正品/假货 与渠道词共现 → S4」这条消歧规则（§3.1）
            'channel' => [
                '哪里买', '在哪买', '官网', '旗舰店', '官方', '专柜', '天猫',
                '京东', '拼多多', '抖音', '商城', '店铺', '授权店', '门店', '链接',
            ],
            // 使用动作词（§3.1 S5）。**只收「已发生/持续中」的动作**。
            // v2 明确：「能用 / 可以用 / 有必要用 / 需要用」不算使用动作（属 S3a 适用性）。
            // 裸「用」也不在里面 —— 由 Rules::stageS5() 剔除需求表达后再查。
            'usage_action' => [
                '用了', '用着', '用过', '用下来', '用完', '用多久', '多久用',
                '能用多久', '一罐能用多久', '涂了', '抹了', '上脸', '正在用',
                '一直在用', '怎么用', '怎么涂', '一起用', '一天几次', '先涂哪个',
                '先用哪个', '叠加', '搭配', '建立耐受', '耐受', '停用', '回购', '空瓶',
                // 【实测新增】删掉「裸用」兜底后，这几类真正的使用后表述要靠它们接住
                '长期用', '持续用', '每天用', '天天用', '越用越', '用久了',
                '坚持用', '一直使用', '长期使用',
            ],
            // 使用反应词。单看它们不成立（「脸刺痛是不是过敏」是 S1），
            // 只有问题里出现具名产品/品牌时才算 S5。
            'usage_reaction' => [
                '刺痛', '泛红加重', '闷痘', '过敏', '反黑', '反弹', '拔干', '搓泥',
                '发红', '脱皮',
            ],

            // ---- 信任角度三组（§3.1 S3b 六类风险词，按语义拆开）----
            // 钱：隐藏费用类。命中 → pricing:S3b
            'trust_hidden_cost' => [
                '隐藏费用', '隐形消费', '额外收费', '额外花钱', '后续还要花钱',
                '绑定消费', '自动续费', '二次消费',
            ],
            // 售后泛问：命中 → trust:S3b（问具体条款的是 S4，见 after_sales_terms）
            'trust_after_sales' => [
                '售后', '售后服务', '有售后吗', '客服态度', '好退吗', '退款难吗',
                '退吗', '能退吗', '退款', '退货', '换货', '质保', '保修',
                '维权', '投诉', '发票', '维修',
            ],
            // 可信 + 资质 + 正品：命中 → trust:S3b
            'trust_authenticity' => [
                '可靠吗', '靠谱吗', '靠谱', '正规吗', '正规', '会不会跑路',
                '是不是骗局', '骗局', '坑不坑', '资质', '认证', '备案',
                '检测报告', '假货', '正品', '是不是真的', '授权', '真货',
                '口碑更真实', '口碑可信度',
            ],
            // 证言可信度：命中 → review:S3b（不是 trust）。v3 两格不合并
            'evidence_doubt' => [
                '真实评价', '差评', '有托吗', '托', '刷单', '刷好评', '水军',
                '口碑可信', '评价是真的',
                // 【实测新增】「这篇测评是不是软文」是典型的证言可信度问题，
                // 模型判 S3b 是对的，但词表里没有「软文」→ 我判成了 S3a。
                '软文', '广告', '恰饭', '收钱', '公关稿',
            ],
            // 常见护肤品品牌（不含自家/竞品档案，用于 BC-8「未登记品牌」识别）
            'known_brands' => [
                '薇诺娜', '理肤泉', '雅漾', '玉泽', '至本', '修丽可', '珂润',
                '芙丽芳丝', '润百颜', '夸迪', '优时颜', '珀莱雅', '欧莱雅',
                '兰蔻', '雅诗兰黛', '可复美', '敷尔佳', '米蓓尔', 'HFP', '半亩花田',
                '谷雨', 'HomeFacialPro', 'CeraVe', '适乐肤', '丝塔芙', '凡士林',
                '郁美净', '大宝', '佰草集', '百雀羚', '自然堂', '相宜本草',
                '安修泽', '初源漾', '瑷尔博士', '博乐达', '芯丝翠',
            ],
        ];
    }

    /** 取某一类词表（取不到返回空数组，调用方不必判 null） */
    public static function words(string $group): array
    {
        $lex = self::lexicon();

        return $lex[$group] ?? [];
    }

    /**
     * concern 信号词表：**键顺序 = 优先级**（命中即止，取最先命中的）。
     *
     * **不要塞进 `lexicon()`** —— lexicon 的契约是扁平 `array<string, list<string>>`，
     * concern 是嵌套结构，塞进去会破掉 `words()` 的返回类型（系统设计 §1.5.2）。
     *
     * 顺序依据系统设计 §1.5.1：
     *   售后 > 真实性 > 安全 > 价格 > 时效性 > 效果 > 质量 > 其他
     * 其中「时效性 必须在 效果 之前」是关键：「多久见效」如果先命中「见效」，
     * 就会被判成效果，但用户问的是时间。团队给的验收用例专门钉了这一条。
     *
     * @return array<string, list<string>>
     */
    public static function concernSignals(): array
    {
        return [
            '售后' => [
                '售后', '售后服务', '退换', '退货', '退款', '退吗', '能退吗',
                '保修', '质保', '客服', '发票', '七天无理由', '三包', '投诉',
                '维权', '维修', '好退吗', '退款难吗',
            ],
            '真实性' => [
                '真的', '真假', '刷单', '刷好评', '真实评价', '水军', '有托',
                '靠谱', '可信', '正规', '资质', '认证', '备案', '官方授权',
                '授权', '骗局', '智商税', '假货', '正品', '是不是真的',
            ],
            '安全' => [
                '过敏', '刺激', '刺痛', '激素', '荧光剂', '重金属', '防腐剂',
                '香精', '酒精', '副作用', '依赖', '安全', '孕妇能用', '烂脸',
                '会不会过敏', '成分安全',
            ],
            '价格' => [
                '贵', '多少钱', '价格', '性价比', '便宜', '优惠', '折扣',
                '溢价', '费用', '二次消费', '隐藏', '值不值', '价位',
            ],
            // 时效性必须在效果之前
            '时效性' => [
                '多久', '几天', '什么时候', '发货', '到货', '快递', '保质期',
                '临期', '开封后', '见效时间',
            ],
            '效果' => [
                '有效', '效果', '有用吗', '管用', '见效', '改善', '修护',
                '淡化', '美白', '祛痘', '真的有用', '好用吗',
            ],
            '质量' => [
                '质量', '做工', '材质', '包装', '批次', '变质', '结块', '分层',
                '异味', '容量', '规格', '肤感', '搓泥', '闷痘', '厚重',
            ],
            // 兜底，无信号词
            '其他' => [],
        ];
    }

    /**
     * 品类同层词阶梯，用于派生 {品类2}。
     *
     * 规则文档没给 {品类2} 的词表来源，这里内置一份「同一层级」的护肤品品类序：
     * 从 {品类} 出发取阶梯上第一个与它不同、且没在标题里出现的词。
     * 取不到就让该格跳过并记 skipped_cells（§1.1「取不到值该格不生成」）。
     *
     * @return list<string>
     */
    public static function categoryLadder(): array
    {
        return ['修护霜', '面霜', '乳液', '精华', '水乳', '面膜', '洁面', '防晒', '眼霜', '身体乳'];
    }

    /**
     * 占位符清单与取值来源说明（页面「推导过程」区展示 + 空转原因拼装用）。
     *
     * @return array<string,string>
     */
    public static function placeholderCatalog(): array
    {
        return [
            '{品牌}' => '自家品牌名（品牌档案，恒定可用）',
            '{产品}' => '标题解析出的产品/品类词组（档案有 products 字段则优先用）',
            '{成分}' => '标题里出现的成分词',
            '{成分2}' => '标题里出现的第二个成分词',
            '{竞品}' => '标题里出现的竞品名（档案 competitors.aliases）',
            '{竞品产品}' => '{竞品} + {品类} 拼接，如「薇诺娜修护霜」',
            '{品类}' => '标题里出现的品类词',
            '{品类2}' => '品类阶梯上与本篇不同的另一个品类',
            '{肤质}' => '标题里的肤质词，取不到时用档案默认受众',
            '{人群}' => '标题里的人群词，取不到时用档案默认受众',
            '{症状}' => '标题里出现的症状词',
            '{场景}' => '标题里出现的场景词',
            '{其他产品}' => '品类阶梯上与 {品类} 不同的另一个（使用兼容性场景）',
        ];
    }

    // =========================================================================
    // T2 动作覆盖集（判定核心）
    // =========================================================================

    /**
     * 标题动作词 → 覆盖的问题格。逐行对应规则文档 v3 §2.1 的映射表。
     *
     * 结构说明：
     *   cover                 无条件覆盖
     *   cover_on_slot         仅当标题带受众槽/场景槽时额外覆盖（测评那一行）
     *   cover_on_multi_brand  仅当标题列出 ≥2 个品牌时额外覆盖（推荐那一行）
     *   cover_on_trust_word   仅当标题含信任/安全类词时额外覆盖
     *   exclude               明确不覆盖（只用于拼 prompt 提示，不参与硬判定）
     *   label                 展示用的中文说明
     *
     * @return array<string, array<string,mixed>>
     */
    public static function actionCoverage(): array
    {
        // 测评类：review:S3a + **信任×S3b（默认覆盖）** + 场景×S3a（带槽时）。
        //
        // 为什么必须默认覆盖 trust:S3b：标题 1「冰溪舒缓修护霜测评：烂脸期真的能用吗」
        // 实测只有 4 个可用模板槽，必然触发「射程过窄（4<5）」。
        // trust:S3b 的 4 个模板只依赖 {品牌}，补进去射程才能到 6-8。
        // 删掉它测评型标题立刻退回 4 条 —— Selftest 有一条断言专门盯着这个数。
        // 测评类：review:S3a + **review:S3b** + **信任×S3b（默认覆盖）** + 场景×S3a（带槽时）。
        //
        // 为什么必须默认覆盖 trust:S3b：标题 1「冰溪舒缓修护霜测评：烂脸期真的能用吗」
        // 实测只有 4 个可用模板槽，必然触发「射程过窄（4<5）」。
        // trust:S3b 的 4 个模板只依赖 {品牌}，补进去射程才能到 6-8。
        // 删掉它测评型标题立刻退回 4 条 —— Selftest 有一条断言专门盯着这个数。
        //
        // 为什么 review:S3b 也在这里：它是「证言可信度」（差评多吗 / 评价是真的吗 /
        // 差评集中在哪）。测评文本来就是证言类内容，天然要对差评把关。
        // 不放进来的后果是 review:S3b 与 user_evidence_roundup **没有任何动作能产出**
        // —— 22 格 ↔ 22 code 会变成「技术上双射、实际有死值」。
        $review = [
            'cover' => ['review:S3a', 'review:S3b', 'trust:S3b'],
            'cover_on_slot' => ['use_case:S3a'],
            'exclude' => ['comparison:S3a', 'definition:S1', 'review:S2', 'pricing:S3a', 'pricing:S4'],
            'label' => '评测×S3a、评测×S3b（证言可信度）、信任×S3b（默认补位）、场景×S3a（标题带受众/场景槽时）',
        ];
        $list = [
            'cover' => ['use_case:S2', 'review:S2', 'definition:S2'],
            'cover_on_multi_brand' => ['comparison:S3a', 'trust:S3a'],
            'exclude' => ['review:S3a', 'pricing:S4', 'definition:S1', 'review:S5', 'review:S3b'],
            'label' => '场景×S2、评测×S2、定义×S2；标题列 ≥2 品牌时额外覆盖 对比×S3a + 信任×S3a',
        ];
        $compare = [
            'cover' => ['comparison:S3a', 'comparison:S2', 'trust:S3a'],
            'exclude' => ['definition:S1', 'review:S5', 'pricing:S4', 'trust:S3b'],
            'label' => '对比×S3a、信任×S3a、对比×S2',
        ];
        $ingredient = [
            'cover' => ['definition:S2', 'definition:S3a', 'review:S3a'],
            'cover_on_trust_word' => ['trust:S3b'],
            'exclude' => ['pricing:S3a', 'pricing:S4', 'comparison:S3a', 'use_case:S1', 'review:S5'],
            'label' => '定义×S2、定义×S3a、评测×S3a（成分视角）；含安全/检测词时补 信任×S3b',
        ];
        $pitfall = [
            'cover' => ['definition:S2', 'comparison:S2', 'review:S2', 'use_case:S2', 'trust:S2'],
            'exclude' => ['review:S3a', 'comparison:S3a', 'use_case:S5'],
            'label' => 'S2 全域（定义/对比/评测/场景）+ 信任×S2（避坑天然含防假货）',
        ];
        $price = [
            'cover' => ['pricing:S2', 'pricing:S3a', 'pricing:S4'],
            'cover_on_trust_word' => ['pricing:S3b'],
            'exclude' => ['definition:S1', 'review:S3a', 'review:S5'],
            'label' => '价格×S2、价格×S3a、价格×S4；含「隐藏费用/收费」时补 价格×S3b',
        ];
        $trust = [
            'cover' => ['trust:S3b', 'trust:S4'],
            'exclude' => ['definition:S1', 'comparison:S3a', 'review:S3a', 'use_case:S2'],
            'label' => '信任×S3b、信任×S4',
        ];
        $howto = [
            'cover' => ['use_case:S5', 'review:S5', 'trust:S5'],
            'exclude' => ['definition:S1', 'comparison:S3a', 'review:S2', 'pricing:S4'],
            'label' => '场景×S5、评测×S5、信任×S5（用出问题怎么办）',
        ];
        $science = [
            'cover' => ['use_case:S1', 'definition:S1'],
            'exclude' => ['review:S3a', 'pricing:S4', 'use_case:S5', 'trust:S3b'],
            'label' => '场景×S1、定义×S1',
        ];

        return [
            '测评' => $review,
            '实测' => $review,
            '使用感受' => $review,
            '开箱' => $review,
            '用了一个月' => $review,

            '推荐' => $list,
            '清单' => $list,
            '排行' => $list,
            '红黑榜' => $list,
            '怎么选' => $list,
            '哪个牌子好' => $list,

            '对比' => $compare,
            'vs' => $compare,
            // 【实测新增】「A 和 B 哪个好」是最常见的对比标题，但之前没有任何
            // 动作词命中它 → 走兜底格（只有 S3*），模型产出的 S2 问题全被 C2 拒，
            // 竞品对比标题最后 0 条 D。实测 batch 13 就是这个现象。
            '哪个好' => $compare,
            '哪个更好' => $compare,
            '区别' => $compare,
            '哪个更值得' => $compare,
            '平替' => $compare,

            '成分' => $ingredient,
            '成分表' => $ingredient,
            '配方' => $ingredient,
            '原理' => $ingredient,
            '为什么有用' => $ingredient,

            '攻略' => $pitfall,
            '指南' => $pitfall,
            '选购' => $pitfall,
            '保姆级' => $pitfall,
            '保姆教程' => $pitfall,
            '避坑' => $pitfall,

            '价格' => $price,
            '多少钱' => $price,
            '值不值' => $price,
            '性价比' => $price,
            '平替价' => $price,

            '售后' => $trust,
            '退款' => $trust,
            '退货' => $trust,
            '质保' => $trust,
            '客服' => $trust,
            '保修' => $trust,
            '靠谱' => $trust,
            '正规' => $trust,
            '资质' => $trust,
            '认证' => $trust,
            '备案' => $trust,
            '检测报告' => $trust,
            '正品' => $trust,
            '假货' => $trust,
            '授权' => $trust,

            '教程' => $howto,
            '怎么用' => $howto,
            '怎么涂' => $howto,
            '搭配' => $howto,
            '叠加' => $howto,
            '多久用一次' => $howto,

            '科普' => $science,
            '是什么' => $science,
            '原因' => $science,
            '为什么' => $science,
        ];
    }

    /**
     * 疑问式标题的覆盖集（R-3：按「疑问宾语」查，不按动作词查）。
     *
     * @return array<string,list<string>>
     */
    public static function questionCoverage(): array
    {
        return [
            // 疑问宾语 = 品类/品牌选择
            'select' => ['definition:S2', 'comparison:S2', 'review:S2', 'use_case:S2', 'pricing:S2'],
            // 疑问宾语 = 这个东西我能不能用
            'fit' => ['use_case:S3a', 'review:S3a'],
            // 疑问宾语 = 值不值
            'worth' => ['pricing:S3a', 'review:S3a'],
            // 疑问宾语 = 真的好用吗
            'effect' => ['review:S3a', 'use_case:S5', 'review:S5'],
            // 疑问宾语 = 为什么/是什么
            'cause' => ['definition:S1', 'use_case:S1'],
            // 疑问宾语 = 靠谱吗 / 是正品吗（§2.1 最后一行明确）
            'trust' => ['trust:S3b', 'trust:S4'],
        ];
    }

    /**
     * 标题里一个动作词都没命中时的兜底覆盖集。
     *
     * §2.1 的表只覆盖「标题带动作词」的情况，纯品牌词标题（如「冰溪修护霜」）
     * 没有对应行。按最保守的解释：标题只承诺了「这是某某产品」，
     * 那它能接的只能是围绕它本身的评估/使用/价格类问题。
     *
     * @return list<string>
     */
    public static function noActionFallback(): array
    {
        return [
            'definition:S3a', 'comparison:S3a', 'pricing:S3a', 'review:S3a',
            'use_case:S3a', 'review:S5', 'use_case:S5', 'trust:S3b',
        ];
    }

    // =========================================================================
    // 排序与分布
    // =========================================================================

    /**
     * 排序权重（§2.5「排序按投入产出比」）。
     *
     * S3b 排第一：已倾向、只差「敢不敢买」，离转化最近；答案是资质页/售后页，
     * 官网独占。S3a 第二：搜的是品牌名，官网有天然优势。
     * S2 是媒体榜单主战场，官网最难进，所以排在 S3b/S3a 之后。
     *
     * 同阶段内 B > C > D > A；**唯一例外：S3b 的 A 类按 C 对待**
     * （答案是自有资质/政策页，不与百科竞争）—— 由 sortForDisplay 处理。
     *
     * @return array{stage: array<string,int>, class: array<string,int>}
     */
    public static function sortPriority(): array
    {
        return [
            'stage' => ['S3b' => 1, 'S3a' => 2, 'S2' => 3, 'S5' => 4, 'S1' => 5, 'S4' => 6],
            'class' => ['B' => 0, 'C' => 1, 'D' => 2, 'A' => 3],
        ];
    }

    /** 数量与分布的硬约束（集中在一处，改上限只改这里） */
    public static function distributionLimits(): array
    {
        return [
            'scoped_min' => 5,
            'scoped_max' => 8,
            'angle_min' => 3,
            'stage_min' => 3,
            // A 的名额只给 A+S3b（走自有信源页的那批）；A 其它阶段走 source_build，
            // 不进本篇射程，因此不占这个名额
            'class_a_cap' => 2,
            'class_c_cap' => 5,
            // 含 1 个品牌时 D 的上限
            'class_d_cap' => 2,
            // 含 ≥2 品牌（竞品对比）时 D 的**绝对值下限**，不用百分比
            // （§2.5④ v3：模板数是结构硬约束，用百分比会逼模型编造凑数）
            'class_d_min_compare' => 3,
            'transactional_cap_without_word' => 1,
        ];
    }

    /** 判定结果 → 中文档位名（页面文案用） */
    public static function fitLabel(?string $fit): string
    {
        return match ($fit) {
            'can' => '能抢',
            'marginal' => '勉强能',
            default => '抢不到',
        };
    }

    /**
     * 文本包含任一关键词。
     *
     * @param  list<string>  $needles
     */
    public static function hits(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = (string) $needle;
            if ($needle !== '' && mb_strpos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 返回文本里命中的全部关键词（用于拼 why / 展示命中原因）。
     *
     * @param  list<string>  $needles
     * @return list<string>
     */
    public static function hitsAll(string $text, array $needles): array
    {
        $out = [];
        foreach ($needles as $needle) {
            $needle = (string) $needle;
            if ($needle !== '' && mb_strpos($text, $needle) !== false) {
                $out[] = $needle;
            }
        }

        return $out;
    }
}
