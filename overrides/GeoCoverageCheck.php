<?php

namespace App\Support\GeoAudit;

use App\Data\Ai\SystemAiIdentity;
use App\Services\GeoFlow\AiVisibility\AiVisibilityCollectionService;
use Illuminate\Support\Facades\DB;

/**
 * 覆盖核查引擎（检索层版）：把问题丢给 AI 搜索引擎，看你的页面排第几。
 *
 * ── 这一版和上一版的根本区别 ─────────────────────────────────────────
 * 上一版是「语料层」：让模型凭训练语料直接答，看回答里有没有你。
 *   问题：模型不联网，今天发的文章它永远不知道 → 生产完复测数字不会动。
 *
 * 这一版是「检索层」：调真实搜索引擎，看真实网页排名。
 *   问题解决了：内容被收录，排名就会变，复测才有意义。
 *
 * ── 为什么直接走官方采集管线 ────────────────────────────────────────
 * 不自己写一遍搜索调用，而是复用 AiVisibilityCollectionService::collect()：
 *   · 数据自动进 ai_visibility_runs / ai_visibility_sources
 *   · 数据中心的提及率 / 前三率 / 第一推荐位率直接用这批数据
 *   · 一次核查 = 一次采样，不需要为了复测单独再跑一遍
 *
 * ── 判定为什么用规则 ────────────────────────────────────────────────
 * "我的域名在第几条"是数值比较，规则可复现、零幻觉。
 * 而且这里把两种情况分开统计，因为它们对应完全不同的动作：
 *   · 自有页面进榜（owned）   → 有阵地，要争位置
 *   · 仅被第三方提及（mention）→ 有讨论没落地页，要补自己的内容
 */
final class GeoCoverageCheck
{
    /**
     * normalizeQuestions() 的硬顶（防止一条请求里塞进几万行把内存撑爆）。
     *
     * 【本地修复】原来是 20，和表单页的「本次核查题数 6/12/20」绑在一起：
     * 79 个词的词库永远只能跑 20 题。题数下拉删除后，业务上限改由
     * routes/web.php 的 COVERAGE_MAX_QUESTIONS（500）把关 —— 超了是**报错**，
     * 不是截断。这里只是防内存的硬顶，不参与任何业务判断，所以放到远高于 500。
     * 方法体一行未改：仍然是"去空白 → 去重复 → 砍到 120 字 → 撞硬顶停"。
     */
    public const MAX_QUESTIONS = 2000;

    /** 报告分块统计认的四个类别，与 keywords.category 对齐 */
    public const CATEGORIES = ['A', 'B', 'C', 'D'];

    /** 类别展示名（报告里按这个顺序：先缺口最狠的 B/D，再 C，最后单独一块 A） */
    public const CATEGORY_LABELS = [
        'A' => 'A 品牌认知',
        'B' => 'B 品类场景',
        'C' => 'C 产品深挖',
        'D' => 'D 竞品对比',
    ];

    /**
     * 品牌/产品名解析：支持中英文逗号、顿号、分号、竖线、换行分隔。
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[,，、;；|\/\n\r]+/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && mb_strlen($p, 'UTF-8') <= 30) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 从站点 URL 推导可用于匹配的域名关键词。
     * https://www.binsis.cn/portal → ['binsis.cn', 'binsis']
     *
     * @return array{host:string,keys:list<string>}
     */
    public static function domainOf(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['host' => '', 'keys' => []];
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return ['host' => '', 'keys' => []];
        }
        $bare = preg_replace('/^www\./', '', $host) ?? $host;
        $keys = [$bare];
        $first = explode('.', $bare)[0] ?? '';
        // 首段 ≥4 个字符才拿来匹配，避免 "www"/"com" 这类误命中
        if (strlen($first) >= 4 && ! in_array($first, ['www', 'com', 'cn'], true)) {
            $keys[] = $first;
        }

        return ['host' => $bare, 'keys' => array_values(array_unique($keys))];
    }

    /**
     * 问题集归一：去空、去重、截断。
     *
     * @param  list<string>  $raw
     * @return list<string>
     */
    public static function normalizeQuestions(array $raw): array
    {
        $out = [];
        $seen = [];
        foreach ($raw as $q) {
            $q = trim(preg_replace('/\s+/u', ' ', (string) $q) ?? (string) $q);
            if ($q === '' || mb_strlen($q, 'UTF-8') < 4) {
                continue;
            }
            $key = mb_strtolower($q, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = mb_substr($q, 0, 120, 'UTF-8');
            if (count($out) >= self::MAX_QUESTIONS) {
                break;
            }
        }

        return $out;
    }

    /**
     * 核查单题：调真实搜索，看排位。
     *
     * $rounds > 1 时会重复采样同一题，把"单次结果"变成"概率"。
     * 这一步很重要：单次搜索结果有天然波动，只跑一次说"我排第 3"经不起追问；
     * 跑 3 次说"3 次里 2 次进前三（67%）"才是能拿去汇报的数据。
     *
     * @param  list<string>  $brands  品牌名（含别名）
     * @param  list<string>  $domains  自有域名关键词
     * @param  list<string>  $competitors  对照品牌（可选）
     * @param  int  $rounds  采样轮数 1–5
     * @return array<string,mixed>
     */
    public function probe(string $question, array $brands, array $domains, array $competitors = [], int $rounds = 1): array
    {
        $rounds = max(1, min(5, $rounds));

        $base = [
            'question' => $question,
            'covered' => false,
            'covered_type' => '',
            'owned_rank' => null,
            'mention_rank' => null,
            'best_rank' => null,
            'tier' => '未进前 10',
            'mine_url' => '',
            'mine_title' => '',
            'top1_domain' => '',
            'top1_title' => '',
            'hit_competitors' => [],
            'sources_count' => 0,
            'run_id' => 0,
            // 【本地修复】这一次 probe 实际落库的全部 run id（含失败重试产生的）。
            // 原来只回传最后一个 run_id，调用方没法把整批采样挂到「核查批次」上，
            // 历史报告就只能按"品牌全部历史"统计，分不出这一批是哪几题。
            // 判定逻辑一行未动，这里只是把已经落库的 id 一并带出去。
            'run_ids' => [],
            'elapsed_ms' => 0,
            'error' => '',
            // 重复采样专项字段
            'rounds' => 0,
            'covered_rounds' => 0,
            'hit_rate' => 0,
            'top3_rounds' => 0,
            'top3_rate' => 0,
            'avg_rank' => null,
            'worst_rank' => null,
            'ranks' => [],
            'errors' => [],
        ];

        $started = microtime(true);
        $identity = SystemAiIdentity::forVisibilityCollection();
        $service = app(AiVisibilityCollectionService::class);

        $collectRounds = [];
        $errors = [];
        $lastRunId = 0;
        // 每次尝试（成功或失败）都会在 ai_visibility_runs 落一条，
        // 这里全部记下来，交给调用方写 ai_visibility_batch_runs。
        /** @var list<int> $runIds */
        $runIds = [];

        // 允许额外重试：目标是凑满 $rounds 次"有效采样"。
        // 上游搜索偶发失败（ai_provider_request_failed）很常见，
        // 如果按轮数硬跑，一次抖动就废掉一整题，用户看到的"失败"里有一半是网络问题。
        $maxAttempts = $rounds + 2;
        $attempt = 0;

        while (count($collectRounds) < $rounds && $attempt < $maxAttempts) {
            $attempt++;

            try {
                // 走官方采集管线：每轮都会落一条 ai_visibility_runs，数据中心可复算
                $runs = $service->collect($identity, $question);

                $run = null;
                foreach ($runs as $key => $candidate) {
                    if ($key === 'search_run') {
                        $run = $candidate;
                        break;
                    }
                    $run = $run ?? $candidate;
                }

                if ($run === null) {
                    $errors[] = '第 '.$attempt.' 次尝试：采集没有返回结果';
                    usleep(1200000);
                    continue;
                }

                $lastRunId = (int) $run->getKey();
                if ($lastRunId > 0 && ! in_array($lastRunId, $runIds, true)) {
                    $runIds[] = $lastRunId;
                }
                $sources = $run->sources()->orderByRaw('COALESCE(rank, 999999) asc')->orderBy('id')->get();

                if ($sources->isEmpty()) {
                    $errors[] = '第 '.$attempt.' 次尝试：搜索无结果（可能被限流）';
                    usleep(1200000);
                    continue;
                }

                $collectRounds[] = $this->judge($sources, $brands, $domains, $competitors);
            } catch (\Throwable $e) {
                $errors[] = '第 '.$attempt.' 次尝试：'.mb_substr($e->getMessage(), 0, 80, 'UTF-8');
                usleep(1200000);
                continue;
            }

            // 成功一档后稍作停顿，避免连续请求被上游限流
            if (count($collectRounds) < $rounds) {
                usleep(600000);
            }
        }

        if ($collectRounds === []) {
            return array_merge($base, [
                'error' => $errors !== [] ? implode('；', array_slice($errors, 0, 2)) : '搜索全部失败',
                'errors' => $errors,
                'run_ids' => $runIds,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }

        $agg = $this->aggregate($collectRounds, $rounds);

        return array_merge($base, $agg, [
            'run_id' => $lastRunId,
            'run_ids' => $runIds,
            'errors' => $errors,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * 多轮结果聚合：把 N 次采样压成一组概率指标。
     *
     * 判定口径（写清楚，免得被追问）：
     *   · covered 用多数决：命中轮数 ≥ 总轮数一半 才算"覆盖"
     *   · best_rank 取所有轮里最好的名次（对外的"最好成绩"）
     *   · worst_rank 取最差（体现波动幅度）
     *   · avg_rank 只统计命中轮次，未进前 10 的轮次不参与平均
     *
     * @param  list<array<string,mixed>>  $rounds
     * @return array<string,mixed>
     */
    private function aggregate(array $rounds, int $planned): array
    {
        $done = count($rounds);
        $coveredRounds = 0;
        $top3Rounds = 0;
        $ranks = [];
        $hitRanks = [];
        $competitors = [];
        $top1Domain = '';
        $top1Title = '';
        $mineUrl = '';
        $mineTitle = '';
        $ownedType = '';
        $sourcesCount = 0;

        foreach ($rounds as $r) {
            $rank = $r['best_rank'] ?? null;
            $ranks[] = $rank;
            if ($rank !== null) {
                $coveredRounds++;
                $hitRanks[] = (int) $rank;
                if ((int) $rank <= 3) {
                    $top3Rounds++;
                }
                if ($r['covered_type'] === '自有页面' && $ownedType === '') {
                    $ownedType = '自有页面';
                    $mineUrl = (string) ($r['mine_url'] ?? '');
                    $mineTitle = (string) ($r['mine_title'] ?? '');
                }
            }
            if ($top1Domain === '' && ! empty($r['top1_domain'])) {
                $top1Domain = (string) $r['top1_domain'];
                $top1Title = (string) ($r['top1_title'] ?? '');
            }
            foreach ((array) ($r['hit_competitors'] ?? []) as $name => $rk) {
                if (! isset($competitors[$name]) || (int) $rk < (int) $competitors[$name]) {
                    $competitors[$name] = (int) $rk;
                }
            }
            $sourcesCount = max($sourcesCount, (int) ($r['sources_count'] ?? 0));
        }

        $hitRate = $done > 0 ? (int) round($coveredRounds / $done * 100) : 0;
        $top3Rate = $done > 0 ? (int) round($top3Rounds / $done * 100) : 0;
        $best = $hitRanks !== [] ? min($hitRanks) : null;
        $worst = $hitRanks !== [] ? max($hitRanks) : null;
        $avg = $hitRanks !== [] ? round(array_sum($hitRanks) / count($hitRanks), 1) : null;

        // 多数决：命中轮数达到总轮数一半以上
        $covered = $coveredRounds > 0 && $coveredRounds * 2 >= $done;

        $type = '';
        if ($covered) {
            $type = $ownedType !== '' ? '自有页面' : '仅被提及';
        }

        $tier = '未进前 10';
        if ($best !== null) {
            $tier = $best === 1 ? '第一推荐位' : ($best <= 3 ? '前三' : '前 10');
        }

        return [
            'covered' => $covered,
            'covered_type' => $type,
            'owned_rank' => $ownedType !== '' ? $best : null,
            'mention_rank' => $ownedType === '' && $best !== null ? $best : null,
            'best_rank' => $best,
            'worst_rank' => $worst,
            'avg_rank' => $avg,
            'tier' => $tier,
            'mine_url' => $mineUrl,
            'mine_title' => $mineTitle,
            'top1_domain' => $top1Domain,
            'top1_title' => $top1Title,
            'hit_competitors' => $competitors,
            'sources_count' => $sourcesCount,
            'rounds' => $done,
            'planned_rounds' => $planned,
            'covered_rounds' => $coveredRounds,
            'hit_rate' => $hitRate,
            'top3_rounds' => $top3Rounds,
            'top3_rate' => $top3Rate,
            'ranks' => $ranks,
        ];
    }

    /**
     * 纯规则判定（对外可见，便于复核）。
     *
     * @param  iterable<int,object>  $sources  AiVisibilitySource 集合，已按 rank 升序
     * @param  list<string>  $brands
     * @param  list<string>  $domains
     * @param  list<string>  $competitors
     * @return array<string,mixed>
     */
    public function judge(iterable $sources, array $brands, array $domains, array $competitors = []): array
    {
        $ownedRank = null;
        $ownedUrl = '';
        $ownedTitle = '';
        $mentionRank = null;
        $top1Domain = '';
        $top1Title = '';
        $competitorHits = [];
        $count = 0;

        foreach ($sources as $index => $source) {
            $count++;
            $rank = (int) (($source->rank ?? 0) ?: $index + 1);
            $url = trim((string) ($source->url ?? ''));
            $domain = mb_strtolower(trim((string) ($source->domain ?? '')), 'UTF-8');
            $siteName = trim((string) ($source->site_name ?? ''));
            $title = trim((string) ($source->title ?? ''));

            // 第 1 名是谁——未覆盖时这是最有说服力的证据（"AI 现在推的是它"）
            if ($rank === 1) {
                $top1Domain = $domain !== '' ? $domain : $siteName;
                $top1Title = $title;
            }

            $hay = mb_strtolower(implode(' ', array_filter([
                $url, $domain, $siteName, $title,
            ], static fn ($v): bool => trim((string) $v) !== '')), 'UTF-8');

            // ① 自有域名命中：你家的页面进了推荐位
            if ($ownedRank === null) {
                foreach ($domains as $d) {
                    $needle = mb_strtolower(trim((string) $d), 'UTF-8');
                    if ($needle === '' || strlen($needle) < 4) {
                        continue;
                    }
                    if ($domain !== '' && str_contains($domain, $needle)) {
                        $ownedRank = $rank;
                        $ownedUrl = $url;
                        $ownedTitle = $title;
                        break;
                    }
                }
            }

            // ② 品牌名命中但不是自家域名：被第三方提到（有讨论、没阵地）
            if ($mentionRank === null && $ownedRank !== $rank) {
                foreach ($brands as $b) {
                    $needle = mb_strtolower(trim((string) $b), 'UTF-8');
                    if ($needle === '' || mb_strlen($needle, 'UTF-8') < 2) {
                        continue;
                    }
                    if (str_contains($hay, $needle)) {
                        $mentionRank = $rank;
                        break;
                    }
                }
            }

            // ③ 对照品牌：记下最早出现的排位
            foreach ($competitors as $c) {
                $needle = mb_strtolower(trim((string) $c), 'UTF-8');
                if ($needle === '') {
                    continue;
                }
                if (str_contains($hay, $needle) && ! isset($competitorHits[$c])) {
                    $competitorHits[$c] = $rank;
                }
            }
        }

        // 自有页面优先：哪怕被提及的名次更靠前，也应该先看自家阵地
        $bestRank = $ownedRank ?? $mentionRank;

        $coveredType = '';
        $tier = '未进前 10';
        $mineUrl = '';
        $mineTitle = '';

        if ($ownedRank !== null) {
            $coveredType = '自有页面';
            $mineUrl = $ownedUrl;
            $mineTitle = $ownedTitle;
        } elseif ($mentionRank !== null) {
            $coveredType = '仅被提及';
        }

        if ($bestRank !== null) {
            $tier = $bestRank === 1 ? '第一推荐位' : ($bestRank <= 3 ? '前三' : '前 10');
        }

        return [
            'covered' => $bestRank !== null,
            'covered_type' => $coveredType,
            'owned_rank' => $ownedRank,
            'mention_rank' => $mentionRank,
            'best_rank' => $bestRank,
            'tier' => $tier,
            'mine_url' => $mineUrl,
            'mine_title' => $mineTitle,
            'top1_domain' => $top1Domain,
            'top1_title' => $top1Title,
            'hit_competitors' => $competitorHits,
            'sources_count' => $count,
        ];
    }

    // =========================================================================
    // 按问题类别聚合（报告分块用：纯读已有数据，零新增采集）
    // =========================================================================

    /**
     * 按 keywords.category（A/B/C/D）聚合历史采样，给报告页做分块指标。
     *
     * ── 为什么必须分块 ────────────────────────────────────────────────
     * A 类（品牌认知）是用户主动搜品牌名（"XX 是什么档次"），AI 回答里必然
     * 提到品牌，所以"覆盖率"在这类题上恒为 100%，方差为零。把它和 B/C/D
     * 混算成一个总覆盖率，只会把 B 类这种 0% 的真缺口抬到看不出问题。
     * 因此 A 类不参与"前三率 / 前 10 覆盖率"，改用三个有区分度的指标：
     *   官网占位率 / 首位归属 / 缺位替身榜（见下方返回结构）。
     *
     * ── 判定口径 ──────────────────────────────────────────────────────
     * 与 judge() 完全一致，不新增任何判定规则：
     *   · 自有页面：来源 domain 命中自有域名关键词（domain contains needle）
     *   · 仅被提及：来源 url/domain/site_name/title 命中品牌别名，且该来源不是自有域名
     *   · best_rank = 自有页面名次 ?? 被提及名次；两者都没有 = 未进前 10
     *
     * ── 为什么分块还要按品牌过滤 ──────────────────────────────────────
     * 不加过滤时 A 类会算出一个跨品牌的混合值（实测：冰溪 24/24 = 100%，
     * 海洋至尊 0/11 = 0%，混成 69%）。一个满分一个零分平均成"还行"，
     * 恰好把最重要的对比抹平了。所以这里按<b>当前品牌</b>只统计它的词库问题，
     * 判定自有域名也用<b>当前品牌</b>的 site_url —— 看的是"这个品牌表现如何"。
     *
     * ── 「官网」按哪个站点判定 ────────────────────────────────────────
     * 按当前品牌的 site_url（storage/app/geo-brands.json）。品牌档案里
     * 没有 site_url 时回退到 $ownDomains（本次核查登记的自有站点）。
     *
     * ── 数据范围 ──────────────────────────────────────────────────────
     * keywords 表里标了 A/B/C/D、且所属词库属于当前品牌的全部历史采样，按 run 去重。
     * 同一道题若同时存在于两个类别库（例如「XX 是什么档次」既在 A 库也在 D 库），
     * 两个类别各计一次——这是刻意保留的：那道题在两边都是有效样本。
     *
     * @param  list<string>  $ownDomains  兜底的自有域名关键词（品牌档案没有 site_url 时用）
     * @param  string|null  $brandSlug  品牌 slug；null = 按 ?brand= → cookie → default 解析
     * @param  list<int>|null  $runIds  只看这几条 run（历史报告按批次过滤）；
     *                                  null = 该品牌全部历史，[] = 这批还没有采样（统计全 0）
     * @return array{categories:array<string,array<string,mixed>>,own_sites:list<string>,
     *               brand:array{slug:string,name:string,site_url:string},
     *               brands:list<array{slug:string,name:string}>,
     *               all_questions:array<string,int>}
     */
    public static function categoryBreakdown(array $ownDomains = [], ?string $brandSlug = null, ?array $runIds = null): array
    {
        $fallbackNeedles = [];
        foreach ($ownDomains as $d) {
            $d = mb_strtolower(trim((string) $d), 'UTF-8');
            // 与 judge() 一致：短于 4 字节的关键词不参与匹配，避免 "cn"/"com" 误命中
            if ($d !== '' && strlen($d) >= 4) {
                $fallbackNeedles[] = $d;
            }
        }

        // ---------- 当前品牌 ----------
        $brand = GeoShareOfVoice::brand($brandSlug);
        $brandName = $brand !== null ? trim((string) ($brand['name'] ?? '')) : '';
        $brandSlugUsed = $brand !== null ? trim((string) ($brand['slug'] ?? '')) : '';
        $siteUrl = $brand !== null ? trim((string) ($brand['site_url'] ?? '')) : '';
        $siteKeys = $siteUrl !== '' ? (array) (self::domainOf($siteUrl)['keys'] ?? []) : [];
        $needles = $siteKeys !== [] ? $siteKeys : $fallbackNeedles;

        $brandOptions = [];
        foreach (GeoShareOfVoice::brands() as $b) {
            $slug = trim((string) ($b['slug'] ?? ''));
            $name = trim((string) ($b['name'] ?? ''));
            if ($slug !== '') {
                $brandOptions[] = ['slug' => $slug, 'name' => $name !== '' ? $name : $slug];
            }
        }

        $rows = DB::table('ai_visibility_runs as r')
            ->join('keywords as k', 'k.keyword', '=', 'r.keyword')
            ->leftJoin('ai_visibility_sources as s', 's.ai_visibility_run_id', '=', 'r.id')
            ->whereIn('k.category', self::CATEGORIES)
            // 历史报告按批次看时只统计这一批的采样；null = 该品牌全部历史（原行为）
            ->when($runIds !== null, static fn ($q) => $q->whereIn('r.id', $runIds))
            ->orderBy('r.id')
            ->orderByRaw('COALESCE(s.rank, 999999) asc')
            ->orderBy('s.id')
            ->get([
                'k.category as category',
                'k.library_id as library_id',
                'k.keyword as keyword',
                'r.id as run_id',
                's.id as source_id',
                's.rank as rank',
                's.url as url',
                's.domain as domain',
                's.site_name as site_name',
                's.title as title',
            ]);

        $libNames = DB::table('keyword_libraries')->pluck('name', 'id')->all();
        $aliasMap = self::brandAliasMap();

        // 词库 → 品牌（"冰溪｜A 品牌认知" 的库属于冰溪）。只留当前品牌的词库，
        // 这样"别的品牌的题"不会跑进这张表把数搅浑。
        /** @var array<int,bool> $libAllowed */
        $libAllowed = [];
        foreach ($libNames as $libId => $libName) {
            $owner = self::libraryBrandName((string) $libName);
            if ($brandName === '' || $owner === $brandName) {
                $libAllowed[(int) $libId] = true;
            }
        }

        /** @var array<int,list<string>> $libAliases 词库 id => 该库品牌的别名（小写） */
        $libAliases = [];

        $stats = [];
        foreach (self::CATEGORIES as $c) {
            $stats[$c] = [
                'category' => $c,
                'label' => self::CATEGORY_LABELS[$c],
                'questions' => 0,
                'samples' => 0,
                'with_src' => 0,
                'own_present' => 0,
                'own_rate' => 0,
                'own_rank1' => 0,
                'own_rank23' => 0,
                'own_rank4p' => 0,
                'own_absent' => 0,
                'brand_present' => 0,
                'brand_rate' => 0,
                'covered' => 0,
                'cover_rate' => 0,
                'top3' => 0,
                'top3_rate' => 0,
                'replacements' => [],
            ];
        }

        // ---------- 第一遍：把行摊成「类别 → run → 判定结果」 ----------
        /** @var array<string,array<int,array<string,mixed>>> $runs */
        $runs = [];
        /** @var array<string,array<string,bool>> $questions */
        $questions = [];
        /** @var array<string,array<string,bool>> $allQuestions 全品牌题数（披露口径用） */
        $allQuestions = [];

        foreach ($rows as $row) {
            $cat = (string) ($row->category ?? '');
            if (! isset($stats[$cat])) {
                continue;
            }
            $runId = (int) ($row->run_id ?? 0);
            $keyword = (string) ($row->keyword ?? '');
            $libId = (int) ($row->library_id ?? 0);

            // 全部品牌的题数（只为页面上写出"22 题中属于冰溪的 6 题"）
            $allQuestions[$cat][$keyword] = true;

            // 别的品牌的词库：不计入这张表
            if (! isset($libAllowed[$libId])) {
                continue;
            }

            if (! isset($libAliases[$libId])) {
                $ownerBrand = self::libraryBrandName((string) ($libNames[$libId] ?? ''));
                $aliases = $aliasMap[$ownerBrand] ?? [];
                if ($aliases === [] && $ownerBrand !== '') {
                    $aliases = [mb_strtolower($ownerBrand, 'UTF-8')];
                }
                $libAliases[$libId] = $aliases;
            }

            if (! isset($runs[$cat][$runId])) {
                $runs[$cat][$runId] = [
                    'has_src' => false,
                    'own_rank' => null,
                    'mention_rank' => null,
                    'top1_domain' => '',
                    'top1_site' => '',
                    'aliases' => $libAliases[$libId],
                ];
                $questions[$cat][$keyword] = true;
            }

            // leftJoin 的占位行：这道题一条信源都没有（被限流 / 搜索无结果）
            if ($row->source_id === null) {
                continue;
            }

            $rank = (int) ($row->rank ?? 0);
            $url = trim((string) ($row->url ?? ''));
            $domain = mb_strtolower(trim((string) ($row->domain ?? '')), 'UTF-8');
            $siteName = trim((string) ($row->site_name ?? ''));
            $title = trim((string) ($row->title ?? ''));

            $runs[$cat][$runId]['has_src'] = true;

            // 第 1 名是谁——官网缺席时这是最有说服力的证据（"AI 现在推的是它"）
            if ($rank === 1) {
                $runs[$cat][$runId]['top1_domain'] = $domain !== '' ? $domain : $siteName;
                $runs[$cat][$runId]['top1_site'] = $siteName !== '' ? $siteName : $domain;
            }

            // ① 自有域名命中（用当前品牌的站点判定）
            $isOwn = false;
            if ($domain !== '') {
                foreach ($needles as $needle) {
                    if ($needle !== '' && str_contains($domain, $needle)) {
                        $isOwn = true;
                        break;
                    }
                }
            }
            if ($isOwn) {
                $cur = $runs[$cat][$runId]['own_rank'];
                if ($cur === null || $rank < (int) $cur) {
                    $runs[$cat][$runId]['own_rank'] = $rank;
                }
                continue;
            }

            // ② 品牌名命中但不是自家域名：被第三方提到（有讨论、没阵地）
            $hay = mb_strtolower(implode(' ', array_filter(
                [$url, $domain, $siteName, $title],
                static fn ($v): bool => trim((string) $v) !== ''
            )), 'UTF-8');
            foreach ($runs[$cat][$runId]['aliases'] as $alias) {
                if (mb_strlen($alias, 'UTF-8') < 2) {
                    continue;
                }
                if (str_contains($hay, $alias)) {
                    $cur = $runs[$cat][$runId]['mention_rank'];
                    if ($cur === null || $rank < (int) $cur) {
                        $runs[$cat][$runId]['mention_rank'] = $rank;
                    }
                    break;
                }
            }
        }

        // ---------- 第二遍：算指标 ----------
        foreach (self::CATEGORIES as $cat) {
            $list = $runs[$cat] ?? [];
            $stat = $stats[$cat];
            $stat['questions'] = count($questions[$cat] ?? []);
            $stat['samples'] = count($list);

            $replacements = [];
            foreach ($list as $run) {
                if (empty($run['has_src'])) {
                    continue;
                }
                $stat['with_src']++;

                if ($run['own_rank'] !== null) {
                    $stat['own_present']++;
                    $ownRank = (int) $run['own_rank'];
                    if ($ownRank === 1) {
                        $stat['own_rank1']++;
                    } elseif ($ownRank <= 3) {
                        $stat['own_rank23']++;
                    } else {
                        $stat['own_rank4p']++;
                    }
                } else {
                    $stat['own_absent']++;
                    // 缺位替身榜：官网缺席时 top1 的域名频次
                    $key = (string) $run['top1_domain'];
                    if ($key !== '') {
                        if (! isset($replacements[$key])) {
                            $replacements[$key] = [
                                'domain' => $key,
                                'name' => (string) ($run['top1_site'] !== '' ? $run['top1_site'] : $key),
                                'count' => 0,
                            ];
                        }
                        $replacements[$key]['count']++;
                    }
                }

                if ($run['mention_rank'] !== null) {
                    $stat['brand_present']++;
                }

                $best = $run['own_rank'] ?? $run['mention_rank'];
                if ($best !== null) {
                    $stat['covered']++;
                    if ((int) $best <= 3) {
                        $stat['top3']++;
                    }
                }
            }

            $den = (int) $stat['with_src'];
            $stat['own_rate'] = $den > 0 ? (int) round($stat['own_present'] / $den * 100) : 0;
            $stat['brand_rate'] = $den > 0 ? (int) round($stat['brand_present'] / $den * 100) : 0;
            $stat['cover_rate'] = $den > 0 ? (int) round($stat['covered'] / $den * 100) : 0;
            $stat['top3_rate'] = $den > 0 ? (int) round($stat['top3'] / $den * 100) : 0;

            $replacements = array_values($replacements);
            // 只按次数倒序；次数相同时保留先后顺序（PHP 8 的 sort 是稳定排序），
            // 这样并列的域名按首次出现排序，不会每次刷新跳来跳去。
            usort($replacements, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $stat['replacements'] = array_slice($replacements, 0, 6);

            $stats[$cat] = $stat;
        }

        $allQuestionCounts = [];
        foreach (self::CATEGORIES as $c) {
            $allQuestionCounts[$c] = count($allQuestions[$c] ?? []);
        }

        return [
            'categories' => $stats,
            'own_sites' => $siteUrl !== '' ? [$siteUrl] : [],
            'brand' => ['slug' => $brandSlugUsed, 'name' => $brandName, 'site_url' => $siteUrl],
            'brands' => $brandOptions,
            'all_questions' => $allQuestionCounts,
        ];
    }

    /**
     * A 类逐次采样明细 —— 「信源缺口清单」的数据源。
     *
     * ── 为什么需要逐条明细，而不是再算一遍比率 ────────────────────────
     * 报告页的 A 区块已经给了「官网占位率 / 首位归属 / 缺位替身榜」三个数，
     * 但运营真正要动手时问的是"具体是哪几道题、现在第 1 名被谁占着"。
     * 所以这里把「一次采样」摊成一行，页面直接列出来。
     *
     * ── 口径必须与 categoryBreakdown() 的 A 块逐字一致 ────────────────
     * 不一致就会出现最难看的那种自相矛盾：上面写「界面新闻网 5 次」，
     * 下面清单只列 4 条。所以三件事都照抄，不另立规则：
     *   · 品牌过滤：词库名（"海洋至尊｜A 品牌认知"）→ 品牌名，只留当前品牌的库
     *   · 官网判定：source.domain 包含自有域名关键词才算官网（<4 字节的关键词不参与匹配）
     *   · 去重单位：一次 ai_visibility_runs = 一次采样（同一 run 的多条信源合成一行）
     *
     * 没有信源的 run（被限流 / 搜索无结果）不返回：它无法判断官网在不在，
     * 算进缺口就是拿网络故障当内容缺口。
     *
     * @param  list<string>  $ownDomains  兜底的自有域名关键词（品牌档案没有 site_url 时用）
     * @param  string|null  $brandSlug  品牌 slug；null = 按 ?brand= → cookie → default 解析
     * @param  list<int>|null  $runIds  只看这几条 run（历史报告按批次过滤）；
     *                                  null = 该品牌全部历史，[] = 这批还没有采样
     * @return list<array{run_id:int,question:string,own_rank:int|null,
     *                    top1_domain:string,top1_site:string,top1_title:string}>
     */
    public static function authorityGapSamples(array $ownDomains = [], ?string $brandSlug = null, ?array $runIds = null): array
    {
        $fallbackNeedles = [];
        foreach ($ownDomains as $d) {
            $d = mb_strtolower(trim((string) $d), 'UTF-8');
            // 与 categoryBreakdown() / judge() 一致：短于 4 字节的关键词不参与匹配
            if ($d !== '' && strlen($d) >= 4) {
                $fallbackNeedles[] = $d;
            }
        }

        $brand = GeoShareOfVoice::brand($brandSlug);
        $brandName = $brand !== null ? trim((string) ($brand['name'] ?? '')) : '';
        $siteUrl = $brand !== null ? trim((string) ($brand['site_url'] ?? '')) : '';
        $siteKeys = $siteUrl !== '' ? (array) (self::domainOf($siteUrl)['keys'] ?? []) : [];
        $needles = $siteKeys !== [] ? $siteKeys : $fallbackNeedles;

        // 词库 → 品牌，只留当前品牌的库（别的品牌的题不参与）
        /** @var array<int,bool> $libAllowed */
        $libAllowed = [];
        foreach (DB::table('keyword_libraries')->pluck('name', 'id')->all() as $libId => $libName) {
            $owner = self::libraryBrandName((string) $libName);
            if ($brandName === '' || $owner === $brandName) {
                $libAllowed[(int) $libId] = true;
            }
        }

        if ($libAllowed === []) {
            return [];
        }

        $rows = DB::table('ai_visibility_runs as r')
            ->join('keywords as k', 'k.keyword', '=', 'r.keyword')
            ->leftJoin('ai_visibility_sources as s', 's.ai_visibility_run_id', '=', 'r.id')
            ->where('k.category', 'A')
            ->whereIn('k.library_id', array_keys($libAllowed))
            // 历史报告按批次看时只统计这一批的采样；null = 该品牌全部历史（原行为）
            ->when($runIds !== null, static fn ($q) => $q->whereIn('r.id', $runIds))
            ->orderBy('r.id')
            ->orderByRaw('COALESCE(s.rank, 999999) asc')
            ->orderBy('s.id')
            ->get([
                'r.id as run_id',
                'r.keyword as keyword',
                's.id as source_id',
                's.rank as rank',
                's.domain as domain',
                's.site_name as site_name',
                's.title as title',
            ]);

        // 一次 run = 一行。用 run_id 归并，别用 keyword：
        // 同一道题跑过多轮（每题 3 次采样）必须保持"几次缺位"的计数，
        // 按题折叠会把 11 次缺位压成 10 道题，和上面的缺位替身榜对不上。
        /** @var array<int,array<string,mixed>> $runs */
        $runs = [];
        foreach ($rows as $row) {
            $runId = (int) ($row->run_id ?? 0);
            if ($runId <= 0) {
                continue;
            }
            if (! isset($runs[$runId])) {
                $runs[$runId] = [
                    'run_id' => $runId,
                    'question' => trim((string) ($row->keyword ?? '')),
                    'has_src' => false,
                    'own_rank' => null,
                    'top1_domain' => '',
                    'top1_site' => '',
                    'top1_title' => '',
                ];
            }

            // leftJoin 的占位行：这道题这一次采样一条信源都没有
            if ($row->source_id === null) {
                continue;
            }

            $runs[$runId]['has_src'] = true;

            $rank = (int) ($row->rank ?? 0);
            $domain = mb_strtolower(trim((string) ($row->domain ?? '')), 'UTF-8');
            $siteName = trim((string) ($row->site_name ?? ''));
            $title = trim((string) ($row->title ?? ''));

            // 第 1 名是谁——官网缺席时这是最有说服力的证据
            if ($rank === 1) {
                $runs[$runId]['top1_domain'] = $domain !== '' ? $domain : $siteName;
                $runs[$runId]['top1_site'] = $siteName !== '' ? $siteName : $domain;
                $runs[$runId]['top1_title'] = $title;
            }

            if ($domain === '') {
                continue;
            }
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($domain, $needle)) {
                    $cur = $runs[$runId]['own_rank'];
                    if ($cur === null || $rank < (int) $cur) {
                        $runs[$runId]['own_rank'] = $rank;
                    }
                    break;
                }
            }
        }

        $out = [];
        foreach ($runs as $run) {
            if (empty($run['has_src'])) {
                continue;
            }
            $out[] = [
                'run_id' => (int) $run['run_id'],
                'question' => (string) $run['question'],
                'own_rank' => $run['own_rank'],
                'top1_domain' => (string) $run['top1_domain'],
                'top1_site' => (string) $run['top1_site'],
                'top1_title' => (string) $run['top1_title'],
            ];
        }

        return $out;
    }

    // =========================================================================
    // 核查批次（历史报告）：把「这一次核查」从 session 里解放出来
    // =========================================================================

    /**
     * 核查开始时建批次记录，返回批次 id（失败返回 0）。
     *
     * ── 为什么要存这些快照 ────────────────────────────────────────────
     * 历史报告如果跟着品牌档案变，等于篡改历史：
     *   · site_url 改了 → 当时判成"自有页面"的采样会变成"仅被提及"；
     *   · 题数 / 采样次数不存 → 页面上写不出"这批到底跑了几题几次"；
     *   · has_site 不存 → 以后做无官网口径时，回看旧报告分不清当时按哪套算的。
     * 所以这四样都在核查那一刻抄一份进批次表。
     *
     * 建批次失败不能影响核查本身（表没建 / 事务冲突）：返回 0，
     * 后续 attachRuns()/finalizeBatch() 收到 0 直接跳过，核查照跑，只是没留档。
     *
     * @param  array<string,mixed>  $ctx  核查上下文（brands / url / rounds / competitors）
     * @param  int  $questionsCount  本次计划跑多少题
     */
    public static function createBatch(array $ctx, int $questionsCount): int
    {
        try {
            $brandSlug = GeoCoverageReport::coverageBrandSlug($ctx);
            $first = trim((string) (($ctx['brands'] ?? [])[0] ?? ''));
            if ($first === '') {
                $first = trim((string) ($ctx['brand'] ?? ''));
            }
            $brandName = '';
            foreach (GeoShareOfVoice::brands() as $brand) {
                if (trim((string) ($brand['slug'] ?? '')) !== $brandSlug || $brandSlug === '') {
                    continue;
                }
                $brandName = trim((string) ($brand['name'] ?? ''));
                break;
            }
            if ($brandName === '') {
                $brandName = $first;
            }

            $siteUrl = trim((string) ($ctx['url'] ?? ''));
            // has_site：核查那一刻品牌档案里有没有官网。
            // 这一批先恒为 true（无官网口径下一批做），但字段必须写进去 ——
            // 否则以后回看旧报告分不清"当时按无官网口径跑的"和"压根没记录"。
            $hasSite = true;

            $admin = auth('admin')->user();
            $now = date('Y-m-d H:i:s');

            return (int) DB::table('ai_visibility_batches')->insertGetId([
                'brand_slug' => $brandSlug !== '' ? mb_substr($brandSlug, 0, 64, 'UTF-8') : 'unknown',
                'brand_name' => $brandName !== '' ? mb_substr($brandName, 0, 128, 'UTF-8') : null,
                'site_url' => $siteUrl !== '' ? mb_substr($siteUrl, 0, 255, 'UTF-8') : null,
                'has_site' => $hasSite,
                'questions_count' => max(0, $questionsCount),
                'rounds' => max(1, (int) ($ctx['rounds'] ?? 1)),
                'runs_count' => 0,
                'valid_count' => 0,
                'status' => 'running',
                'source' => 'coverage_check',
                'created_by' => $admin !== null ? (int) $admin->getKey() : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 把这一题产生的 run 挂到批次上（每跑完一题调用一次）。
     *
     * 用 insertOrIgnore 而不是 insert：同一批可能被重复提交（用户刷新页面重试某一题），
     * 主键冲突不该让整个 AJAX 请求 500，静默去重即可。
     *
     * @param  list<int>  $runIds
     * @return int 实际尝试写入的行数（0 = 跳过了）
     */
    public static function attachRuns(int $batchId, array $runIds): int
    {
        if ($batchId <= 0 || $runIds === []) {
            return 0;
        }

        $rows = [];
        foreach (array_unique(array_map('intval', $runIds)) as $runId) {
            if ($runId > 0) {
                $rows[] = ['batch_id' => $batchId, 'run_id' => $runId];
            }
        }
        if ($rows === []) {
            return 0;
        }

        try {
            DB::table('ai_visibility_batch_runs')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            return 0;
        }

        return count($rows);
    }

    /**
     * 同步批次的计数与状态。
     *
     * runs_count  = 关联表里这一批的 run 数（含失败重试产生的）
     * valid_count = 其中至少拿到 1 条信源的 run 数
     *
     * 每跑完一题都会调一次（status 仍是 running），全部跑完再调一次（completed）。
     * 这样即使用户中途关掉页面，下拉里也能看到"跑到第几题、多少条有效"。
     *
     * @param  string  $status  running / completed / aborted
     */
    public static function syncBatch(int $batchId, string $status = 'running'): void
    {
        if ($batchId <= 0) {
            return;
        }

        try {
            $runs = (int) DB::table('ai_visibility_batch_runs')->where('batch_id', $batchId)->count();
            $valid = (int) DB::table('ai_visibility_batch_runs as br')
                ->join('ai_visibility_sources as s', 's.ai_visibility_run_id', '=', 'br.run_id')
                ->where('br.batch_id', $batchId)
                ->distinct()
                ->count('br.run_id');

            DB::table('ai_visibility_batches')->where('id', $batchId)->update([
                'runs_count' => $runs,
                'valid_count' => $valid,
                'status' => in_array($status, ['running', 'completed', 'aborted'], true) ? $status : 'running',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 计数是"锦上添花"，不能因为它把核查结果本身弄丢
        }
    }

    /**
     * 一条批次记录还原成「核查上下文」。
     *
     * 会话过期后报告页仍然要能渲染，而渲染需要 ctx（品牌 / 站点 / 域名关键词 / 采样次数）。
     * 这些值在批次表里都有快照，直接拼回来即可 —— 注意品牌和站点取的是
     * <b>核查那一刻</b>的值，不是现在的品牌档案，否则历史报告会跟着档案变。
     *
     * @return array{brand:string,brands:list<string>,url:string,domains:list<string>,
     *               rounds:int,competitors:list<string>,batch_id:int}|null
     *         null = 批次不存在
     */
    public static function batchContext(int $batchId): ?array
    {
        if ($batchId <= 0) {
            return null;
        }

        $batch = DB::table('ai_visibility_batches')->where('id', $batchId)->first();
        if ($batch === null) {
            return null;
        }

        $brandName = trim((string) ($batch->brand_name ?? ''));
        $siteUrl = trim((string) ($batch->site_url ?? ''));
        $domains = (array) (self::domainOf($siteUrl)['keys'] ?? []);

        // 品牌名只用来标注"这次核查跑的是谁"，统计口径仍由 ?brand= 决定。
        $display = $brandName !== '' ? $brandName : trim((string) ($batch->brand_slug ?? ''));

        return [
            'brand' => $display,
            'brands' => $display !== '' ? [$display] : [],
            'url' => $siteUrl,
            'domains' => array_values($domains),
            'rounds' => max(1, (int) ($batch->rounds ?? 1)),
            'competitors' => [],
            'batch_id' => (int) $batch->id,
        ];
    }

    /**
     * 批次 → 它包含了哪些 run（升序）。
     *
     * 报告页按批次统计时用这个列表去过滤 categoryBreakdown() / authorityGapSamples()。
     *
     * @return list<int>
     */
    public static function batchRunIds(int $batchId): array
    {
        if ($batchId <= 0) {
            return [];
        }

        $ids = DB::table('ai_visibility_batch_runs')
            ->where('batch_id', $batchId)
            ->orderBy('run_id')
            ->pluck('run_id')
            ->all();

        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * 批次 → 逐题结果（会话过期后重建报告用）。
     *
     * ── 为什么不重跑一遍判定以外的任何东西 ────────────────────────────
     * 采样已经在 ai_visibility_runs / ai_visibility_sources 里，
     * 判定又是纯字符串规则（judge()），所以"重建"= 把当时的信源重新判一次，
     * 结果与当时页面上的完全一致，不需要也不允许重新调搜索。
     *
     * ── 多轮采样怎么合 ────────────────────────────────────────────────
     * 与 probe() 完全同一套：judge() 逐轮判 → aggregate() 多数决压成一行。
     * 这样"历史报告"和"当时看到的报告"对同一道题给出同一个结论。
     *
     * 没有信源的 run（被限流 / 搜索无结果）不参与多数决，全部轮次都空时
     * 该题标成失败 —— 与 probe() 的处理一致，不能拿网络故障当内容缺口。
     *
     * @return list<array<string,mixed>> 形状与 probe() 的返回一致，可直接喂 reportPage()
     */
    public static function resultsForBatch(int $batchId): array
    {
        if ($batchId <= 0) {
            return [];
        }

        $ctx = self::batchContext($batchId);
        if ($ctx === null) {
            return [];
        }

        $runRows = DB::table('ai_visibility_batch_runs as br')
            ->join('ai_visibility_runs as r', 'r.id', '=', 'br.run_id')
            ->where('br.batch_id', $batchId)
            ->orderBy('r.id')
            ->get(['r.id as run_id', 'r.keyword as keyword']);

        if ($runRows->isEmpty()) {
            return [];
        }

        $brands = self::batchBrandNames($batchId, (string) $ctx['brand']);
        $domains = (array) $ctx['domains'];
        $competitors = self::batchCompetitors($batchId);

        /** @var array<string,list<int>> $byQuestion 题面 => 该题的 run id 列表（按落库顺序） */
        $byQuestion = [];
        foreach ($runRows as $row) {
            $keyword = trim((string) ($row->keyword ?? ''));
            if ($keyword === '') {
                continue;
            }
            $byQuestion[$keyword][] = (int) $row->run_id;
        }

        $self = new self();
        $out = [];
        foreach ($byQuestion as $question => $runIds) {
            $judged = [];
            $lastError = '';
            foreach ($runIds as $runId) {
                $sources = DB::table('ai_visibility_sources')
                    ->where('ai_visibility_run_id', $runId)
                    ->orderByRaw('COALESCE(rank, 999999) asc')
                    ->orderBy('id')
                    ->get(['rank', 'url', 'domain', 'site_name', 'title']);

                if ($sources->isEmpty()) {
                    $lastError = '本批这次采样没有拿到有效信源（可能被限流），当次判定结果已不可得';
                    continue;
                }

                $judged[] = $self->judge($sources, $brands, $domains, $competitors);
            }

            if ($judged === []) {
                $out[] = [
                    'question' => $question,
                    'covered' => false,
                    'covered_type' => '',
                    'best_rank' => null,
                    'error' => $lastError !== '' ? $lastError : '本批没有可用的采样结果',
                    'run_ids' => $runIds,
                ];
                continue;
            }

            $agg = $self->aggregate($judged, count($runIds));

            $out[] = array_merge(['question' => $question], $agg, [
                'run_id' => (int) end($runIds),
                'run_ids' => $runIds,
                'error' => '',
                'elapsed_ms' => 0,
            ]);
        }

        return $out;
    }

    /**
     * 批次所属品牌的全部匹配名（小写无关，judge() 内部会归一）。
     *
     * 优先用品牌档案的 aliases（与当时核查时 judge() 拿到的列表基本一致），
     * 档案里认不出这个 slug 时退回批次快照的品牌名 —— 至少"品牌名出现"这一档还能判。
     *
     * @return list<string>
     */
    private static function batchBrandNames(int $batchId, string $fallbackName): array
    {
        $batch = DB::table('ai_visibility_batches')->where('id', $batchId)->first();
        $slug = $batch !== null ? trim((string) ($batch->brand_slug ?? '')) : '';
        $name = $batch !== null ? trim((string) ($batch->brand_name ?? '')) : '';

        $out = [];
        foreach (GeoShareOfVoice::brands() as $brand) {
            $brandSlug = trim((string) ($brand['slug'] ?? ''));
            // 不能用 GeoShareOfVoice::brand()：它认不出 slug 时会静默回退到第一个品牌，
            // 拿别家的别名去判这一批的题，会把"被提及"算错。
            if ($slug === '' || $brandSlug !== $slug) {
                continue;
            }
            foreach ((array) ($brand['aliases'] ?? []) as $a) {
                $a = trim((string) $a);
                if ($a !== '') {
                    $out[] = $a;
                }
            }
            foreach ([(string) ($brand['name'] ?? ''), (string) ($brand['full_name'] ?? '')] as $n) {
                $n = trim($n);
                if ($n !== '') {
                    $out[] = $n;
                }
            }
            break;
        }

        foreach ([$name, $fallbackName] as $n) {
            $n = trim($n);
            if ($n !== '') {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 批次所属品牌的竞品别名（只影响逐题的"竞品: 谁(第几)"标注，不参与覆盖判定）。
     *
     * @return list<string>
     */
    private static function batchCompetitors(int $batchId): array
    {
        $batch = DB::table('ai_visibility_batches')->where('id', $batchId)->first();
        $slug = $batch !== null ? trim((string) ($batch->brand_slug ?? '')) : '';
        if ($slug === '') {
            return [];
        }

        $out = [];
        foreach (GeoShareOfVoice::brands() as $brand) {
            if (trim((string) ($brand['slug'] ?? '')) !== $slug) {
                continue;
            }
            foreach ((array) ($brand['competitors'] ?? []) as $c) {
                if (! is_array($c)) {
                    continue;
                }
                $aliases = [];
                foreach ((array) ($c['aliases'] ?? []) as $a) {
                    $a = trim((string) $a);
                    if ($a !== '') {
                        $aliases[] = $a;
                    }
                }
                if ($aliases === []) {
                    $n = trim((string) ($c['name'] ?? ''));
                    if ($n !== '') {
                        $aliases[] = $n;
                    }
                }
                foreach ($aliases as $a) {
                    $out[$a] = true;
                }
            }
            break;
        }

        return array_keys($out);
    }

    /**
     * 词库名 → 品牌名："冰溪｜A 品牌认知" → "冰溪"
     */
    public static function libraryBrandName(string $libraryName): string
    {
        $name = trim($libraryName);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/[｜|]/u', $name) ?: [];

        return trim((string) ($parts[0] ?? ''));
    }

    /**
     * 品牌名 → 别名列表（小写）。
     *
     * 别名不在这里另维护一份：复用「声量对标」的品牌档案
     * （storage/app/geo-brands.json，GeoShareOfVoice::brands()），
     * 否则同一个品牌会出现两套别名，两边算出来的数对不上。
     *
     * @return array<string,list<string>>
     */
    private static function brandAliasMap(): array
    {
        $map = [];
        foreach (GeoShareOfVoice::brands() as $brand) {
            $aliases = [];
            foreach ((array) ($brand['aliases'] ?? []) as $a) {
                $a = mb_strtolower(trim((string) $a), 'UTF-8');
                if ($a !== '') {
                    $aliases[] = $a;
                }
            }
            if ($aliases === []) {
                foreach ([(string) ($brand['name'] ?? ''), (string) ($brand['full_name'] ?? '')] as $n) {
                    $n = mb_strtolower(trim($n), 'UTF-8');
                    if ($n !== '') {
                        $aliases[] = $n;
                    }
                }
            }
            $aliases = array_values(array_unique($aliases));
            if ($aliases === []) {
                continue;
            }
            foreach ([(string) ($brand['name'] ?? ''), (string) ($brand['full_name'] ?? '')] as $n) {
                $n = trim($n);
                if ($n !== '') {
                    $map[$n] = $aliases;
                }
            }
        }

        return $map;
    }
}
