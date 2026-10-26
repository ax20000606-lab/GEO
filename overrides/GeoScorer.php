<?php

namespace App\Support\GeoAudit;

/**
 * GEO 单篇内容十维质量评分（标注引擎）
 *
 * 和「平台合规检查」的分工（两者不冲突，都保留）：
 *   - 平台合规检查：格式层。字数区间、标题长度、话题标签数、禁用词。答"能不能发"
 *   - 十维质量评分：内容层。权威性、数据、溯源、术语…答"值不值得被 AI 引用"
 *
 * 设计取舍：
 *   ① 纯规则，不调模型。同一篇跑两次分数必须一样，否则没法用来对比改前改后。
 *   ② 规则写在 storage/app/geo-scoring.json，改配置即时生效，不用重建容器。
 *   ③ 一句话只挂一个标注（权重最高的那个维度），但**每个维度独立计分**。
 *      —— 标注是为了读得清，计分是为了算得准，两个目标不一样，不能混。
 */
final class GeoScorer
{
    private const CONFIG_PATH = 'app/geo-scoring.json';

    /** 圈数字，正文标注用 */
    private const CIRCLED = ['①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧', '⑨', '⑩', '⑪', '⑫'];

    /** 每个维度在正文里最多标几处。标全篇等于没标，2 处刚好能定位又不淹内容。 */
    private const MAX_MARKS_PER_DIM = 2;

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    // =========================================================================
    // 配置
    // =========================================================================

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = storage_path(self::CONFIG_PATH);
        $raw = is_file($path) ? (string) file_get_contents($path) : '';
        $data = $raw !== '' ? json_decode($raw, true) : null;

        if (! is_array($data) || empty($data['dimensions'])) {
            // 兜底：配置缺失时给一个空结构，页面显示"配置未就绪"而不是白屏
            return self::$cache = ['pass_score' => 70, 'good_score' => 85, 'dimensions' => []];
        }

        return self::$cache = $data;
    }

    /** @return list<array<string,mixed>> */
    public static function dimensions(): array
    {
        return (array) (self::config()['dimensions'] ?? []);
    }

    public static function hasConfig(): bool
    {
        return self::dimensions() !== [];
    }

    /** 维度 key => 序号（0 开始） */
    public static function indexOf(string $key): int
    {
        $i = 0;
        foreach (self::dimensions() as $d) {
            if ((string) ($d['key'] ?? '') === $key) {
                return $i;
            }
            $i++;
        }

        return -1;
    }

    public static function circled(int $idx): string
    {
        return self::CIRCLED[$idx] ?? ('#'.($idx + 1));
    }

    // =========================================================================
    // 生成时的 prompt 约束
    // =========================================================================

    /**
     * 把十维要求注入生成 prompt。
     *
     * 关键：明确写"没有真实数据就不要编"。否则模型为了凑"统计数据"这一项
     * 会造数字，那是把内容评分做成了造假激励，比不评分更糟。
     */
    public static function promptBlock(): string
    {
        $dims = self::dimensions();
        if ($dims === []) {
            return '';
        }

        $lines = [];
        $lines[] = '## 内容质量十项要求（写完自查，这是被 AI 引用的底层条件）';
        $lines[] = '下面十项不是写作技巧，是评审标准。**每写完一节回来对照一遍。**';
        $lines[] = '';

        $i = 0;
        foreach ($dims as $d) {
            $lines[] = self::circled($i).' '.(string) ($d['name'] ?? '').'（权重 '.(int) ($d['weight'] ?? 0).'%）：'
                .(string) ($d['prompt'] ?? ($d['desc'] ?? ''));
            $i++;
        }

        $lines[] = '';
        $lines[] = '## 硬底线（违反本次输出作废）';
        $lines[] = '- **不许编造数据、专家、认证、专利、销量、排名**。没有真实数据就写定性描述，'
            .'宁可"统计数据"这一项拿 0 分，也不许造。编的数字一旦被核对，整篇可信度全废。';
        $lines[] = '- 不许写功效承诺（治疗、根治、祛痘成功），护肤品类尤其严格。';
        $lines[] = '- 「表达确定性」要的是有边界，不是含糊。写完别用「可能有效」搪塞所有判断，'
            .'该给结论的地方必须给明确结论。';

        return implode("\n", $lines);
    }

    // =========================================================================
    // 评分
    // =========================================================================

    /**
     * 对 markdown 正文打分。
     *
     * @return array{
     *   ok:bool,total:float,grade:string,grade_color:string,
     *   dimensions:list<array<string,mixed>>,
     *   annotations:array<int,array<string,mixed>>,
     *   gaps:list<array<string,mixed>>,
     *   stats:array<string,int>
     * }
     */
    public static function score(string $markdown): array
    {
        $dims = self::dimensions();
        if ($dims === []) {
            return ['ok' => false, 'total' => 0.0, 'grade' => '未配置', 'grade_color' => '#6b7280',
                'dimensions' => [], 'annotations' => [], 'gaps' => [], 'stats' => []];
        }

        $lines = self::splitLines($markdown);

        // ---------- ① 每个维度独立计分（全量命中，不去重）----------
        $results = [];
        $total = 0.0;

        foreach ($dims as $i => $d) {
            $r = self::scoreDimension($d, $markdown);
            $weight = (int) ($d['weight'] ?? 0);
            $weighted = round($r['score'] * $weight / 100, 2);
            $total += $weighted;

            $results[] = [
                'key' => (string) ($d['key'] ?? ''),
                'name' => (string) ($d['name'] ?? ''),
                'weight' => $weight,
                'color' => (string) ($d['color'] ?? '#6b7280'),
                'desc' => (string) ($d['desc'] ?? ''),
                'score' => $r['score'],
                'hits' => $r['hits'],
                'weighted' => $weighted,
                'examples' => array_slice($r['examples'], 0, 5),
                'mark' => self::circled($i),
                'index' => $i,
            ];
        }

        // ---------- ② 逐句定位，给正文加标注 ----------
        // 两难：一句话可能同时命中多个维度，而且常见的词（"可能""建议""其实"）
        // 几乎每句都有。全标出来的结果是整篇都是底色，等于没标。
        // 所以：**每个维度最多标 2 处**（取最先出现的），一句只挂权重最高的那个维度。
        // 图例里照实写"命中 21 处，正文标出 2 处" —— 不假装全标了。
        $cand = [];
        foreach ($lines as $li => $line) {
            if (trim($line) === '' || self::isStructuralLine($line)) {
                continue;
            }

            foreach (self::splitSentences(self::stripPrefix($line)) as $sentence) {
                $sentence = trim($sentence);
                if (mb_strlen($sentence, 'UTF-8') < 6) {
                    continue;
                }

                foreach ($dims as $i => $d) {
                    if (self::matchSentence($d, $sentence) === null) {
                        continue;
                    }
                    $key = (string) ($d['key'] ?? '');
                    $cand[$key][] = [
                        'li' => $li,
                        'sentence' => $sentence,
                        'dim' => $key,
                        'name' => (string) ($d['name'] ?? ''),
                        'color' => (string) ($d['color'] ?? '#6b7280'),
                        'mark' => self::circled($i),
                        'index' => $i,
                        'weight' => (int) ($d['weight'] ?? 0),
                    ];
                }
            }
        }

        $picked = [];
        foreach ($cand as $items) {
            foreach (array_slice($items, 0, self::MAX_MARKS_PER_DIM) as $it) {
                $key = $it['li'].'|'.$it['sentence'];
                // 同一句被两个维度选中时，留权重高的那个
                if (! isset($picked[$key]) || $picked[$key]['weight'] < $it['weight']) {
                    $picked[$key] = $it;
                }
            }
        }

        $annotations = [];
        foreach ($picked as $it) {
            $li = (int) $it['li'];
            unset($it['li'], $it['weight']);
            $annotations[$li][] = $it;
        }

        // ---------- ③ 最该补哪几项（权重高但得分低，按丢分排序）----------
        $gaps = [];
        foreach ($results as $r) {
            if ($r['score'] >= 100) {
                continue;
            }
            $gaps[] = $r + ['loss' => round($r['weight'] * (100 - $r['score']) / 100, 2)];
        }
        usort($gaps, static fn ($a, $b) => $b['loss'] <=> $a['loss']);
        $gaps = array_slice($gaps, 0, 3);

        $total = round($total, 1);
        $pass = (int) (self::config()['pass_score'] ?? 70);
        $good = (int) (self::config()['good_score'] ?? 85);

        [$grade, $gradeColor] = match (true) {
            $total >= $good => ['优秀', '#047857'],
            $total >= $pass => ['合格', '#b45309'],
            $total >= $pass - 15 => ['偏弱', '#d97706'],
            default => ['不足', '#b91c1c'],
        };

        return [
            'ok' => true,
            'total' => $total,
            'grade' => $grade,
            'grade_color' => $gradeColor,
            'dimensions' => $results,
            'annotations' => $annotations,
            'gaps' => $gaps,
            'stats' => [
                'chars' => mb_strlen(preg_replace('/\s+/u', '', $markdown) ?? '', 'UTF-8'),
                'lines' => count($lines),
                'marks' => array_sum(array_map('count', $annotations)),
            ],
        ];
    }

    /**
     * 单维度打分。
     *
     * @param  array<string,mixed>  $dim
     * @return array{score:int,hits:int,examples:list<string>}
     */
    private static function scoreDimension(array $dim, string $text): array
    {
        $hits = 0;
        $examples = [];
        $penalty = 0;

        foreach ((array) ($dim['rules'] ?? []) as $rule) {
            $type = (string) ($rule['type'] ?? '');

            if ($type === 'keyword') {
                $found = [];
                foreach ((array) ($rule['words'] ?? []) as $w) {
                    $w = (string) $w;
                    if ($w !== '' && mb_strpos($text, $w, 0, 'UTF-8') !== false) {
                        $found[] = $w;
                    }
                }
                // 去重叠：「联合实验室」命中时不再重复计「实验室」。
                // 不做这一步，一个长词能顶两三个短词，分数会虚高。
                $found = array_values(array_filter($found, static function ($w) use ($found) {
                    foreach ($found as $o) {
                        if ($o !== $w && mb_strpos($o, $w, 0, 'UTF-8') !== false) {
                            return false;
                        }
                    }

                    return true;
                }));
                $hits += count($found);
                $examples = array_merge($examples, array_slice($found, 0, 4));
            } elseif ($type === 'regex') {
                $pattern = (string) ($rule['pattern'] ?? '');
                if ($pattern === '') {
                    continue;
                }
                $n = @preg_match_all('/'.$pattern.'/u', $text, $m);
                if (is_int($n) && $n > 0) {
                    $hits += $n;
                    foreach (array_slice($m[0], 0, 3) as $one) {
                        $one = trim(preg_replace('/\s+/u', ' ', (string) $one) ?? '');
                        if ($one === '') {
                            continue;
                        }
                        // 截断要带省略号，否则界面上会出现 "FAQP" 这种半截词，看着像乱码
                        $examples[] = mb_strlen($one, 'UTF-8') <= 20
                            ? $one
                            : mb_substr($one, 0, 16, 'UTF-8').'…';
                    }
                }
            } elseif ($type === 'hedge') {
                // 表达确定性：有边界词加分，绝对化词扣分。
                // 这一项不是"命中越多越好"，所以单独处理。
                $good = 0;
                foreach ((array) ($rule['good'] ?? []) as $w) {
                    $good += self::countOf($text, (string) $w);
                }
                $bad = 0;
                $badWords = [];
                foreach ((array) ($rule['bad'] ?? []) as $w) {
                    $c = self::countOf($text, (string) $w);
                    if ($c > 0) {
                        $bad += $c;
                        $badWords[] = (string) $w;
                    }
                }
                $hits = $good;
                if ($good > 0) {
                    $examples[] = '边界表述 '.$good.' 处';
                }
                if ($badWords !== []) {
                    $examples[] = '绝对化：'.implode('/', array_slice($badWords, 0, 3));
                }
                // 每个绝对化词扣 12 分，最多扣 45 —— 扣到 0 为止，不倒扣
                $penalty = min($bad * 12, 45);
            }
        }

        $score = self::tierScore((array) ($dim['tiers'] ?? [[0, 0]]), $hits);
        if ($score > 0 && $penalty > 0) {
            $score = max(0, $score - $penalty);
        }

        return ['score' => (int) $score, 'hits' => $hits, 'examples' => $examples];
    }

    private static function countOf(string $text, string $word): int
    {
        if ($word === '') {
            return 0;
        }

        return (int) mb_substr_count($text, $word, 'UTF-8');
    }

    /**
     * @param  list<array{0:int,1:int}>  $tiers
     */
    private static function tierScore(array $tiers, int $hits): int
    {
        foreach ($tiers as $tier) {
            $need = (int) ($tier[0] ?? 0);
            if ($hits >= $need) {
                return (int) ($tier[1] ?? 0);
            }
        }

        return 0;
    }

    /** 单句是否命中某维度（只判真假，不计数） */
    private static function matchSentence(array $dim, string $sentence): ?string
    {
        foreach ((array) ($dim['rules'] ?? []) as $rule) {
            $type = (string) ($rule['type'] ?? '');

            if ($type === 'keyword') {
                foreach ((array) ($rule['words'] ?? []) as $w) {
                    $w = (string) $w;
                    if ($w !== '' && mb_strpos($sentence, $w, 0, 'UTF-8') !== false) {
                        return $w;
                    }
                }
            } elseif ($type === 'regex') {
                $pattern = (string) ($rule['pattern'] ?? '');
                if ($pattern !== '' && @preg_match('/'.$pattern.'/u', $sentence) === 1) {
                    return 'regex';
                }
            }
        }

        return null;
    }

    // =========================================================================
    // 切分
    // =========================================================================

    /**
     * @return list<string>
     */
    private static function splitLines(string $markdown): array
    {
        $md = str_replace(["\r\n", "\r"], "\n", $markdown);

        return explode("\n", $md);
    }

    /**
     * 剥掉 markdown 行前缀（`- `、`1. `、`> `）。
     *
     * 不剥的话，切出来的第一句会带着「- 」前缀，而渲染阶段拿到的是去掉前缀的文本，
     * 两边对不上 → 标注句匹配失败、静默丢失（实测 15 处只渲染出 9 个）。
     */
    private static function stripPrefix(string $line): string
    {
        $t = ltrim($line);
        if (preg_match('/^(?:[-*+]|\d+[.、)]|>)\s+(.*)$/u', $t, $m)) {
            return $m[1];
        }

        return $t;
    }

    /** 标题、分隔线、表格分隔行这类结构行不参与标注（标上去反而乱） */
    private static function isStructuralLine(string $line): bool
    {
        $t = trim($line);
        if ($t === '') {
            return true;
        }
        if (preg_match('/^#{1,6}\s/u', $t)) {
            return true;
        }
        if (preg_match('/^[-*_]{3,}$/u', $t)) {
            return true;
        }
        if (preg_match('/^\|[\s:|-]+\|$/u', $t)) {
            return true;
        }
        if (preg_match('/^```/u', $t)) {
            return true;
        }
        // 表格行不参与标注：渲染走的是另一条分支（按单元格拆），
        // 在这里算了标注却渲染不出来，会让"标注 N 处"和正文实际标注数对不上。
        if (str_starts_with($t, '|') && str_ends_with($t, '|')) {
            return true;
        }

        return false;
    }

    /**
     * 按中英文句末标点切句。保留标点，方便原样替换回正文。
     *
     * @return list<string>
     */
    private static function splitSentences(string $line): array
    {
        $parts = preg_split('/(?<=[。！？；!?;])/u', $line) ?: [$line];

        return array_values(array_filter($parts, static fn ($v) => trim((string) $v) !== ''));
    }

    // =========================================================================
    // 渲染：把正文变成带标注的 HTML
    // =========================================================================

    /**
     * markdown → HTML，命中的句子包 <mark> 并挂圈数字。
     *
     * @param  array<int,array<string,mixed>>  $annotations  score() 返回的 annotations
     */
    public static function render(string $markdown, array $annotations = []): string
    {
        $lines = self::splitLines($markdown);
        $html = '';
        $inList = false;
        $inCode = false;

        foreach ($lines as $li => $line) {
            $raw = rtrim($line);
            $t = trim($raw);

            // 代码块原样输出
            if (preg_match('/^```/u', $t)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= $inCode ? '</pre>' : '<pre style="background:#0f172a;color:#e5e7eb;padding:12px;border-radius:10px;overflow:auto;font-size:12px">';
                $inCode = ! $inCode;
                continue;
            }
            if ($inCode) {
                $html .= self::e($raw)."\n";
                continue;
            }

            if ($t === '') {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                continue;
            }

            $lineAnns = (array) ($annotations[$li] ?? []);

            if (preg_match('/^###\s+(.*)$/u', $t, $m)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h4>'.self::e($m[1]).'</h4>';
                continue;
            }
            if (preg_match('/^##\s+(.*)$/u', $t, $m)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h3>'.self::markInline(self::e($m[1])).'</h3>';
                continue;
            }
            if (preg_match('/^#\s+(.*)$/u', $t, $m)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h2>'.self::e($m[1]).'</h2>';
                continue;
            }
            if (preg_match('/^[-*_]{3,}$/u', $t)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<hr>';
                continue;
            }
            // 表格行：整行渲染，标注加在单元格里不好看，跳过标注
            if (preg_match('/^\|.*\|$/u', $t)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                if (preg_match('/^\|[\s:|-]+\|$/u', $t)) {
                    continue;
                }
                $cells = array_map('trim', explode('|', trim($t, '|')));
                $html .= '<div style="display:flex;gap:0;border-bottom:1px solid #f3f4f6;padding:7px 0;font-size:13px">'
                    .implode('', array_map(static fn ($c) => '<div style="flex:1;padding-right:10px">'.self::markInline(self::e($c)).'</div>', $cells))
                    .'</div>';
                continue;
            }
            if (preg_match('/^(\d+)[.、)]\s+(.*)$/u', $t, $m)) {
                if (! $inList) { $html .= '<ul style="padding-left:5px;list-style:none">'; $inList = true; }
                $html .= '<li style="margin:5px 0"><b style="color:#6b7280;margin-right:7px">'.$m[1].'.</b>';
                $html .= self::annotateLine(self::e($m[2]), $lineAnns).'</li>';
                continue;
            }
            if (preg_match('/^[-*]\s+(.*)$/u', $t, $m)) {
                if (! $inList) { $html .= '<ul style="padding-left:5px;list-style:none">'; $inList = true; }
                $html .= '<li style="margin:5px 0"><span style="color:#9ca3af;margin-right:7px">·</span>';
                $html .= self::annotateLine(self::e($m[1]), $lineAnns).'</li>';
                continue;
            }

            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<p>'.self::annotateLine(self::e($t), $lineAnns).'</p>';
        }

        if ($inList) {
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * 对一行（已 HTML 转义）做句子级标注。
     *
     * @param  list<array<string,mixed>>  $anns
     */
    private static function annotateLine(string $escapedLine, array $anns): string
    {
        $out = $escapedLine;

        if ($anns === []) {
            return self::markInline($out);
        }

        // 按标注句的原文（转义后）在行内替换。长的先替换，避免短句是长句子串时替换错。
        $anns = array_values(array_filter($anns, static fn ($a) => trim((string) ($a['sentence'] ?? '')) !== ''));
        usort($anns, static fn ($a, $b) => mb_strlen((string) $b['sentence'], 'UTF-8') <=> mb_strlen((string) $a['sentence'], 'UTF-8'));

        foreach ($anns as $a) {
            $needle = self::e(trim((string) $a['sentence']));
            if ($needle === '' || ! str_contains($out, $needle)) {
                continue;
            }
            $color = (string) ($a['color'] ?? '#6b7280');
            $mark = (string) ($a['mark'] ?? '');
            $replacement = '<mark data-dim="'.self::e((string) ($a['dim'] ?? '')).'" '
                .'style="background:'.$color.'1f;border-bottom:2px solid '.$color.';padding:1px 2px;border-radius:2px">'
                .$needle.'<sup style="color:'.$color.';font-weight:700;font-size:11px;margin-left:2px">'.$mark.'</sup></mark>';
            // 只替换第一次出现，避免同一行重复句被连坐
            $pos = strpos($out, $needle);
            $out = substr_replace($out, $replacement, $pos, strlen($needle));
        }

        return self::markInline($out);
    }

    /** 加粗与行内代码（标注块已经包含 HTML 标签，不能再整体转义） */
    private static function markInline(string $html): string
    {
        $html = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/`([^`]+)`/u', '<code style="background:#f3f4f6;padding:1px 5px;border-radius:4px;font-size:12px">$1</code>', $html) ?? $html;

        return $html;
    }

    private static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
