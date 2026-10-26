<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;

/**
 * 声量对标：竞品 SOV ＋ 引用来源结构
 *
 * 和相邻模块的分工：
 *   - 覆盖核查：按【问题】看"我有没有进前 10"（单品牌视角）
 *   - AI 可见性：按【天】看整体 KPI（官方，单品牌）
 *   - 声量对标（本文件）：按【品牌】横向比"我 vs 竞品"＋ 按【域名】看"谁在抢位置"
 *
 * 零新增采集：全部读已有的 ai_visibility_runs / ai_visibility_sources。
 *
 * 两个指标为什么要合在一个模块：
 *   SOV 说的是"我占多少份额"，信源结构说的是"这份额凭什么来的"。
 *   只看 SOV 会误判——份额低可能是因为没写内容（内容问题），也可能是
 *   内容写了但没人替你背书（信任问题），这两件事的动作完全相反。
 */
final class GeoShareOfVoice
{
    private const CONFIG = 'app/geo-brands.json';

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    // =========================================================================
    // 品牌档案
    // =========================================================================

    /** @return array<string,mixed> */
    public static function raw(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $path = storage_path(self::CONFIG);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return self::$cache = is_array($data) ? $data : ['default' => '', 'brands' => []];
    }

    /** @return list<array<string,mixed>> */
    public static function brands(): array
    {
        return array_values(array_filter((array) (self::raw()['brands'] ?? []), 'is_array'));
    }

    /** 当前口径的品牌 slug：URL 参数 → cookie → default */
    public static function resolveSlug(?string $slug = null): string
    {
        if ($slug !== null && trim($slug) !== '') {
            return trim($slug);
        }
        $fromRequest = trim((string) ($_GET['brand'] ?? $_COOKIE['geo_brand'] ?? ''));
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        return (string) (self::raw()['default'] ?? '');
    }

    /** @return array<string,mixed>|null */
    public static function brand(?string $slug = null): ?array
    {
        $slug = self::resolveSlug($slug);
        foreach (self::brands() as $b) {
            if ((string) ($b['slug'] ?? '') === $slug) {
                return $b;
            }
        }
        // slug 不存在时退回第一个，保证页面不白屏
        $list = self::brands();

        return $list[0] ?? null;
    }

    /**
     * 拼出参与 SOV 计算的全部对象：自己 + 竞品。
     *
     * @return list<array{key:string,name:string,aliases:list<string>,is_self:bool}>
     */
    public static function roster(?array $brand = null): array
    {
        $brand ??= self::brand();
        if ($brand === null) {
            return [];
        }

        $aliases = array_values(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            (array) ($brand['aliases'] ?? [])
        ), static fn ($v) => $v !== ''));

        // 没配 aliases 时用 name + full_name 兜底
        if ($aliases === []) {
            $aliases = array_values(array_filter([
                trim((string) ($brand['name'] ?? '')),
                trim((string) ($brand['full_name'] ?? '')),
            ], static fn ($v) => $v !== ''));
        }

        $out = [[
            'key' => 'self',
            'name' => (string) ($brand['name'] ?? '自己'),
            'aliases' => $aliases,
            'is_self' => true,
        ]];

        $i = 0;
        foreach ((array) ($brand['competitors'] ?? []) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $cAliases = array_values(array_filter(array_map(
                static fn ($v) => trim((string) $v),
                (array) ($c['aliases'] ?? [])
            ), static fn ($v) => $v !== ''));
            if ($cAliases === []) {
                $cAliases = array_values(array_filter([trim((string) ($c['name'] ?? ''))], static fn ($v) => $v !== ''));
            }
            if ($cAliases === []) {
                continue;
            }
            $out[] = [
                'key' => 'c'.$i,
                'name' => (string) ($c['name'] ?? ('竞品 '.($i + 1))),
                'aliases' => $cAliases,
                'is_self' => false,
            ];
            $i++;
        }

        return $out;
    }

    // =========================================================================
    // 取数
    // =========================================================================

    /**
     * 按范围取源数据（run + source 一起取，避免 N+1）。
     *
     * @param  array{days?:int,keyword?:string,scope?:string}  $opts
     * @return list<object>
     */
    private function fetchSources(array $opts): array
    {
        $q = DB::table('ai_visibility_sources as s')
            ->join('ai_visibility_runs as r', 'r.id', '=', 's.ai_visibility_run_id')
            ->select('s.id', 's.ai_visibility_run_id', 's.url', 's.domain', 's.site_name', 's.title',
                's.snippet', 's.summary', 's.rank', 's.created_at', 'r.keyword')
            ->orderBy('s.id');

        $this->applyFilters($q, $opts, 'r.keyword', 'r.created_at');

        try {
            return $q->limit(20000)->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** 范围内有多少个 run（问题） */
    private function countRuns(array $opts): int
    {
        $q = DB::table('ai_visibility_runs');
        $this->applyFilters($q, $opts, 'keyword', 'created_at');

        try {
            return (int) $q->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 统一应用时间 / 问题 / 问题类型三个筛选。
     *
     * scope 是这个模块最关键的一个口径：
     *   brand   = 提问里带品牌名（用户已经知道你），搜回来自然全是你
     *   generic = 提问里不带品牌名（用户还不知道你）← 真正的战场
     * all     = 不筛
     *
     * 不拆这一层，SOV 一定虚高：实测冰溪题库里品牌词问题占了一半的 run，
     * 全部混算得出 80.5%，看着像垄断，实际品类词战场上根本不是这样。
     */
    private function applyFilters($q, array $opts, string $kwCol, string $timeCol, bool $keepOnlyTrack = true): void
    {
        // 声量对标的取样范围**只限词库里标了 B/C/D 的题**：
        //   · A 品牌认知的份额天然虚高（用户点你名字时几乎必然有你），混进来会掩盖真实缺口；
        //   · 未分类的是老题库遗留，对应的库已经不存在，算进来只会污染口径。
        // 这一步无条件执行，"全部问题"看到的也一定是干净的三类。
        if ($keepOnlyTrack) {
            try {
                $validKws = DB::table('keywords')->whereIn('category', ['B', 'C', 'D'])->pluck('keyword')->all();
                if ($validKws === []) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn($kwCol, $validKws);
                }
            } catch (\Throwable) {
                $q->whereRaw('1 = 0');
            }
        }

        $days = (int) ($opts['days'] ?? 0);
        if ($days > 0) {
            $q->where($timeCol, '>=', now()->subDays($days));
        }

        $kw = trim((string) ($opts['keyword'] ?? ''));
        if ($kw !== '') {
            $q->where($kwCol, $kw);
        }

        // 按词库筛：一次只看一个库的题（比如「冰溪品类场景与竞品」里全是 B+D）
        $libId = (int) ($opts['library_id'] ?? 0);
        if ($libId > 0) {
            try {
                $kws = DB::table('keywords')->where('library_id', $libId)->pluck('keyword')->all();
                if ($kws === []) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn($kwCol, $kws);
                }
            } catch (\Throwable) {
                $q->whereRaw('1 = 0');
            }
        }

        // 按问题类别（A/B/C/D）筛。类别来自词库标注，不是文本猜的，
        // 所以它比下面那个 scope（品牌词/品类词）更准，但只在标过类的题上有效。
        $cat = trim((string) ($opts['category'] ?? ''));
        if ($cat !== '' && $cat !== 'all') {
            try {
                $kws = DB::table('keywords')->where('category', $cat)->pluck('keyword')->all();
                if ($kws === []) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn($kwCol, $kws);
                }
            } catch (\Throwable) {
                $q->whereRaw('1 = 0');
            }
        }

        $scope = (string) ($opts['scope'] ?? 'all');
        if ($scope !== 'brand' && $scope !== 'generic') {
            return;
        }

        $selfAliases = [];
        foreach (self::roster() as $r) {
            if ($r['is_self']) {
                $selfAliases = $r['aliases'];
                break;
            }
        }
        $brandKws = self::brandKeywords($selfAliases);

        if ($scope === 'brand') {
            if ($brandKws === []) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn($kwCol, $brandKws);
            }
        } elseif ($brandKws !== []) {
            // 注意：whereNotIn 传空数组时 Laravel 不会过滤，所以这里必须判空
            $q->whereNotIn($kwCol, $brandKws);
        }
    }

    /**
     * 哪些提问里带了品牌名。
     *
     * @param  list<string>  $aliases
     * @return list<string>
     */
    public static function brandKeywords(array $aliases): array
    {
        if ($aliases === []) {
            return [];
        }

        try {
            $all = DB::table('ai_visibility_runs')->distinct()->pluck('keyword')->all();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($all as $kw) {
            $low = mb_strtolower((string) $kw, 'UTF-8');
            foreach ($aliases as $a) {
                $a = mb_strtolower(trim((string) $a), 'UTF-8');
                if ($a !== '' && mb_strlen($a, 'UTF-8') >= 2 && mb_strpos($low, $a, 0, 'UTF-8') !== false) {
                    $out[] = (string) $kw;
                    break;
                }
            }
        }

        return $out;
    }

    // =========================================================================
    // SOV
    // =========================================================================

    /**
     * 声量份额。
     *
     * 算法：某个品牌被多少条来源「提到」 / 所有品牌被提到的总次数。
     * 这是行业通用的 mention-based SOV（Peec AI 等用的口径）。
     *
     * 为什么不用"出现在排名前 N"来算：
     *   同一个问题下可能有多条来源提到同一个品牌，按条数算才能反映
     *   "这个品牌在多少地方被讨论"，这才是声量。
     *
     * @param  array{days?:int,keyword?:string}  $opts
     * @return array<string,mixed>
     */
    public function sov(?string $brandSlug = null, array $opts = []): array
    {
        $brand = self::brand($brandSlug);
        $roster = self::roster($brand);
        if ($roster === [] || $brand === null) {
            return ['ok' => false, 'brands' => [], 'total_mentions' => 0, 'runs' => 0, 'self' => null];
        }

        $rows = $this->fetchSources($opts);
        $runs = $this->countRuns($opts);

        $stat = [];
        foreach ($roster as $r) {
            $stat[$r['key']] = [
                'key' => $r['key'],
                'name' => $r['name'],
                'is_self' => $r['is_self'],
                'sources' => 0,
                'runs' => [],        // run id 集合，用来算提及率
                'domains' => [],     // 域名集合，用来算信源广度
                'ranks' => [],
            ];
        }

        $totalMentions = 0;

        foreach ($rows as $s) {
            $text = $this->textOf($s);
            if ($text === '') {
                continue;
            }
            foreach ($roster as $r) {
                if (! $this->hit($text, $r['aliases'])) {
                    continue;
                }
                $k = $r['key'];
                $stat[$k]['sources']++;
                $stat[$k]['runs'][(int) $s->ai_visibility_run_id] = true;
                $d = trim((string) ($s->domain ?? ''));
                if ($d !== '') {
                    $stat[$k]['domains'][$d] = true;
                }
                if ($s->rank !== null) {
                    $stat[$k]['ranks'][] = (int) $s->rank;
                }
                $totalMentions++;
            }
        }

        $out = [];
        foreach ($stat as $k => $v) {
            $ranks = $v['ranks'];
            $out[] = [
                'key' => $v['key'],
                'name' => $v['name'],
                'is_self' => $v['is_self'],
                'sources' => $v['sources'],
                'run_count' => count($v['runs']),
                'domain_count' => count($v['domains']),
                'mention_rate' => $runs > 0 ? round(count($v['runs']) / $runs * 100, 1) : 0.0,
                'sov' => $totalMentions > 0 ? round($v['sources'] / $totalMentions * 100, 1) : 0.0,
                'avg_rank' => $ranks !== [] ? round(array_sum($ranks) / count($ranks), 1) : null,
                'best_rank' => $ranks !== [] ? min($ranks) : null,
            ];
        }

        // 按 SOV 降序排名。sources 为 0 的品牌不参与排名（rank=0，页面显示「—」）——
        // 一个 0 提及的品牌排第 1 在语义上是错的，之前就踩过这个。
        usort($out, static fn ($a, $b) => $b['sov'] <=> $a['sov']);
        $rank = 1;
        foreach ($out as $i => $o) {
            $out[$i]['rank'] = $o['sources'] > 0 ? $rank++ : 0;
        }

        $self = null;
        foreach ($out as $o) {
            if ($o['is_self']) {
                $self = $o;
                break;
            }
        }

        $scope = (string) ($opts['scope'] ?? 'all');
        $selfAliases = [];
        foreach ($roster as $r) {
            if ($r['is_self']) {
                $selfAliases = $r['aliases'];
                break;
            }
        }
        $brandKws = self::brandKeywords($selfAliases);

        return [
            'ok' => true,
            'brands' => $out,
            'self' => $self,
            'total_mentions' => $totalMentions,
            'runs' => $runs,
            'sources' => count($rows),
            'roster_size' => count($roster),
            'scope' => $scope,
            'brand_kw_count' => count($brandKws),
        ];
    }

    // =========================================================================
    // 引用来源结构
    // =========================================================================

    /**
     * 域名层面的信源结构：谁在占这个品类的位置，我的引用来自哪里。
     *
     * 核心用途是区分「内容问题」还是「信任问题」：
     *   - 我的引用全部来自自己官网 → 信任问题（没人替你背书）
     *   - 第三方大量存在但我完全不在 → 内容问题（没写对东西）
     *
     * @param  array{days?:int,keyword?:string}  $opts
     * @return array<string,mixed>
     */
    public function sourceStructure(?string $brandSlug = null, array $opts = []): array
    {
        $brand = self::brand($brandSlug);
        $roster = self::roster($brand);
        if ($brand === null) {
            return ['ok' => false, 'domains' => [], 'own' => [], 'third' => [], 'diagnosis' => null];
        }

        $selfRoster = null;
        foreach ($roster as $r) {
            if ($r['is_self']) {
                $selfRoster = $r;
                break;
            }
        }
        $selfAliases = $selfRoster['aliases'] ?? [];

        // 自有域名：站点 URL 的 host + aliases 里像域名的项
        $ownHosts = [];
        $siteUrl = trim((string) ($brand['site_url'] ?? ''));
        if ($siteUrl !== '') {
            $h = (string) parse_url($siteUrl, PHP_URL_HOST);
            $h = strtolower((string) preg_replace('/^www\./', '', $h));
            if ($h !== '') {
                $ownHosts[] = $h;
            }
        }

        $rows = $this->fetchSources($opts);

        $map = [];
        $runsTotal = $this->countRuns($opts);

        foreach ($rows as $s) {
            $domain = strtolower(trim((string) ($s->domain ?? '')));
            if ($domain === '') {
                continue;
            }
            if (! isset($map[$domain])) {
                $map[$domain] = [
                    'domain' => $domain,
                    'sources' => 0,
                    'runs' => [],
                    'ranks' => [],
                    'mentions_self' => 0,
                    'ranked' => 0,
                ];
            }
            $map[$domain]['sources']++;
            $map[$domain]['runs'][(int) $s->ai_visibility_run_id] = true;
            if ($s->rank !== null) {
                $map[$domain]['ranks'][] = (int) $s->rank;
                // 前 10 才算"占了位置"
                if ((int) $s->rank <= 10) {
                    $map[$domain]['ranked']++;
                }
            }

            $text = $this->textOf($s);
            if ($selfAliases !== [] && $this->hit($text, $selfAliases)) {
                $map[$domain]['mentions_self']++;
            }
        }

        $list = [];
        foreach ($map as $d => $v) {
            $isOwn = false;
            foreach ($ownHosts as $oh) {
                if ($d === $oh || str_ends_with($d, '.'.$oh)) {
                    $isOwn = true;
                    break;
                }
            }
            $list[] = [
                'domain' => $d,
                'sources' => $v['sources'],
                'runs' => count($v['runs']),
                'ranked' => $v['ranked'],
                'avg_rank' => $v['ranks'] !== [] ? round(array_sum($v['ranks']) / count($v['ranks']), 1) : null,
                'mentions_self' => $v['mentions_self'],
                'is_own' => $isOwn,
            ];
        }

        usort($list, static fn ($a, $b) => $b['runs'] <=> $a['runs'] ?: $b['sources'] <=> $a['sources']);

        // 我的引用分布：提到我的来源里，自有 vs 第三方
        $mySources = 0;
        $myOwn = 0;
        $myThird = 0;
        $myOwnDomains = [];
        $myThirdDomains = [];
        foreach ($list as $d) {
            if ($d['mentions_self'] <= 0) {
                continue;
            }
            $mySources += $d['mentions_self'];
            if ($d['is_own']) {
                $myOwn += $d['mentions_self'];
                $myOwnDomains[$d['domain']] = true;
            } else {
                $myThird += $d['mentions_self'];
                $myThirdDomains[$d['domain']] = true;
            }
        }

        // 竞品信源广度（不同域名数），用来对比"谁被更多地方讨论"
        $competitorBreadth = [];
        foreach ($roster as $r) {
            if ($r['is_self']) {
                continue;
            }
            $doms = [];
            foreach ($rows as $s) {
                $text = $this->textOf($s);
                if ($text !== '' && $this->hit($text, $r['aliases'])) {
                    $doms[strtolower(trim((string) ($s->domain ?? '')))] = true;
                }
            }
            unset($doms['']);
            $competitorBreadth[] = ['name' => $r['name'], 'domains' => count($doms)];
        }
        usort($competitorBreadth, static fn ($a, $b) => $b['domains'] <=> $a['domains']);

        $structure = [
            'ok' => true,
            'domains' => array_slice($list, 0, 40),
            'domain_total' => count($list),
            'runs' => $runsTotal,
            'my_sources' => $mySources,
            'my_own' => $myOwn,
            'my_third' => $myThird,
            'my_own_domains' => count($myOwnDomains),
            'my_third_domains' => count($myThirdDomains),
            'competitor_breadth' => array_slice($competitorBreadth, 0, 6),
            'own_hosts' => $ownHosts,
        ];

        $structure['diagnosis'] = $this->diagnose($structure, (string) ($brand['name'] ?? '你'));

        return $structure;
    }

    // =========================================================================
    // 诊断：把两个数字合成一句可执行的话
    // =========================================================================

    /**
     * @param  array<string,mixed>  $s
     * @return array{verdict:string,color:string,text:string,actions:list<string>}
     */
    /**
     * 可投稿/可留言的平台域名。这些站上被引用的页面，你能去补充或投稿，
     * 所以要跟"别人写的新闻稿"区分开——两者的动作完全不同。
     */
    private const PLATFORM_HOSTS = [
        'zhihu.com' => '知乎', 'douban.com' => '豆瓣', 'xiaohongshu.com' => '小红书',
        'weibo.com' => '微博', 'bilibili.com' => 'B站', 'tieba.baidu.com' => '贴吧',
        'baijiahao.baidu.com' => '百家号', 'toutiao.com' => '头条号', 'jianshu.com' => '简书',
        'csdn.net' => 'CSDN', 'sohu.com' => '搜狐号', '163.com' => '网易号',
        'sina.com.cn' => '新浪', 'qq.com' => '腾讯内容', 'ifeng.com' => '凤凰号',
        'smzdm.com' => '什么值得买', '39.net' => '39健康', 'miaoshou.net' => '妙手医生',
        '360doc.com' => '个人图书馆', 'docin.com' => '豆丁', 'wenku.baidu.com' => '百度文库',
        'xiaohongshu.com' => '小红书', 'meipian.cn' => '美篇', 'zhuanlan.zhihu.com' => '知乎专栏',
    ];

    /**
     * 按「具体网页」聚合：谁在占位置、哪些页面上有我、这个页面我能不能动手。
     *
     * 为什么不用域名级：AI 引用的是一个页面，你要去维护/投稿的也是那个页面。
     * 只说"知乎上有你"没法行动，得说"这条知乎回答里有你，去补一条评论"。
     *
     * @return array{
     *     groups:array{own:list<array>,platform:list<array>,third:list<array>},
     *     mine:list<array>,
     *     stats:array<string,int>
     * }
     */
    public function pageLevel(string $brandSlug = '', array $opts = []): array
    {
        $out = [
            'groups' => ['own' => [], 'platform' => [], 'third' => []],
            'mine' => [],
            'stats' => ['total' => 0, 'own' => 0, 'platform' => 0, 'third' => 0, 'mine' => 0],
        ];

        $brand = self::brand($brandSlug);
        $aliases = array_values(array_filter(array_map(
            static fn ($v) => mb_strtolower(trim((string) $v), 'UTF-8'),
            (array) ($brand['aliases'] ?? [])
        )));
        if ($aliases === []) {
            $n = mb_strtolower(trim((string) ($brand['name'] ?? '')), 'UTF-8');
            if ($n !== '') {
                $aliases = [$n];
            }
        }

        $ownHost = '';
        $siteUrl = trim((string) ($brand['site_url'] ?? ''));
        if ($siteUrl !== '') {
            $h = (string) parse_url($siteUrl, PHP_URL_HOST);
            if ($h !== '') {
                $ownHost = mb_strtolower(preg_replace('/^www\./', '', $h) ?? $h, 'UTF-8');
            }
        }

        try {
            $q = DB::table('ai_visibility_sources as s')
                ->join('ai_visibility_runs as r', 'r.id', '=', 's.ai_visibility_run_id')
                ->select('s.url', 's.domain', 's.title', 's.rank', 'r.keyword', 'r.analysis_json', 'r.raw_response_json');
            $this->applyFilters($q, $opts, 'r.keyword', 'r.created_at');
            $rows = $q->get();
        } catch (\Throwable) {
            return $out;
        }

        // 用户会点开页面看内容，如果标题没提但正文提了，标"无我"就是骗人。
        // 所以额外从原始搜索响应里取每条结果的摘要（Snippet），一并参与匹配。
        // 仍然区分"标题命中"和"仅摘要命中"——标题有品牌名说明主题就是它，摘要提一句是弱信号。
        $snippetByUrl = [];
        foreach ($rows as $r) {
            $raw = json_decode((string) ($r->raw_response_json ?? ''), true);
            foreach ((array) ($raw['Result']['WebResults'] ?? []) as $w) {
                $u = trim((string) ($w['Url'] ?? ''));
                if ($u !== '' && ! isset($snippetByUrl[$u])) {
                    $snippetByUrl[$u] = (string) ($w['Snippet'] ?? '');
                }
            }
        }

        $byUrl = [];
        $hasCitation = false;
        foreach ($rows as $r) {
            // 引用确认结果存在 analysis_json.cited_urls 里；没跑过确认的题就没有这个键
            $citedUrls = [];
            $aj = json_decode((string) ($r->analysis_json ?? ''), true);
            if (is_array($aj) && isset($aj['cited_urls']) && is_array($aj['cited_urls'])) {
                $citedUrls = $aj['cited_urls'];
                $hasCitation = true;
            }
            $url = trim((string) $r->url);
            if ($url === '') {
                continue;
            }
            if (! isset($byUrl[$url])) {
                $byUrl[$url] = [
                    'url' => $url,
                    'domain' => (string) $r->domain,
                    'title' => mb_substr(trim((string) $r->title), 0, 60, 'UTF-8'),
                    'hits' => 0,
                    'mentioned' => false,
                    'mention_in' => '',
                    'cited' => null,
                    'best_rank' => 99,
                ];
            }
            $byUrl[$url]['hits']++;
            if ($citedUrls !== []) {
                if (in_array($url, $citedUrls, true)) {
                    $byUrl[$url]['cited'] = true;
                } elseif (! isset($byUrl[$url]['cited'])) {
                    $byUrl[$url]['cited'] = false;
                }
            }
            $rank = (int) $r->rank;
            if ($rank > 0 && $rank < $byUrl[$url]['best_rank']) {
                $byUrl[$url]['best_rank'] = $rank;
            }
            // 标题命中 = 强信号（这篇文章的主题就是它）
            $titleLow = mb_strtolower(trim((string) $r->title), 'UTF-8');
            $inTitle = false;
            foreach ($aliases as $a) {
                if ($a !== '' && mb_strlen($a, 'UTF-8') >= 2 && mb_strpos($titleLow, $a, 0, 'UTF-8') !== false) {
                    $inTitle = true;
                    break;
                }
            }
            if ($inTitle) {
                $byUrl[$url]['mentioned'] = true;
                $byUrl[$url]['mention_in'] = 'title';
            } elseif (empty($byUrl[$url]['mention_in'])) {
                // 标题没中，再看摘要——摘要里提到也算"这页面上有你"，但标为弱信号
                $snipLow = mb_strtolower(trim((string) ($snippetByUrl[$url] ?? '')), 'UTF-8');
                $inSnippet = false;
                foreach ($aliases as $a) {
                    if ($a !== '' && mb_strlen($a, 'UTF-8') >= 2 && mb_strpos($snipLow, $a, 0, 'UTF-8') !== false) {
                        $inSnippet = true;
                        break;
                    }
                }
                if ($inSnippet) {
                    $byUrl[$url]['mentioned'] = true;
                    $byUrl[$url]['mention_in'] = 'snippet';
                } else {
                    $byUrl[$url]['mention_in'] = '';
                }
            }
        }

        foreach ($byUrl as $p) {
            $host = mb_strtolower(preg_replace('/^www\./', '', (string) $p['domain']) ?? (string) $p['domain'], 'UTF-8');

            // 归属分类：自有 → 平台 → 其他
            if ($ownHost !== '' && ($host === $ownHost || str_ends_with($host, '.'.$ownHost))) {
                $p['kind'] = 'own';
            } else {
                $platformName = '';
                foreach (self::PLATFORM_HOSTS as $ph => $pn) {
                    if ($host === $ph || str_ends_with($host, '.'.$ph)) {
                        $platformName = $pn;
                        break;
                    }
                }
                if ($platformName !== '') {
                    $p['kind'] = 'platform';
                    $p['platform'] = $platformName;
                } else {
                    $p['kind'] = 'third';
                }
            }

            $out['groups'][$p['kind']][] = $p;
            if ($p['mentioned']) {
                $out['mine'][] = $p;
            }
            $out['stats']['total']++;
            $out['stats'][$p['kind']]++;
            if ($p['mentioned']) {
                $out['stats']['mine']++;
            }
        }

        // 每类内部：先按"提到我"排（提到我的优先，因为能立刻动手），再按出现次数
        foreach ($out['groups'] as $k => $list) {
            // 排序优先级：提到我的 > 被 AI 引用过的 > 出现次数多的。
            // 被引用的必须往前排，否则用户看到"34 个被引用"却在列表里一个都找不到，会以为坏了。
            // 排序优先级：被 AI 引用的 > 提到我的 > 出现次数多的。
            // 被引用排最前，是因为"真的进了答案"比"只是被搜到"重要一档；
            // 否则 34 个被引用的页面会被几十个"仅被搜到"的挤到列表外，标记一个都看不到。
            usort($list, static function (array $a, array $b): int {
                $ac = ! empty($a['cited']);
                $bc = ! empty($b['cited']);
                if ($ac !== $bc) {
                    return $ac ? -1 : 1;
                }
                if ($a['mentioned'] !== $b['mentioned']) {
                    return $a['mentioned'] ? -1 : 1;
                }

                return $b['hits'] <=> $a['hits'];
            });
            $out['groups'][$k] = array_slice($list, 0, 30);
        }
        usort($out['mine'], static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);
        $out['mine'] = array_slice($out['mine'], 0, 30);

        // 被 AI 实际引用的页面数（只有跑过引用确认的题才有）
        $citedCount = 0;
        foreach ($byUrl as $p) {
            if (! empty($p['cited'])) {
                $citedCount++;
            }
        }
        $out['stats']['cited'] = $citedCount;
        $out['stats']['has_citation'] = $hasCitation ? 1 : 0;

        return $out;
    }

    /**
     * 题目分级常量。承诺什么，取决于题目本身值不值得争。
     *
     * win     必赢题：已经在前三，承诺保持 Top3
     * fight   争夺题：前十或能通过投稿争取，承诺 Top10 或提及率提升
     * give_up 放弃题：位置被品牌官网/巨头占死，只监测不承诺
     */
    public const PRIORITY_LABELS = [
        'win' => '必赢题（承诺 Top3）',
        'fight' => '争夺题（承诺 Top10）',
        'give_up' => '放弃题（只监测）',
        'pending' => '待定（人工判断）',
    ];

    /**
     * 给每道题自动建议分级。
     *
     * 判断依据来自实际采样结果，不是凭感觉：
     *   已经在前三 → 必赢（守住就行）
     *   四到十   → 争夺（有位置，加把劲能上去）
     *   十名开外 → 看这题前三名里有没有可投稿的平台
     *              有 → 争夺（能发内容挤进去）
     *              没有 → 放弃（被品牌官网/巨头占死，投入产出不划算）
     *
     * @return array<string,array{priority:string,my_rank:int,reason:string}>
     */
    public function suggestPriorities(string $brandSlug = ''): array
    {
        $brand = self::brand($brandSlug);
        $ownHost = '';
        $siteUrl = trim((string) ($brand['site_url'] ?? ''));
        if ($siteUrl !== '') {
            $h = (string) parse_url($siteUrl, PHP_URL_HOST);
            if ($h !== '') {
                $ownHost = mb_strtolower(preg_replace('/^www\./', '', $h) ?? $h, 'UTF-8');
            }
        }

        try {
            $runs = DB::table('ai_visibility_runs')->select('id', 'keyword')->get();
        } catch (\Throwable) {
            return [];
        }

        // 每题聚合：我的最好排位 + 前三名里有没有可投稿平台
        $best = [];
        foreach ($runs as $run) {
            $kw = trim((string) $run->keyword);
            if ($kw === '') {
                continue;
            }
            if (! isset($best[$kw])) {
                $best[$kw] = ['rank' => 99, 'platform' => false];
            }
            try {
                $srcs = DB::table('ai_visibility_sources')
                    ->where('ai_visibility_run_id', $run->id)
                    ->orderBy('rank')
                    ->get(['url', 'domain', 'rank']);
            } catch (\Throwable) {
                continue;
            }
            foreach ($srcs as $src) {
                $host = mb_strtolower(preg_replace('/^www\./', '', (string) $src->domain) ?? '', 'UTF-8');
                $rank = (int) $src->rank;
                if ($ownHost !== '' && ($host === $ownHost || str_ends_with($host, '.'.$ownHost))) {
                    if ($rank > 0 && $rank < $best[$kw]['rank']) {
                        $best[$kw]['rank'] = $rank;
                    }
                }
                if ($rank > 0 && $rank <= 3) {
                    foreach (self::PLATFORM_HOSTS as $ph => $pn) {
                        if ($host === $ph || str_ends_with($host, '.'.$ph)) {
                            $best[$kw]['platform'] = true;
                            break;
                        }
                    }
                }
            }
        }

        $out = [];
        foreach ($best as $kw => $b) {
            $rank = (int) $b['rank'];
            if ($rank <= 3) {
                $out[$kw] = ['priority' => 'win', 'my_rank' => $rank, 'reason' => '已在前三，守住即可'];
            } elseif ($rank <= 10) {
                $out[$kw] = ['priority' => 'fight', 'my_rank' => $rank, 'reason' => '在前十，再推一把能进前三'];
            } elseif ($b['platform']) {
                $out[$kw] = ['priority' => 'fight', 'my_rank' => $rank, 'reason' => '十名外，但前三名有可投稿平台，能挤进去'];
            } else {
                // 不做"放弃"的自动判定：放弃是战略决策，机器不该替人下。
                // 这里只标"待定"，让人看过竞争格局后自己定。
                $out[$kw] = ['priority' => 'pending', 'my_rank' => $rank, 'reason' => '十名外且前三名无投稿平台，需人工判断值不值得争'];
            }
        }

        return $out;
    }

    /**
     * 按分级统计"承诺达成情况"——这是给客户看的那张表。
     *
     * @return array<string,array{total:int,hit:int,pct:float,target:int}>
     */
    public function gradeStats(string $brandSlug = '', int $libraryId = 0): array
    {
        $out = [
            'win' => ['total' => 0, 'hit' => 0, 'pct' => 0.0, 'target' => 3],
            'fight' => ['total' => 0, 'hit' => 0, 'pct' => 0.0, 'target' => 10],
            'give_up' => ['total' => 0, 'hit' => 0, 'pct' => 0.0, 'target' => 0],
            'pending' => ['total' => 0, 'hit' => 0, 'pct' => 0.0, 'target' => 0],
            'none' => ['total' => 0, 'hit' => 0, 'pct' => 0.0, 'target' => 0],
        ];

        try {
            $q = DB::table('keywords')
                ->select('keyword', 'priority')
                ->whereIn('priority', ['win', 'fight', 'give_up', 'pending']);
            if ($libraryId > 0) {
                $q->where('library_id', $libraryId);
            } else {
                // 承诺是品牌级的，不该只看某一个库——按品牌名下所有库统计
                $brandName = (string) (self::brand($brandSlug)['name'] ?? '');
                if ($brandName !== '') {
                    $libIds = DB::table('keyword_libraries')
                        ->where('name', 'like', $brandName.'%')
                        ->pluck('id')->all();
                    if ($libIds === []) {
                        return $out;
                    }
                    $q->whereIn('library_id', $libIds);
                }
            }
            $rows = $q->get();
        } catch (\Throwable) {
            return $out;
        }
        if ($rows->isEmpty()) {
            return $out;
        }

        $actual = $this->suggestPriorities($brandSlug);

        foreach ($rows as $r) {
            $p = (string) $r->priority;
            if (! isset($out[$p])) {
                continue;
            }
            $out[$p]['total']++;
            $myRank = (int) ($actual[(string) $r->keyword]['my_rank'] ?? 99);
            $target = (int) $out[$p]['target'];
            if ($target > 0 && $myRank <= $target) {
                $out[$p]['hit']++;
            }
        }

        foreach ($out as $k => $v) {
            if ($v['total'] > 0) {
                $out[$k]['pct'] = round($v['hit'] / $v['total'] * 100, 1);
            }
        }

        return $out;
    }

    /**
     * 竞品内容地图：每个品牌占了哪些具体页面、这些页面在讲什么。
     *
     * 这是客户最想看的东西——不是"我占多少"，而是"对手靠什么占住的"。
     * 判断依据是来源页面的标题里出现了谁的名字（零额外成本，数据本来就有）。
     *
     * @return list<array{name:string,slug:string,is_self:bool,pages:list<array>,count:int}>
     */
    public function competitorMap(string $brandSlug = '', array $opts = []): array
    {
        // 这里必须把 brandSlug 对应的品牌传进去。
        // roster() 不传参数时会退回到"全局默认品牌"，导致查海洋至尊却返回冰溪的竞品数据。
        $roster = self::roster(self::brand($brandSlug));
        if ($roster === []) {
            return [];
        }

        try {
            $q = DB::table('ai_visibility_sources as s')
                ->join('ai_visibility_runs as r', 'r.id', '=', 's.ai_visibility_run_id')
                ->select('s.url', 's.domain', 's.title', 's.rank');
            $this->applyFilters($q, $opts, 'r.keyword', 'r.created_at');
            $rows = $q->get();
        } catch (\Throwable) {
            return [];
        }

        // 先把页面去重聚合
        $pages = [];
        foreach ($rows as $r) {
            $url = trim((string) $r->url);
            if ($url === '') {
                continue;
            }
            if (! isset($pages[$url])) {
                $pages[$url] = [
                    'url' => $url,
                    'domain' => (string) $r->domain,
                    'title' => mb_substr(trim((string) $r->title), 0, 56, 'UTF-8'),
                    'hits' => 0,
                    'best_rank' => 99,
                ];
            }
            $pages[$url]['hits']++;
            $rank = (int) $r->rank;
            if ($rank > 0 && $rank < $pages[$url]['best_rank']) {
                $pages[$url]['best_rank'] = $rank;
            }
        }

        // 每个页面归给哪些品牌
        $out = [];
        foreach ($roster as $entry) {
            $aliases = [];
            foreach ((array) ($entry['aliases'] ?? []) as $a) {
                $a = mb_strtolower(trim((string) $a), 'UTF-8');
                if ($a !== '' && mb_strlen($a, 'UTF-8') >= 2) {
                    $aliases[] = $a;
                }
            }
            if ($aliases === []) {
                continue;
            }

            $hit = [];
            foreach ($pages as $pg) {
                $titleLow = mb_strtolower((string) $pg['title'], 'UTF-8');
                foreach ($aliases as $a) {
                    if (mb_strpos($titleLow, $a, 0, 'UTF-8') !== false) {
                        $hit[] = $pg;
                        break;
                    }
                }
            }
            if ($hit === []) {
                continue;
            }

            usort($hit, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);

            $out[] = [
                'name' => (string) $entry['name'],
                'slug' => (string) $entry['key'],
                'is_self' => ! empty($entry['is_self']),
                'pages' => array_slice($hit, 0, 12),
                'count' => count($hit),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['is_self'] !== $b['is_self']) {
                return $a['is_self'] ? -1 : 1;
            }

            return $b['count'] <=> $a['count'];
        });

        return $out;
    }

    /**
     * 「自说自话」诊断：页数很多，但几乎全是标题里带你名字的。
     *
     * 为什么这个诊断重要：客户看到"我占了 44 个页面"会以为表现很好，
     * 但这 44 个多半是「冰溪怎么样」这类——只有已经知道你的人才搜。
     * 真正有意义的是"用户不知道你时，会不会遇到你"。
     *
     * @return array{verdict:string,color:string,my_total:int,my_named:int,my_scene:int,comp_total:int,text:string}
     */
    public function selfTalkDiagnosis(string $brandSlug = '', array $opts = []): array
    {
        $out = [
            'verdict' => '', 'color' => '#6b7280',
            'my_total' => 0, 'my_named' => 0, 'my_scene' => 0, 'comp_total' => 0, 'text' => '',
        ];

        $map = $this->competitorMap($brandSlug, $opts);
        if ($map === []) {
            return $out;
        }

        $my = null;
        $compTotal = 0;
        foreach ($map as $b) {
            if (! empty($b['is_self'])) {
                $my = $b;
            } else {
                $compTotal += (int) $b['count'];
            }
        }
        if ($my === null) {
            return $out;
        }

        // 我的页面里，标题含我品牌名的算"自说自话"，其余算"场景露出"
        $brand = self::brand($brandSlug);
        $aliases = [];
        foreach ((array) ($brand['aliases'] ?? []) as $a) {
            $a = mb_strtolower(trim((string) $a), 'UTF-8');
            if ($a !== '') {
                $aliases[] = $a;
            }
        }
        if ($aliases === []) {
            $n = mb_strtolower(trim((string) ($brand['name'] ?? '')), 'UTF-8');
            if ($n !== '') {
                $aliases[] = $n;
            }
        }

        // 三个口径要说清楚，不能混：
        //   myNamed    标题里带品牌名的页面数 —— "别人在点评你"
        //   myOwnAll   你自己域名下的页面数   —— 你能直接改的
        //   myOwnScene 自有域名下、标题却不带品牌名的 —— 真正的"场景内容"
        // 前一个来自 competitorMap（already title-matched），后两个要按域名算。
        $myTotal = (int) $my['count'];
        $myNamed = $myTotal;

        $ownHost = '';
        $siteUrl = trim((string) ($brand['site_url'] ?? ''));
        if ($siteUrl !== '') {
            $h = (string) parse_url($siteUrl, PHP_URL_HOST);
            if ($h !== '') {
                $ownHost = mb_strtolower(preg_replace('/^www\./', '', $h) ?? $h, 'UTF-8');
            }
        }

        $myOwnAll = 0;
        $myOwnScene = 0;
        if ($ownHost !== '') {
            try {
                $q2 = DB::table('ai_visibility_sources as s')
                    ->join('ai_visibility_runs as r', 'r.id', '=', 's.ai_visibility_run_id')
                    ->select('s.url', 's.title', 's.domain');
                $this->applyFilters($q2, $opts, 'r.keyword', 'r.created_at');
                $seen = [];
                foreach ($q2->get() as $row) {
                    $url = trim((string) $row->url);
                    if ($url === '' || isset($seen[$url])) {
                        continue;
                    }
                    $host = mb_strtolower(preg_replace('/^www\./', '', (string) $row->domain) ?? '', 'UTF-8');
                    if ($host !== $ownHost && ! str_ends_with($host, '.'.$ownHost)) {
                        continue;
                    }
                    $seen[$url] = true;
                    $myOwnAll++;
                    $titleLow = mb_strtolower((string) $row->title, 'UTF-8');
                    $named = false;
                    foreach ($aliases as $a) {
                        if ($a !== '' && mb_strlen($a, 'UTF-8') >= 2 && mb_strpos($titleLow, $a, 0, 'UTF-8') !== false) {
                            $named = true;
                            break;
                        }
                    }
                    if (! $named) {
                        $myOwnScene++;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $myScene = $myOwnScene;

        $out['my_total'] = $myTotal;
        $out['my_named'] = $myNamed;
        $out['my_scene'] = $myScene;
        $out['comp_total'] = $compTotal;

        // 三种典型情况，用大白话说
        if ($myTotal >= 5 && $myOwnScene < max(3, (int) round($myTotal * 0.1))) {
            $out['verdict'] = '在自己名字周边自说自话';
            $out['color'] = '#b91c1c';
            $out['text'] = 'AI 搜出来的结果里，有 <b>'.$myTotal.' 个页面</b>标题带着你的品牌名——'
                .'比三个竞品加起来（'.$compTotal.' 个）还多，看着很风光。'
                .'但这些都是<b>别人在点评你</b>（"XX怎么样"这类），只有已经知道你的人才搜。'
                .'而真正能算"帮用户解决问题"的页面（你自己域名下、标题不带品牌名的），只有 <b>'.$myOwnScene.' 个</b>。'
                .'<br><br>说白了两件事：<b>一、你自己几乎没写场景内容</b>（官网被 AI 采到的页面里绝大多数标题都带着品牌名）；'
                .'<b>二、现在提到你的全是别人在点评你</b>——话语权不在你手上，别人写偏你也改不了。';
        } elseif ($myTotal > 0 && $myTotal < $compTotal) {
            $out['verdict'] = '覆盖面明显落后';
            $out['color'] = '#b45309';
            $out['text'] = '你占 '.$myTotal.' 个页面，竞品加起来占 '.$compTotal.' 个。'
                .'在你被提到的地方，竞品的声量更密——这不是内容质量问题，是<b>数量不够</b>。';
        } elseif ($myTotal > 0) {
            $out['verdict'] = '结构基本健康';
            $out['color'] = '#047857';
            $out['text'] = '你占 '.$myTotal.' 个页面，其中 '.$myNamed.' 个带品牌名、'.$myScene.' 个是场景露出。'
                .'结构尚可，继续往场景词上铺。';
        }

        return $out;
    }

    private function diagnose(array $s, string $name): array
    {
        $my = (int) ($s['my_sources'] ?? 0);
        $own = (int) ($s['my_own'] ?? 0);
        $third = (int) ($s['my_third'] ?? 0);
        $ownDomains = (int) ($s['my_own_domains'] ?? 0);
        $thirdDomains = (int) ($s['my_third_domains'] ?? 0);

        if ($my === 0) {
            return [
                'verdict' => '完全缺席',
                'color' => '#b91c1c',
                'text' => '在目前采集到的来源里，一次都没有提到'.$name.'。这不是份额问题，是根本没有进入候选池。',
                'actions' => [
                    '先用「覆盖核查」跑一批真实问题，确认是"没被检索到"还是"检索到了但没被选中"',
                    '不要急着加内容量。先看竞品在哪些问题上占位，从差距最大的问题切入',
                    '如果搜你的品牌名都搜不到，说明站点连索引都没进，先解决抓取',
                ],
            ];
        }

        // 全部来自自己官网 → 信任问题
        if ($third === 0 && $own > 0) {
            return [
                'verdict' => '信任问题',
                'color' => '#b45309',
                'text' => '提到'.$name.'的 '.$my.' 条来源<b>全部来自你自己官网</b>（'.$ownDomains.' 个域名），'
                    .'第三方信源 0 条。这意味着 AI 只能在你的自述里看到你，没有任何独立背书。',
                'actions' => [
                    '<b>补内容的边际收益很低</b>——你已经写了不少，缺的是别人替你说',
                    '去拿第三方场合的露出：行业媒体的测评、榜单、对比文章、问答社区的真实讨论',
                    '优先争取已经被 AI 高频引用的那几个域名，而不是广撒网',
                ],
            ];
        }

        // 只有第三方，自己没落地页
        if ($own === 0 && $third > 0) {
            return [
                'verdict' => '内容问题',
                'color' => '#2563eb',
                'text' => '提到'.$name.'的 '.$my.' 条来源<b>全部是第三方</b>（'.$thirdDomains.' 个域名），你自己官网一次都没进。'
                    .'别人在替你说话，但话语权不在你手上，随时可能被写偏或写成陪衬。',
                'actions' => [
                    '针对这些第三方正在回答的问题，自己写落地页，把话语权拿回来',
                    '优先做「仅被提及」的那批问题——需求已验证，只差自己的内容',
                    '检查官网有没有被正常抓取（robots、sitemap、llms.txt）',
                ],
            ];
        }

        // 自有占比过高
        $ownRatio = $my > 0 ? $own / $my : 0;
        if ($ownRatio >= 0.7) {
            return [
                'verdict' => '信源失衡',
                'color' => '#b45309',
                'text' => '提到'.$name.'的 '.$my.' 条来源里，'.round($ownRatio * 100).'% 来自自己官网（'
                    .$ownDomains.' 个域名），第三方只有 '.$thirdDomains.' 个域名。自有占比过高，AI 缺少独立验证。',
                'actions' => [
                    '继续补内容有用，但<b>更该补第三方露出</b>',
                    '目标是让第三方域名数翻倍，而不是让官网页数翻倍',
                ],
            ];
        }

        return [
            'verdict' => '信源结构健康',
            'color' => '#047857',
            'text' => '提到'.$name.'的 '.$my.' 条来源里，自有 '.$own.' 条（'.$ownDomains.' 个域名）、'
                .'第三方 '.$third.' 条（'.$thirdDomains.' 个域名）。既有自述也有外部背书，这是正常结构。',
            'actions' => [
                '重点转向份额：看 SOV 排名，找出竞品占优的问题逐个攻',
                '保持第三方露出，别让自有内容把结构拉回单极',
            ],
        ];
    }

    // =========================================================================
    // 工具
    // =========================================================================

    /** 把一条 source 的所有文本字段拼起来做匹配 */
    private function textOf(object $s): string
    {
        $parts = [
            (string) ($s->title ?? ''),
            (string) ($s->snippet ?? ''),
            (string) ($s->summary ?? ''),
            (string) ($s->site_name ?? ''),
            (string) ($s->url ?? ''),
        ];

        return mb_strtolower(implode(' ', $parts), 'UTF-8');
    }

    /** @param  list<string>  $aliases */
    private function hit(string $text, array $aliases): bool
    {
        foreach ($aliases as $a) {
            $a = mb_strtolower(trim((string) $a), 'UTF-8');
            if ($a === '' || mb_strlen($a, 'UTF-8') < 2) {
                continue;
            }
            if (mb_strpos($text, $a, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /** 可选的关键词列表（用于范围筛选下拉） */
    public function keywords(int $limit = 200, int $libraryId = 0): array
    {
        try {
            // 从跑过采样的词出发（这才是下拉里能选的），左连词库拿分类。
            // 不这么做的话，下拉只能显示"问题 + 采样次数"，看不出它是 B 类还是 D 类，
            // 而这两类的生态位含义完全不同，混在一起看没有意义。
            // 用 inner join 而不是 left join：没标分类的题（老题库遗留）直接排除。
            // 声量对标是按类看的，没类的题混进来只会让"全部"这个口径失真。
            $q = DB::table('ai_visibility_runs as r')
                ->join('keywords as k', 'k.keyword', '=', 'r.keyword')
                ->whereRaw("coalesce(nullif(k.category, ''), '') <> ''")
                ->selectRaw('r.keyword, count(*) as runs, max(k.category) as category, max(k.library_id) as library_id')
                ->groupBy('r.keyword')
                ->orderByRaw('count(*) desc')
                ->limit($limit);

            if ($libraryId > 0) {
                $q->where('k.library_id', $libraryId);
            }

            return $q->get()
                ->map(static fn ($x): array => [
                    'keyword' => (string) $x->keyword,
                    'runs' => (int) $x->runs,
                    'category' => (string) $x->category,
                    'library_id' => (int) ($x->library_id ?? 0),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 列出「跑过采样」的词库，给数据源下拉用。
     *
     * 为什么不直接把所有词库列出来：没跑过采样的库点进去一定是空的，
     * 列出来只会让人以为功能坏了。只显示有数据的库。
     *
     * @return list<array{id:int,name:string,keywords:int,runs:int,categories:list<string>}>
     */
    public function librariesWithData(): array
    {
        try {
            $rows = DB::table('ai_visibility_runs as r')
                ->join('keywords as k', 'k.keyword', '=', 'r.keyword')
                ->join('keyword_libraries as l', 'l.id', '=', 'k.library_id')
                ->whereRaw("coalesce(nullif(k.category, ''), '') <> ''")
                ->selectRaw('l.id, l.name, count(distinct k.id) as keywords, count(distinct r.id) as runs, '
                    ."string_agg(distinct k.category, ',' order by k.category) as cats")
                ->groupBy(['l.id', 'l.name'])
                ->orderByRaw('count(distinct r.id) desc')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $x) {
            $cats = array_values(array_filter(explode(',', (string) ($x->cats ?? ''))));
            sort($cats);
            $out[] = [
                'id' => (int) $x->id,
                'name' => (string) $x->name,
                'keywords' => (int) $x->keywords,
                'runs' => (int) $x->runs,
                'categories' => $cats,
            ];
        }

        return $out;
    }

    /**
     * 按类别列出已跑过采样的问题（供分组下拉）。
     *
     * @return array<string,list<array{keyword:string,runs:int}>>
     */
    public function keywordsByCategory(int $libraryId = 0): array
    {
        $out = [];
        foreach ($this->keywords(200, $libraryId) as $k) {
            $out[$k['category']][] = ['keyword' => $k['keyword'], 'runs' => $k['runs']];
        }

        return $out;
    }
}
