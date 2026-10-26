<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI 关键词生成器：按四类规则批量生成问题，筛选后导入词库。
 *
 * 和 GeoKeywordMiner 的分工：
 *   - GeoKeywordMiner：拓词。AI 泛化问法 + 百度联想词（真实搜索词），偏"发现"
 *   - 本文件：按类别定向生成。严格按 A/B/C/D 四类规则出题，偏"补齐结构"
 *
 * 分类存哪：keywords 表原本没有 category 字段，这里首次使用时自动补一列。
 * 不加这一列，生成的分类导入后就丢了，覆盖核查没法按类筛题。
 */
final class GeoKeywordGen
{
    private const RULES_PATH = 'app/geo-keyword-rules.json';

    /** 单次生成的输出上限（24 条问题 + 理由，够用） */
    private const MAX_TOKENS = 3000;

    private const TIMEOUT = 90;

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    // =========================================================================
    // 规则与建表
    // =========================================================================

    /** @return array<string,mixed> */
    public static function rules(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $path = storage_path(self::RULES_PATH);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || empty($data['categories'])) {
            return self::$cache = ['default_count' => 6, 'categories' => []];
        }

        return self::$cache = $data;
    }

    /** @return list<array<string,mixed>> */
    public static function categories(): array
    {
        return array_values(array_filter((array) (self::rules()['categories'] ?? []), 'is_array'));
    }

    /** @return array<string,mixed>|null */
    public static function category(string $key): ?array
    {
        foreach (self::categories() as $c) {
            if ((string) ($c['key'] ?? '') === $key) {
                return $c;
            }
        }

        return null;
    }

    /**
     * 首次使用时给 keywords 加 category 列。
     *
     * 为什么用 ALTER TABLE 而不是 migration：和 geo_* 系列表同一个原则——
     * 不碰镜像里的 migration 流程，升级镜像不受影响。GEOFlow 原生代码不读这一列，
     * 加了也不会干扰它自己的功能。
     */
    public function ensureCategoryColumn(): bool
    {
        try {
            if (! Schema::hasTable('keywords')) {
                return false;
            }
            if (Schema::hasColumn('keywords', 'category')) {
                return true;
            }
            DB::statement("ALTER TABLE keywords ADD COLUMN IF NOT EXISTS category varchar(10) DEFAULT ''");
            DB::statement("CREATE INDEX IF NOT EXISTS keywords_category_idx ON keywords (category)");

            return Schema::hasColumn('keywords', 'category');
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasCategoryColumn(): bool
    {
        try {
            return Schema::hasTable('keywords') && Schema::hasColumn('keywords', 'category');
        } catch (\Throwable) {
            return false;
        }
    }

    // =========================================================================
    // 生成
    // =========================================================================

    /**
     * 按类别批量生成问题。
     *
     * @param  list<string>  $catKeys  要生成哪几类，如 ['A','C']
     * @param  array{brand?:string,industry?:string,audience?:string,products?:string,competitors?:string}  $ctx
     * @return array{ok:bool,items:array<string,list<array{q:string,why:string}>>,raw:string,error:string,elapsed_ms:int,model:string}
     */
    public function generate(string $brand, array $catKeys, array $ctx = [], ?int $modelId = null, ?int $perCatOverride = null): array
    {
        $brand = trim($brand);
        if ($brand === '') {
            return ['ok' => false, 'items' => [], 'raw' => '', 'error' => '请先填写品牌名', 'elapsed_ms' => 0, 'model' => ''];
        }

        $picked = [];
        foreach (self::categories() as $c) {
            $k = (string) ($c['key'] ?? '');
            if (in_array($k, $catKeys, true)) {
                $picked[$k] = $c;
            }
        }
        if ($picked === []) {
            return ['ok' => false, 'items' => [], 'raw' => '', 'error' => '请至少选择一类要生成的问题', 'elapsed_ms' => 0, 'model' => ''];
        }

        $perCat = $perCatOverride !== null && $perCatOverride > 0
            ? min(30, $perCatOverride)
            : (int) (self::rules()['default_count'] ?? 6);
        $system = '你是 GEO 关键词策略师。你很清楚用户在不同阶段会怎么搜、怎么问。'
            .'你只输出真实用户会打出来的问题，不写营销文案，也不编造任何你不确定的信息。';

        $user = $this->buildPrompt($brand, $picked, $perCat, $ctx);

        try {
            $res = GeoChat::complete($system, $user, self::MAX_TOKENS, 0.4, self::TIMEOUT, $modelId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'items' => [], 'raw' => '', 'error' => mb_substr($e->getMessage(), 0, 160, 'UTF-8'),
                'elapsed_ms' => 0, 'model' => ''];
        }

        $items = $this->parse($res['content'], $picked, $brand);

        return [
            'ok' => true,
            'items' => $items,
            'raw' => $res['content'],
            'error' => '',
            'elapsed_ms' => (int) $res['elapsed_ms'],
            'model' => (string) $res['model'],
            'usage' => (int) ($res['total_tokens'] ?? 0),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $picked
     * @param  array<string,mixed>  $ctx
     */
    private function buildPrompt(string $brand, array $picked, int $perCat, array $ctx): string
    {
        $lines = [];
        $lines[] = '## 任务';
        $lines[] = '围绕品牌「'.$brand.'」，按下面给定的分类规则生成用户会真实搜索的问题。';
        $lines[] = '';

        $lines[] = '## 分类规则';
        foreach ($picked as $k => $c) {
            $needBrand = ! empty($c['must_have_brand']);
            $lines[] = '';
            $lines[] = '### '.$k.' '.(string) ($c['label'] ?? '').'——'.(string) ($c['desc'] ?? '');
            $lines[] = '- **'.($needBrand ? '必须出现品牌名「'.$brand.'」' : '**绝对不能出现品牌名「'.$brand.'」**').'**';
            $lines[] = '- 每类生成 '.$perCat.' 条';
            $lines[] = '- 覆盖这些角度（每个角度一条）：'.implode('、', (array) ($c['angles'] ?? []));
            $ex = (array) ($c['examples'] ?? []);
            if ($ex !== []) {
                $lines[] = '- 风格参考（不要照抄）：'.implode(' / ', array_map(static fn ($v) => '「'.$v.'」', array_slice($ex, 0, 3)));
            }
        }

        // 业务上下文：给得越多，生成的题越具体；不给的话模型容易编
        $ctxLines = [];
        if (($ctx['industry'] ?? '') !== '') {
            $ctxLines[] = '- 品类：'.$ctx['industry'];
        }
        if (($ctx['audience'] ?? '') !== '') {
            $ctxLines[] = '- 目标人群：'.$ctx['audience'];
        }
        if (($ctx['products'] ?? '') !== '') {
            $ctxLines[] = '- 已知产品（只能用这些，不要编新的）：'.$ctx['products'];
        }
        if (($ctx['competitors'] ?? '') !== '') {
            $ctxLines[] = '- 竞品（D 类对比时用这些）：'.$ctx['competitors'];
        }
        if ($ctxLines !== []) {
            $lines[] = '';
            $lines[] = '## 业务信息';
            $lines = array_merge($lines, $ctxLines);
        }

        $lines[] = '';
        $lines[] = '## 硬约束（违反任一条本次作废）';
        $lines[] = '1. **不要编造产品名**。不知道具体产品就用「'.$brand.'的产品」「'.$brand.'的修护类产品」这种泛指。';
        $lines[] = '2. **不要编造数据**（成分浓度、专利号、销量、排名）。问题里可以问，但不要预设答案。';
        $lines[] = '3. 每条问题不超过 30 个字，用中文，用用户的口语说法，不要营销腔。';
        $lines[] = '4. B 类问题里**一个字都不能出现品牌名**——出现即作废，因为这类题用来测"用户不知道你时会不会搜到你"。';
        $lines[] = '';
        $lines[] = '## 输出格式';
        $lines[] = '只输出一个 JSON 对象，不要任何解释、不要 markdown 代码块标记：';
        $lines[] = '{"'.implode('":[...],"', array_keys($picked)).'":[...]}';
        $lines[] = '每一项的结构：{"q":"问题原文","why":"这条题用来验证什么，10 字以内"}';

        return implode("\n", $lines);
    }

    /**
     * 解析模型输出。容错：模型可能套 ```json 代码块，也可能前后带说明文字。
     *
     * @param  array<string,array<string,mixed>>  $picked
     * @return array<string,list<array{q:string,why:string}>>
     */
    private function parse(string $raw, array $picked, string $brand): array
    {
        $out = [];
        foreach (array_keys($picked) as $k) {
            $out[$k] = [];
        }

        $json = $this->extractJson($raw);
        if ($json === null) {
            return $out;
        }

        foreach ($picked as $k => $c) {
            $list = $json[$k] ?? [];
            if (! is_array($list)) {
                continue;
            }
            $needBrand = ! empty($c['must_have_brand']);
            foreach ($list as $item) {
                $q = is_array($item) ? (string) ($item['q'] ?? '') : (string) $item;
                $q = trim($q);
                if ($q === '' || mb_strlen($q, 'UTF-8') > 40) {
                    continue;
                }
                // 规则自检：B 类不能带品牌名，A/C/D 必须带。模型偶尔会违反，这里挡一道。
                $hasBrand = mb_strpos($q, $brand, 0, 'UTF-8') !== false;
                if ($needBrand && ! $hasBrand) {
                    continue;
                }
                if (! $needBrand && $hasBrand) {
                    continue;
                }
                $out[$k][] = [
                    'q' => $q,
                    'why' => is_array($item) ? trim((string) ($item['why'] ?? '')) : '',
                ];
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function extractJson(string $raw): ?array
    {
        $t = trim($raw);

        // 去掉 ```json ... ``` 包裹
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $t, $m)) {
            $t = trim($m[1]);
        }

        $decoded = json_decode($t, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 兜底：从第一个 { 到最后一个 }
        $start = strpos($t, '{');
        $end = strrpos($t, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($t, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    // =========================================================================
    // 导入
    // =========================================================================

    // =========================================================================
    // 百度联想词模式：真实搜索数据 + AI 筛选归类
    //
    // 为什么要有这个模式：纯 AI 生成的问题看着合理，但用户未必真在搜；
    // 百度联想词是真的搜索补全，但没有意图过滤（会混进"泛红的拼音""修护牙膏"这种）。
    // 两者结合才是完整链路：真实来源负责"广度"，AI 负责"纯度 + 归类"。
    // =========================================================================

    /**
     * 走百度联想词 + AI 筛选的完整流程。
     *
     * @param  list<string>  $catKeys  要归类到哪几类
     * @param  array{brand?:string,industry?:string,audience?:string}  $ctx
     * @return array{ok:bool,items:array<string,list<array{q:string,why:string}>>,raw:string,error:string,elapsed_ms:int,model:string,stats:array<string,int>}
     */
    public function generateFromBaidu(string $brand, array $catKeys, array $ctx = [], int $limitPerCat = 10, array $exclude = [], ?int $modelId = null): array
    {
        $brand = trim($brand);
        if ($brand === '') {
            return $this->fail('请先填写品牌名');
        }
        if ($catKeys === []) {
            return $this->fail('请至少选择一类');
        }

        $started = microtime(true);

        // ① 生成种子词：太长的句子百度补全拉不到东西，必须是短词
        $seeds = $this->suggestSeeds($brand, $ctx, $modelId);
        if ($seeds === []) {
            return $this->fail('没能生成种子词，请检查模型配置');
        }

        // ② 拉百度联想词
        $rawWords = [];
        try {
            $miner = new GeoKeywordMiner();
            foreach ($miner->baiduSuggest($seeds) as $seed => $words) {
                foreach ((array) $words as $w) {
                    $w = trim((string) $w);
                    // 长度过滤：太短（如"敏皮"）信息量不足，太长不是搜索词
                    $len = mb_strlen($w, 'UTF-8');
                    if ($len < 4 || $len > 30) {
                        continue;
                    }
                    $rawWords[$w] = true;
                }
            }
        } catch (\Throwable $e) {
            return $this->fail('百度联想词拉取失败：'.mb_substr($e->getMessage(), 0, 80, 'UTF-8'));
        }
        $rawWords = array_keys($rawWords);
        if ($rawWords === []) {
            return $this->fail('百度联想词一条都没拉到（种子词可能太偏，换个品类词试试）');
        }

        // ③ AI 筛选 + 归类（分批，一次塞太多模型会偷懒）
        $classified = $this->classifyWords($rawWords, $catKeys, $brand, $limitPerCat, $exclude, $modelId);

        // ④ 数量不够的类，用纯 AI 补齐。
        // 为什么必须补：百度联想词天然偏产品和品牌（用户搜"XX洗面奶"），
        // 某一类（尤其 A 品牌认知）可能只有一两条。不补的话用户选一类就只能拿到一条，
        // 看着像功能坏了。补的逻辑是"真实词优先，不够的用 AI 生成凑到目标条数"。
        $filled = [];
        foreach ($catKeys as $k) {
            $have = count($classified['items'][$k]);
            if ($have >= $limitPerCat) {
                continue;
            }
            $need = $limitPerCat - $have;
            try {
                $fill = $this->generate($brand, [$k], $ctx, $modelId, $need + 2);
                if ($fill['ok']) {
                    $seen = [];
                    foreach ($classified['items'][$k] as $it) {
                        $seen[$it['q']] = true;
                    }
                    $added = 0;
                    foreach ((array) ($fill['items'][$k] ?? []) as $it) {
                        if ($added >= $need) {
                            break;
                        }
                        $q = trim((string) $it['q']);
                        if ($q === '' || isset($seen[$q])) {
                            continue;
                        }
                        // 库里已有的也不要
                        $dup = false;
                        foreach ($exclude as $ex) {
                            if ($ex === $q) {
                                $dup = true;
                                break;
                            }
                        }
                        if ($dup) {
                            continue;
                        }
                        $seen[$q] = true;
                        $classified['items'][$k][] = ['q' => $q, 'why' => 'AI 补齐'];
                        $added++;
                    }
                    if ($added > 0) {
                        $filled[$k] = $added;
                    }
                }
            } catch (\Throwable) {
                // 补齐失败不影响主流程
            }
        }

        $total = 0;
        foreach ($classified['items'] as $list) {
            $total += count($list);
        }
        if ($total === 0) {
            return $this->fail('拉到了 '.count($rawWords).' 条联想词，但没有一条通过相关性筛选，AI 补齐也失败');
        }

        return [
            'ok' => true,
            'items' => $classified['items'],
            'raw' => '',
            'error' => '',
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'model' => $classified['model'],
            'stats' => [
                'seeds' => count($seeds),
                'raw' => count($rawWords),
                'kept' => $total,
                'dropped' => (int) $classified['dropped'],
                'filled' => $filled,
            ],
        ];
    }

    /**
     * 让模型给 5–8 个适合百度补全的种子词（2–6 字，不要长句）。
     *
     * @param  array<string,mixed>  $ctx
     * @return list<string>
     */
    private function suggestSeeds(string $brand, array $ctx, ?int $modelId): array
    {
        $industry = trim((string) ($ctx['industry'] ?? ''));
        $audience = trim((string) ($ctx['audience'] ?? ''));

        $sys = '你是搜索词分析师，只输出 JSON。';
        $user = '品牌：'.$brand."\n"
            .($industry !== '' ? '品类：'.$industry."\n" : '')
            .($audience !== '' ? '人群：'.$audience."\n" : '')
            ."\n请给出 8 个用于百度下拉框补全的「种子词」。要求：\n"
            ."- 每个 2–6 个字，是用户会在搜索框里打的前半截词\n"
            ."- 覆盖皮肤问题、使用场景、人群三类角度\n"
            ."- 不要带品牌名（品牌名单独拉一轮）\n"
            ."- 不要长句，不要问句\n\n"
            .'只输出 JSON：{"seeds":["词1","词2"]}';

        $seeds = [];
        // 最多试两轮：种子词决定联想词覆盖面，一轮失败就只剩品牌名，
        // 拉到的联想词会从几十条掉到十条，大部分靠 AI 补——真实数据优势就没了。
        for ($attempt = 0; $attempt < 2 && $seeds === []; $attempt++) {
            try {
                $res = GeoChat::complete($sys, $user, 500, $attempt === 0 ? 0.3 : 0.6, 60, $modelId);
                $json = $this->extractJson($res['content']);

                foreach ((array) ($json['seeds'] ?? []) as $w) {
                    $w = trim((string) $w);
                    if ($w !== '' && mb_strlen($w, 'UTF-8') >= 2 && mb_strlen($w, 'UTF-8') <= 8) {
                        $seeds[] = $w;
                    }
                }

                // JSON 解析失败时，从正文里兜底抓 2-6 字的中文短语
                if ($seeds === []) {
                    if (preg_match_all('/[\x{4e00}-\x{9fa5}]{2,6}/u', (string) $res['content'], $m)) {
                        foreach (array_slice($m[0], 0, 12) as $w) {
                            if (! in_array($w, $seeds, true)) {
                                $seeds[] = $w;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // 换下一轮
            }
        }

        // 两轮都失败 → 用"品牌 + 品类 + 几个通用场景词"兜底。
        // 总比只剩品牌名强：只有品牌名时百度补全只会给"XX怎么样""XX旗舰店"，覆盖面太窄。
        if ($seeds === []) {
            $seeds = [$brand];
            if ($industry !== '') {
                $seeds[] = $industry;
            }
            foreach (['男士护肤', '护肤品推荐', '护肤套装'] as $extra) {
                if (mb_strpos($industry, $extra, 0, 'UTF-8') === false) {
                    $seeds[] = $extra;
                }
            }
        }

        // 品牌名也拉一轮，能抓到"XX怎么样"这类长尾
        array_unshift($seeds, $brand);

        return array_slice(array_values(array_unique($seeds)), 0, 10);
    }

    /**
     * 分批让 AI 判断每条联想词属于哪一类，或判定为无关。
     *
     * @param  list<string>  $words
     * @param  list<string>  $catKeys
     * @return array{items:array<string,list<array{q:string,why:string}>>,model:string,dropped:int}
     */
    private function classifyWords(array $words, array $catKeys, string $brand, int $limitPerCat, array $exclude, ?int $modelId): array
    {
        $items = [];
        foreach ($catKeys as $k) {
            $items[$k] = [];
        }
        $dropped = 0;
        $model = '';
        $dropAll = [];

        $defs = [];
        foreach (self::categories() as $c) {
            $ck = (string) ($c['key'] ?? '');
            if (! in_array($ck, $catKeys, true)) {
                continue;
            }
            $defs[] = $ck.' '.(string) ($c['label'] ?? '').'：'.(string) ($c['desc'] ?? '')
                .(! empty($c['must_have_brand']) ? '（必须出现品牌名）' : '（绝对不能出现品牌名）');
        }
        $defText = implode("\n", $defs);

        foreach (array_chunk($words, 60) as $chunk) {
            $numbered = [];
            foreach ($chunk as $i => $w) {
                $numbered[] = ($i + 1).'. '.$w;
            }

            // 已有词只传前 60 条：传全量会撑爆 token，而且这部分在代码层还会再过滤一遍
            $excludeHint = '';
            if ($exclude !== []) {
                $excludeHint = "\n\n下面这些词问题库里已经有了，**语义相同或几乎一样的不要再输出**：\n"
                    .implode('、', array_slice($exclude, 0, 60))."\n";
            }

            $sys = '你是 GEO 搜索词质检员。你要判断一批真实搜索词，哪些能归入给定的问题类别、哪些是无关噪声。只输出 JSON。';
            $user = '品牌：'.$brand."\n\n可归入的类别：\n".$defText."\n\n"
                ."判断规则：\n"
                ."- 属于某一类的，放到那一类的数组里，词保持原样\n"
                ."- 以下一律判为 drop：查词典（X的拼音/近义词/怎么读）、找素材（广播稿/文案/图片/视频）、"
                ."无关话题（咳嗽/掉头发/鼻炎/脱皮/牙膏/胃药）、纯医疗诉求（根治/手术/激光/消除/去除）、"
                ."错别字或残缺（敏皮/敏敢肌肤）、带别的品牌名\n"
                ."- **同一批里语义相近的只留一条**（例如「红血丝怎么修复最有效」「红血丝最好的解决方法」"
                ."「红血丝修复最好的方法」算同一条，只留表述最自然的那个）\n"
                ."- 每一类**最多输出 ".$limitPerCat." 条**，挑最典型、最像真实用户问法的\n"
                ."- 拿不准的一律 drop，宁可少留不要脏数据\n"
                .$excludeHint."\n"
                .'只输出 JSON：{"A":["词"],"B":["词"],"drop":["词"]}（没有的类别可以省略）'."\n\n"
                ."待判断：\n".implode("\n", $numbered);

            try {
                $res = GeoChat::complete($sys, $user, 3000, 0.2, 120, $modelId);
                $model = (string) $res['model'];
                $json = $this->extractJson($res['content']);
                if ($json === null) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $seen = [];
            $existSet = [];
            foreach ($exclude as $e) {
                $existSet[trim((string) $e)] = true;
            }
            foreach ($catKeys as $k) {
                if (count($items[$k]) >= $limitPerCat) {
                    continue;
                }
                foreach ((array) ($json[$k] ?? []) as $w) {
                    $w = trim((string) $w);
                    if ($w === '' || isset($seen[$w]) || isset($existSet[$w])) {
                        continue;
                    }
                    // AI 偶尔无视"最多 N 条"，这里兜一道
                    if (count($items[$k]) >= $limitPerCat) {
                        break;
                    }
                    $seen[$w] = true;
                    $items[$k][] = ['q' => $w, 'why' => '百度联想词'];
                }
            }
            foreach ((array) ($json['drop'] ?? []) as $w) {
                $dropAll[trim((string) $w)] = true;
            }
        }

        $dropped = count($dropAll);

        return ['items' => $items, 'model' => $model, 'dropped' => $dropped];
    }

    /** @return array{ok:bool,items:array<string,mixed>,raw:string,error:string,elapsed_ms:int,model:string} */
    private function fail(string $msg): array
    {
        return ['ok' => false, 'items' => [], 'raw' => '', 'error' => $msg, 'elapsed_ms' => 0, 'model' => ''];
    }

    /**
     * 批量导入词库。
     *
     * 去重靠表的唯一约束 (library_id, keyword)：已存在的静默跳过，不报错。
     * 注意这只拦"完全一样的字符串"，换个说法（"用什么护肤品"vs"用啥护肤品"）拦不住。
     *
     * @param  list<array{keyword:string,category:string}>  $rows
     * @return array{added:int,skipped:int,total:int,error:string}
     */
    public function import(int $libraryId, array $rows): array
    {
        $out = ['added' => 0, 'skipped' => 0, 'total' => count($rows), 'error' => ''];

        if ($libraryId <= 0) {
            return array_merge($out, ['error' => '请选择要导入的词库']);
        }
        if ($rows === []) {
            return array_merge($out, ['error' => '没有选中任何问题']);
        }

        $exists = DB::table('keyword_libraries')->where('id', $libraryId)->exists();
        if (! $exists) {
            return array_merge($out, ['error' => '词库不存在（可能已被删除）']);
        }

        $hasCat = $this->hasCategoryColumn();

        foreach ($rows as $row) {
            $kw = trim((string) ($row['keyword'] ?? ''));
            if ($kw === '') {
                continue;
            }
            $kw = mb_substr($kw, 0, 200, 'UTF-8');

            $payload = [
                'library_id' => $libraryId,
                'keyword' => $kw,
                'created_at' => now(),
            ];
            if ($hasCat) {
                $payload['category'] = mb_substr((string) ($row['category'] ?? ''), 0, 10);
            }

            try {
                $dup = DB::table('keywords')->where('library_id', $libraryId)->where('keyword', $kw)->exists();
                if ($dup) {
                    $out['skipped']++;
                    continue;
                }
                DB::table('keywords')->insert($payload);
                $out['added']++;
            } catch (\Throwable) {
                $out['skipped']++;
            }
        }

        // 词库的 keyword_count 是 GEOFlow 自己维护的冗余字段，导入后同步一下，
        // 否则词库列表里显示的条数会跟实际不一致。
        try {
            $n = DB::table('keywords')->where('library_id', $libraryId)->count();
            DB::table('keyword_libraries')->where('id', $libraryId)->update(['keyword_count' => $n]);
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * 词库按类别统计（导入前给用户看"这个库现在有几条 A 几 条 C"）。
     *
     * @return array<string,int>
     */
    public function libraryStats(int $libraryId): array
    {
        $out = [];
        foreach (self::categories() as $c) {
            $out[(string) ($c['key'] ?? '')] = 0;
        }
        $out[''] = 0;

        try {
            $q = DB::table('keywords')->where('library_id', $libraryId);
            if ($this->hasCategoryColumn()) {
                $rows = $q->selectRaw('COALESCE(category, \'\') as cat, count(*) as n')->groupBy('cat')->get();
                foreach ($rows as $r) {
                    $k = (string) $r->cat;
                    $out[$k] = (int) $out[$k] + (int) $r->n;
                }
            } else {
                $out[''] = (int) $q->count();
            }
        } catch (\Throwable) {
        }

        return $out;
    }
}
