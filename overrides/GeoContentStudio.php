<?php

namespace App\Support\GeoAudit;

use RuntimeException;

/**
 * 内容工坊引擎：旧文改造（content-refiner）+ 科普生成（explainer-builder）
 *
 * ── 为什么这两件事要放在一起 ────────────────────────────────────────
 * 它们共用同一套约束：直答首段 → 按平台结构展开 → 列表承载要点 → FAQ →
 * 证据边界 → JSON-LD。区别只是输入：一个是"你已有的稿子"，一个是"一个主题"。
 *
 * ── 平台是一等公民 ────────────────────────────────────────────────
 * 知乎 2000+ 字分点论述，小红书 300–600 字结论前置 + emoji + 标签。
 * 这不是"改写"关系，是两种文体。所以结构要求按平台动态生成：
 *   长文平台（>1200 字）→ 完整结构（核心结论 / 判断标准 / 误区 / 怎么做 / FAQ / 证据边界）
 *   中档平台（600–1200）→ 精简结构（结论 / 3 要点 / FAQ 2–3 条 / 证据边界）
 *   短平台（<600）    → 极简（结论 / 3–5 条要点 / 互动 / 标签 / 一句话边界）
 * 平台配置在 storage/app/geo-platforms.json，加平台不用改代码。
 *
 * ── 真正的价值不在"能生成" ─────────────────────────────────────────
 * DeepSeek 搜索明确会过滤低质 AIGC。随便生成一堆发出去，反而被判低质。
 * 所以 prompt 有一半篇幅是**约束**：
 *   · 不许编造数据、研究、专家、认证、销量、排名
 *   · 原文没有依据的结论，要么删，要么标「（原文未提供依据）」
 *   · 不做产品功效承诺，不用绝对化表述
 *   · 必须给出「证据边界」段
 */
final class GeoContentStudio
{
    /** 单次输出上限；正文+FAQ+JSON-LD 一起产出，给小了 JSON-LD 会被截断 */
    private const MAX_TOKENS = 8000;

    private const TIMEOUT = 180;

    /** 原始文本最多送这么多字符进去 */
    private const MAX_INPUT_CHARS = 12000;

    public const MODES = [
        'refine' => '旧文改造',
        'explain' => '科普生成',
    ];

    /**
     * 旧文改造：把已有稿子按目标平台重排成 AI 愿意引用的结构。
     *
     * @param  array{brand?:string,industry?:string,audience?:string,platform?:string,model_id?:int}  $opts
     * @return array<string,mixed>
     */
    public function refine(string $raw, array $opts): array
    {
        $raw = trim($raw);
        if (mb_strlen($raw, 'UTF-8') < 120) {
            throw new RuntimeException('原文太短（少于 120 字），没有改造价值。请粘贴完整文章。');
        }
        $rawLen = mb_strlen($raw, 'UTF-8');
        if ($rawLen > self::MAX_INPUT_CHARS) {
            $raw = mb_substr($raw, 0, self::MAX_INPUT_CHARS, 'UTF-8')."\n\n（原文过长，已截断到前 ".self::MAX_INPUT_CHARS.' 字）';
            $rawLen = self::MAX_INPUT_CHARS;
        }

        $platform = $this->platform($opts['platform'] ?? null);
        $words = (array) ($platform['words'] ?? [800, 1500]);
        $targetMax = (int) ($words[1] ?? 1500);

        $system = '你是 GEO（生成式引擎优化）内容改造专家。你的任务是把已有文稿按目标平台的写法重排，'
            .'而不是重写观点。你严格遵守事实边界，宁可保留「未提供依据」的表述，也绝不编造任何数据。';

        // 平台字数明显小于原文时，说明这是"提炼"不是"压缩"，必须换说法
        $modeHint = $rawLen > $targetMax * 1.5
            ? "原文 ".$rawLen." 字，明显超过目标平台的 ".$targetMax." 字上限。\n"
                .'**这时不要逐段压缩**（会把每句话都挤成病句）。正确做法：提炼原文最核心的 3–5 个观点，'
                .'用目标平台的写法重新组织；次要枝节直接舍弃。保住观点，不保句子。'
            : '原文长度与目标平台接近，可以完整保留全部要点，主要做结构重排与表达改写。';

        $user = implode("\n", [
            '## 任务',
            '把下面的原文改造成适合【'.($platform['name'] ?? '').'】发布、且 AI 愿意引用的结构化内容。',
            '',
            GeoPlatforms::promptBlock($platform),
            '',
            GeoScorer::promptBlock(),
            '',
            '',
            '## 改造策略',
            $modeHint,
            '',
            '## 铁律（违反任一条即为失败）',
            '1. 只能重排结构、改写表达，**不能新增原文里没有的事实、数据、认证、研究结论、专家姓名**；',
            '2. 原文中的数字与结论：有明确出处的保留并标注出处；无出处的**要么删除，要么改写为中性表述并在句末标「（原文未提供依据）」**；',
            '3. 不新增任何产品功效承诺；涉及疗效、见效时间、治愈率一类表述，一律改为谨慎表述或删除；',
            '4. 不贬低任何竞品；如需比较，只做同口径参数对比；',
            '5. 不确定的地方写「公开资料未明确」，不要用模糊话术填空。',
            '',
            '## 业务背景（用于措辞对齐，不要写进正文）',
            '品牌：'.(($opts['brand'] ?? '') !== '' ? ($opts['brand'] ?? '') : '未提供'),
            '品类：'.(($opts['industry'] ?? '') !== '' ? ($opts['industry'] ?? '') : '未提供'),
            '人群：'.(($opts['audience'] ?? '') !== '' ? ($opts['audience'] ?? '') : '未提供'),
            '',
            '## 输出要求',
            '开始先给一行标题（按平台的标题字数上限）。',
            $this->outline($platform, 'refine'),
            '',
            '### 必须额外输出两段',
            '1. **证据边界**：用列表写清哪些结论有依据、哪些属于通用常识、哪些无法核实。这段不能省。',
            '2. **改动说明**：用列表写 5–8 条，说明改了什么、为什么这么改。这是我复核的依据。',
            '',
            '## 输出顺序（重要，顺序错了本次输出作废）',
            '1. 先**完整写完正文**，中途不要插任何 JSON；',
            '2. 正文写完之后，再单独输出一个 ```json 代码块，内容是 JSON-LD（@graph 数组，含 Article、FAQPage、BreadcrumbList）。',
            '',
            '**JSON-LD 里严禁放正文全文**：不要 articleBody、不要把段落复制进去。',
            '只放：headline（标题）、description（100 字以内）、FAQ 问答（每条答案不超过 150 字）。',
            '若目标平台字数过少没有 FAQ，可省略 FAQPage。',
            '',
            '## 原文',
            '---',
            $raw,
            '---',
        ]);

        return $this->run($system, $user, 'refine', $opts, $platform);
    }

    /**
     * 科普生成：按目标平台从零写一篇 AI 友好文章。
     *
     * @param  array{brand?:string,industry?:string,audience?:string,angle?:string,platform?:string,model_id?:int}  $opts
     * @return array<string,mixed>
     */
    public function explain(string $topic, array $opts): array
    {
        $topic = trim($topic);
        if (mb_strlen($topic, 'UTF-8') < 4) {
            throw new RuntimeException('请填写主题或用户问题（至少 4 个字）');
        }

        $platform = $this->platform($opts['platform'] ?? null);

        $system = '你是 GEO 科普内容作者。你写的每一句都要能经得起追问：不知道就写不知道，'
            .'绝不编造研究数据、专家姓名、认证、销量或排名。你只讲知识，不做产品广告。';

        $user = implode("\n", [
            '## 任务',
            '围绕「'.$topic.'」写一篇适合【'.($platform['name'] ?? '').'】发布的 AI 友好科普内容。',
            '',
            GeoPlatforms::promptBlock($platform),
            '',
            GeoScorer::promptBlock(),
            '',
            '',
            '## 铁律（违反任一条即为失败）',
            '1. **不许编造**研究数据、比例、专家姓名、机构名称、认证编号、销量、排名、奖项；',
            '2. 需要数据支撑但没有可靠来源时，写「公开资料未明确」或「需以官方信息为准」，不要用"据研究""数据显示"这类空壳话术；',
            '3. 不写产品功效承诺，出现品牌时只作为举例，不做推荐排序，不贬低竞品；',
            '4. 涉及医疗、用药、疾病判断的内容，必须提示「请就医或咨询专业人士」；',
            '5. 通篇不要出现「最」「第一」「唯一」「100%」这类绝对化表述。',
            '',
            '## 业务背景（用于选角度与举例，不要写成广告）',
            '品牌：'.(($opts['brand'] ?? '') !== '' ? ($opts['brand'] ?? '') : '未提供（不要提品牌）'),
            '品类：'.(($opts['industry'] ?? '') !== '' ? ($opts['industry'] ?? '') : '未提供'),
            '人群：'.(($opts['audience'] ?? '') !== '' ? ($opts['audience'] ?? '') : '普通用户'),
            '切入角度：'.(($opts['angle'] ?? '') !== '' ? ($opts['angle'] ?? '') : '由你按用户真实搜索意图判断'),
            '',
            '## 输出结构',
            $this->outline($platform, 'explain'),
            '',
            '## 输出顺序（重要，顺序错了本次输出作废）',
            '1. 先**完整写完正文**，中途不要插任何 JSON；',
            '2. 正文写完之后，再单独输出一个 ```json 代码块，内容是 JSON-LD（@graph 数组，含 Article、FAQPage）。',
            '',
            '**JSON-LD 里严禁放正文全文**：不要 articleBody、不要把段落复制进去。',
            '只放：headline（标题）、description（100 字以内）、FAQ 问答（每条答案不超过 150 字）。',
            '若目标平台字数过少没有 FAQ，可省略 FAQPage。',
        ]);

        return $this->run($system, $user, 'explain', $opts, $platform);
    }

    /**
     * 按平台字数分档生成结构要求。
     *
     * 三档的判断依据是平台配置的上限字数，不是拍脑袋：
     *   >1200 字 → 能容纳完整六段结构
     *   600–1200 → 只保留最必要的骨架
     *   <600     → 只能要点式
     * 另外 no_markdown 的平台（小红书/公众号/抖音）不能靠 ## 做结构，要换成序号/emoji。
     */
    private function outline(array $platform, string $mode): string
    {
        $words = (array) ($platform['words'] ?? [800, 1500]);
        $max = (int) ($words[1] ?? 1500);
        $min = (int) ($words[0] ?? 800);
        $titleMax = (int) ($platform['title_max'] ?? 30);
        $noMd = ! empty($platform['no_markdown']);

        $h = static fn (string $t): string => $noMd ? $t : '## '.$t;
        $h3 = static fn (string $t): string => $noMd ? $t : '### '.$t;

        $lines = [];
        $lines[] = '- 标题：不超过 '.$titleMax.' 字，把核心实体与用户问题放前面，不要标题党。';
        // 字数要求由 GeoPlatforms::promptBlock 统一给（含目标区间与硬上限），这里不重复

        if ($noMd) {
            $lines[] = '- **不要用 `#`、`**`、`-` 做结构**。用空行分段、用「1. 2. 3.」或 emoji 开头做要点。';
        }

        if ($max > 1200) {
            // 长文：完整结构
            $lines[] = '- 首段：80–150 字，第一句就是结论。读者只读这一段也能拿到核心信息。';
            $lines[] = '- '.$h('核心结论').'：3–5 条，每条一句结论 + 半句理由。';
            $lines[] = '- '.$h('判断标准').'：3–5 个维度，每个用 '.$h3('小标题').' + 一段解释。';
            $lines[] = '- '.$h('常见误区').'：4–6 条，每条先写误区再写正确认知。';
            $lines[] = '- '.$h('具体怎么做').'：有序列表 4–6 步，每步可执行。';
            $lines[] = '- '.$h('常见问题').'：5–6 条问答，问句用用户真实会打出来的话，答案 60–150 字。';
        } elseif ($max >= 600) {
            // 中档：精简骨架
            $lines[] = '- 首段：60–100 字，第一句就是结论。';
            $lines[] = '- 正文：3 个要点，每个要点一个小标题 + 一段说明。';
            $lines[] = '- '.$h('常见问题').'：2–3 条问答，问句用用户真实会打出来的话。';
        } else {
            // 短文案：只要点
            $lines[] = '- 第一行：一句话结论，30–60 字，直接给答案。';
            $lines[] = '- 正文：3–5 条要点，每条一行，用 emoji 或序号开头，每条不超过 40 字。';
            if ($mode === 'explain') {
                $lines[] = '- 结尾：抛一个引导互动的问题。';
                $lines[] = '- 最后一行：3–6 个话题标签，每个以 # 开头。';
            }
        }

        // 证据边界：所有档位都要，但短文案只留一句
        $lines[] = $max >= 600
            ? '- '.$h('证据边界').'：用列表写清哪些有公开依据、哪些属于通用常识、哪些无法核实、什么情况需要就医。'
            : '- 最后用一句话交代边界（例如「以上为通用护肤常识，不构成医疗建议」）。';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $opts
     * @param  array<string,mixed>  $platform
     * @return array<string,mixed>
     */
    private function run(string $system, string $user, string $mode, array $opts, array $platform): array
    {
        $modelId = isset($opts['model_id']) ? (int) $opts['model_id'] : null;

        $res = GeoChat::complete($system, $user, self::MAX_TOKENS, 0.4, self::TIMEOUT, $modelId);
        $content = trim((string) $res['content']);

        if ($content === '') {
            throw new RuntimeException('模型没有产出内容，请重试');
        }

        [$markdown, $schema] = $this->splitSchema($content);

        $schemaSource = 'model';
        if ($schema === '') {
            $schema = $this->fallbackSchema($markdown);
            $schemaSource = 'fallback';
        }

        $title = $this->titleOf($markdown);
        $check = GeoPlatforms::check($markdown, $title, $platform);
        // 十维内容质量评分：纯规则、可复现，用来对比"改前 vs 改后"
        $scoring = GeoScorer::score($markdown);

        return [
            'markdown' => $markdown,
            'schema' => $schema,
            'schema_source' => $schemaSource,
            'mode' => $mode,
            'label' => self::MODES[$mode] ?? $mode,
            'title' => $title,
            'stats' => $this->stats($markdown),
            'platform' => [
                'slug' => (string) ($platform['slug'] ?? ''),
                'name' => (string) ($platform['name'] ?? ''),
            ],
            'platform_check' => $check,
            'scoring' => $scoring,
            'model' => (string) $res['model'],
            'model_id' => $modelId,
            // 输出接近上限时极可能被截断（正文写一半就断了）。页面要明确提示，
            // 否则用户拿到一篇 73 字的稿子不知道发生了什么。
            'truncated_risk' => ((int) $res['completion_tokens'] >= self::MAX_TOKENS * 0.95),
            'elapsed_ms' => (int) $res['elapsed_ms'],
            'usage' => [
                'prompt_tokens' => (int) $res['prompt_tokens'],
                'completion_tokens' => (int) $res['completion_tokens'],
                'total_tokens' => (int) $res['total_tokens'],
            ],
        ];
    }

    /**
     * 取平台档案；slug 无效时回退默认平台。
     *
     * @return array<string,mixed>
     */
    private function platform(?string $slug): array
    {
        $slug = trim((string) $slug);
        if ($slug !== '') {
            $p = GeoPlatforms::get($slug);
            if ($p !== null) {
                return $p;
            }
        }

        $fallback = GeoPlatforms::get(GeoPlatforms::defaultSlug());
        if ($fallback !== null) {
            return $fallback;
        }

        // 平台档案文件损坏时的兜底，保证功能不整体挂掉
        return [
            'slug' => 'generic', 'name' => '通用长文',
            'words' => [1500, 2500], 'title_max' => 30, 'no_markdown' => false,
            'structure' => '结论先行，分节展开', 'tone' => '专业、可读',
            'forbidden' => [], 'required' => [], 'checks' => [],
        ];
    }

    /**
     * 剥出 JSON-LD 代码块。
     * 只认带 json 标记、且能被 json_decode 解析成 schema 结构的代码块，避免误伤正文示例。
     *
     * @return array{0:string,1:string} [markdown, schema]
     */
    private function splitSchema(string $content): array
    {
        if (! preg_match_all('/```(?:json|JSON)\s*(.+?)\s*```/su', $content, $m)) {
            return [$content, ''];
        }

        for ($i = count($m[1]) - 1; $i >= 0; $i--) {
            $raw = trim($m[1][$i]);
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }
            if (! isset($decoded['@graph']) && ! isset($decoded['@type']) && ! array_is_list($decoded)) {
                continue;
            }

            $schema = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($schema === false) {
                continue;
            }

            $markdown = preg_replace('/```(?:json|JSON)\s*'.preg_quote($m[1][$i], '/').'\s*```/su', '', $content, 1);

            return [trim((string) $markdown), $schema];
        }

        return [$content, ''];
    }

    /**
     * 规则兜底：模型没产出 JSON-LD 时，用正文标题与首段拼一个 Article 骨架。
     * 只保证结构合法，字段值要用户自己补。
     */
    private function fallbackSchema(string $markdown): string
    {
        $title = $this->titleOf($markdown);
        $desc = '';

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(#|[-*+]|\d+\.|\||>)/u', $line)) {
                continue;
            }
            $plain = trim(preg_replace('/[*_`\[\]]/u', '', $line) ?? $line);
            if (mb_strlen($plain, 'UTF-8') >= 40) {
                $desc = mb_substr($plain, 0, 120, 'UTF-8');
                break;
            }
        }

        $json = json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [[
                '@type' => 'Article',
                'headline' => $title !== '' ? $title : '（请填写标题）',
                'description' => $desc !== '' ? $desc : '（请填写一句话描述，80–160 字）',
                'inLanguage' => 'zh-CN',
                'author' => ['@type' => 'Organization', 'name' => '（请填写品牌或作者）'],
                'publisher' => ['@type' => 'Organization', 'name' => '（请填写品牌）'],
                'mainEntityOfPage' => '（请填写这篇文章的 URL）',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return is_string($json) ? $json : '';
    }

    private function titleOf(string $markdown): string
    {
        if (preg_match('/^\s*#\s+(.+)$/mu', $markdown, $m)) {
            return trim($m[1]);
        }

        // 无 markdown 的平台（小红书/公众号）标题在第一行
        foreach (preg_split('/\R/u', trim($markdown)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && mb_strlen($line, 'UTF-8') <= 60) {
                return trim(preg_replace('/^[*_`]+|[*_`]+$/u', '', $line) ?? $line);
            }
        }

        return '';
    }

    /**
     * 统计结构指标，让用户一眼看出这篇是否达标。
     *
     * @return array<string,int>
     */
    private function stats(string $markdown): array
    {
        $plain = preg_replace('/```.*?```/su', '', $markdown) ?? $markdown;
        $textLen = mb_strlen(preg_replace('/\s+/u', '', $plain) ?? '', 'UTF-8');

        return [
            'chars' => $textLen,
            'minutes' => max(1, (int) round($textLen / 400)),
            'h1' => preg_match_all('/^#\s+/mu', $plain) ?: 0,
            'h2' => preg_match_all('/^##\s+/mu', $plain) ?: 0,
            'h3' => preg_match_all('/^###\s+/mu', $plain) ?: 0,
            'lists' => preg_match_all('/^\s*(?:[-*+]|\d+\.)\s+/mu', $plain) ?: 0,
            'questions' => preg_match_all('/[？?]/u', $plain) ?: 0,
            'hashtags' => preg_match_all('/#[^\s#]{1,20}/u', $plain) ?: 0,
        ];
    }
}
