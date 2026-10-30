<?php

namespace App\Support\GeoAudit;

/**
 * 标题反推提问：判定内核（复算 + 三判据 + 分流 + 分布 + 去重 + 排序）。
 *
 * 这一层是纯函数：不碰 DB、不碰模型、不读全局状态。理由：
 *   C1/C2 是**硬判据**，判错一条就把一条真实需求踢出清单。
 *   硬判据必须是可单测的确定性代码 —— 所以「判定权全部收回服务端」，
 *   模型只负责造句（q / angle / stage / why）。
 *
 * 复算顺序（规则文档 §3.2，命中即止）：
 *   S4 → S5 → S3b → S3a → S2 → S1
 * 为什么 S3b 在 S3a 前面又不会吃掉「A 和 B 哪个售后好」：
 *   S3b 的准入条件里含 `N_b === 1`，所以「≥2 品牌在比」的题进不了 S3b，
 *   自动落到 S3a。效果等同「先判有没有比较对象」，但不需要额外分支。
 */
final class GeoTitleQuestionRules
{
    /** 泛问品类价的词（N_b=0 时判 S2，不判 S4） */
    private const GENERIC_PRICE = ['一般多少钱', '什么价位', '大概多少', '多少钱左右', '大概什么价'];

    // =========================================================================
    // 标注复算
    // =========================================================================

    /**
     * 复算阶段（§3.2，六步命中即止）。
     *
     * @param  array<string,mixed>|null  $brand  给了档案才能数准 N_b
     */
    public static function labelStage(string $q, ?array $brand = null): ?string
    {
        $q = self::norm($q);
        if ($q === '') {
            return null;
        }
        $lex = GeoTitleQuestionIndustry::lexicon();
        $nb = self::brandCount($q, $brand);

        // ---- step1 S4 行动准备 ----
        if (self::stageS4($q, $nb, $lex)) {
            return 'S4';
        }

        // ---- step2 S5 使用后 ----
        if (self::stageS5($q, $brand, $lex)) {
            return 'S5';
        }

        // ---- step3 S3b 风险验证（准入：N_b === 1）----
        if ($nb === 1 && self::stageS3b($q, $lex)) {
            return 'S3b';
        }

        // ---- step4 S3a 候选比较 ----
        if ($nb >= 1 && ($nb >= 2 || GeoTitleQuestionIndustry::hits($q, $lex['evaluation']))) {
            return 'S3a';
        }

        // ---- step5 S2 方案探索 ----
        //
        // 为什么评估词也算进来：S2 的格子里有一批问题只带评估词、不带
        // 教科书上的「选择词」——「{品类}踩雷的多吗」「平价{品类}和大牌{品类}
        // 差在哪」「{品类}是什么 有必要用吗」。只按选择词判，这些模板自己
        // 生成的问题会被判成「无阶段」直接丢掉（v1 实测踩到过）。
        // 【实测修正】加了两组信号：价值判断（便宜/贵/值不值）不被识别时，
        // 「便宜的敏感肌护肤品能信吗」会一路退到 S1 —— 但它明确有品类词，
        // 是方案探索不是症状识别。品类词一旦命中，就不该往下退成 S1。
        $hasCategory = GeoTitleQuestionIndustry::hits($q, $lex['category']);
        if ($nb === 0 && $hasCategory
            && (GeoTitleQuestionIndustry::hits($q, $lex['selection'])
                || GeoTitleQuestionIndustry::hits($q, $lex['evaluation'])
                || GeoTitleQuestionIndustry::hits($q, $lex['value_judgment'])
                || GeoTitleQuestionIndustry::hits($q, $lex['commercial']))) {
            return 'S2';
        }

        // ---- step6 S1 需求觉醒 ----
        $hasSymptom = GeoTitleQuestionIndustry::hits($q, $lex['symptom']);
        if ($nb === 0 && $hasSymptom && GeoTitleQuestionIndustry::hits($q, $lex['question'])) {
            return 'S1';
        }

        return null;
    }

    /**
     * S4 = 强购买动词 或 具体售后条款词，且不命中三个例外。
     *
     * 三个例外（§3.2 step1 c/d/e）：
     *   c) 只有价值判断词（贵不贵/值不值/性价比/为什么贵）→ 回 S3a
     *   d) N_b = 0 且泛问价格（一般多少钱/什么价位）→ 判 S2
     *   e) 退货/退款/换货 单独出现且无具体条款词 → 回 S3b
     */
    private static function stageS4(string $q, int $nb, array $lex): bool
    {
        // 例外 d 要放在最前面：「多少钱」本身是强购买动词，
        // 放后面永远轮不到。N_b=0 说明连买哪个牌子都没定，
        // 不可能是「行动准备」。
        if ($nb === 0 && GeoTitleQuestionIndustry::hits($q, self::GENERIC_PRICE)) {
            return false;
        }

        // 具体售后条款词（七天无理由 / 退款多久到账 / 支持退货吗 …）→ S4
        if (GeoTitleQuestionIndustry::hits($q, $lex['after_sales_terms'])) {
            return true;
        }

        if (! GeoTitleQuestionIndustry::hits($q, $lex['purchase_strong'])) {
            return false;
        }

        // 例外 c：只有价值判断，没有购买动作
        if (GeoTitleQuestionIndustry::hits($q, $lex['value_judgment'])
            && ! GeoTitleQuestionIndustry::hits($q, ['多少钱', '哪里买', '在哪买', '小样', '优惠', '折扣'])) {
            return false;
        }

        return true;
    }

    /**
     * S5 = 已发生/持续中的使用动作。
     *
     * 「能用 / 可以用 / 有必要用 / 需要用」**不在此列**（§3.1 v2 明确）：
     * 「冰溪修护霜烂脸期能用吗」问的是适用性 → S3a，不是 S5。
     */
    private static function stageS5(string $q, ?array $brand, array $lex): bool
    {
        if (GeoTitleQuestionIndustry::hits($q, $lex['usage_action'])) {
            return true;
        }

        // 【实测修正】这里原本还有一条「裸『用』」兜底，已删除。
        //
        // 删它的理由：假单字匹配无法区分「用」是动词还是构词语素，实测 4 条误判 ——
        //   烂脸期用效果怎么样（应 S3a）｜敏皮用冰溪还是薇诺娜好（应 S3a）
        //   敏感肌护肤品和医用敷料有啥区别（『医用』，应 S2）
        //   冰溪修护霜涂起来油不油（无『用』但有涂）
        // 现在只认 usage_action 里的多字词；真·使用后问题的表述都能被它接住
        // （「长期用」「每天用」「越用越」「用了」「怎么用」「能用多久」）。
        // 【实测修正】使用反应词（刺痛/闷痘/过敏）**不再单独触发 S5**。
        //
        // 原因：这些词本身就是症状词，单独出现时更常见的是
        //   「闷痘是真的吗」→ 求证（S3b 证言可信度）
        //   「敏感肌会刺痛吗」→ 求证/评估（S3b 或 S3a）
        // 而不是「已经用了、在反馈反应」。真正的"使用后反应"一定会带使用动作
        // （「用了刺痛」「涂了闷痘」），那些已经被 usage_action 接住。
        // 之前的写法把 2 条求证类问题误判成 S5 并被 C2 丢掉。

        return false;
    }

    /**
     * S3b = 命中六类风险词之一（准入 N_b === 1 由调用方保证）。
     *
     * 三处消歧（§3.1）：
     *   · 钱（隐藏费用类）→ 一定 S3b
     *   · 售后泛问 → S3b；具体条款已在 S4 被吃掉
     *   · 可信/资质/正品 → S3b，但**与渠道动作词共现时让给 S4**
     *   · 证言（差评/有托）→ 仍算 S3b 阶段，但角度是 review（出 review:S3b）
     */
    private static function stageS3b(string $q, array $lex): bool
    {
        if (GeoTitleQuestionIndustry::hits($q, $lex['trust_hidden_cost'])) {
            return true;
        }
        if (GeoTitleQuestionIndustry::hits($q, $lex['trust_after_sales'])) {
            return true;
        }
        if (GeoTitleQuestionIndustry::hits($q, $lex['trust_authenticity'])) {
            // 「哪里买是正品」是渠道问题（S4），不是风险验证
            return ! GeoTitleQuestionIndustry::hits($q, $lex['channel']);
        }
        if (GeoTitleQuestionIndustry::hits($q, $lex['evidence_doubt'])) {
            return true;
        }

        return false;
    }

    /** 问题里的不同品牌实体个数 N_b（自家算 1，每个竞品各算 1） */
    public static function brandCount(string $q, ?array $brand = null): int
    {
        return count(self::brandsInQuestion(self::norm($q), $brand)['found']);
    }

    /**
     * 是否含「具名候选」（品牌 / 具体产品 / 成分）。
     *
     * 品类词**不算**具名候选 —— 「修护霜哪个牌子好」是 S2 不是 S3a（BC-4）。
     */
    public static function hasNamedCandidate(string $q, ?array $brand = null): bool
    {
        $q = self::norm($q);
        if (self::brandsInQuestion($q, $brand)['found'] !== []) {
            return true;
        }

        return GeoTitleQuestionIndustry::hits($q, GeoTitleQuestionIndustry::words('ingredient'));
    }

    /**
     * 复算意图（§3.3，五步命中即止）。
     *
     * $brand 可选：第 3 步「含品牌名 且 含站点指向词 → 导航型」需要知道
     * 哪些词算品牌。不给档案时只能靠内置常见品牌表，会漏掉客户自己的品牌名，
     * 结果是「冰溪官网」被判成信息型（BC-7 反例）。
     */
    public static function labelIntent(string $q, ?array $brand = null): string
    {
        $q = self::norm($q);
        $lex = GeoTitleQuestionIndustry::lexicon();

        // 第 1 步：交易词 → 交易型。**带一个例外**：
        //
        // 「冰溪修护霜和薇诺娜修护霜哪个更值得买」命中交易词「买」，但它是
        // 比价/价值判断（BC-6 同一类），不是下单动作。判成交易型会连锁两个后果：
        //   ① content_type 走 price_page 而不是 brand_comparison
        //   ② R-2 把它降级到副清单 —— 实测就是「哪个更好」这类主问题被挤走
        // 所以：只有价值判断、没有强购买动词时，落到第 2 步判商业调查型。
        $hasAfterSalesTerms = GeoTitleQuestionIndustry::hits($q, $lex['after_sales_terms']);
        if ($hasAfterSalesTerms) {
            return 'transactional';
        }
        if (GeoTitleQuestionIndustry::hits($q, $lex['transaction'])) {
            $onlyValueJudgment = GeoTitleQuestionIndustry::hits($q, $lex['value_judgment'])
                && ! GeoTitleQuestionIndustry::hits($q, $lex['purchase_strong']);
            if (! $onlyValueJudgment) {
                return 'transactional';
            }
        }

        // 第 2 步：对比或评估词 → 商业调查型
        if (GeoTitleQuestionIndustry::hits($q, $lex['commercial'])) {
            return 'commercial';
        }

        // 第 3 步：品牌名 且 站点指向词 → 导航型（双条件，缺一不可，BC-7）
        $hasBrand = self::brandsInQuestion($q, $brand)['found'] !== []
            || GeoTitleQuestionIndustry::hits($q, $lex['known_brands']);
        if ($hasBrand && GeoTitleQuestionIndustry::hits($q, $lex['site_pointing'])) {
            return 'navigational';
        }

        // 第 4 步：疑问词 → 信息型
        if (GeoTitleQuestionIndustry::hits($q, $lex['question'])) {
            return 'informational';
        }

        // 第 5 步：兜底信息型
        return 'informational';
    }

    /**
     * 复算 A/B/C/D（§3.4）。
     *
     * 顺序不能反：先数 N，N≥2 一律 D（不是 A）；
     * N=1 且有具体产品/成分 → C；N=1 且只问品牌整体 → A；N=0 → B。
     *
     * @param  array<string,mixed>  $brand
     * @return array{class:string, brands_found:list<string>, unknown:list<string>, relevance:string}
     */
    public static function labelClass(string $q, array $brand): array
    {
        $q = self::norm($q);
        $scan = self::brandsInQuestion($q, $brand);
        $found = $scan['found'];
        $unknown = $scan['unknown'];
        $n = count($found);

        $relevance = 'none';
        if ($scan['own'] && $scan['competitor']) {
            $relevance = 'both';
        } elseif ($scan['own']) {
            $relevance = 'own';
        } elseif ($scan['competitor'] || $unknown !== []) {
            $relevance = 'competitor';
        }

        if ($n >= 2) {
            return ['class' => 'D', 'brands_found' => $found, 'unknown' => $unknown, 'relevance' => $relevance];
        }

        if ($n === 1) {
            // 「含具体产品/成分」用品类词/成分词做信号。
            // 不建独立产品库：档案里没有 products 字段，而「冰溪修护霜」与
            // 「冰溪」的差别恰好就是有没有品类词。
            $hasProductOrIngredient = GeoTitleQuestionIndustry::hits($q, GeoTitleQuestionIndustry::words('category'))
                || GeoTitleQuestionIndustry::hits($q, GeoTitleQuestionIndustry::words('ingredient'));
            $class = $hasProductOrIngredient ? 'C' : 'A';

            return ['class' => $class, 'brands_found' => $found, 'unknown' => $unknown, 'relevance' => $relevance];
        }

        return ['class' => 'B', 'brands_found' => $found, 'unknown' => $unknown, 'relevance' => $relevance];
    }

    /**
     * 问题里出现的品牌实体（自家 + 已登记竞品 + 未登记常见品牌）。
     *
     * @param  array<string,mixed>|null  $brand
     * @return array{found:list<string>, unknown:list<string>, own:bool, competitor:bool}
     */
    public static function brandsInQuestion(string $q, ?array $brand = null): array
    {
        $q = self::norm($q);
        $found = [];
        $unknown = [];
        $own = false;
        $competitor = false;
        $registered = [];

        if (is_array($brand)) {
            foreach (GeoTitleQuestionSlots::ownBrandNeedles($brand) as $needle => $canonical) {
                if (mb_strpos($q, self::norm((string) $needle)) !== false) {
                    $found[$canonical] = true;
                    $registered[$canonical] = true;
                    $own = true;
                }
            }
            foreach (GeoTitleQuestionSlots::competitorNeedles($brand) as $needle => $canonical) {
                if (mb_strpos($q, self::norm((string) $needle)) !== false) {
                    $found[$canonical] = true;
                    $registered[$canonical] = true;
                    $competitor = true;
                }
            }
        }

        // BC-8：档案未登记的常见品牌也要计入 N（新竞品往往是这种）
        foreach (GeoTitleQuestionIndustry::words('known_brands') as $kb) {
            $kb = (string) $kb;
            if ($kb === '' || mb_strpos($q, self::norm($kb)) === false) {
                continue;
            }
            if (isset($registered[$kb])) {
                continue;
            }
            $absorbed = false;
            foreach ($registered as $reg) {
                if (mb_strpos($kb, self::norm((string) $reg)) !== false) {
                    $absorbed = true;
                    break;
                }
            }
            if ($absorbed) {
                continue;
            }
            $found[$kb] = true;
            $unknown[] = $kb;
        }

        return [
            'found' => array_keys($found),
            'unknown' => array_values(array_unique($unknown)),
            'own' => $own,
            'competitor' => $competitor,
        ];
    }

    /**
     * 角度推断（可能多解，按可能性排序）。
     *
     * 为什么要多解：一条自然语言问句经常同时像两个角度。C2 是硬判据，
     * 只用一个角度判会把合法问题误杀 —— 所以 C2 只要「任一可能角度 + 阶段」
     * 拼出的 cell 在白名单里就放行。
     *
     * @return list<string>
     */
    public static function inferAngles(string $q): array
    {
        $q = self::norm($q);
        $out = [];

        // ---- 信任角度必须最先判 ----
        //
        // 理由：比较维度决定角度。「冰溪和薇诺娜哪个更靠谱」的「哪个更」是通用的
        // 比较词，但它比的是**可靠性**，不是效果/成分/价格 —— 按 §1.2 的划分
        // 应归 信任×S3a，不是 对比×S3a。放在 comparison 之后判，这条永远被抢走。
        //
        // 两个刻意的排除：
        //   · 「差评」不算信任词（对角度而言「有什么差评」是在评价产品 → review）
        //   · 隐藏费用类不算信任词（「有没有隐藏费用」问的是钱 → pricing，BC-9）
        $trustSignals = array_values(array_diff(
            array_merge(
                GeoTitleQuestionIndustry::words('trust_after_sales'),
                GeoTitleQuestionIndustry::words('trust_authenticity')
            ),
            ['差评']
        ));
        if (GeoTitleQuestionIndustry::hits($q, $trustSignals)) {
            $out[] = 'trust';
        }

        if (GeoTitleQuestionIndustry::hits($q, ['哪个好', '哪个更', '区别', '差在哪', '对比', '平替', '更适合', '哪款好', '哪个值得', '怎么选', '更值得'])) {
            $out[] = 'comparison';
        }

        $pricingSignals = array_merge(
            ['多少钱', '价格', '贵不贵', '性价比', '为什么这么贵', '哪里买', '小样', '活动', '优惠', '值不值', '值得买', '便宜', '折扣', '价位'],
            GeoTitleQuestionIndustry::words('trust_hidden_cost')
        );
        if (GeoTitleQuestionIndustry::hits($q, $pricingSignals)) {
            $out[] = 'pricing';
        }

        if (GeoTitleQuestionIndustry::hits($q, ['怎么用', '怎么涂', '一起用', '叠加', '搭配', '刺痛', '闷痘', '多久', '一天几次', '顺序', '先用哪个', '能用吗', '能不能用', '可以用吗', '适合', '一罐', '空瓶', '回购', '停用', '反弹'])) {
            $out[] = 'use_case';
        }

        // 评测角度：把「证言可信度」那一组词并进来。
        // 为什么：S3b 分两格出题 —— 差评/有托/评价是真的 → review:S3b（真实评价汇总），
        // 售后/资质/正品 → trust:S3b（资质政策）。如果「评价是真的吗」推不出 review，
        // 它就会落到兜底的 use_case:S3b，而那一格在矩阵里不存在 → 问题被 C2 丢掉。
        $reviewSignals = array_merge(
            ['好用吗', '好用', '测评', '实测', '踩雷', '缺点', '效果', '怎么样', '口碑',
                '真实', '长期用', '停用', '反弹', '回购', '空瓶', '值得回购'],
            GeoTitleQuestionIndustry::words('evidence_doubt')
        );
        if (GeoTitleQuestionIndustry::hits($q, $reviewSignals)) {
            $out[] = 'review';
        }

        if (GeoTitleQuestionIndustry::hits($q, ['是什么', '什么原因', '原因', '为什么', '原理', '干什么', '有必要', '里含', '含', '牌子', '成分', '智商税'])) {
            $out[] = 'definition';
        }

        // 隐藏费用类要补一个 trust 兜底角度。
        //
        // 「有没有隐藏费用」问的是钱 → 角度优先算 pricing（BC-9，price_transparency）。
        // 但如果**标题本身没承诺价格维度**，C2 会拒绝 pricing:S3b；这时它应该落到
        // trust:S3b（资质公示与售后政策里本来就写了收费项）。
        // 不补这个兜底，这类问题会在只写「测评」的标题下被直接丢掉 —— 实测踩到过。
        if (GeoTitleQuestionIndustry::hits($q, GeoTitleQuestionIndustry::words('trust_hidden_cost'))
            && ! in_array('trust', $out, true)) {
            $out[] = 'trust';
        }

        // 兜底：一个信号都没命中时的最后退路。
        //
        // 【实测修正】原来一律退 use_case，但试跑发现的最大的漂移来源就是这里：
        // 「敏感肌护肤品到底指哪些产品」（模型判 definition，更对）、
        // 「该买面霜还是精华」（模型判 comparison，更对）、
        // 「真的有用吗」（模型判 review，更对）—— 全被兜底成了 use_case。
        // 兜底不该是「随便挑一个」，应该按问句形态给最有依据的那个：
        //   问「指哪些 / 是什么 / 有什么区别」→ definition 或 comparison
        //   问「A 还是 B / 哪个」→ comparison
        //   问「有用吗 / 效果」→ review
        if ($out === []) {
            if (GeoTitleQuestionIndustry::hits($q, ['哪些', '是什么', '指什么', '包括', '定义'])) {
                $out[] = 'definition';
            } elseif (GeoTitleQuestionIndustry::hits($q, ['还是', '哪个', '区别', '比'])) {
                $out[] = 'comparison';
            } elseif (GeoTitleQuestionIndustry::hits($q, ['有用', '效果', '好用', '值不值'])) {
                $out[] = 'review';
            } else {
                // 真的认不出来才退 use_case（场景类最通用，
                // 不会凭空造出「价格×S5」这种矩阵上不存在的格）
                $out[] = 'use_case';
            }
        }

        return array_values(array_unique($out));
    }

    /** 主角度（展示用）：取推断结果的第一个 */
    public static function primaryAngle(string $q): string
    {
        $angles = self::inferAngles($q);

        return $angles[0] ?? 'use_case';
    }

    // =========================================================================
    // 由 cell 推导三件套（concern / expected / content_type）
    // =========================================================================

    /**
     * cell → content_type code。查不到就是上游 bug，抛异常而不是兜底。
     *
     * 为什么不返回默认值（系统设计 §1.4.4）：查不到说明 cell 不合法，
     * 静默兜底会让脏数据流进库里；而 22 格 ↔ 22 code 是双射，正常永远查得到。
     *
     * @throws \InvalidArgumentException
     */
    public static function deriveContentType(string $cell): string
    {
        $map = GeoTitleQuestionIndustry::contentTypeByCell();
        if (! isset($map[$cell])) {
            throw new \InvalidArgumentException('未知的矩阵格：'.$cell);
        }

        return (string) $map[$cell];
    }

    /**
     * cell → expected 文案（服务端确定性推导，不注入占位符）。
     *
     * 落库前硬截断到 200 字：一条超长会让整批 insertOrIgnore 全废，
     * 不能指望 CHECK 兜底。
     */
    public static function deriveExpected(string $cell): string
    {
        $map = GeoTitleQuestionIndustry::expectedByCell();
        $text = (string) ($map[$cell] ?? '');

        return mb_substr($text, 0, 200);
    }

    /**
     * 推导 concern（1–2 个值，按优先级取前 2）。
     *
     * 顺序按系统设计 §1.5.1，命中即止：
     *   售后 > 真实性 > 安全 > 价格 > 时效性 > 效果 > 质量 > 其他
     *
     * @return list<string>
     */
    public static function deriveConcerns(string $q): array
    {
        $q = self::norm($q);
        $out = [];
        foreach (GeoTitleQuestionIndustry::concernSignals() as $concern => $signals) {
            if ($signals === []) {
                continue;
            }
            if (GeoTitleQuestionIndustry::hits($q, $signals)) {
                $out[] = (string) $concern;
                if (count($out) >= 2) {
                    break;
                }
            }
        }

        if ($out === []) {
            $out[] = '其他';
        }

        return $out;
    }

    /** 主顾虑（单值，用于展示与旧调用方） */
    public static function deriveConcern(string $q): string
    {
        $list = self::deriveConcerns($q);

        return (string) ($list[0] ?? '其他');
    }

    /**
     * 模型标注漂移的**方向拆分**（不只看比例）。
     *
     * 为什么必须拆方向：只看「漂移 39%」会得出「模型不行，改 prompt」这个结论 ——
     * 而实测拆完发现 14 条里 10 条是**服务端规则太贪**，改 prompt 一点用没有，
     * 反而会把服务端的错误固化进 prompt。
     *
     * 分类口径（按"谁更具体"，不是按生命周期先后）：
     *   model 在 S1/S2（品类层）而服务端在 S3a/S3b/S4/S5（具名层）→ 模型更粗
     *   model 在具名层而服务端在品类层                          → 模型更细
     *   两边同层（S3a↔S3b、S4↔S5）                             → 同级互换
     *
     * ⚠️ 这三类的处置方向完全相反，所以「谁对」必须人工抽样判断，
     *    本方法只负责把条数分好类，不替人下"谁对"的结论。
     *
     * @param  list<array<string,mixed>>  $items  含 model_angle / model_stage / angle / stage 的行
     * @return array{total:int, drifted:int, angle_only:int, stage_only:int, both:int, coarser:int, finer:int, lateral:int, samples:array<string,list<string>>}
     */
    public static function driftBreakdown(array $items): array
    {
        $coarse = ['S1', 'S2'];
        $named = ['S3a', 'S3b', 'S4', 'S5'];

        $out = [
            'total' => 0, 'drifted' => 0, 'angle_only' => 0, 'stage_only' => 0, 'both' => 0,
            'coarser' => 0, 'finer' => 0, 'lateral' => 0,
            'samples' => ['coarser' => [], 'finer' => [], 'lateral' => []],
        ];

        foreach ($items as $item) {
            $out['total']++;
            $ms = (string) ($item['model_stage'] ?? '');
            $s = (string) ($item['stage'] ?? '');
            $ma = (string) ($item['model_angle'] ?? '');
            $a = (string) ($item['angle'] ?? '');
            if ($ms === '' && $ma === '') {
                continue;   // 没有模型标注（比如模板直填的演练）不算漂移
            }
            $angleDiff = $ma !== '' && $ma !== $a;
            $stageDiff = $ms !== '' && $ms !== $s;
            if (! $angleDiff && ! $stageDiff) {
                continue;
            }
            $out['drifted']++;
            if ($angleDiff && $stageDiff) {
                $out['both']++;
            } elseif ($angleDiff) {
                $out['angle_only']++;
            } else {
                $out['stage_only']++;
            }

            $q = (string) ($item['q'] ?? $item['question'] ?? '');
            if (in_array($ms, $coarse, true) && in_array($s, $named, true)) {
                $out['coarser']++;
                if (count($out['samples']['coarser']) < 5) {
                    $out['samples']['coarser'][] = $ms.'→'.$s.' '.$q;
                }
            } elseif (in_array($ms, $named, true) && in_array($s, $coarse, true)) {
                $out['finer']++;
                if (count($out['samples']['finer']) < 5) {
                    $out['samples']['finer'][] = $ms.'→'.$s.' '.$q;
                }
            } elseif ($stageDiff) {
                $out['lateral']++;
                if (count($out['samples']['lateral']) < 5) {
                    $out['samples']['lateral'][] = $ms.'→'.$s.' '.$q;
                }
            }
        }

        return $out;
    }

    // =========================================================================
    // 三判据 + 分流
    // =========================================================================

    /**
     * 判定「这个标题能不能抢到这个问题」。
     *
     * @param  array<string,mixed>  $slots  GeoTitleQuestionSlots::parse() 的输出
     * @param  array<string,mixed>  $brand  品牌档案
     * @param  string|null  $angleHint  模型给的 angle（可选，仅用于兜底/多解）
     * @param  string|null  $stageHint  模型给的 stage（可选，复算不出来时兜底）
     * @return array<string,mixed>
     */
    public static function judge(
        string $q,
        array $slots,
        array $brand,
        ?string $angleHint = null,
        ?string $stageHint = null
    ): array {
        $qNorm = self::norm($q);
        $lex = GeoTitleQuestionIndustry::lexicon();

        // ---- 标签复算（判定权在服务端，模型给的一律覆写）----
        $stage = self::labelStage($qNorm, $brand);
        if ($stage === null) {
            // ⚠️ 这里曾经写的是 strtoupper($stageHint) —— 'S3a' 会变成 'S3A'，
            // 于是模型的合法标注全被判非法，全部落成 NO_STAGE。
            // 表现是「模型给了 stage 但系统说无法定阶段」，很容易误判成模型的问题。
            // 用大小写不敏感归一化，命中原枚举值。
            $stage = is_string($stageHint)
                ? GeoTitleQuestionStore::normalizeEnum($stageHint, GeoTitleQuestionStore::MATRIX_STAGES)
                : null;
        }

        $angles = self::inferAngles($qNorm);
        $angleHint = is_string($angleHint)
            ? (string) GeoTitleQuestionStore::normalizeEnum($angleHint, GeoTitleQuestionStore::ANGLES)
            : '';
        if ($angleHint !== '' && in_array($angleHint, GeoTitleQuestionStore::ANGLES, true)
            && ! in_array($angleHint, $angles, true)) {
            $angles[] = $angleHint;
        }
        $angle = $angles[0] ?? 'use_case';

        $classInfo = self::labelClass($qNorm, $brand);
        $intent = self::labelIntent($qNorm, $brand);

        $base = [
            'fit' => null,
            'route' => null,
            'drop_reason' => null,
            'drop_detail' => null,
            'class' => $classInfo['class'],
            'intent' => $intent,
            'angle' => $angle,
            'stage' => $stage,
            'cell' => null,
            'relevance' => $classInfo['relevance'],
            'unknown' => $classInfo['unknown'],
        ];

        if ($stage === null) {
            return array_merge($base, ['drop_reason' => 'NO_STAGE', 'drop_detail' => '既没有硬信号也没有模型标注，无法定阶段']);
        }

        // ---- C1 实体覆盖（硬判据）----
        $c1 = self::checkC1($qNorm, $slots, $brand);
        if ($c1 !== null) {
            return array_merge($base, ['drop_reason' => 'C1', 'drop_detail' => $c1]);
        }

        // ---- C2 动作覆盖（硬判据，按 cell 判）----
        //
        // cell 是 (angle, stage) 的坐标，**不由模型输出**。角度可能多解，
        // 所以逐个试：任一「可能角度 + 阶段」拼出的 cell 在白名单里就放行。
        $coverage = (array) ($slots['coverage_cells'] ?? []);
        $cells = GeoTitleQuestionIndustry::contentTypeByCell();
        $matchedCell = null;
        $matchedAngle = null;
        foreach ($angles as $candidateAngle) {
            $cellKey = $candidateAngle.':'.$stage;
            if (isset($cells[$cellKey]) && in_array($cellKey, $coverage, true)) {
                $matchedCell = $cellKey;
                $matchedAngle = $candidateAngle;
                break;
            }
        }
        if ($matchedCell === null) {
            return array_merge($base, [
                'drop_reason' => 'C2',
                'drop_detail' => '（'.$angle.'/'.$stage.'）不在标题动作承诺的覆盖集内',
            ]);
        }
        $angle = (string) $matchedAngle;

        // ---- C3 受众/场景覆盖（软判据）----
        [$c3, $c3detail] = self::checkC3($qNorm, $slots, $lex);
        if ($c3 === 'conflict') {
            return array_merge($base, ['drop_reason' => 'C3', 'drop_detail' => $c3detail]);
        }

        $fit = $c3 === 'marginal' ? 'marginal' : 'can';

        // ---- R-2 交易型问题需要标题授权 ----
        if ($intent === 'transactional' && ! ($slots['has_transaction_word'] ?? false)) {
            $fit = 'marginal';
        }

        // ---- R-1（v3 修订）：A + S3b 例外 ----
        //
        // A 类默认不做本篇目标（位置被媒体/百科占）。
        // 但 A + S3b（「靠谱吗/有资质吗/售后怎么样/是正品吗」）的答案是
        // 资质页 / 售后政策页 / 用户证言 —— 媒体和百科没有这类页面，
        // **官网天然独占**。按软文处理才是错的，所以给它一个独立 route，
        // 并且**计入本篇射程**（不能复用 source_build，那会被排除出射程）。
        if ($classInfo['class'] === 'A') {
            $route = $stage === 'S3b' ? 'own_source' : 'source_build';
        } elseif ($fit === 'can') {
            $route = 'direct';
        } else {
            $route = 'sidelong';
        }

        return array_merge($base, [
            'fit' => $fit,
            'route' => $route,
            'angle' => $angle,
            'cell' => $matchedCell,
            'drop_detail' => $c3detail,
        ]);
    }

    /**
     * C1 实体覆盖（硬判据）。返回 null = 通过，字符串 = 淘汰原因。
     */
    private static function checkC1(string $q, array $slots, array $brand): ?string
    {
        $entities = (array) ($slots['entities'] ?? []);
        $titleNamed = array_values(array_unique(array_merge(
            (array) ($entities['brand'] ?? []),
            (array) ($entities['competitor'] ?? []),
            (array) ($entities['ingredient'] ?? [])
        )));

        $scan = self::brandsInQuestion($q, $brand);
        $qNamed = array_values(array_unique(array_merge(
            $scan['found'],
            GeoTitleQuestionIndustry::hitsAll($q, GeoTitleQuestionIndustry::words('ingredient'))
        )));

        if ($qNamed === []) {
            return null;
        }

        if ($titleNamed === []) {
            return '标题只到品类/症状层，问题里出现具名实体「'.implode('、', $qNamed).'」';
        }

        foreach ($qNamed as $e) {
            if (! in_array($e, $titleNamed, true)) {
                return '实体「'.$e.'」不在标题里（标题实体：'.implode('、', $titleNamed).'）';
            }
        }

        return null;
    }

    /**
     * C3 受众/场景覆盖（软判据）。
     *
     * @return array{0:string, 1:string|null}  [pass|marginal|conflict, 说明]
     */
    private static function checkC3(string $q, array $slots, array $lex): array
    {
        $dims = [
            '肤质' => [
                'q' => GeoTitleQuestionIndustry::hitsAll($q, $lex['skin_type']),
                'title' => (array) ($slots['audience_skin_type'] ?? ($slots['audience'] ?? [])),
            ],
            '人群' => [
                'q' => GeoTitleQuestionIndustry::hitsAll($q, $lex['crowd']),
                'title' => (array) ($slots['audience_crowd'] ?? ($slots['audience'] ?? [])),
            ],
            '场景' => [
                'q' => GeoTitleQuestionIndustry::hitsAll($q, $lex['scene']),
                'title' => (array) ($slots['scene'] ?? []),
            ],
        ];

        $verdict = 'pass';
        $detail = null;
        $marginalDims = [];
        foreach ($dims as $dim => $row) {
            $qVals = $row['q'];
            if ($qVals === []) {
                continue;
            }
            $titleVals = $row['title'];
            if ($titleVals === []) {
                $marginalDims[] = $dim.'('.implode('/', $qVals).')';
                if ($verdict === 'pass') {
                    $verdict = 'marginal';
                }
                continue;
            }
            if (array_intersect($qVals, $titleVals) === []) {
                return ['conflict', $dim.'冲突：标题写「'.implode('/', $titleVals).'」，问题问「'.implode('/', $qVals).'」'];
            }
        }

        if ($marginalDims !== []) {
            $detail = '标题未承诺'.implode('、', $marginalDims).'（内容可能带到，但不是本篇目标）';
        }

        return [$verdict, $detail];
    }

    // =========================================================================
    // 组装一条完整候选（T03 直接调用）
    // =========================================================================

    /**
     * 把「问题原文 + 槽位」组装成可直接落库的一行（含全部标签与判定）。
     *
     * concern / expected / content_type 三件套全部在这里填好，
     * **不要等落库前再补** —— 补的地方一多就必然漏，而这三列是 NOT NULL。
     *
     * @param  array<string,mixed>  $slots
     * @param  array<string,mixed>  $brand
     * @return array<string,mixed>
     */
    public static function makeItem(
        string $q,
        array $slots,
        array $brand,
        ?string $why = null,
        ?string $angleHint = null,
        ?string $stageHint = null
    ): array {
        $q = trim($q);
        $verdict = self::judge($q, $slots, $brand, $angleHint, $stageHint);

        $stage = (string) ($verdict['stage'] ?? '');
        $angle = (string) ($verdict['angle'] ?? 'use_case');
        $cell = (string) ($verdict['cell'] ?? '');

        // cell 查不到（理论上不会：judge 已按 cells() 白名单校验过）
        // → 该条判无效，而不是抛异常把整批炸掉
        if ($cell !== '' && ! isset(GeoTitleQuestionIndustry::contentTypeByCell()[$cell])) {
            $verdict['fit'] = null;
            $verdict['route'] = null;
            $verdict['drop_reason'] = 'INVALID_CELL';
            $verdict['drop_detail'] = 'cell「'.$cell.'」不在 22 格注册表里';
            $cell = '';
        }

        // angles_touched：这条问题**可能**归到哪些角度（1..N，含主角度）。
        // 必须在生成时一次算对并落库 —— 事后用 inferAngles() 重算等于用新规则改历史。
        $touched = self::inferAngles($q);
        if ($angle !== '' && ! in_array($angle, $touched, true)) {
            $touched[] = $angle;
        }
        $touched = array_values(array_unique($touched));

        $class = (string) ($verdict['class'] ?? 'B');
        $intent = (string) ($verdict['intent'] ?? 'informational');
        $concerns = self::deriveConcerns($q);

        $finalWhy = trim((string) $why);
        if ($finalWhy === '') {
            $finalWhy = self::buildWhy($q, $slots, $verdict);
        }

        $contentType = '';
        $expected = '';
        if ($cell !== '') {
            $contentType = self::deriveContentType($cell);
            $expected = self::deriveExpected($cell);
        }

        return [
            'q' => $q,
            'question' => $q,
            'angle' => $angle,
            'stage' => $stage,
            'class' => $class,
            'intent' => $intent,
            'brand_relevance' => (string) ($verdict['relevance'] ?? 'none'),
            'route' => $verdict['route'],
            'fit' => $verdict['fit'],
            'cell' => $cell,
            'why' => $finalWhy,
            // ---- 三件套（服务端确定性推导，模型不给）----
            'concern' => (string) ($concerns[0] ?? '其他'),
            'concern_alt' => isset($concerns[1]) ? (string) $concerns[1] : null,
            'expected' => $expected,
            'content_type' => $contentType,
            'angles_touched' => $touched,
            // 命中「资质公示与售后政策」时附固定提示（只展示，不影响计数与排序）
            'content_type_hint' => $contentType === 'prospectus_and_policy'
                ? GeoTitleQuestionIndustry::PROSPECTUS_HINT
                : null,
            'drop_reason' => $verdict['drop_reason'],
            'drop_detail' => $verdict['drop_detail'],
            'unknown' => (array) ($verdict['unknown'] ?? []),
            'cell_note' => $cell !== '' ? (string) (GeoTitleQuestionIndustry::cellsByKey()[$cell]['note'] ?? '') : '',
        ];
    }

    /**
     * why 兜底拼装（模型没给或给了空话时用）。
     *
     * 决策 8 说「无 why 判不合格」，但不合格的处理是「标记需人工复核」
     * 而不是「把这条问题扔掉」—— 问题本身可能是对的，缺的只是那句解释。
     */
    private static function buildWhy(string $q, array $slots, array $verdict): string
    {
        $bits = [];
        $actions = (array) ($slots['action'] ?? []);
        if ($actions !== []) {
            $bits[] = '标题承诺=「'.implode('/', $actions).'」';
        }
        $scene = (array) ($slots['scene'] ?? []);
        if ($scene !== []) {
            $bits[] = '场景槽含「'.implode('/', $scene).'」';
        }
        $audience = (array) ($slots['audience'] ?? []);
        if ($audience !== []) {
            $bits[] = '受众槽含「'.implode('/', $audience).'」';
        }
        $bits[] = '判定 '.(string) ($verdict['class'] ?? 'B').' 类 / '
            .GeoTitleQuestionIndustry::stageLabel((string) ($verdict['stage'] ?? 'S1'));

        return implode('，', $bits).'（why 由服务端兜底生成，建议人工复核）';
    }

    // =========================================================================
    // 去重
    // =========================================================================

    /**
     * 同义去重（§2.5⑥）。
     *
     * 只合并「只差 {肤质} 取值」的条目：「油皮能用吗」+「干皮能用吗」→ 一条。
     * 做法是把句中的肤质词替换成占位符后归组，同组保留第一条。
     *
     * 为什么不用相似度：阈值无法解释，运营看到两条被合并会问「凭什么它俩算一条」；
     * 替换法可以一句话解释清楚。
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public static function dedupe(array $items): array
    {
        $skin = GeoTitleQuestionIndustry::words('skin_type');
        // 长词优先：「混油皮」必须先于「油皮」被替换，否则会变成「混{肤质}」
        usort($skin, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $seen = [];
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $q = self::norm((string) ($item['q'] ?? $item['question'] ?? ''));
            $key = $q;
            foreach ($skin as $w) {
                $key = str_replace(self::norm($w), '{肤质}', $key);
            }
            // 标点差异也归一到一起：「能用吗？」与「能用吗」是同一句
            $key = preg_replace('/[\s\p{P}\p{S}]+/u', '', $key) ?? $key;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    // =========================================================================
    // 分布校验 + 裁剪
    // =========================================================================

    /**
     * 校验与裁剪。
     *
     * 关键设计：**下限类约束（角度/阶段覆盖、类分布下限、D ≥ 3）只写 warnings 不重跑**。
     * 理由（§2.5④）：重试会让模型为了凑数编造与标题无关的问题，清单就废了。
     * 上限类约束（C≤5 / D≤2 / 总数≤8）才裁。
     *
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,mixed>  $slots
     * @return array<string,mixed>
     */
    public static function validate(array $items, array $slots): array
    {
        $limits = GeoTitleQuestionIndustry::distributionLimits();
        $warnings = [];
        $rejected = [];

        $primary = [];
        $marginal = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $fit = $item['fit'] ?? null;
            if ($fit === 'can') {
                $primary[] = $item;
            } elseif ($fit === 'marginal') {
                $marginal[] = $item;
            } else {
                $rejected[] = [
                    'q' => (string) ($item['q'] ?? ''),
                    'drop' => (string) ($item['drop_reason'] ?? 'UNKNOWN'),
                    'detail' => (string) ($item['drop_detail'] ?? ''),
                ];
            }
        }

        $primary = self::sortForDisplay($primary);
        $marginal = self::sortForDisplay($marginal);

        // ---- ⑤ 标题不含交易词时，交易型 ≤ 1 ----
        $titleHasTransaction = (bool) ($slots['has_transaction_word'] ?? false);
        if (! $titleHasTransaction) {
            $trx = 0;
            $kept = [];
            $demoted = 0;
            foreach ($primary as $item) {
                if (($item['intent'] ?? '') === 'transactional') {
                    $trx++;
                    if ($trx > $limits['transactional_cap_without_word']) {
                        $item['fit'] = 'marginal';
                        $item['route'] = 'sidelong';
                        $marginal[] = $item;
                        $demoted++;
                        continue;
                    }
                }
                $kept[] = $item;
            }
            $primary = $kept;
            if ($demoted > 0) {
                $warnings[] = '标题未承诺交易动作，却产出了 '.$trx.' 条交易型问题，已降级 '.$demoted.' 条到副清单'
                    .'（检查是否把价值判断「贵不贵/值不值/性价比」误标为交易型）';
            }
        }

        // ---- ④ 类上限裁剪 ----
        $titleType = (string) ($slots['title_type'] ?? 'category');
        $isCompare = $titleType === 'competitor_compare';

        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $kept = [];
        foreach ($primary as $item) {
            $cls = (string) ($item['class'] ?? 'B');
            $route = (string) ($item['route'] ?? '');
            $stage = (string) ($item['stage'] ?? '');

            // A 的名额只给 A+S3b（own_source）。A 其它阶段走 source_build，
            // 不进本篇射程，所以不占名额、也不该被裁掉（它是另一条工作流）。
            if ($cls === 'A' && $route !== 'own_source') {
                $kept[] = $item;
                continue;
            }

            $cap = match ($cls) {
                'A' => $limits['class_a_cap'],
                'C' => $limits['class_c_cap'],
                // 竞品对比标题下 D 不设上限（要求已改成绝对值 D ≥ 3）
                'D' => $isCompare ? PHP_INT_MAX : $limits['class_d_cap'],
                default => PHP_INT_MAX,
            };
            if (($counts[$cls] ?? 0) >= $cap) {
                // 超出配额 → 进副清单（§2.5④ 明说「不是降级，是这篇不主打」）
                $item['fit'] = 'marginal';
                $item['route'] = 'sidelong';
                $marginal[] = $item;
                $rejected[] = [
                    'q' => (string) ($item['q'] ?? ''),
                    'drop' => 'S5',
                    'detail' => $cls.' 类已达上限 '.$cap.' 条（stage='.$stage.'），移入副清单',
                ];
                continue;
            }
            $counts[$cls] = ($counts[$cls] ?? 0) + 1;
            $kept[] = $item;
        }
        $primary = $kept;

        // ---- ① 本篇射程 5..8（A 类 source_build 不算射程）----
        $scopedIdx = [];
        foreach ($primary as $idx => $item) {
            if (($item['route'] ?? '') !== 'source_build') {
                $scopedIdx[] = $idx;
            }
        }
        $scopedCount = count($scopedIdx);

        if ($scopedCount > $limits['scoped_max']) {
            $excess = $scopedCount - $limits['scoped_max'];
            $dropIdx = array_flip(array_slice($scopedIdx, -$excess));
            $kept = [];
            foreach ($primary as $idx => $item) {
                if (isset($dropIdx[$idx])) {
                    $rejected[] = [
                        'q' => (string) ($item['q'] ?? ''),
                        'drop' => 'S1',
                        'detail' => '射程超过 '.$limits['scoped_max'].' 条，按投入产出比取前 '.$limits['scoped_max'],
                    ];
                    continue;
                }
                $kept[] = $item;
            }
            $primary = $kept;
            $warnings[] = '射程过宽：本文无法同时抢下全部，已按优先级截断到 '.$limits['scoped_max'].' 条';
        }

        $stats = self::stats($primary, $marginal);

        // ---- ①' n<5 有两种完全不同的原因，处置相反（架构师 v4 增量③）----
        //
        // 「矩阵缺格」是我方问题：可用模板槽 < 5，说明这套矩阵对这个动作词
        //   覆盖不足。报「射程过窄」会让运营去改标题 —— 改一辈子也改不出来。
        //   实测依据：「冰溪舒缓修护霜测评」在还没有 trust:S3b 时只有 4 个可用槽。
        // 「标题射程真窄」是用户问题：槽够（≥5），但模型只产出 n<5 条。
        $templateSlots = (int) ($slots['template_slot_count'] ?? 0);
        if ($stats['scoped'] < $limits['scoped_min']) {
            if ($templateSlots > 0 && $templateSlots < $limits['scoped_min']) {
                $warnings[] = 'MATRIX_GAP：当前矩阵对这个标题的动作词覆盖不足'
                    .'（可用模板槽仅 '.$templateSlots.' 个，要求 ≥'.$limits['scoped_min'].'）。'
                    .'这是矩阵问题不是标题问题 —— 需补格，改标题没用。';
            } else {
                $warnings[] = '射程过窄（本篇射程仅 '.$stats['scoped'].' 条）：'
                    .'建议在标题补一个角度词（测评/对比/成分/价格/售后）或放宽受众限定';
            }
        }

        // ---- ② / ③ 角度 / 阶段覆盖 ----
        if ($stats['scoped'] > 0 && $stats['angles'] < $limits['angle_min']) {
            $warnings[] = '推导偏科：只覆盖 '.$stats['angles'].' 个角度（建议 ≥'.$limits['angle_min'].'）'
                .'——不重跑，重跑会让模型为凑角度编造与标题无关的问题';
        }
        if ($stats['scoped'] > 0 && $stats['stages'] < $limits['stage_min']) {
            $warnings[] = '推导偏科：只覆盖 '.$stats['stages'].' 个阶段（建议 ≥'.$limits['stage_min'].'）';
        }

        // ---- S2b 内容收敛：条数够了但文体集中 ----
        //
        // 【措辞】这里叫「内容收敛」不叫「射程虚高」。
        // 「射程虚高」暗示「这些条数是凑出来的」——那是指控；而事实是
        // 「这些问题由 N 篇内容覆盖」——那是一条信息。
        // 运营看到告警的第一反应是「我是不是哪里配错了」，所以文案必须
        // 立刻告诉他「不是你的问题，这是这个标题的真实形态」。
        //
        // 判据必须**同时**看阶段和 content_type：
        // 反例 6 条全 S3a 但 6 种文体 = 6 篇不同内容，一点都不收敛。
        // 只看阶段会指挥运营砍掉 4 条真内容。
        // content_type 覆盖恒 ≥ 阶段覆盖（content_type ↔ cell ↔ (angle,stage) 是 1:1），
        // 所以加这个条件只收紧、不漏判。
        if ($stats['scoped'] >= $limits['scoped_min']) {
            $ctypeCov = self::contentTypeCoverage($primary);
            if ($stats['stages'] < $limits['stage_min'] && $ctypeCov < $limits['stage_min']) {
                // 主导角度：告诉运营「价值集中在哪个维度」，才知道要不要改标题
                $dominant = self::dominantAngle($primary);
                $warnings[] = '内容收敛：'.$stats['scoped'].' 条问题只覆盖 '.$stats['stages'].' 个阶段 / '
                    .$ctypeCov.' 种内容类型，可由 '.$ctypeCov.' 篇内容覆盖'
                    .'（这不是缺陷 —— 说明这个标题的价值集中在'.$dominant.'维度，'
                    .'不是配置错了；想让射程更宽，得在标题里补其它角度的动作词）';
            }
        }

        // ---- ③' 品牌词标题必须有评估/风险类问题 ----
        $titleBrandCount = (int) ($slots['brand_count'] ?? 0);
        if ($titleBrandCount >= 1 && $stats['scoped'] > 0) {
            $hasEval = false;
            foreach ($primary as $item) {
                if (in_array((string) ($item['stage'] ?? ''), ['S3a', 'S3b'], true)) {
                    $hasEval = true;
                    break;
                }
            }
            if (! $hasEval) {
                $warnings[] = '品牌词标题却没有评估/风险类问题，射程错位（建议至少 1 条 S3a 或 S3b）';
            }
        }

        // ---- ④ 类分布（下限只写 warnings）----
        if ($stats['scoped'] > 0) {
            $n = $stats['scoped'];
            $b = $stats['classes']['B'];
            $c = $stats['classes']['C'];
            $d = $stats['classes']['D'];
            if ($titleType === 'category' && $b / $n < 0.6) {
                $warnings[] = '类分布偏离：纯品类标题应以 B 类为主（B 目前 '.$b.'/'.$n.'，建议 ≥60%）';
            }
            if ($titleType === 'brand_product' && ($c + $d) / $n < 0.5) {
                $warnings[] = '类分布偏离：品牌/产品词标题应以 C+D 为主体（目前 '.($c + $d).'/'.$n.'，建议 ≥50%）';
            }
            if ($isCompare && $d < $limits['class_d_min_compare']) {
                // 绝对值要求，不用百分比：模板数是结构硬约束，
                // 用「D ≥ 50%」在射程 8 时会逼模型编造凑数（§2.5④ v3）
                $warnings[] = '竞品对比标题的 D 类不足：目前 '.$d.' 条，要求 ≥'.$limits['class_d_min_compare'].' 条'
                    .'（可用 D 模板共 8 个：对比×S3a 4 个 + 信任×S3a 4 个）';
            }
        }

        // ---- 未登记品牌提示（BC-8）----
        $unknownAll = [];
        foreach (array_merge($primary, $marginal) as $item) {
            foreach ((array) ($item['unknown'] ?? []) as $u) {
                $unknownAll[(string) $u] = true;
            }
        }
        if ($unknownAll !== []) {
            $warnings[] = '出现未登记品牌：'.implode('、', array_keys($unknownAll)).'，请确认是否为竞品';
        }

        if ($primary === [] && $marginal === []) {
            $warnings[] = '没有一条候选通过三判据：标题射程与 22 格矩阵不匹配，建议重写标题或补齐品牌档案（竞品/产品）';
        }

        // 写回展示序号
        $primary = self::sortForDisplay($primary);
        $marginal = self::sortForDisplay($marginal);
        foreach ($primary as $i => $item) {
            $primary[$i]['list_kind'] = 'primary';
            $primary[$i]['position'] = $i + 1;
        }
        foreach ($marginal as $i => $item) {
            $marginal[$i]['list_kind'] = 'marginal';
            $marginal[$i]['position'] = $i + 1;
        }

        return [
            'primary' => $primary,
            'marginal' => $marginal,
            'warnings' => $warnings,
            'rejected' => $rejected,
            'stats' => $stats,
            'status' => $warnings === [] ? 'completed' : 'degraded',
        ];
    }

    /**
     * 主清单覆盖了几种 content_type（S2b 内容收敛的第二判据）。
     *
     * @param  list<array<string,mixed>>  $items
     */
    public static function contentTypeCoverage(array $items): int
    {
        $seen = [];
        foreach ($items as $item) {
            $ct = (string) ($item['content_type'] ?? '');
            if ($ct !== '') {
                $seen[$ct] = true;
            }
        }

        return count($seen);
    }

    /**
     * 主清单里出现次数最多的角度（中文名）。
     *
     * 用途：S2b「内容收敛」告警里告诉运营「价值集中在哪个维度」——
     * 只说「你收敛了」是废话，说「集中在对比维度」他才知道该不该补别的角度。
     *
     * @param  list<array<string,mixed>>  $items
     */
    public static function dominantAngle(array $items): string
    {
        $count = [];
        foreach ($items as $item) {
            $a = (string) ($item['angle'] ?? '');
            if ($a !== '') {
                $count[$a] = ($count[$a] ?? 0) + 1;
            }
        }
        if ($count === []) {
            return '未知';
        }
        arsort($count);
        $top = (string) array_key_first($count);

        return GeoTitleQuestionIndustry::angleLabel($top);
    }

    /**
     * 统计条（页面 stat 卡的数据源）。
     *
     * @param  list<array<string,mixed>>  $primary
     * @param  list<array<string,mixed>>  $marginal
     * @return array<string,mixed>
     */
    public static function stats(array $primary, array $marginal): array
    {
        $classes = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $angles = [];
        $stages = [];
        $scoped = 0;

        foreach ($primary as $item) {
            $cls = (string) ($item['class'] ?? 'B');
            if (array_key_exists($cls, $classes)) {
                $classes[$cls]++;
            }
            $angles[(string) ($item['angle'] ?? '')] = true;
            $stages[(string) ($item['stage'] ?? '')] = true;
            // A 类里只有 source_build 不算射程；own_source（A+S3b）算
            if (($item['route'] ?? '') !== 'source_build') {
                $scoped++;
            }
        }

        return [
            'primary' => count($primary),
            'scoped' => $scoped,
            'marginal' => count($marginal),
            'angles' => count(array_filter(array_keys($angles), static fn ($k): bool => $k !== '')),
            'stages' => count(array_filter(array_keys($stages), static fn ($k): bool => $k !== '')),
            'classes' => $classes,
        ];
    }

    // =========================================================================
    // 排序
    // =========================================================================

    /**
     * 按投入产出比排序（S3b > S3a > S2 > S5 > S1 > S4；同阶段 B > C > D > A）。
     *
     * 两个例外：
     *   · **S3b 的 A 类按 C 对待**（答案是自有资质/政策页，不与百科竞争）
     *   · **D 类里 对比×S3a 优先于 信任×S3a**（§2.5④ D 配额分配顺序）
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public static function sortForDisplay(array $items): array
    {
        $prio = GeoTitleQuestionIndustry::sortPriority();
        $stageOrder = $prio['stage'];
        $classOrder = $prio['class'];

        // cell → priority 用 Industry::CELL_PRIORITY 这一张表（单一来源）。
        // D 类两个出口（comparison:S3a=1 / trust:S3a=2）的先后也由它决定，
        // 不再单独写一张 D 权重表 —— 两张表会静默漂移。
        $cellPriority = GeoTitleQuestionIndustry::CELL_PRIORITY;

        $indexed = [];
        foreach (array_values($items) as $i => $item) {
            $indexed[] = ['i' => $i, 'item' => $item];
        }

        usort($indexed, static function (array $a, array $b) use ($stageOrder, $classOrder, $cellPriority): int {
            $stageA = (string) ($a['item']['stage'] ?? '');
            $stageB = (string) ($b['item']['stage'] ?? '');
            $sa = $stageOrder[$stageA] ?? 9;
            $sb = $stageOrder[$stageB] ?? 9;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            $classA = (string) ($a['item']['class'] ?? '');
            $classB = (string) ($b['item']['class'] ?? '');
            // S3b 的 A 按 C 对待
            $sortClassA = ($stageA === 'S3b' && $classA === 'A') ? 'C' : $classA;
            $sortClassB = ($stageB === 'S3b' && $classB === 'A') ? 'C' : $classB;
            $ca = $classOrder[$sortClassA] ?? 9;
            $cb = $classOrder[$sortClassB] ?? 9;
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }

            // 同阶段同类时按 CELL_PRIORITY 排，让高价值格先吃配额。
            // D 类的「对比×S3a 优先、信任×S3a 补位」也在这里生效（1 vs 2）。
            // 具体场景：S3b 的 A 类名额只有 2 个，trust:S3b（资质页，priority 1）
            // 必须排在 review:S3b（证言，priority 3）前面，否则最有价值的一格
            // 会被挤进副清单 —— 这是实测踩到过的。
            $cellPriorityA = $cellPriority[(string) ($a['item']['cell'] ?? '')] ?? 9;
            $cellPriorityB = $cellPriority[(string) ($b['item']['cell'] ?? '')] ?? 9;
            if ($cellPriorityA !== $cellPriorityB) {
                return $cellPriorityA <=> $cellPriorityB;
            }

            // 完全同级时保持输入顺序（稳定排序），避免两次运行结果乱序
            return $a['i'] <=> $b['i'];
        });

        $out = [];
        foreach ($indexed as $row) {
            $out[] = $row['item'];
        }

        return $out;
    }

    // =========================================================================
    // 工具
    // =========================================================================

    /** 归一化：trim + 小写（中文不受影响，ASCII 别名需要） */
    private static function norm(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
