<?php

namespace App\Support\GeoAudit;

/**
 * 规则内核自测（CLI，纯函数断言，不碰 DB、不碰模型）。
 *
 * 为什么要有这个文件：
 *   C1/C2 是硬判据，判错一条就把一条真实需求踢出清单，而且线上表现是
 *   「清单少了几条」—— 没人会注意到。所以规则文档 §3.5 的每个边界 case
 *   都必须有一条固定断言钉住，改规则时先跑这里。
 *
 * 独立运行（不需要 Laravel）：
 *   php overrides/GeoTitleQuestionSelftest.php
 *   docker exec geoflow-app-prod php /var/www/html/app/Support/GeoAudit/GeoTitleQuestionSelftest.php
 *
 * 退出码：0 = 全绿，1 = 有失败（可直接挂 CI / 发版前钩子）。
 */
final class GeoTitleQuestionSelftest
{
    // =========================================================================
    // 品牌档案 fixture
    // =========================================================================

    /**
     * 冰溪档案（与 storage/app/geo-brands.json 的第一条一致）。
     *
     * 刻意内联而不读 json 文件：自测要在没有 Laravel、没有 storage 的情况下
     * 也能跑。档案结构一旦变了，这里跟着改，顺便强迫人复核下游有没有依赖。
     *
     * @return array<string,mixed>
     */
    public static function fixtureBrand(): array
    {
        return [
            'slug' => 'binsis',
            'name' => '冰溪',
            'full_name' => '冰溪生物',
            'site_url' => 'https://www.binsis.cn',
            'aliases' => ['冰溪', '冰溪生物', 'binsis', '冰溪护肤'],
            'competitors' => [
                ['name' => '薇诺娜', 'aliases' => ['薇诺娜', 'Winona']],
                ['name' => '理肤泉', 'aliases' => ['理肤泉', 'La Roche-Posay', 'LaRochePosay']],
                ['name' => '雅漾', 'aliases' => ['雅漾', 'Avène', 'Avene']],
                ['name' => '玉泽', 'aliases' => ['玉泽']],
                ['name' => '至本', 'aliases' => ['至本']],
            ],
        ];
    }

    // =========================================================================
    // 主入口
    // =========================================================================

    /**
     * 跑全部断言。
     *
     * @return array{total:int, passed:int, failed:int, rows:list<array<string,mixed>>}
     */
    public static function run(): array
    {
        $brand = self::fixtureBrand();
        $rows = [];

        // =====================================================================
        // 一、规则文档 §3.5 边界 case（BC-1 ~ BC-13）
        // =====================================================================
        $rows[] = self::assertLabel(
            'BC-1 冰溪和薇诺娜哪个好 → D + S3a + 对比 + 商业调查',
            '冰溪和薇诺娜哪个好',
            $brand,
            ['class' => 'D', 'stage' => 'S3a', 'intent' => 'commercial']
        );

        $rows[] = self::assertLabel(
            'BC-2 敏感肌修护霜推荐 → B + S2 + 商业调查',
            '敏感肌修护霜推荐',
            $brand,
            ['class' => 'B', 'stage' => 'S2', 'intent' => 'commercial']
        );

        $rows[] = self::assertLabel(
            'BC-3 冰溪修护霜能叠加酸吗 → C + S5 + 信息',
            '冰溪修护霜能叠加酸吗',
            $brand,
            ['class' => 'C', 'stage' => 'S5', 'intent' => 'informational']
        );

        $rows[] = self::assertLabel(
            'BC-4a 修护霜哪个牌子好 → S2（哪个牌子是未知候选）',
            '修护霜哪个牌子好',
            $brand,
            ['stage' => 'S2']
        );

        $rows[] = self::assertLabel(
            'BC-4b 冰溪修护霜好用吗 → S3a（具名产品 + 评估词）',
            '冰溪修护霜好用吗',
            $brand,
            ['class' => 'C', 'stage' => 'S3a']
        );

        $rows[] = self::assertLabel(
            'BC-5 用了刺痛要不要退货 → S5（不是 S3b）',
            '用了刺痛要不要退货',
            $brand,
            ['stage' => 'S5']
        );

        $rows[] = self::assertLabel(
            'BC-6 冰溪修护霜贵不贵 → S3a + 商业调查（不是 S4、也不是 S3b）',
            '冰溪修护霜贵不贵',
            $brand,
            ['stage' => 'S3a', 'intent' => 'commercial']
        );

        $rows[] = self::assertLabel(
            'BC-7a 冰溪是什么牌子 → A + 信息（不是导航）',
            '冰溪是什么牌子',
            $brand,
            ['class' => 'A', 'intent' => 'informational']
        );

        $rows[] = self::assertLabel(
            'BC-7b 冰溪官网 → A + 导航（站点指向词双条件成立）',
            '冰溪官网',
            $brand,
            ['class' => 'A', 'intent' => 'navigational']
        );

        // BC-9：隐藏费用 → A + S3b + 价格 + 商业调查，content_type=price_transparency
        $bc9 = GeoTitleQuestionRules::makeItem('冰溪有没有隐藏费用', self::emptySlots(), $brand);
        $rows[] = self::assertCase(
            'BC-9 冰溪有没有隐藏费用 → S3b + A + 价格 + price_transparency',
            $bc9['stage'] === 'S3b' && $bc9['class'] === 'A' && $bc9['angle'] === 'pricing'
                && $bc9['concern'] === '价格',
            'stage=S3b class=A angle=pricing concern=价格',
            'stage='.$bc9['stage'].' class='.$bc9['class'].' angle='.$bc9['angle'].' concern='.$bc9['concern']
        );

        // BC-10：靠谱吗 → A + S3b + 信任
        $bc10 = GeoTitleQuestionRules::makeItem('冰溪靠谱吗', self::emptySlots(), $brand);
        $rows[] = self::assertCase(
            'BC-10 冰溪靠谱吗 → A + S3b + 信任 + 真实性',
            $bc10['stage'] === 'S3b' && $bc10['class'] === 'A' && $bc10['angle'] === 'trust'
                && $bc10['concern'] === '真实性',
            'stage=S3b class=A angle=trust concern=真实性',
            'stage='.$bc10['stage'].' class='.$bc10['class'].' angle='.$bc10['angle'].' concern='.$bc10['concern']
        );

        // BC-11：同一个风险词，带具体产品 → C
        $bc11 = GeoTitleQuestionRules::makeItem('冰溪修护霜靠谱吗', self::emptySlots(), $brand);
        $rows[] = self::assertCase(
            'BC-11 冰溪修护霜靠谱吗 → C + S3b（带产品名降 C）',
            $bc11['stage'] === 'S3b' && $bc11['class'] === 'C',
            'stage=S3b class=C',
            'stage='.$bc11['stage'].' class='.$bc11['class']
        );

        // BC-12：两个品牌比售后 → D + S3a + 信任（进不了 S3b）
        $bc12 = GeoTitleQuestionRules::makeItem('冰溪和薇诺娜哪个售后好', self::emptySlots(), $brand);
        $rows[] = self::assertCase(
            'BC-12 冰溪和薇诺娜哪个售后好 → D + S3a + 信任',
            $bc12['stage'] === 'S3a' && $bc12['class'] === 'D' && $bc12['angle'] === 'trust',
            'stage=S3a class=D angle=trust',
            'stage='.$bc12['stage'].' class='.$bc12['class'].' angle='.$bc12['angle']
        );

        // BC-13：有假货吗 → A + S3b（无渠道词）；哪里买是正品 → S4
        $bc13a = GeoTitleQuestionRules::makeItem('冰溪有假货吗', self::emptySlots(), $brand);
        $bc13b = GeoTitleQuestionRules::makeItem('冰溪哪里买是正品', self::emptySlots(), $brand);
        $rows[] = self::assertCase(
            'BC-13 有假货吗 → S3b；哪里买是正品 → S4（渠道词共现）',
            $bc13a['stage'] === 'S3b' && $bc13a['class'] === 'A' && $bc13b['stage'] === 'S4',
            'S3b/A + S4',
            $bc13a['stage'].'/'.$bc13a['class'].' + '.$bc13b['stage']
        );

        // =====================================================================
        // 二、v3 验收五条（架构师给的，能钉住 S3b / concern 优先级）
        // =====================================================================
        $newCases = [
            '冰溪售后怎么样' => ['stage' => 'S3b', 'concern' => '售后', 'cell' => 'trust:S3b', 'content_type' => 'prospectus_and_policy'],
            // 题面是「钱」，但**落哪一格取决于标题承诺**：标题不提价格维度时
            // C2 只放行 trust:S3b，于是 angle 被 cell 覆写成 trust。
            // content_type 单独用「标题承诺了价格」的用例断言（见下面 price_transparency 那条）。
            '冰溪有隐藏费用吗' => ['stage' => 'S3b', 'concern' => '价格', 'angle' => 'trust'],
            '冰溪的评价是真的吗' => ['stage' => 'S3b', 'concern' => '真实性', 'cell' => 'review:S3b'],
            '冰溪是正规的吗 有资质吗' => ['stage' => 'S3b', 'concern' => '真实性'],
            // 时效性必须排在效果之前，否则「多久见效」会被「见效」吃掉
            '冰溪修护霜多久见效' => ['concern' => '时效性'],
        ];
        $bad = [];
        foreach ($newCases as $q => $expect) {
            $item = self::judgeWithTitle($q, '冰溪修护霜测评', $brand);
            foreach ($expect as $field => $want) {
                if ((string) ($item[$field] ?? '') !== (string) $want) {
                    $bad[] = $q.' 的 '.$field.'='.var_export($item[$field] ?? null, true).'（期望 '.$want.'）';
                }
            }
        }
        $rows[] = self::assertCase(
            'v3 验收五条（售后/隐藏费用/评价真假/资质/时效性优先级）',
            $bad === [],
            '全部命中',
            $bad === [] ? '全部命中' : implode('；', $bad)
        );

        // 价格×S3b 可达性：标题必须承诺价格维度 + 带信任词，
        // 否则这一格与 price_transparency 会变成永远不会被产出的死值。
        $priceSlots = GeoTitleQuestionSlots::parse('冰溪修护霜多少钱 有隐藏费用吗', $brand);
        $priceItem = GeoTitleQuestionRules::makeItem('冰溪有隐藏费用吗', $priceSlots, $brand);
        $rows[] = self::assertCase(
            '价格×S3b 可达：标题承诺价格+含隐藏费用 → pricing:S3b / price_transparency',
            $priceItem['cell'] === 'pricing:S3b' && $priceItem['content_type'] === 'price_transparency',
            'cell=pricing:S3b content_type=price_transparency',
            'cell='.$priceItem['cell'].' content_type='.$priceItem['content_type']
        );

        // =====================================================================
        // 三、C1 反例 + 去重 + 排序 + R-1
        // =====================================================================
        $pureCategory = GeoTitleQuestionSlots::parse('修护霜怎么选', $brand);
        $verdict = GeoTitleQuestionRules::judge('冰溪和薇诺娜哪个好', $pureCategory, $brand);
        $rows[] = self::assertCase(
            'C1 反例 标题「修护霜怎么选」抢不到「冰溪和薇诺娜哪个好」',
            $verdict['fit'] === null && $verdict['drop_reason'] === 'C1',
            'fit=null, drop_reason=C1',
            'fit='.var_export($verdict['fit'], true).', drop_reason='.var_export($verdict['drop_reason'], true)
        );

        $deduped = GeoTitleQuestionRules::dedupe([
            ['q' => '冰溪修护霜油皮能用吗'],
            ['q' => '冰溪修护霜干皮能用吗'],
        ]);
        $rows[] = self::assertCase(
            '去重 只差肤质的两条合并成一条',
            count($deduped) === 1,
            '1 条',
            count($deduped).' 条'
        );

        $sorted = GeoTitleQuestionRules::sortForDisplay([
            ['q' => '换季起皮是什么原因', 'angle' => 'definition', 'stage' => 'S1', 'class' => 'B'],
            ['q' => '冰溪靠谱吗', 'angle' => 'trust', 'stage' => 'S3b', 'class' => 'A'],
            ['q' => '冰溪修护霜好用吗', 'angle' => 'review', 'stage' => 'S3a', 'class' => 'C'],
        ]);
        $rows[] = self::assertCase(
            '排序 S3b > S3a > S1（投入产出比，不是阶段顺序）',
            ($sorted[0]['stage'] ?? '') === 'S3b' && ($sorted[1]['stage'] ?? '') === 'S3a' && ($sorted[2]['stage'] ?? '') === 'S1',
            'S3b, S3a, S1',
            ($sorted[0]['stage'] ?? '').', '.($sorted[1]['stage'] ?? '').', '.($sorted[2]['stage'] ?? '')
        );

        // R-1 v3：A+S3b → own_source，且**计入射程**；A+其它 → source_build，不计入
        $brandOnly = GeoTitleQuestionSlots::parse('冰溪修护霜', $brand);
        $r1a = GeoTitleQuestionRules::judge('冰溪是什么牌子', $brandOnly, $brand);
        $r1b = GeoTitleQuestionRules::judge('冰溪售后怎么样', $brandOnly, $brand);
        $rows[] = self::assertCase(
            'R-1 A+S3b → own_source（计入射程）；A+其它 → source_build',
            $r1a['route'] === 'source_build' && $r1b['route'] === 'own_source',
            'source_build / own_source',
            var_export($r1a['route'], true).' / '.var_export($r1b['route'], true)
        );

        $scopedStats = GeoTitleQuestionRules::stats([$r1b], []);
        $rows[] = self::assertCase(
            'R-1 A+S3b 的 own_source 计入本篇射程（scoped=1）',
            $scopedStats['scoped'] === 1,
            'scoped=1',
            'scoped='.$scopedStats['scoped']
        );

        // =====================================================================
        // 四、22 格注册表 + 22 code 双射
        // =====================================================================
        $cells = GeoTitleQuestionIndustry::cells();
        $keys = array_map(static fn (array $c): string => (string) $c['key'], $cells);
        $rows[] = self::assertCase(
            '22 格注册表恰好 22 格且 key 无重复',
            count($cells) === 22 && count(array_unique($keys)) === 22,
            '22 格 / 22 个唯一 key',
            count($cells).' 格 / '.count(array_unique($keys)).' 个唯一 key'
        );

        $expectedCells = [
            'definition:S1', 'definition:S2', 'definition:S3a',
            'comparison:S2', 'comparison:S3a',
            'pricing:S2', 'pricing:S3a', 'pricing:S3b', 'pricing:S4',
            'review:S2', 'review:S3a', 'review:S3b', 'review:S5',
            'use_case:S1', 'use_case:S2', 'use_case:S3a', 'use_case:S5',
            'trust:S2', 'trust:S3a', 'trust:S3b', 'trust:S4', 'trust:S5',
        ];
        sort($keys);
        $expectedSorted = $expectedCells;
        sort($expectedSorted);
        $rows[] = self::assertCase(
            '22 格 angle:stage 组合与规则文档 §1.3 一一对应',
            $keys === $expectedSorted,
            implode(',', $expectedSorted),
            implode(',', $keys)
        );

        // 22 格 ↔ 22 code 严格 1:1 双射
        $codes = array_values(GeoTitleQuestionIndustry::contentTypeByCell());
        $codeSet = array_values(array_unique($codes));
        $bidirectional = count($codes) === 22
            && count($codeSet) === 22
            && count(array_diff($codeSet, GeoTitleQuestionStore::CONTENT_TYPES)) === 0
            && count(array_diff(GeoTitleQuestionStore::CONTENT_TYPES, $codeSet)) === 0;
        $rows[] = self::assertCase(
            '22 格 ↔ 22 个 content_type code 严格 1:1 双射（无死值、无重复）',
            $bidirectional,
            '双向完全相等',
            count($codeSet).' 个唯一 code；只出现在常量里的：'.implode(',', array_diff(GeoTitleQuestionStore::CONTENT_TYPES, $codeSet))
        );

        // trust 角度只产出 5 个 code（§1.4.3 的保险丝）
        $trustCodes = ['category_pitfall_guide', 'brand_reliability_comparison', 'prospectus_and_policy', 'authentic_channel_guide', 'counterfeit_rights_guide'];
        $badTrust = [];
        foreach ($cells as $cell) {
            if ((string) $cell['angle'] === 'trust' && ! in_array($cell['content_type'], $trustCodes, true)) {
                $badTrust[] = $cell['key'].'→'.$cell['content_type'];
            }
        }
        $rows[] = self::assertCase(
            'trust 角度只产出 5 个指定 code（防语义漂移的保险丝）',
            $badTrust === [],
            '无越界',
            $badTrust === [] ? '无越界' : implode('；', $badTrust)
        );

        // D 类模板必须含 {品牌}（§1.1 硬规则 2）
        $badD = [];
        foreach ($cells as $cell) {
            foreach ((array) $cell['templates'] as $tpl) {
                if ((string) $tpl['class'] === 'D' && mb_strpos((string) $tpl['tpl'], '{品牌}') === false) {
                    $badD[] = $cell['key'].'：'.$tpl['tpl'];
                }
            }
        }
        $rows[] = self::assertCase(
            'D 类模板必须含 {品牌}（否则生成出来是 C 类，白占 D 配额）',
            $badD === [],
            '无违反',
            $badD === [] ? '无违反' : implode('；', $badD)
        );

        // D 模板总数必须是 8（对比×S3a 4 + 信任×S3a 4），否则 D ≥ 3 结构上不可达
        $dTemplates = 0;
        foreach ($cells as $cell) {
            foreach ((array) $cell['templates'] as $tpl) {
                if ((string) $tpl['class'] === 'D') {
                    $dTemplates++;
                }
            }
        }
        $rows[] = self::assertCase(
            'D 类模板总数为 8（对比×S3a 4 + 信任×S3a 4），保证 D ≥ 3 可达',
            $dTemplates === 8,
            '8 个',
            $dTemplates.' 个'
        );

        // 喂模型的格数 = 20（22 格 - 价格×S2 - 对比×S2）
        // R14 拍板：22 格全进 prompt，本轮不精简。
        // 但必须验「这两格确实在 availableCells 里」，否则以后被 in_prompt=false
        // 挡住时看不出来（真实标题可能本来就不出题）。见下面 22 格可达性断言。
        $promptCells = GeoTitleQuestionIndustry::promptCellKeys();
        $rows[] = self::assertCase(
            '喂模型的格子数为 22（R14：本轮不精简）',
            count($promptCells) === 22
                && in_array('pricing:S2', $promptCells, true)
                && in_array('comparison:S2', $promptCells, true),
            '22 格，含 pricing:S2 / comparison:S2',
            count($promptCells).' 格'
        );

        // =====================================================================
        // 五、枚举与 DDL 一致性
        // =====================================================================
        $badAngle = [];
        $badStage = [];
        $badClass = [];
        foreach ($cells as $cell) {
            if (! in_array((string) $cell['angle'], GeoTitleQuestionStore::ANGLES, true)) {
                $badAngle[] = (string) $cell['key'].'(angle='.$cell['angle'].')';
            }
            if (! in_array((string) $cell['stage'], GeoTitleQuestionStore::STAGES, true)) {
                $badStage[] = (string) $cell['key'].'(stage='.$cell['stage'].')';
            }
            foreach ((array) $cell['templates'] as $tpl) {
                if (! in_array((string) $tpl['class'], GeoTitleQuestionStore::CLASSES, true)) {
                    $badClass[] = (string) $cell['key'].'(class='.$tpl['class'].')';
                }
                if (! in_array((string) $tpl['intent'], GeoTitleQuestionStore::INTENTS, true)) {
                    $badClass[] = (string) $cell['key'].'(intent='.$tpl['intent'].')';
                }
            }
        }
        $enumOk = $badAngle === [] && $badStage === [] && $badClass === [];
        $rows[] = self::assertCase(
            '枚举一致性 所有格子的 angle/stage/class/intent 都在枚举内',
            $enumOk,
            '无越界',
            $enumOk ? '无越界' : implode('；', array_merge($badAngle, $badStage, $badClass))
        );

        $stageEnumOk = GeoTitleQuestionStore::STAGES === ['S0', 'S1', 'S2', 'S3a', 'S3b', 'S4', 'S5'];
        $angleEnumOk = GeoTitleQuestionStore::ANGLES === ['definition', 'comparison', 'pricing', 'review', 'use_case', 'trust'];
        $noBareS3 = ! in_array('S3', GeoTitleQuestionStore::STAGES, true);
        $rows[] = self::assertCase(
            '枚举值 stage 7 值（含 S3a/S3b，无裸 S3）+ angle 6 值（含 trust）',
            $stageEnumOk && $angleEnumOk && $noBareS3,
            'stage=7 值无裸 S3；angle=6 值含 trust',
            'stage='.implode('/', GeoTitleQuestionStore::STAGES).'；angle='.implode('/', GeoTitleQuestionStore::ANGLES)
        );

        // 模型 JSON Schema 的枚举必须等于这里（不改 Schema 会静默丢数据）
        $schemaEnums = GeoTitleQuestionStore::jsonSchemaEnums();
        $rows[] = self::assertCase(
            '模型 JSON Schema 枚举 = MATRIX_STAGES / ANGLES（C5：不改会静默丢全部候选）',
            $schemaEnums['stage'] === GeoTitleQuestionStore::MATRIX_STAGES
                && $schemaEnums['angle'] === GeoTitleQuestionStore::ANGLES
                && ! in_array('S3', $schemaEnums['stage'], true),
            'stage=6 个矩阵阶段（含 S3a/S3b）；angle=6 个',
            'stage='.implode('/', $schemaEnums['stage'])
        );

        // DDL 的 CHECK 由常量生成
        $ddl = '';
        try {
            $ref = new \ReflectionMethod(GeoTitleQuestionStore::class, 'ddlQuestions');
            $ref->setAccessible(true);
            $ddl = (string) $ref->invoke(null);
        } catch (\Throwable $e) {
            $ddl = '';
        }
        $expectStageChk = "geo_tpq_stage_chk CHECK (stage IN ('S0','S1','S2','S3a','S3b','S4','S5'))";
        $expectAngleChk = "geo_tpq_angle_chk CHECK (angle IN ('definition','comparison','pricing','review','use_case','trust'))";
        $expectRouteChk = "geo_tpq_route_chk CHECK (route IN ('direct','sidelong','own_source','source_build'))";
        $checkOk = $ddl !== ''
            && mb_strpos($ddl, $expectStageChk) !== false
            && mb_strpos($ddl, $expectAngleChk) !== false
            && mb_strpos($ddl, $expectRouteChk) !== false
            && mb_strpos($ddl, 'geo_tpq_concern_chk') !== false
            && mb_strpos($ddl, 'geo_tpq_concern_alt_chk') !== false
            && mb_strpos($ddl, 'geo_tpq_ctype_chk') !== false
            && mb_strpos($ddl, 'geo_tpq_expected_len_chk') !== false
            && mb_strpos($ddl, 'concern         varchar(32)  NOT NULL') !== false
            && mb_strpos($ddl, 'content_type    varchar(32)  NOT NULL') !== false
            && mb_strpos($ddl, 'char_length(expected) BETWEEN 4 AND 20') !== false
            && mb_strpos($ddl, 'angles_touched  jsonb        NOT NULL') !== false
            && mb_strpos($ddl, 'geo_tpq_touched_chk') !== false;
        $rows[] = self::assertCase(
            'DDL 的 CHECK 由枚举常量生成（7 值 stage / 6 值 angle / 4 值 route / 4 条新 CHECK / 3 列 NOT NULL）',
            $checkOk,
            'CHECK 文本与常量逐字一致',
            $checkOk ? '一致' : '不一致（ddlQuestions() 里找不到预期片段）'
        );

        // expected 文案长度必须落在 CHECK 的 6..200 里
        $badExpected = [];
        foreach ($cells as $cell) {
            $len = mb_strlen((string) $cell['expected']);
            if ($len < 4 || $len > 20) {
                $badExpected[] = $cell['key'].'('.$len.' 字)';
            }
            if (mb_strpos((string) $cell['expected'], '{') !== false) {
                $badExpected[] = $cell['key'].'(含占位符)';
            }
        }
        $rows[] = self::assertCase(
            'expected 全部 4–20 字且不含占位符（与 CHECK 一致）',
            $badExpected === [],
            '22 条全部合规',
            $badExpected === [] ? '22 条全部合规' : implode('；', $badExpected)
        );

        // content_type = prospectus_and_policy 时必须带固定提示
        $ppCell = null;
        foreach ($cells as $cell) {
            if ($cell['content_type'] === 'prospectus_and_policy') {
                $ppCell = $cell;
                break;
            }
        }
        $hintOk = $ppCell !== null
            && GeoTitleQuestionIndustry::PROSPECTUS_HINT !== ''
            && mb_strpos(GeoTitleQuestionIndustry::PROSPECTUS_HINT, '资质页') !== false;
        $rows[] = self::assertCase(
            'prospectus_and_policy 有固定提示「需要资质页/售后政策页」',
            $hintOk,
            '提示文案非空且含「资质页」',
            $hintOk ? '已配置' : '缺失'
        );

        // =====================================================================
        // 六、防回退条款：测评型标题可用模板槽必须 ≥ 5
        // =====================================================================
        //
        // 规则文档 §2.1 防回退条款：一删「测评 → 信任×S3b」，测评型标题立刻
        // 退回 4 条、必然触发下限告警。这条断言就是那根保险丝。
        $reviewSlots = self::availableTemplateSlots(
            array_merge(
                (array) (GeoTitleQuestionIndustry::actionCoverage()['测评']['cover'] ?? []),
                (array) (GeoTitleQuestionIndustry::actionCoverage()['测评']['cover_on_slot'] ?? [])
            ),
            ['{品牌}' => '冰溪', '{产品}' => '修护霜', '{品类}' => '修护霜', '{品类2}' => '面霜', '{场景}' => '烂脸期', '{其他产品}' => '面霜']
        );
        $rows[] = self::assertCase(
            '防回退 测评型标题可用模板槽 ≥ 5（删掉 信任×S3b 会立刻不达标）',
            $reviewSlots >= 5,
            '≥ 5 个槽',
            $reviewSlots.' 个槽'
        );

        // =====================================================================
        // 七、titleHashOf / declaration
        // =====================================================================
        $h1 = GeoTitleQuestionStore::titleHashOf(' 冰溪 修护霜 ');
        $h2 = GeoTitleQuestionStore::titleHashOf('冰溪修护霜');
        $h3 = GeoTitleQuestionStore::titleHashOf('冰溪修护霜（2026）');
        $rows[] = self::assertCase(
            'titleHashOf 空白差异归一到同值，括号年份不归一',
            $h1 === $h2 && $h1 !== $h3,
            'h1==h2 且 h1!=h3',
            ($h1 === $h2 ? 'h1==h2' : 'h1!=h2').' / '.($h1 !== $h3 ? 'h1!=h3' : 'h1==h3')
        );

        $rows[] = self::assertCase(
            'declaration 常量在 Store 与 Industry 两处一致',
            GeoTitleQuestionStore::DECLARATION === GeoTitleQuestionIndustry::DECLARATION,
            '两处文本逐字相同',
            GeoTitleQuestionStore::DECLARATION === GeoTitleQuestionIndustry::DECLARATION
                ? '相同' : '不同（页面会显示两种口径的免责声明）'
        );

        // =====================================================================
        // 八、槽位解析实战
        // =====================================================================
        $slots = GeoTitleQuestionSlots::parse('冰溪修护霜测评：烂脸期能不能扛', $brand);
        $slotsOk = ($slots['entities']['brand'] ?? []) === ['冰溪']
            && ($slots['entities']['product'] ?? []) === ['修护霜']
            && in_array('测评', (array) ($slots['action'] ?? []), true)
            && ($slots['scene'] ?? []) === ['烂脸期']
            && ($slots['is_question_title'] ?? false) === true;
        $rows[] = self::assertCase(
            '槽位解析 冰溪修护霜测评：烂脸期能不能扛',
            $slotsOk,
            'brand=[冰溪] product=[修护霜] action⊇[测评] scene=[烂脸期] is_question=true',
            'brand=['.implode(',', (array) ($slots['entities']['brand'] ?? [])).'] '
            .'product=['.implode(',', (array) ($slots['entities']['product'] ?? [])).'] '
            .'action=['.implode(',', (array) ($slots['action'] ?? [])).'] '
            .'scene=['.implode(',', (array) ($slots['scene'] ?? [])).'] '
            .'is_question='.var_export((bool) ($slots['is_question_title'] ?? false), true)
        );

        $coverage = (array) ($slots['coverage_cells'] ?? []);
        sort($coverage);
        $rows[] = self::assertCase(
            'C2 覆盖集 测评标题 = review:S3a + review:S3b + trust:S3b + use_case:S3a',
            $coverage === ['review:S3a', 'review:S3b', 'trust:S3b', 'use_case:S3a'],
            'review:S3a,review:S3b,trust:S3b,use_case:S3a',
            implode(',', $coverage)
        );

        // R-2：标题不含交易词时交易型降级
        $trxItem = [
            'q' => '冰溪修护霜多少钱', 'angle' => 'pricing', 'stage' => 'S4', 'class' => 'C',
            'intent' => 'transactional', 'route' => 'direct', 'fit' => 'can', 'cell' => 'pricing:S4',
        ];
        $validated = GeoTitleQuestionRules::validate(array_merge([$trxItem], self::paddingItems(4)), $slots);
        $trxInPrimary = 0;
        foreach ($validated['primary'] as $it) {
            if (($it['intent'] ?? '') === 'transactional') {
                $trxInPrimary++;
            }
        }
        $rows[] = self::assertCase(
            'R-2 标题无交易词时主清单交易型 ≤ 1',
            $trxInPrimary <= 1,
            '≤ 1 条',
            $trxInPrimary.' 条'
        );

        // =====================================================================
        // 九、防退化：动作覆盖槽 / D 双出口 / D 配额分配 / 射程虚高
        // =====================================================================
        $cellsByKey = GeoTitleQuestionIndustry::cellsByKey();

        // 断言 6：每个动作词的覆盖集可用模板槽必须 ≥ 5。
        // 任何人删格、或改 actionCoverage() 导致某动作覆盖不足，这里立刻红，
        // 不用等到线上跑出一个假的「射程过窄」warning。
        $badActionSlots = [];
        foreach (GeoTitleQuestionIndustry::actionCoverage() as $action => $cfg) {
            $coverCells = array_unique(array_merge(
                (array) ($cfg['cover'] ?? []),
                (array) ($cfg['cover_on_slot'] ?? []),
                (array) ($cfg['cover_on_multi_brand'] ?? []),
                (array) ($cfg['cover_on_trust_word'] ?? [])
            ));
            $slotCount = 0;
            foreach ($coverCells as $ck) {
                $slotCount += count((array) ($cellsByKey[(string) $ck]['templates'] ?? []));
            }
            if ($slotCount < 5) {
                $badActionSlots[] = $action.'('.$slotCount.' 槽)';
            }
        }
        $rows[] = self::assertCase(
            '每个动作词的覆盖集可用模板槽 ≥ 5（否则 n<5 是矩阵缺格，不是标题窄）',
            $badActionSlots === [],
            '全部 ≥ 5 槽',
            $badActionSlots === [] ? '全部 ≥ 5 槽' : '不足：'.implode('、', $badActionSlots)
        );

        // 断言 7：comparison:S3a 与 trust:S3a 两个 D 出口必须都在。
        // 它们都是 S3a + D，看起来确实像重复，下一个人很可能想「去重」删一个。
        // 删了 D 模板从 8 降到 4，D ≥ 3 立刻物理不可达。
        $hasCmpD = isset($cellsByKey['comparison:S3a']);
        $hasTrustD = isset($cellsByKey['trust:S3a']);
        $rows[] = self::assertCase(
            'D 类两个出口都在（对比×S3a 答主问题 / 信任×S3a 答细分维度）',
            $hasCmpD && $hasTrustD,
            '两个出口都在',
            ($hasCmpD ? '有 comparison:S3a' : '缺 comparison:S3a')
                .' / '.($hasTrustD ? '有 trust:S3a' : '缺 trust:S3a')
                .'。两者不是重复：删 trust:S3a → D 模板从 8 降到 4，'
                .'「哪个售后更好/哪个更靠谱/哪家资质更全/哪个口碑更真实」这类细分问题再也产不出来；'
                .'删 comparison:S3a → 用户主问题「A 和 B 哪个好」消失。删任一个都要先改 §2.5④ 的 D 配额论证。'
        );

        // D 配额分配顺序：构造「两出口各 4 条、D 上限 2」的极端场景。
        // 真实标题跑一次 D 类可能只出 2-3 条、两出口不会同时满额 → 排序错了看不出来，
        // 所以必须用构造场景验（架构师特别点名）。
        $dItems = [];
        for ($i = 0; $i < 4; $i++) {
            $dItems[] = ['q' => '冰溪和薇诺娜哪个好'.$i, 'cell' => 'comparison:S3a', 'angle' => 'comparison',
                'stage' => 'S3a', 'class' => 'D', 'intent' => 'commercial', 'route' => 'direct', 'fit' => 'can',
                'content_type' => 'brand_comparison', 'concern' => '其他'];
            $dItems[] = ['q' => '冰溪和薇诺娜哪个更靠谱'.$i, 'cell' => 'trust:S3a', 'angle' => 'trust',
                'stage' => 'S3a', 'class' => 'D', 'intent' => 'commercial', 'route' => 'direct', 'fit' => 'can',
                'content_type' => 'brand_reliability_comparison', 'concern' => '真实性'];
        }
        $dSlots = ['title_type' => 'brand_product', 'brand_count' => 2, 'has_transaction_word' => false,
            'coverage_cells' => [], 'template_slot_count' => 8, 'audience' => [], 'scene' => [],
            'audience_skin_type' => [], 'audience_crowd' => [], 'entities' => ['brand' => [], 'competitor' => [], 'ingredient' => []]];
        $dV = GeoTitleQuestionRules::validate($dItems, $dSlots);
        $dCellsInPrimary = [];
        foreach ($dV['primary'] as $it) {
            $dCellsInPrimary[(string) $it['cell']] = true;
        }
        $rows[] = self::assertCase(
            'D 配额分配 对比×S3a 优先于 信任×S3a（构造：各 4 条 + 1 品牌 D 上限 2）',
            count($dCellsInPrimary) === 1 && isset($dCellsInPrimary['comparison:S3a']),
            '主清单 D 全部来自 comparison:S3a',
            '主清单 D 的 cell 集合：'.implode(',', array_keys($dCellsInPrimary))
        );

        // S2b 射程虚高：判据必须同时看 stage 和 content_type。
        // 反例：6 条全 S3a 但 6 种 content_type = 6 篇不同内容，不虚高。
        $sameCtype = [];
        for ($i = 0; $i < 6; $i++) {
            $sameCtype[] = ['q' => '冰溪修护霜好用吗'.$i, 'cell' => 'review:S3a', 'angle' => 'review',
                'stage' => 'S3a', 'class' => 'C', 'intent' => 'commercial', 'route' => 'direct', 'fit' => 'can',
                'content_type' => 'pros_cons_analysis', 'concern' => '效果'];
        }
        $vSame = GeoTitleQuestionRules::validate($sameCtype, $dSlots);
        $hitSame = false;
        foreach ($vSame['warnings'] as $w) {
            if (mb_strpos($w, '内容收敛') !== false) {
                $hitSame = true;
            }
        }

        $multiCtype = $sameCtype;
        $ctList = ['pros_cons_analysis', 'brand_comparison', 'price_comparison',
            'audience_fit_comparison', 'ingredient_explainer', 'brand_reliability_comparison'];
        foreach ($multiCtype as $i => $it) {
            $multiCtype[$i]['content_type'] = $ctList[$i];
        }
        $vMulti = GeoTitleQuestionRules::validate($multiCtype, $dSlots);
        $hitMulti = false;
        foreach ($vMulti['warnings'] as $w) {
            if (mb_strpos($w, '内容收敛') !== false) {
                $hitMulti = true;
            }
        }
        $rows[] = self::assertCase(
            'S2b 内容收敛 6 条同阶段：同一文体要告警，6 种文体不告警',
            $hitSame === true && $hitMulti === false,
            '同文体→告警；6 种文体→不告警',
            '同文体='.($hitSame ? '告警' : '不告警').'；6 种文体='.($hitMulti ? '告警' : '不告警')
        );

        // 22 格可达性：comparison:S2 / pricing:S2 必须真的出现在 availableCells 里，
        // 否则「22 格全进 prompt」只是字段上的 true（可能被别处挡掉）。
        $catSlots = GeoTitleQuestionSlots::parse('2026 敏感肌修护霜推荐：薇诺娜、玉泽、冰溪怎么选', $brand);
        $rows[] = self::assertCase(
            '22 格可达性 comparison:S2 在 availableCells 里（不是只写在配置里）',
            in_array('comparison:S2', (array) ($catSlots['available_cells'] ?? []), true),
            'comparison:S2 在可用格里',
            '可用格：'.implode(',', (array) ($catSlots['available_cells'] ?? []))
        );

        // =====================================================================
        // 十、可达性 + 优先级表单一来源（防「注册了但永远打不开」）
        // =====================================================================

        // 断言 41（死格校验）：每个有效格必须至少被「动作覆盖 / 疑问式覆盖 /
        // 无动作兜底」三者之一打通。双射校验只能保证「格子和 code 一一对应」，
        // **保证不了格子真的会被打到** —— review:S3b 就是这么漏的：它注册了、
        // 有 code、双射通过，但当时没有任何动作词通往它。
        $reachable = [];
        foreach (GeoTitleQuestionIndustry::actionCoverage() as $cfg) {
            foreach (['cover', 'cover_on_slot', 'cover_on_multi_brand', 'cover_on_trust_word'] as $k) {
                foreach ((array) ($cfg[$k] ?? []) as $c) {
                    $reachable[(string) $c] = true;
                }
            }
        }
        foreach (GeoTitleQuestionIndustry::questionCoverage() as $cellsOfGroup) {
            foreach ((array) $cellsOfGroup as $c) {
                $reachable[(string) $c] = true;
            }
        }
        foreach (GeoTitleQuestionIndustry::noActionFallback() as $c) {
            $reachable[(string) $c] = true;
        }
        $deadCells = [];
        foreach ($cells as $cell) {
            $key = (string) $cell['key'];
            if (($cell['enabled'] ?? false) === true && ! isset($reachable[$key])) {
                $deadCells[] = $key;
            }
        }
        $rows[] = self::assertCase(
            '断言41 无死格：每个有效格至少被一个动作词/疑问式/兜底打通',
            $deadCells === [],
            '无死格',
            $deadCells === [] ? '无死格' : '死格（注册了但永远打不开）：'.implode('、', $deadCells)
        );

        // 断言 42：CELL_PRIORITY 必须覆盖全部 22 格（一个不多一个不少）。
        // 这套系统有两条独立裁剪路径（A 类名额 / D 配额），共用一张表才不会漂；
        // 缺一个 key 会让那格在配额裁剪时按默认值 5 排序，静默排到最后。
        $prioKeys = array_keys(GeoTitleQuestionIndustry::CELL_PRIORITY);
        sort($prioKeys);
        $cellKeysSorted = $keys;
        sort($cellKeysSorted);
        $rows[] = self::assertCase(
            '断言42 CELL_PRIORITY 覆盖全部 22 格（单一优先级表，两处裁剪共用）',
            $prioKeys === $cellKeysSorted,
            '22 个 key 与 cells() 完全一致',
            '多出的：'.implode(',', array_diff($prioKeys, $cellKeysSorted))
                .'；缺的：'.implode(',', array_diff($cellKeysSorted, $prioKeys))
        );

        // 断言 43：angles_touched 形状（NOT NULL jsonb，1..N，含主角度，值都在枚举内）
        $touchProbe = GeoTitleQuestionRules::makeItem('冰溪有激素吗', self::emptySlots(), $brand);
        $touch = (array) ($touchProbe['angles_touched'] ?? []);
        $touchOk = $touch !== []
            && in_array((string) $touchProbe['angle'], $touch, true)
            && array_diff($touch, GeoTitleQuestionStore::ANGLES) === [];
        $rows[] = self::assertCase(
            '断言43 angles_touched 形状正确（非空、含主角度、值都在 6 角度内）',
            $touchOk,
            '非空 + 含主角度 + 值合法',
            'touched=['.implode(',', $touch).']，主角度='.(string) $touchProbe['angle']
        );

        // 断言44 漂移方向拆分：分类必须是机械可判的（不依赖人判断"谁对"）
        $drift = GeoTitleQuestionRules::driftBreakdown([
            ['q' => 'a', 'model_stage' => 'S2', 'stage' => 'S3a', 'model_angle' => 'review', 'angle' => 'review'],
            ['q' => 'b', 'model_stage' => 'S3b', 'stage' => 'S3a', 'model_angle' => 'trust', 'angle' => 'trust'],
            ['q' => 'c', 'model_stage' => 'S3a', 'stage' => 'S3a', 'model_angle' => 'review', 'angle' => 'use_case'],
            ['q' => 'd', 'model_stage' => 'S3a', 'stage' => 'S3a', 'model_angle' => 'review', 'angle' => 'review'],
        ]);
        $driftOk = $drift['total'] === 4 && $drift['drifted'] === 3
            && $drift['coarser'] === 1 && $drift['lateral'] === 1 && $drift['angle_only'] === 1;
        $rows[] = self::assertCase(
            '断言44 漂移按方向拆分（更粗/更细/同级互换），不是只给一个比例',
            $driftOk,
            'total=4 drifted=3 coarser=1 lateral=1 angle_only=1',
            'total='.$drift['total'].' drifted='.$drift['drifted'].' coarser='.$drift['coarser']
                .' finer='.$drift['finer'].' lateral='.$drift['lateral'].' angle_only='.$drift['angle_only']
        );

        $total = count($rows);
        $passed = 0;
        foreach ($rows as $r) {
            if (($r['ok'] ?? false) === true) {
                $passed++;
            }
        }

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
            'rows' => $rows,
        ];
    }

    /**
     * 用指定标题的槽位判一条问题（省去每条自测都 parse 一遍）。
     *
     * @param  array<string,mixed>  $brand
     * @return array<string,mixed>
     */
    private static function judgeWithTitle(string $q, string $title, array $brand): array
    {
        return GeoTitleQuestionRules::makeItem($q, GeoTitleQuestionSlots::parse($title, $brand), $brand);
    }

    /**
     * 只有品牌的空槽位（判标签用，不关心射程）。
     *
     * @return array<string,mixed>
     */
    private static function emptySlots(): array
    {
        return [
            'entities' => ['brand' => [], 'competitor' => [], 'ingredient' => [], 'product' => [], 'category' => [], 'symptom' => []],
            'action' => [],
            'audience' => [],
            'scene' => [],
            'coverage_cells' => [],
            'has_transaction_word' => false,
            'title_type' => 'brand_product',
            'brand_count' => 1,
        ];
    }

    /**
     * 数一组格子在给定占位符池下有多少个可用模板槽。
     *
     * @param  list<string>  $cellKeys
     * @param  array<string,string>  $pool
     */
    private static function availableTemplateSlots(array $cellKeys, array $pool): int
    {
        $cellsByKey = GeoTitleQuestionIndustry::cellsByKey();
        $count = 0;
        foreach ($cellKeys as $key) {
            $cell = $cellsByKey[(string) $key] ?? null;
            if ($cell === null) {
                continue;
            }
            foreach ((array) $cell['templates'] as $tpl) {
                $ok = true;
                foreach ((array) $tpl['slots'] as $ph) {
                    if (! isset($pool[(string) $ph])) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 断言 1 条问题文本的三维标注。
     *
     * @param  array<string,string>  $expected
     * @return array<string,mixed>
     */
    private static function assertLabel(string $desc, string $q, array $brand, array $expected): array
    {
        $classInfo = GeoTitleQuestionRules::labelClass($q, $brand);
        $actual = [
            'class' => (string) ($classInfo['class'] ?? ''),
            'stage' => (string) (GeoTitleQuestionRules::labelStage($q, $brand) ?? ''),
            'intent' => (string) GeoTitleQuestionRules::labelIntent($q, $brand),
        ];

        $ok = true;
        $expParts = [];
        $actParts = [];
        foreach ($expected as $k => $v) {
            $expParts[] = $k.'='.$v;
            $actParts[] = $k.'='.$actual[$k];
            if ($actual[$k] !== $v) {
                $ok = false;
            }
        }

        return self::row($desc, $ok, implode(' ', $expParts), implode(' ', $actParts));
    }

    /**
     * 通用断言行。
     *
     * @return array<string,mixed>
     */
    private static function assertCase(string $desc, bool $ok, string $expected, string $actual): array
    {
        return self::row($desc, $ok, $expected, $actual);
    }

    /** @return array<string,mixed> */
    private static function row(string $desc, bool $ok, string $expected, string $actual): array
    {
        return [
            'desc' => $desc,
            'ok' => $ok,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }

    /**
     * 造几条填充条目，让 validate() 有足够样本跑分布校验。
     *
     * @return list<array<string,mixed>>
     */
    private static function paddingItems(int $n): array
    {
        $pool = [
            ['q' => '冰溪修护霜烂脸期能用吗', 'angle' => 'use_case', 'stage' => 'S3a', 'class' => 'C', 'intent' => 'informational', 'cell' => 'use_case:S3a'],
            ['q' => '冰溪修护霜真实测评', 'angle' => 'review', 'stage' => 'S3a', 'class' => 'C', 'intent' => 'commercial', 'cell' => 'review:S3a'],
            ['q' => '冰溪修护霜有什么缺点', 'angle' => 'review', 'stage' => 'S3a', 'class' => 'C', 'intent' => 'commercial', 'cell' => 'review:S3a'],
            ['q' => '冰溪售后怎么样', 'angle' => 'trust', 'stage' => 'S3b', 'class' => 'A', 'intent' => 'commercial', 'cell' => 'trust:S3b'],
        ];

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $item = $pool[$i % count($pool)];
            $item['route'] = $item['class'] === 'A' ? 'own_source' : 'direct';
            $item['fit'] = 'can';
            $out[] = $item;
        }

        return $out;
    }

    // =========================================================================
    // CLI
    // =========================================================================

    /** 跑一遍并打印清单，返回进程退出码 */
    public static function main(): int
    {
        $result = self::run();

        $line = str_repeat('=', 100);
        echo $line."\n";
        echo "标题反推提问 · 规则内核自测（v3 / 22 格）\n";
        echo $line."\n";

        foreach ($result['rows'] as $i => $row) {
            $flag = ($row['ok'] ?? false) === true ? 'PASS' : 'FAIL';
            printf("%02d [%s] %s\n", $i + 1, $flag, (string) $row['desc']);
            if (($row['ok'] ?? false) !== true) {
                echo "        期望: ".(string) $row['expected']."\n";
                echo "        实际: ".(string) $row['actual']."\n";
            }
        }

        echo $line."\n";
        printf("合计 %d 条：通过 %d / 失败 %d\n", (int) $result['total'], (int) $result['passed'], (int) $result['failed']);
        echo $line."\n";

        return $result['failed'] === 0 ? 0 : 1;
    }
}

// ---------------------------------------------------------------------------
// 独立运行支持：被 web 请求 include 时不执行；只有直接 `php 本文件` 才跑。
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath((string) $_SERVER['argv'][0]) === __FILE__) {
    // 依赖按顺序加载：Store 持有枚举 → Industry 持有格子 → Slots → Rules → Selftest
    require_once __DIR__.'/GeoTitleQuestionStore.php';
    require_once __DIR__.'/GeoTitleQuestionIndustry.php';
    require_once __DIR__.'/GeoTitleQuestionSlots.php';
    require_once __DIR__.'/GeoTitleQuestionRules.php';

    exit(\App\Support\GeoAudit\GeoTitleQuestionSelftest::main());
}
