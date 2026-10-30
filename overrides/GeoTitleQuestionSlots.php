<?php

namespace App\Support\GeoAudit;

/**
 * 标题槽位解析（T1 实体 / T2 动作 / T3 受众 / T4 场景 / T5 范围）。
 *
 * 纯规则，0 次模型调用。为什么必须纯规则：
 *   判定 C2 依赖「标题承诺了哪些动作」这件事。如果让模型解析槽位，
 *   模型把「测评」看成「评测+推荐」时，整份清单的射程就错了，而且错得
 *   无法复现、无法单测。规则解析的漏判是可接受的（漏一个动作词 =
 *   少覆盖几格 = 少几条问题），错判是不可接受的（多覆盖 = 清单虚胖）。
 *
 * 匹配策略：**最长优先 + 占位掩码**。
 *   先把所有候选词按字符数倒序排，命中后把占的字符标脏，后面的短词就不能
 *   再吃这些字符。这样「薇诺娜修护霜」不会把「薇诺娜」切成「薇」，
 *   「敏感肌」不会被症状词「敏感」吃掉，「烂脸期」不会被「烂脸」吃掉。
 *   类型扫描优先级同时决定了冲突时谁赢：
 *   品牌 > 竞品 > 成分 > 品类 > 场景 > 肤质 > 人群 > 症状。
 */
final class GeoTitleQuestionSlots
{
    /**
     * 解析标题。
     *
     * @param  string  $title  标题原文
     * @param  array<string,mixed>  $brand  品牌档案（GeoShareOfVoice::brand() 的返回值）
     * @return array<string,mixed>
     */
    public static function parse(string $title, array $brand): array
    {
        $rawTitle = $title;
        $title = trim($title);
        // 统一小写便于 ASCII 别名（binsis / Winona / La Roche-Posay）匹配。
        // 中文经 mb_strtolower 后保持不变，不影响任何中文词。
        $haystack = mb_strtolower($title);

        $entities = self::scanEntities($haystack, $brand);
        $actions = self::scanActions($haystack);

        $ownName = self::ownBrandName($brand);
        $competitors = self::competitorWords($brand);

        $audience = self::mergeUnique($entities['skin_type'], $entities['crowd']);
        $scene = $entities['scene'];
        $symptom = $entities['symptom'];

        $brandInTitle = $entities['brand'];
        // 竞品数 + 自家品牌是否出现在标题里 = 标题列出的品牌数
        $brandCount = count($brandInTitle) + count($entities['competitor']);
        $titleType = 'category';
        if ($brandCount >= 2) {
            $titleType = 'competitor_compare';
        } elseif ($brandCount === 1) {
            $titleType = 'brand_product';
        }

        // 范围槽：产品实体 0 个 = none，1 个 = single，≥2 = multi
        $productWords = $entities['product'];
        if ($productWords === []) {
            // R1 方案 C：档案没有 products 字段时，用标题里的品类词当产品词。
            // 标题必含品类词否则本身无射程，所以这条兜底能覆盖绝大多数标题。
            $productWords = $entities['category'];
        }
        $scope = 'none';
        if (count($productWords) === 1) {
            $scope = 'single';
        } elseif (count($productWords) >= 2) {
            $scope = 'multi';
        }

        $isQuestion = self::isQuestionTitle($haystack);
        $questionGroups = self::questionObjectGroups($haystack);

        // 占位符池：先算出「能取到什么」，取不到的留 null，后面按格过滤
        $pool = self::buildPool($entities, $productWords, $ownName, $brand);

        $actionLabels = [];
        foreach ($actions as $a) {
            $row = GeoTitleQuestionIndustry::actionCoverage()[$a] ?? null;
            if ($row !== null) {
                $actionLabels[$a] = (string) $row['label'];
            }
        }

        // 标题自己有没有谈"售后/资质/正品/隐藏费用"。
        // 作用：某些动作行（价格、成分）带 cover_on_trust_word —— 只有标题
        // 本身承诺了信任维度，才额外放开 trust:S3b / pricing:S3b。
        // 否则「冰溪修护霜多少钱」也会去接「有隐藏费用吗」，那是硬凑。
        $hasTrustWord = GeoTitleQuestionIndustry::hits($haystack, array_merge(
            GeoTitleQuestionIndustry::words('trust_hidden_cost'),
            GeoTitleQuestionIndustry::words('trust_after_sales'),
            GeoTitleQuestionIndustry::words('trust_authenticity'),
            GeoTitleQuestionIndustry::words('evidence_doubt')
        ));

        $coverage = self::coverageFor(
            $actions,
            $brandCount >= 2,
            $audience !== [] || $scene !== [],
            $isQuestion,
            $questionGroups,
            $hasTrustWord
        );

        [$availableCells, $skippedCells, $cellDetail] = self::availableCells($pool, $coverage);

        $resolved = [];
        $missing = [];
        foreach (GeoTitleQuestionIndustry::placeholderCatalog() as $placeholder => $desc) {
            if (isset($pool[$placeholder]) && $pool[$placeholder] !== '') {
                $resolved[$placeholder] = $pool[$placeholder];
            } else {
                $missing[] = $placeholder;
            }
        }

        return [
            'title' => $rawTitle,
            'title_normalized' => $title,
            'entities' => [
                'brand' => $entities['brand'],
                'product' => $productWords,
                'ingredient' => $entities['ingredient'],
                'competitor' => $entities['competitor'],
                'category' => $entities['category'],
                'symptom' => $symptom,
            ],
            'action' => $actions,
            'action_labels' => $actionLabels,
            'audience' => $audience,
            'audience_skin_type' => $entities['skin_type'],
            'audience_crowd' => $entities['crowd'],
            'scene' => $scene,
            'symptom' => $symptom,
            'scope' => $scope,
            'title_type' => $titleType,
            'title_type_label' => GeoTitleQuestionIndustry::titleTypeLabel($titleType),
            'brand_count' => $brandCount,
            'own_brand' => $ownName,
            'competitor_pool' => $competitors,
            'has_transaction_word' => GeoTitleQuestionIndustry::hits($haystack, GeoTitleQuestionIndustry::words('transaction')),
            'is_question_title' => $isQuestion,
            'question_object' => $questionGroups,
            'resolved' => $resolved,
            'missing' => $missing,
            'coverage_cells' => $coverage,
            'available_cells' => $availableCells,
            // 可用模板槽总数（不是格数）。用途：区分「矩阵缺格」和「标题射程真窄」——
            // 槽 < 5 时 n<5 是矩阵问题，改标题永远改不出来（架构师 v4 增量③）。
            'template_slot_count' => self::templateSlotCount($availableCells, $cellDetail),
            'skipped_cells' => $skippedCells,
            'cells' => $cellDetail,
        ];
    }

    /**
     * 当前动作词 + 疑问宾语能覆盖哪些格（C2 的白名单）。
     *
     * @param  list<string>  $actions
     * @param  list<string>  $questionGroups
     * @return list<string>
     */
    public static function coverageFor(
        array $actions,
        bool $multiBrand,
        bool $hasAudienceOrScene,
        bool $isQuestion,
        array $questionGroups,
        bool $hasTrustWord = false
    ): array {
        $map = GeoTitleQuestionIndustry::actionCoverage();
        $out = [];

        foreach ($actions as $action) {
            $row = $map[$action] ?? null;
            if ($row === null) {
                continue;
            }
            foreach ((array) ($row['cover'] ?? []) as $cell) {
                $out[] = (string) $cell;
            }
            // 「测评」那一行：只有标题本身带受众/场景槽时才算承诺了场景×S3。
            // 否则「冰溪修护霜测评」去接「适合油皮吗」就是硬凑。
            if ($hasAudienceOrScene) {
                foreach ((array) ($row['cover_on_slot'] ?? []) as $cell) {
                    $out[] = (string) $cell;
                }
            }
            // 「推荐」那一行：标题列出 ≥2 品牌才额外接对比×S3a + 信任×S3a
            if ($multiBrand) {
                foreach ((array) ($row['cover_on_multi_brand'] ?? []) as $cell) {
                    $out[] = (string) $cell;
                }
            }
            // 「价格」「成分」那两行：标题自己带信任词时才额外放开
            // （价格 → pricing:S3b；成分 → trust:S3b）
            if ($hasTrustWord) {
                foreach ((array) ($row['cover_on_trust_word'] ?? []) as $cell) {
                    $out[] = (string) $cell;
                }
            }
        }

        // R-3：疑问式标题按「疑问宾语」判，不额外加严；与动作词覆盖集取并集
        if ($isQuestion) {
            $qMap = GeoTitleQuestionIndustry::questionCoverage();
            foreach ($questionGroups as $group) {
                foreach ((array) ($qMap[$group] ?? []) as $cell) {
                    $out[] = (string) $cell;
                }
            }
        }

        // 一个动作词都没命中：走保守兜底（见 noActionFallback 的注释）
        if ($out === []) {
            foreach (GeoTitleQuestionIndustry::noActionFallback() as $cell) {
                $out[] = (string) $cell;
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * 数「真正可用」的模板槽总数（每格可能 2-4 套模板）。
     *
     * 只数 availableCells 里的格 —— $cellDetail 含「占位符齐但标题没承诺该角度」
     * 的格，把它们算进来会让这个数虚高，MATRIX_GAP 就永远判不出来。
     *
     * @param  list<string>  $availableCells
     * @param  array<string,mixed>  $cellDetail
     */
    public static function templateSlotCount(array $availableCells, array $cellDetail): int
    {
        $n = 0;
        foreach ($availableCells as $key) {
            $n += count((array) ($cellDetail[$key]['templates'] ?? []));
        }

        return $n;
    }

    /**
     * 扫实体槽。
     *
     * @param  array<string,mixed>  $brand
     * @return array{brand:list<string>, competitor:list<string>, ingredient:list<string>, category:list<string>, product:list<string>, scene:list<string>, symptom:list<string>, skin_type:list<string>, crowd:list<string>, unknown:list<string>}
     */
    public static function scanEntities(string $haystack, array $brand): array
    {
        $lex = GeoTitleQuestionIndustry::lexicon();
        $chars = self::chars($haystack);
        $occupied = [];

        // 类型顺序即优先级：品牌 > 竞品 > 成分 > 品类 > 场景 > 肤质 > 人群 > 症状
        $brandMap = self::needleMap(self::ownBrandNeedles($brand));
        $competitorMap = self::needleMap(self::competitorNeedles($brand));
        $productMap = self::needleMap(self::brandProductNeedles($brand));
        $ingredientMap = self::needleMap(self::selfMap($lex['ingredient'] ?? []));
        $categoryMap = self::needleMap(self::selfMap($lex['category'] ?? []));
        $sceneMap = self::needleMap(self::selfMap($lex['scene'] ?? []));
        $skinMap = self::needleMap(self::selfMap($lex['skin_type'] ?? []));
        $crowdMap = self::needleMap(self::selfMap($lex['crowd'] ?? []));
        $symptomMap = self::needleMap(self::selfMap($lex['symptom'] ?? []));
        // BC-8：档案里没登记的常见品牌，也要算进 N（用于提示，不用于 C1 放行）
        $knownMap = self::needleMap(self::selfMap($lex['known_brands'] ?? []));

        $brandHits = self::scanChars($chars, $brandMap, $occupied);
        $competitorHits = self::scanChars($chars, $competitorMap, $occupied);
        // 未登记品牌要先扫，否则会被品类/症状词的短词拆碎（如「薇诺娜」被「薇」无影响，
        // 但「理肤泉」里的「肤」不影响；主要是保证不会被自家档案之外的词先占位）
        $unknownHits = self::scanChars($chars, $knownMap, $occupied);
        $productHits = self::scanChars($chars, $productMap, $occupied);
        $ingredientHits = self::scanChars($chars, $ingredientMap, $occupied);
        $categoryHits = self::scanChars($chars, $categoryMap, $occupied);
        $sceneHits = self::scanChars($chars, $sceneMap, $occupied);
        $skinHits = self::scanChars($chars, $skinMap, $occupied);
        $crowdHits = self::scanChars($chars, $crowdMap, $occupied);
        $symptomHits = self::scanChars($chars, $symptomMap, $occupied);

        // 未登记品牌 = 命中了常见品牌词表、但既不是自家也不是已登记竞品的
        $registered = array_merge($brandHits, $competitorHits);
        $unknown = array_values(array_filter(
            $unknownHits,
            static fn (string $w): bool => ! in_array($w, $registered, true)
        ));

        return [
            'brand' => $brandHits,
            'competitor' => $competitorHits,
            'ingredient' => $ingredientHits,
            'category' => $categoryHits,
            'product' => $productHits,
            'scene' => $sceneHits,
            'symptom' => $symptomHits,
            'skin_type' => $skinHits,
            'crowd' => $crowdHits,
            'unknown' => $unknown,
        ];
    }

    /**
     * 扫动作槽（T2）。返回命中的动作词键，可能多个（「测评对比」）。
     *
     * @return list<string>
     */
    public static function scanActions(string $haystack): array
    {
        $keys = array_keys(GeoTitleQuestionIndustry::actionCoverage());
        // 最长优先：「为什么有用」必须先于「为什么」被匹配到，
        // 否则「为什么有用」会被降级成科普类（S1），本该走成分类（S2/S3）
        usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $hits = [];
        $consumed = [];
        foreach ($keys as $key) {
            $len = mb_strlen($key);
            // 已被更长的词吃掉的区间不再匹配，避免「成分表」里的「成分」二次命中
            $pos = mb_strpos($haystack, $key);
            while ($pos !== false) {
                $overlap = false;
                for ($i = $pos; $i < $pos + $len; $i++) {
                    if (isset($consumed[$i])) {
                        $overlap = true;
                        break;
                    }
                }
                if (! $overlap) {
                    for ($i = $pos; $i < $pos + $len; $i++) {
                        $consumed[$i] = true;
                    }
                    $hits[] = $key;
                }
                $pos = mb_strpos($haystack, $key, $pos + 1);
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * 标题是否是疑问式（R-3 的触发条件）。
     *
     * 除了规则文档列出的「怎么/为什么/能不能/值不值/真的吗」开头或结尾，
     * 还额外认「怎么选 / 哪个好 / 哪个牌子」这类中置疑问 ——
     * 规则文档 §2.4 R-3 自己的例子就是「修护霜怎么选」，它不以这些词结尾，
     * 只按「结尾」判会把规则文档给的例子漏掉。
     */
    public static function isQuestionTitle(string $haystack): bool
    {
        $haystack = trim($haystack);
        if ($haystack === '') {
            return false;
        }
        $ends = ['吗', '呢', '?', '？'];
        foreach ($ends as $mark) {
            if (mb_substr($haystack, -mb_strlen($mark)) === $mark) {
                return true;
            }
        }
        $starts = ['怎么', '为什么', '能不能', '值不值', '真的吗', '要不要', '如何'];
        foreach ($starts as $mark) {
            if (mb_strpos($haystack, $mark) === 0) {
                return true;
            }
        }
        $infix = [
            '怎么选', '哪个好', '哪个牌子', '哪个更适合', '值得买吗', '选哪个', '如何选',
            // 疑问词出现在句中也是疑问式标题。系统设计 §2.2 给的样例
            // 「冰溪修护霜测评：烂脸期能不能扛」就是这种 —— 只按首尾判会漏掉，
            // 进而少算 R-3 的疑问宾语覆盖集。
            '能不能', '会不会', '值不值', '真的吗', '是什么', '为什么', '怎么', '如何',
        ];
        foreach ($infix as $mark) {
            if (mb_strpos($haystack, $mark) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 疑问宾语归组（R-3）。一个标题可能同时问两件事，返回多组。
     *
     * @return list<string>
     */
    public static function questionObjectGroups(string $haystack): array
    {
        $groups = [];
        if (GeoTitleQuestionIndustry::hits($haystack, ['怎么选', '选哪个', '哪个好', '哪个牌子', '哪个更适合', '如何选', '选购', '推荐'])) {
            $groups[] = 'select';
        }
        if (GeoTitleQuestionIndustry::hits($haystack, ['能用吗', '能不能用', '能不能', '可以用吗', '适合', '可以用'])) {
            $groups[] = 'fit';
        }
        if (GeoTitleQuestionIndustry::hits($haystack, ['值不值', '值得买吗', '值得买', '性价比'])) {
            $groups[] = 'worth';
        }
        if (GeoTitleQuestionIndustry::hits($haystack, ['真的有用', '好用吗', '有用吗', '效果怎么样'])) {
            $groups[] = 'effect';
        }
        if (GeoTitleQuestionIndustry::hits($haystack, ['为什么', '是什么', '什么原因', '原因', '原理'])) {
            $groups[] = 'cause';
        }
        if ($groups === []) {
            // 兜底：认得出是疑问句但认不出宾语时，按「评估效果」处理
            // （15 格里 review:S3 是最通用的一格，不会把清单撑得离谱）
            $groups[] = 'effect';
        }

        return array_values(array_unique($groups));
    }

    // =========================================================================
    // 占位符池 & 可用格
    // =========================================================================

    /**
     * 建占位符池。取不到的占位符值为 null，由 availableCells() 负责跳过。
     *
     * @param  array<string, list<string>>  $entities
     * @param  list<string>  $productWords
     * @param  array<string,mixed>  $brand
     * @return array<string,string|null>
     */
    public static function buildPool(array $entities, array $productWords, string $ownName, array $brand): array
    {
        $category = $entities['category'];
        $ingredient = $entities['ingredient'];
        $competitor = $entities['competitor'];

        $firstCategory = $category[0] ?? '';
        $firstIngredient = $ingredient[0] ?? '';
        $firstCompetitor = $competitor[0] ?? '';
        $firstProduct = $productWords[0] ?? '';

        // {品类2}：品类阶梯上第一个「与本篇不同且标题里没出现」的词
        $category2 = '';
        foreach (GeoTitleQuestionIndustry::categoryLadder() as $cand) {
            if ($cand === $firstCategory) {
                continue;
            }
            if (in_array($cand, $category, true)) {
                continue;
            }
            $category2 = $cand;
            break;
        }

        // {成分2}：只认标题里出现的第二个成分。
        //
        // 为什么不用成分词表兜底：C1 是硬判据 —— 问题里出现标题没有的具名成分
        // 一律判「抢不到」。从词表里编一个成分进来，生成出来的每一条都会被
        // C1 打掉，白占一个候选名额。宁可让「{成分}和{成分2}哪个更温和」
        // 这条模板在单成分标题下不生成。
        $ingredient2 = $ingredient[1] ?? '';

        // {竞品产品} = {竞品} + {品类}，如「薇诺娜修护霜」。
        // 刻意不去查竞品产品档案：档案里没有这个字段（R1），
        // 而真实提问习惯就是「品牌+品类」，拼接比留空更接近真人说法。
        $competitorProduct = '';
        if ($firstCompetitor !== '') {
            $tail = $firstCategory !== '' ? $firstCategory : $firstProduct;
            $competitorProduct = $firstCompetitor.$tail;
        }

        // {其他产品}：使用兼容性场景要的「另一个产品」，取品类阶梯上的下一个
        $otherProduct = $category2;

        // 决策 1：{肤质}/{人群}/{场景} 取不到时可用档案默认受众兜底，
        // 但标题优先；两者都空 → 该格不生成（绝不填「敏感肌」这种通用词）
        $archiveAudience = self::archiveAudience($brand);
        $skinType = $entities['skin_type'][0] ?? '';
        if ($skinType === '') {
            $skinType = $archiveAudience;
        }
        $crowd = $entities['crowd'][0] ?? '';

        return [
            '{品牌}' => $ownName !== '' ? $ownName : null,
            '{产品}' => $firstProduct !== '' ? $firstProduct : null,
            '{成分}' => $firstIngredient !== '' ? $firstIngredient : null,
            '{成分2}' => $ingredient2 !== '' ? $ingredient2 : null,
            '{竞品}' => $firstCompetitor !== '' ? $firstCompetitor : null,
            '{竞品产品}' => $competitorProduct !== '' ? $competitorProduct : null,
            '{品类}' => $firstCategory !== '' ? $firstCategory : null,
            '{品类2}' => $category2 !== '' ? $category2 : null,
            '{肤质}' => $skinType !== '' ? $skinType : null,
            '{人群}' => $crowd !== '' ? $crowd : null,
            '{症状}' => ($entities['symptom'][0] ?? '') !== '' ? $entities['symptom'][0] : null,
            '{场景}' => ($entities['scene'][0] ?? '') !== '' ? $entities['scene'][0] : null,
            '{其他产品}' => $otherProduct !== '' ? $otherProduct : null,
        ];
    }

    /**
     * 算可用格 + 跳过格。
     *
     * 判定粒度是**模板**：只要某格有一套模板的占位符全取到值，这格就可用。
     * 按格取并集会让「{品类}是什么 有必要用吗」因为同格的
     * 「{品类}和{品类2}有什么区别」缺 {品类2} 而整格消失 —— 那是明显的浪费。
     *
     * @param  array<string,string|null>  $pool
     * @param  list<string>  $coverage   C2 白名单；空数组表示不限（理论上不会走到）
     * @return array{0:list<string>, 1:list<array{cell:string,reason:string}>, 2:array<string,mixed>}
     */
    public static function availableCells(array $pool, array $coverage): array
    {
        $available = [];
        $skipped = [];
        $detail = [];

        foreach (GeoTitleQuestionIndustry::cells() as $cell) {
            $key = (string) $cell['key'];
            $enabled = ($cell['enabled'] ?? false) === true;

            if (! $enabled) {
                $skipped[] = ['cell' => $key, 'reason' => '该格已被运营关闭（enabled=false）'];
                continue;
            }

            // 先看占位符，再看 C2：这样 skipped_cells 里的原因是
            // 「缺 {成分}」而不是「不被动作覆盖」—— 后者对运营没有行动价值，
            // 运营不能靠改标题以外的手段补上一个「成分」。
            $usableTemplates = [];
            $missingUnion = [];
            foreach ((array) $cell['templates'] as $tpl) {
                $missing = [];
                foreach ((array) $tpl['slots'] as $ph) {
                    $ph = (string) $ph;
                    if (! isset($pool[$ph]) || $pool[$ph] === null || $pool[$ph] === '') {
                        $missing[] = $ph;
                    }
                }
                if ($missing === []) {
                    $usableTemplates[] = $tpl;
                } else {
                    foreach ($missing as $m) {
                        $missingUnion[$m] = true;
                    }
                }
            }

            if ($usableTemplates === []) {
                $skipped[] = [
                    'cell' => $key,
                    'reason' => '缺少可用占位符 '.implode('、', array_keys($missingUnion)),
                ];
                continue;
            }

            $detail[$key] = [
                'cell' => $key,
                'angle' => $cell['angle'],
                'stage' => $cell['stage'],
                'priority' => $cell['priority'],
                'note' => $cell['note'],
                'in_coverage' => in_array($key, $coverage, true),
                'templates' => array_map(
                    static fn (array $t): array => [
                        'tpl' => (string) $t['tpl'],
                        'class' => (string) $t['class'],
                        'intent' => (string) $t['intent'],
                    ],
                    $usableTemplates
                ),
            ];

            if (! in_array($key, $coverage, true)) {
                // 占位符齐了但标题没承诺这个角度：记下来但不进可用格。
                // 这类"空转"和缺占位符的空转原因完全不同，运营看页面能分清。
                $skipped[] = [
                    'cell' => $key,
                    'reason' => '标题未承诺该角度（动作词覆盖集不含此格）',
                ];
                continue;
            }

            $available[] = $key;
        }

        return [$available, $skipped, $detail];
    }

    // =========================================================================
    // 内部工具
    // =========================================================================

    /** 字符串 → 字符数组（UTF-8 安全） */
    private static function chars(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($chars) ? $chars : [];
    }

    /**
     * 关键词列表 → [needle => canonical]。
     *
     * @param  array<string,string>  $map
     * @return array<string,string> 已按 needle 字符数倒序
     */
    private static function needleMap(array $map): array
    {
        $clean = [];
        foreach ($map as $needle => $canonical) {
            $needle = mb_strtolower(trim((string) $needle));
            $canonical = trim((string) $canonical);
            if ($needle === '' || $canonical === '') {
                continue;
            }
            $clean[$needle] = $canonical;
        }
        uksort($clean, static fn ($a, $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        return $clean;
    }

    /**
     * 在字符数组上做「最长优先 + 占位掩码」扫描。
     *
     * @param  list<string>  $chars
     * @param  array<string,string>  $needles
     * @param  array<int,bool>  $occupied  跨类型共享的占位表，同一次 parse 内传引用
     * @return list<string> 命中的 canonical 值（去重、保持命中顺序）
     */
    private static function scanChars(array $chars, array $needles, array &$occupied): array
    {
        $len = count($chars);
        $found = [];

        foreach ($needles as $needle => $canonical) {
            $nChars = self::chars((string) $needle);
            $n = count($nChars);
            if ($n === 0 || $n > $len) {
                continue;
            }
            for ($i = 0; $i + $n <= $len; $i++) {
                $ok = true;
                for ($j = 0; $j < $n; $j++) {
                    if (isset($occupied[$i + $j]) || $chars[$i + $j] !== $nChars[$j]) {
                        $ok = false;
                        break;
                    }
                }
                if (! $ok) {
                    continue;
                }
                for ($j = 0; $j < $n; $j++) {
                    $occupied[$i + $j] = true;
                }
                if (! in_array($canonical, $found, true)) {
                    $found[] = $canonical;
                }
            }
        }

        return $found;
    }

    /**
     * 词表 → [word => word]
     *
     * @param  list<string>  $words
     * @return array<string,string>
     */
    private static function selfMap(array $words): array
    {
        $map = [];
        foreach ($words as $w) {
            $w = (string) $w;
            if ($w !== '') {
                $map[$w] = $w;
            }
        }

        return $map;
    }

    /** 自家品牌的所有写法 → 统一成档案里的 name（展示用主名，不用别名） */
    public static function ownBrandNeedles(array $brand): array
    {
        $canonical = self::ownBrandName($brand);
        if ($canonical === '') {
            return [];
        }
        $map = [$canonical => $canonical];
        foreach (['name', 'full_name'] as $k) {
            $v = trim((string) ($brand[$k] ?? ''));
            if ($v !== '') {
                $map[$v] = $canonical;
            }
        }
        foreach ((array) ($brand['aliases'] ?? []) as $alias) {
            $alias = trim((string) $alias);
            if ($alias !== '') {
                $map[$alias] = $canonical;
            }
        }

        return $map;
    }

    /** 竞品的所有写法 → 统一成竞品 name */
    public static function competitorNeedles(array $brand): array
    {
        $map = [];
        foreach ((array) ($brand['competitors'] ?? []) as $comp) {
            if (! is_array($comp)) {
                continue;
            }
            $name = trim((string) ($comp['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $map[$name] = $name;
            foreach ((array) ($comp['aliases'] ?? []) as $alias) {
                $alias = trim((string) $alias);
                if ($alias !== '') {
                    $map[$alias] = $name;
                }
            }
        }

        return $map;
    }

    /**
     * 档案里的产品清单（P1 精度提升用；档案没这个字段时返回空，
     * 由 {产品} 回退到标题里的品类词 —— R1 方案 C）。
     */
    public static function brandProductNeedles(array $brand): array
    {
        $map = [];
        foreach ((array) ($brand['products'] ?? []) as $p) {
            $name = is_array($p) ? trim((string) ($p['name'] ?? '')) : trim((string) $p);
            if ($name !== '') {
                $map[$name] = $name;
            }
            if (is_array($p)) {
                foreach ((array) ($p['aliases'] ?? []) as $alias) {
                    $alias = trim((string) $alias);
                    if ($alias !== '') {
                        $map[$alias] = $name;
                    }
                }
            }
        }

        return $map;
    }

    /** 档案里的竞品名列表（用于页面展示和 prompt） */
    public static function competitorWords(array $brand): array
    {
        $out = [];
        foreach ((array) ($brand['competitors'] ?? []) as $comp) {
            if (is_array($comp)) {
                $name = trim((string) ($comp['name'] ?? ''));
                if ($name !== '') {
                    $out[] = $name;
                }
            }
        }

        return $out;
    }

    /** 自家品牌主名 */
    public static function ownBrandName(array $brand): string
    {
        return trim((string) ($brand['name'] ?? ''));
    }

    /**
     * 档案默认受众。
     *
     * 档案目前没有 audience 字段（只有 aliases/competitors），
     * 所以这里返回空 —— 按决策 1「两者都空 → 该格不生成」处理。
     * 将来档案加了 audience，这个方法自动生效，不用改其余代码。
     */
    private static function archiveAudience(array $brand): string
    {
        $a = $brand['audience'] ?? null;
        if (is_string($a)) {
            return trim($a);
        }
        if (is_array($a)) {
            foreach ($a as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    /**
     * 合并两个词列表并去重（保持顺序）。
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private static function mergeUnique(array $a, array $b): array
    {
        $out = [];
        foreach (array_merge($a, $b) as $v) {
            $v = (string) $v;
            if ($v !== '' && ! in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
