<?php

namespace App\Support\GeoAudit;

/**
 * 标题反推提问 —— Prompt 构建（T03）。
 *
 * 设计原则：**模型只负责造句，不负责判定。**
 *   模型输出：q / angle / stage / why（四件）
 *   服务端推导：intent / class / fit / route / concern / concern_alt /
 *              content_type / expected / cell
 *
 * 为什么这么做：
 *   · 判定（C1 实体覆盖 / C2 动作覆盖 / C3 受众冲突）是硬判据，判错一条就丢掉
 *     一条真实需求。硬判据必须是确定性代码，才能单测、才能解释。
 *   · 让模型输出 intent 会和 labelIntent() 形成两套口径。即使服务端覆写，
 *     `corrected` 也会天天 100%，这个字段就失去诊断价值了。
 *   · cell 是 (angle, stage) 的坐标。让模型输出等于让它把同一个推导再做一遍，
 *     只会多一个漂移源（系统设计 C10）。
 */
final class GeoTitleQuestionPrompt
{
    /**
     * 系统提示词。
     *
     * 三个刻意的设计：
     *   ① **把可用格菜单交给模型**，只列这个标题真正能开的格（通常 4–7 格）。
     *      22 格全灌进去，模型会去凑那些开不了的格，产出一堆服务端要丢掉的东西。
     *   ② **明确禁止编造实体**。规则里 C1 是硬判据：问题里出现标题没有的具名实体
     *      一律判「抢不到」。不提前讲，模型会大量生成这类废候选。
     *   ③ **why 给了正反例**。这是全功能唯一的验收抓手 —— 如果 why 是复述，
     *      整份清单退化成「20 个问题」，运营看不出凭什么能抢。
     */
    public static function system(): string
    {
        return <<<'TXT'
你是 GEO（生成式引擎优化）内容策略分析师。任务：给定一篇文章的标题，推导出「这个标题能抢到哪些 AI 提问」。

# 你的输出只负责造句，不负责判定
你只输出四个字段：q（问题原文）/ angle（角度）/ stage（阶段）/ why（凭什么能抢到）。
意图、A/B/C/D 类、能否抢到、内容类型、预期答案，全部由服务端程序判定，**你不要输出**。

# angle 只能是这 6 个值之一
definition（定义/成分原理）、comparison（和谁比）、pricing（值不值/多少钱）、
review（实测效果/口碑）、use_case（肤质/人群/时期/使用方式）、trust（售后/资质/可信度）

# stage 只能是这 6 个值之一
S1 需求觉醒（有症状但还没想到买东西）、S2 方案探索（在选品类/还没定品牌）、
S3a 候选比较（有具体候选，在比谁好）、S3b 风险验证（已倾向某品牌，在排雷：隐藏费用/售后/靠谱吗/资质）、
S4 行动准备（问价/渠道/正品/退换）、S5 使用后（怎么用/能不能叠加/长期效果/回购）

# stage 边界速查（这三条是实测中模型最容易判错的）
- **问题里出现具名品牌或产品名 → 至少 S3a，不许留在 S2。**
  「平价修护霜和薇诺娜差在哪」提到了薇诺娜 → S3a，不是 S2。
  S2 的定义是「还不知道有哪些牌子」，出现了牌子就已经过了那一步。
- **多少钱 / 价格 / 优惠 / 哪里买 / 小样 → S4**（已经决定要买，在问怎么买）。
  「冰溪修护霜多少钱一支」是 S4，不是 S2。
- **怎么用 / 能天天用吗 / 多久用一次 / 能不能叠加 → S5**（已经买了，在问怎么用）。
  注意「能用吗 / 适合油皮吗」不是 S5，是 S3a（在问该不该买）。

# 硬约束（违反会被服务端直接丢掉，且不提示你）
1. **不要编造标题里没有的具名实体**。标题没提到的品牌、竞品、成分、产品，一律不许出现在 q 里。
   如果标题里没有品牌名，你推出来的问题里也不许出现任何品牌名。
2. **q 要像真人会输进 AI 的短问句**，不超过 24 个字。不要写「请问…」「…有哪些」这种客套。
3. **angle + stage 必须在下面给你的「可用格」里**。不在菜单里的组合请直接不要写。
4. 同一件事只写一条，不要换个说法重复。

# why 是这份清单的验收抓手，必须写「凭什么」，不是复述
反例（复述，无效）：
  q：冰溪修护霜好用吗    why：因为这个标题承诺了测评
正例（给出机制）：
  q：冰溪修护霜好用吗    why：标题的「测评」直接承诺实测结论，正文必然给出肤感与适用人群，正好回答"好不好用"

why 要同时说清两件事：
  （a）标题的哪个词/哪个承诺授权了这个问题
  （b）为什么用户会这样问（他的真实处境是什么）
再补一句正文哪一段能接住。20-60 字，不超过 60 字。

反例 1（复述 —— 只说了"和标题有关"）：
  why：因为这个标题承诺了测评
反例 2（注水 —— 后半句是废话，没有增加信息）：
  why：「测评」二字直接承诺结论，正文必给肤感与适用人群，用户拿这句就能对上号
  ← "用户拿这句就能对上号"是空话，删掉反而更好
反例 3（只说承接、不说授权）：
  why：正文的成分与肤感对比段正好回答
  ← 没说标题的哪个词让这个问题成立
反例 4（授权写成了「标题的结构」，不是标题里的词）：
  why：标题并置两个品牌，用户想知道差距是否值得纠结，正文的成分对比段正好回答
  ← 「标题并置两个品牌」换成任何双品牌标题都成立，等于没说。
    应该引用原词：「两个牌子被『哪个好』直接摆在一起比」← 只有这个标题成立

# 验收 why 的两条硬标准
1. **授权部分必须引用标题里的原词（用「」括起来）**，不要描述标题的结构。
   ✅ 「真的能用吗」自带怀疑口吻
   ❌ 标题并置两个品牌
2. **不要用「用户拿这句就能对上号」「正好命中」「最直白的选品问题」这类话收尾。**
   这些是把前面说过的换个说法，不增加信息。

# 输出格式
只输出一个 JSON 对象，不要任何解释文字、不要 ```json 包裹：
{"questions":[{"q":"...","angle":"review","stage":"S3a","why":"..."}]}

数量：给 10–12 条候选（服务端会筛掉一部分，多给几条才有余量）。
TXT;
    }

    /**
     * 用户提示词：标题 + 品牌上下文 + **这个标题的可用格菜单**。
     *
     * @param  array<string,mixed>  $slots  GeoTitleQuestionSlots::parse() 的输出
     * @param  array<string,mixed>  $brand  品牌档案
     */
    public static function user(string $title, array $slots, array $brand): string
    {
        $ownName = (string) ($brand['name'] ?? '');
        $alias = array_values(array_filter((array) ($brand['aliases'] ?? []), 'is_string'));
        $competitors = [];
        foreach ((array) ($brand['competitors'] ?? []) as $c) {
            if (is_array($c) && trim((string) ($c['name'] ?? '')) !== '') {
                $competitors[] = trim((string) $c['name']);
            }
        }

        $slotsInfo = [];
        foreach (['场景' => '{场景}', '受众' => '{肤质}', '产品' => '{产品}', '竞品' => '{竞品}'] as $label => $ph) {
            $v = (string) ($slots['resolved'][$ph] ?? '');
            $slotsInfo[] = $label.'：'.($v !== '' ? $v : '（标题里没有）');
        }

        return "# 文章标题\n"
            .$title."\n\n"
            ."# 品牌上下文\n"
            .'自家品牌：'.($ownName !== '' ? $ownName : '（无）')
            .($alias !== [] ? '（别名：'.implode('、', array_slice($alias, 0, 5)).'）' : '')."\n"
            .'竞品档案：'.($competitors !== [] ? implode('、', $competitors) : '（无）')."\n"
            ."标题里解析到的槽位\n  ".implode("\n  ", $slotsInfo)."\n\n"
            ."# 这个标题的可用格（angle + stage 只能从下面选）\n"
            .self::cellMenu($slots)."\n"
            ."# 现在请输出 JSON\n"
            .'按上面「可用格」推导 10–12 条候选问题。记得：每条都要有 q / angle / stage / why 四个字段，why 写「凭什么」。';
    }

    /**
     * 可用格菜单：每格给 (angle, stage) + 中文说明 + 1 套示例模板。
     *
     * 只给每格 1 套示例而不是全部 3-4 套，是因为模型看到太多模板会直接抄模板，
     * 生成出一批「填空句」（「{品牌}{产品}好用吗」），而不是像真人提问。
     * 给 1 套当格式参照、其余让它自己造，问句自然度明显更好。
     *
     * @param  array<string,mixed>  $slots
     */
    public static function cellMenu(array $slots): string
    {
        $lines = [];
        foreach ((array) ($slots['available_cells'] ?? []) as $key) {
            $cell = (array) ($slots['cells'][$key] ?? []);
            if ($cell === []) {
                continue;
            }
            $tpls = (array) ($cell['templates'] ?? []);
            $sample = (string) ($tpls[0]['tpl'] ?? '');
            // 示例模板里的占位符换成真实值，避免模型输出带 {} 的句子
            foreach ((array) ($slots['resolved'] ?? []) as $ph => $val) {
                $sample = str_replace((string) $ph, (string) $val, $sample);
            }
            $lines[] = '- angle='.$cell['angle'].' stage='.$cell['stage']
                .'（'.GeoTitleQuestionIndustry::angleLabel((string) $cell['angle']).'/'
                .GeoTitleQuestionIndustry::stageLabel((string) $cell['stage']).'）'
                .'  例：'.$sample;
        }

        return $lines === [] ? '（无可用格 —— 这个标题没有可推导的射程）' : implode("\n", $lines);
    }

    /**
     * 从模型输出里抽 JSON。
     *
     * 为什么不用 response_format=json_object 就够了：DeepSeek 支持，但
     * ① 现有 GeoChat::attempt 没传这个字段（改它要动共享文件），
     * ② 即使传了，模型仍可能包 ```json 围栏或前后加一句「好的，以下是…」。
     * 所以这里做三层兜底：直接解析 → 剥围栏 → 找第一个平衡的 {...}。
     *
     * @return array<string,mixed>|null
     */
    public static function extractJson(string $content): ?array
    {
        return self::extractJsonMeta($content)['payload'];
    }

    /**
     * 与 extractJson 相同，但**同时告诉调用方这条结果是不是抢救回来的**。
     *
     * 为什么必须区分：抢救回来 9/12 条时，如果批次状态写成 completed，
     * 运营会以为 12 条都跑过了 —— 而少掉的那 3 条可能正好是要抢的核心问题。
     * 「少了但看起来正常」比「报错」危险，所以调用方要拿这个标记去把状态
     * 降成 degraded 并写进 warnings。
     *
     * @return array{payload:?array<string,mixed>, salvaged:bool}
     */
    public static function extractJsonMeta(string $content): array
    {
        $text = trim($content);
        if ($text === '') {
            return ['payload' => null, 'salvaged' => false];
        }

        // ① 直接解析（理想情况）
        $direct = json_decode($text, true);
        if (is_array($direct)) {
            return ['payload' => $direct, 'salvaged' => false];
        }

        // ② 剥 ```json ... ``` 围栏
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m) === 1) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return ['payload' => $decoded, 'salvaged' => false];
            }
        }

        // ③ 截断抢救：模型写到一半被 max_tokens 掐断时，整个 object 不平衡，
        //    但里面每个 candidate 可能是完整的。逐个扫平衡的 {...} 收进来，
        //    能救回 n-1 条总比 0 条好（0 条 = 整批废 = 重跑一次真花钱）。
        //
        //    从前往后扫：截断一定发生在末尾，所以前面的对象一定完整；
        //    从后往前反而可能先捞到半条片段。
        $salvaged = self::salvageQuestions($text);
        if ($salvaged !== null) {
            return ['payload' => $salvaged, 'salvaged' => true];
        }

        // ④ 找第一个平衡的大括号块
        $start = mb_strpos($text, '{');
        if ($start === false) {
            return ['payload' => null, 'salvaged' => false];
        }
        $depth = 0;
        $len = mb_strlen($text);
        $inString = false;
        $escape = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1);
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = mb_substr($text, $start, $i - $start + 1);
                    $decoded = json_decode($candidate, true);

                    return ['payload' => is_array($decoded) ? $decoded : null, 'salvaged' => false];
                }
            }
        }

        return ['payload' => null, 'salvaged' => false];
    }

    /**
     * 从被截断的 JSON 里抢救候选。
     *
     * 只在「整体解析失败 + 围栏解析失败」之后才走这里。
     * 做法：从 `"questions"` 之后逐个扫描平衡的 {...}，能 json_decode 的就收。
     * 只要捞到 ≥1 条就返回（调用方 normalizeItems 会再逐条校验）。
     *
     * @return array<string,mixed>|null
     */
    public static function salvageQuestions(string $text): ?array
    {
        $anchor = mb_strpos($text, '"questions"');
        if ($anchor === false) {
            return null;
        }
        $body = mb_substr($text, $anchor);
        $len = mb_strlen($body);
        $items = [];
        $i = 0;

        while ($i < $len) {
            if (mb_substr($body, $i, 1) !== '{') {
                $i++;
                continue;
            }
            $depth = 0;
            $inString = false;
            $escape = false;
            $closed = false;
            for ($j = $i; $j < $len; $j++) {
                $ch = mb_substr($body, $j, 1);
                if ($inString) {
                    if ($escape) {
                        $escape = false;
                    } elseif ($ch === '\\') {
                        $escape = true;
                    } elseif ($ch === '"') {
                        $inString = false;
                    }
                    continue;
                }
                if ($ch === '"') {
                    $inString = true;
                } elseif ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $chunk = mb_substr($body, $i, $j - $i + 1);
                        $decoded = json_decode($chunk, true);
                        // 只收「像一条候选」的对象，避免把外层包装也收进来
                        if (is_array($decoded) && isset($decoded['q'])) {
                            $items[] = $decoded;
                        }
                        $closed = true;
                        break;
                    }
                }
            }
            if (! $closed) {
                // 这个 object 被截断了，后面也不会有完整的了
                break;
            }
            $i = $j + 1;
        }

        return $items === [] ? null : ['questions' => $items];
    }

    /**
     * 重试时追加的纠正提示。
     *
     * 只在「上一轮一条有效候选都没有」时才追加 —— 而且是指出具体错在哪，
     * 不是笼统说「再试一次」。实测笼统重试的成功率远低于指出具体违反项。
     */
    public static function retryHint(string $reason): string
    {
        return "\n\n# 上一次输出不合格，请修正\n".$reason
            ."\n只输出 JSON 对象本身，第一个字符必须是 `{`，最后一个字符必须是 `}`。";
    }
}
