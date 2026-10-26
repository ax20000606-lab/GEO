<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 发布追踪引擎（阶段 3）
 *
 * 与 AI 可见性 / 覆盖核查的分工（重要，避免功能重复）：
 *   - AI 可见性（官方）：按【天】聚合，回答"我整体在 AI 里表现如何"
 *   - 覆盖核查（本扩展）：按【问题】聚合，回答"哪些问题我缺席"
 *   - 发布追踪（本文件）：按【内容资产】聚合，回答"我发出去的哪一篇进了榜、哪个平台有效"
 *
 * 三者共用同一份采集数据（ai_visibility_runs / ai_visibility_sources），
 * 本模块**不新增任何采集**，只做两件事：
 *   ① 记录我发出去的内容资产与落点 URL（发布台账）
 *   ② 把已有 sources 里的 URL 反向匹配回台账，算出每篇的被引用情况
 *
 * 表（自建，不走 migration，避免影响镜像升级）：
 *   geo_content_assets   内容资产（内容工坊的产出 + 台账主表）
 *   geo_publish_records  发布记录（一条资产可发到多个平台，各自一个 URL）
 */
final class GeoPublish
{
    public const ASSET_TABLE = 'geo_content_assets';

    public const RECORD_TABLE = 'geo_publish_records';

    /** 收录状态：从未被采到 */
    public const ST_PENDING = 'pending';

    /** 收录状态：至少被采到一次 */
    public const ST_CITED = 'cited';

    /** 收录状态：曾采到，但最近若干次采样都不见了 */
    public const ST_DROPPED = 'dropped';

    /** 收录状态：URL 已死（仅 404/410 判定，403/503 不算） */
    public const ST_DEAD = 'dead';

    /** 判掉榜要看最近几个 run */
    private const DROP_WINDOW = 5;

    /**
     * 平台域名映射：从发布 URL 反推平台 slug。
     * 顺序敏感，先匹配更具体的子域。
     */
    private const HOST_MAP = [
        'zhuanlan.zhihu.com' => 'zhihu',
        'zhihu.com' => 'zhihu',
        'xiaohongshu.com' => 'xiaohongshu',
        'xhslink.com' => 'xiaohongshu',
        'mp.weixin.qq.com' => 'wechat',
        'toutiao.com' => 'toutiao',
        'baijiahao.baidu.com' => 'baijiahao',
        'douyin.com' => 'douyin_script',
        'iesdouyin.com' => 'douyin_script',
        'weibo.com' => 'weibo',
        'bilibili.com' => 'bilibili',
        'sohu.com' => 'sohu',
        '163.com' => 'netease',
    ];

    /** 状态中文名 */
    public const STATUS_LABEL = [
        self::ST_PENDING => '待收录',
        self::ST_CITED => '已引用',
        self::ST_DROPPED => '已掉榜',
        self::ST_DEAD => '链接失效',
    ];

    // =========================================================================
    // 建表
    // =========================================================================

    public function ensureTables(): void
    {
        try {
            DB::statement(
                'CREATE TABLE IF NOT EXISTS '.self::ASSET_TABLE.' ('
                .'id bigserial PRIMARY KEY,'
                .'batch_id varchar(60),'
                .'platform varchar(40) NOT NULL,'
                .'platform_name varchar(60),'
                .'mode varchar(20),'
                .'title varchar(300),'
                .'topic varchar(300),'
                .'brand varchar(120),'
                .'words integer DEFAULT 0,'
                .'check_pass integer DEFAULT 0,'
                .'check_total integer DEFAULT 0,'
                .'markdown text,'
                .'schema_json text,'
                .'model varchar(120),'
                .'status varchar(20) DEFAULT \'draft\','
                .'meta jsonb,'
                .'created_at timestamp,'
                .'updated_at timestamp'
                .')'
            );

            DB::statement(
                'CREATE TABLE IF NOT EXISTS '.self::RECORD_TABLE.' ('
                .'id bigserial PRIMARY KEY,'
                .'asset_id bigint NOT NULL,'
                .'platform varchar(40),'
                .'platform_name varchar(60),'
                .'url varchar(1000) NOT NULL,'
                .'domain varchar(255),'
                .'url_key varchar(600),'
                .'published_at timestamp,'
                .'status varchar(20) DEFAULT \'pending\','
                .'first_seen_at timestamp,'
                .'last_seen_at timestamp,'
                .'hit_runs integer DEFAULT 0,'
                .'best_rank integer,'
                .'avg_rank numeric(6,2),'
                .'delay_days integer,'
                .'notes varchar(500),'
                .'created_at timestamp,'
                .'updated_at timestamp'
                .')'
            );

            DB::statement('CREATE INDEX IF NOT EXISTS geo_publish_records_asset_idx ON '.self::RECORD_TABLE.' (asset_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS geo_publish_records_domain_idx ON '.self::RECORD_TABLE.' (domain)');
            DB::statement('CREATE INDEX IF NOT EXISTS geo_content_assets_platform_idx ON '.self::ASSET_TABLE.' (platform)');
        } catch (\Throwable) {
            // 建表失败不阻塞主流程，上层会拿到空结果
        }
    }

    public function ready(): bool
    {
        return Schema::hasTable(self::ASSET_TABLE) && Schema::hasTable(self::RECORD_TABLE);
    }

    // =========================================================================
    // URL 归一化与平台识别
    // =========================================================================

    /**
     * 主机名（去 www、转小写）。用于精确匹配与平台识别。
     */
    public static function hostOf(string $url): string
    {
        $p = @parse_url(trim($url));
        if (! is_array($p)) {
            return '';
        }
        $host = strtolower((string) ($p['host'] ?? ''));

        return (string) preg_replace('/^www\./', '', $host);
    }

    /**
     * URL 指纹：把"同一篇文章的不同分享链接"归一成同一个字符串。
     *
     * 为什么不能简单去掉 query：
     *   - 微信文章的唯一标识在 query 里（__biz + mid + idx），去掉就分不清是哪篇
     *   - 知乎/小红书的 query 是分享令牌（utm_* / xsec_token），每次分享都变，必须去掉
     *
     * 所以按平台分别处理：微信保留标识参数，其余只保留白名单里的 ID 型参数。
     */
    public static function urlKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $p = @parse_url($url);
        if (! is_array($p)) {
            return mb_strtolower($url);
        }

        $host = (string) preg_replace('/^www\./', '', strtolower((string) ($p['host'] ?? '')));
        $path = rtrim((string) ($p['path'] ?? ''), '/');

        parse_str((string) ($p['query'] ?? ''), $qs);

        // 微信：/s?__biz=X&mid=Y&idx=Z 或 /s/短链
        if (str_contains($host, 'weixin.qq.com')) {
            $biz = (string) ($qs['__biz'] ?? '');
            $mid = (string) ($qs['mid'] ?? '');
            if ($biz !== '' && $mid !== '') {
                return $host.$path.'|'.$biz.'|'.$mid.'|'.((string) ($qs['idx'] ?? '1'));
            }

            return $host.$path;
        }

        // 其余平台：只保留稳定的 ID 型参数
        $keep = [];
        foreach ($qs as $k => $v) {
            if (preg_match('/^(id|p|a|aid|articleid|item_id|note_id|wid)$/i', (string) $k)) {
                $keep[(string) $k] = (string) $v;
            }
        }
        ksort($keep);

        return $host.$path.($keep !== [] ? '|'.http_build_query($keep) : '');
    }

    /**
     * 从发布 URL 反推平台。
     *
     * @return array{slug:string,name:string}
     */
    public static function platformOfUrl(string $url): array
    {
        $host = self::hostOf($url);
        $names = [
            'zhihu' => '知乎',
            'xiaohongshu' => '小红书',
            'wechat' => '微信公众号',
            'toutiao' => '今日头条',
            'baijiahao' => '百家号',
            'douyin_script' => '抖音',
            'weibo' => '微博',
            'bilibili' => '哔哩哔哩',
            'sohu' => '搜狐号',
            'netease' => '网易号',
            'official' => '自有官网',
        ];

        // 先精确、再后缀匹配，避免 zhuanlan.zhihu.com 被 xxxzhihu.com 误判
        foreach (self::HOST_MAP as $needle => $slug) {
            if ($host === $needle || str_ends_with($host, '.'.$needle)) {
                return ['slug' => $slug, 'name' => $names[$slug] ?? $slug];
            }
        }

        // 不在映射表里 = 自己的官网或其他域名，归到 official
        return ['slug' => 'official', 'name' => $host !== '' ? $host : '未知平台'];
    }

    /** 给发布记录打平台标签（映射表之外的自有域名） */
    public static function labelFor(string $slug): string
    {
        $names = [
            'zhihu' => '知乎',
            'xiaohongshu' => '小红书',
            'wechat' => '微信公众号',
            'toutiao' => '今日头条',
            'baijiahao' => '百家号',
            'douyin_script' => '抖音口播稿',
            'weibo' => '微博',
            'bilibili' => '哔哩哔哩',
            'official' => '自有官网',
        ];

        return $names[$slug] ?? $slug;
    }

    // =========================================================================
    // 内容资产
    // =========================================================================

    /**
     * 保存一份内容工坊产出为"内容资产"。
     *
     * 设计取舍：不加去重。同一个主题跑两次就该是两篇（人可能想对比两版），
     * 真要删就在列表里删。
     *
     * @param  array<string,mixed>  $result  GeoContentStudio 的返回值
     * @param  array<string,mixed>  $ctx  提交时的上下文（brand/topic/mode/model_id）
     * @return int  新资产 id，失败返回 0
     */
    public function saveAsset(array $result, array $ctx, string $batchId = ''): int
    {
        if (! $this->ready()) {
            $this->ensureTables();
            if (! $this->ready()) {
                return 0;
            }
        }

        $platform = (string) ($result['platform']['slug'] ?? ($ctx['platform'] ?? 'unknown'));
        $pc = (array) ($result['platform_check'] ?? []);
        $stats = (array) ($result['stats'] ?? []);

        try {
            return (int) DB::table(self::ASSET_TABLE)->insertGetId([
                'batch_id' => mb_substr($batchId, 0, 60),
                'platform' => mb_substr($platform, 0, 40),
                'platform_name' => mb_substr((string) ($result['platform']['name'] ?? self::labelFor($platform)), 0, 60),
                'mode' => mb_substr((string) ($ctx['mode'] ?? ($result['mode'] ?? '')), 0, 20),
                'title' => mb_substr((string) ($result['title'] ?? ''), 0, 300),
                'topic' => mb_substr((string) ($ctx['topic'] ?? $ctx['raw'] ?? ''), 0, 300),
                'brand' => mb_substr((string) ($ctx['brand'] ?? ''), 0, 120),
                'words' => (int) ($stats['chars'] ?? 0),
                'check_pass' => (int) ($pc['pass'] ?? 0),
                'check_total' => (int) ($pc['total'] ?? 0),
                'markdown' => (string) ($result['markdown'] ?? ''),
                'schema_json' => (string) ($result['schema'] ?? ''),
                'model' => mb_substr((string) ($result['model'] ?? ''), 0, 120),
                'status' => 'draft',
                'meta' => json_encode([
                    'check_items' => $pc['items'] ?? [],
                    'schema_source' => $result['schema_source'] ?? 'model',
                    'truncated_risk' => (bool) ($result['truncated_risk'] ?? false),
                    'elapsed_ms' => $result['elapsed_ms'] ?? 0,
                    'industry' => $ctx['industry'] ?? '',
                    'audience' => $ctx['audience'] ?? '',
                    'angle' => $ctx['angle'] ?? '',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 资产列表。
     *
     * @param  array{platform?:string,status?:string,q?:string,published?:string}  $filter
     * @return \Illuminate\Support\Collection<int,object>
     */
    public function listAssets(array $filter = [], int $limit = 200)
    {
        if (! $this->ready()) {
            return collect();
        }

        try {
            $q = DB::table(self::ASSET_TABLE.' as a')
                ->leftJoin(self::RECORD_TABLE.' as r', 'r.asset_id', '=', 'a.id')
                ->selectRaw('a.id, a.platform, a.platform_name, a.mode, a.title, a.topic, a.brand, a.words, a.check_pass, a.check_total, a.model, a.status, a.created_at, '
                    .'count(r.id) as rec_count, '
                    .'count(r.id) filter (where r.status in (\'cited\',\'dropped\')) as cited_count, '
                    .'min(r.best_rank) as best_rank, '
                    .'min(r.delay_days) as delay_days')
                ->groupBy(['a.id', 'a.platform', 'a.platform_name', 'a.mode', 'a.title', 'a.topic', 'a.brand', 'a.words', 'a.check_pass', 'a.check_total', 'a.model', 'a.status', 'a.created_at'])
                ->orderByDesc('a.id')
                ->limit($limit);

            if (($filter['platform'] ?? '') !== '') {
                $q->where('a.platform', (string) $filter['platform']);
            }
            if (($filter['status'] ?? '') === 'unpublished') {
                $q->where('a.status', 'draft');
            } elseif (($filter['status'] ?? '') === 'published') {
                $q->where('a.status', '!=', 'draft');
            } elseif (($filter['status'] ?? '') === 'cited') {
                $q->havingRaw('count(r.id) filter (where r.status in (\'cited\',\'dropped\')) > 0');
            }
            if (($filter['q'] ?? '') !== '') {
                $kw = '%'.($filter['q'] ?? '').'%';
                $q->where(function ($w) use ($kw) {
                    $w->where('a.title', 'like', $kw)
                        ->orWhere('a.topic', 'like', $kw)
                        ->orWhere('a.brand', 'like', $kw);
                });
            }

            return $q->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    public function findAsset(int $id): ?object
    {
        if (! $this->ready()) {
            return null;
        }

        return DB::table(self::ASSET_TABLE)->where('id', $id)->first();
    }

    public function deleteAsset(int $id): bool
    {
        if (! $this->ready()) {
            return false;
        }
        DB::table(self::RECORD_TABLE)->where('asset_id', $id)->delete();

        return DB::table(self::ASSET_TABLE)->where('id', $id)->delete() > 0;
    }

    public function setAssetStatus(int $id, string $status): void
    {
        if (! $this->ready()) {
            return;
        }
        if (! in_array($status, ['draft', 'published', 'archived', 'invalid'], true)) {
            return;
        }
        DB::table(self::ASSET_TABLE)->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
    }

    // =========================================================================
    // 发布记录
    // =========================================================================

    /**
     * 登记一条发布记录。
     *
     * 用户只填 URL（平台、域名、指纹全部自动推导），这是"少填"原则的落实。
     *
     * @return array{ok:bool,id:int,msg:string}
     */
    public function registerPublish(int $assetId, string $url, ?string $platformSlug = null, ?string $publishedAt = null, string $notes = ''): array
    {
        if (! $this->ready()) {
            return ['ok' => false, 'id' => 0, 'msg' => '数据表还没建好'];
        }

        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'id' => 0, 'msg' => '请填写发布后的链接'];
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $asset = $this->findAsset($assetId);
        if ($asset === null) {
            return ['ok' => false, 'id' => 0, 'msg' => '找不到这条内容资产'];
        }

        $host = self::hostOf($url);
        if ($host === '') {
            return ['ok' => false, 'id' => 0, 'msg' => '链接格式不对，请粘贴完整的 https:// 地址'];
        }

        // 平台：优先用用户指定的；没指定就按 URL 域名猜
        $guess = self::platformOfUrl($url);
        $slug = $platformSlug !== null && $platformSlug !== '' ? $platformSlug : $guess['slug'];
        $name = $platformSlug !== null && $platformSlug !== ''
            ? self::labelFor($platformSlug)
            : $guess['name'];

        $key = self::urlKey($url);

        // 同一指纹不重复登记（同一篇发同一个平台两次没有意义，只会让统计翻倍）
        $dup = DB::table(self::RECORD_TABLE)->where('url_key', $key)->first(['id']);
        if ($dup !== null) {
            return ['ok' => false, 'id' => (int) $dup->id, 'msg' => '这个链接已经登记过了'];
        }

        $pubAt = null;
        if ($publishedAt !== null && trim($publishedAt) !== '') {
            $ts = strtotime($publishedAt);
            $pubAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        }

        try {
            $id = (int) DB::table(self::RECORD_TABLE)->insertGetId([
                'asset_id' => $assetId,
                'platform' => mb_substr($slug, 0, 40),
                'platform_name' => mb_substr($name, 0, 60),
                'url' => mb_substr($url, 0, 1000),
                'domain' => mb_substr($host, 0, 255),
                'url_key' => mb_substr($key, 0, 600),
                'published_at' => $pubAt ?? now(),
                'status' => self::ST_PENDING,
                'notes' => mb_substr($notes, 0, 500),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => 0, 'msg' => '保存失败：'.mb_substr($e->getMessage(), 0, 80, 'UTF-8')];
        }

        // 资产状态从 draft 升到 published
        DB::table(self::ASSET_TABLE)->where('id', $assetId)->where('status', 'draft')
            ->update(['status' => 'published', 'updated_at' => now()]);

        return ['ok' => true, 'id' => $id, 'msg' => '已登记'];
    }

    public function deletePublish(int $id): bool
    {
        if (! $this->ready()) {
            return false;
        }
        $row = DB::table(self::RECORD_TABLE)->where('id', $id)->first(['asset_id']);
        $ok = DB::table(self::RECORD_TABLE)->where('id', $id)->delete() > 0;

        // 该资产一条记录都不剩 → 回到 draft
        if ($ok && $row !== null) {
            $left = DB::table(self::RECORD_TABLE)->where('asset_id', (int) $row->asset_id)->count();
            if ($left === 0) {
                DB::table(self::ASSET_TABLE)->where('id', (int) $row->asset_id)
                    ->update(['status' => 'draft', 'updated_at' => now()]);
            }
        }

        return $ok;
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    public function recordsOf(int $assetId)
    {
        if (! $this->ready()) {
            return collect();
        }

        return DB::table(self::RECORD_TABLE)->where('asset_id', $assetId)->orderByDesc('id')->get();
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    public function allRecords(bool $onlyPending = false, int $limit = 500)
    {
        if (! $this->ready()) {
            return collect();
        }
        $q = DB::table(self::RECORD_TABLE.' as r')
            ->join(self::ASSET_TABLE.' as a', 'a.id', '=', 'r.asset_id')
            ->select('r.*', 'a.title as asset_title', 'a.platform as gen_platform')
            ->orderByDesc('r.id')
            ->limit($limit);
        if ($onlyPending) {
            $q->where('r.status', self::ST_PENDING);
        }

        return $q->get();
    }

    // =========================================================================
    // 收录轮询（核心）
    // =========================================================================

    /**
     * 轮询所有发布记录，看有没有被采集到。
     *
     * 判定口径：
     *   - 该 URL 出现在任何一条 ai_visibility_sources 里 → cited
     *   - 从未出现 → pending
     *   - 曾出现，但**同一个关键词**最近 DROP_WINDOW 次采样都没再出现 → dropped
     *
     * 为什么"掉榜"必须按同关键词判：
     *   不同 run 问的是不同的问题。如果最近几次采样问的是别的问题，
     *   这篇文章自然采不到——那不是掉榜，是没查它。跨关键词比较会把
     *   正常的文章误判成掉榜，结论完全是错的。
     *
     * @param  bool  $probeDead  是否额外做 HTTP 探测判死链（会慢，默认不做）
     * @return array{checked:int,cited:int,pending:int,dropped:int,dead:int,changed:int}
     */
    public function pollAll(bool $probeDead = false): array
    {
        $out = ['checked' => 0, 'cited' => 0, 'pending' => 0, 'dropped' => 0, 'dead' => 0, 'changed' => 0];
        if (! $this->ready()) {
            return $out;
        }

        $records = DB::table(self::RECORD_TABLE)->orderBy('id')->get();
        if ($records->isEmpty()) {
            return $out;
        }

        foreach ($records as $rec) {
            $out['checked']++;
            $id = (int) $rec->id;
            $urlKey = (string) ($rec->url_key ?? '');
            $domain = (string) ($rec->domain ?? '');

            if ($urlKey === '' || $domain === '') {
                continue;
            }

            // ① 全量匹配：按域名过滤后再比指纹（域名有索引，量级可控）
            //    带上 run 的 keyword，后面判掉榜要用
            $sources = DB::table('ai_visibility_sources as s')
                ->join('ai_visibility_runs as r', 'r.id', '=', 's.ai_visibility_run_id')
                ->where('s.domain', $domain)
                ->orderByDesc('s.id')
                ->limit(500)
                ->get(['s.id', 's.ai_visibility_run_id', 's.url', 's.rank', 's.created_at', 'r.keyword']);

            $hitIds = [];
            $ranks = [];
            $seenTimes = [];
            $hitKeywords = [];
            foreach ($sources as $s) {
                if ($urlKey !== '' && self::urlKey((string) ($s->url ?? '')) === $urlKey) {
                    $hitIds[] = (int) $s->ai_visibility_run_id;
                    if ($s->rank !== null) {
                        $ranks[] = (int) $s->rank;
                    }
                    $seenTimes[] = $s->created_at;
                    $kw = trim((string) ($s->keyword ?? ''));
                    if ($kw !== '') {
                        $hitKeywords[$kw] = true;
                    }
                }
            }

            $hitRuns = count(array_unique($hitIds));
            $bestRank = $ranks !== [] ? min($ranks) : null;
            $avgRank = $ranks !== [] ? round(array_sum($ranks) / count($ranks), 2) : null;

            // 首次被采集到的时间：取最早那条 source 的时间（比"当前轮询时间"更真实）
            $firstSeen = null;
            $lastSeen = null;
            if ($seenTimes !== []) {
                $sorted = $seenTimes;
                sort($sorted);
                $firstSeen = $sorted[0];
                $lastSeen = $sorted[count($sorted) - 1];
            }

            // 收录延迟（天）：发布 → 首次被采到
            $delayDays = null;
            if ($firstSeen !== null && $rec->published_at !== null) {
                $a = strtotime((string) $firstSeen);
                $b = strtotime((string) $rec->published_at);
                if ($a !== false && $b !== false && $a >= $b) {
                    $delayDays = (int) floor(($a - $b) / 86400);
                }
            }

            // ② 定状态
            $status = self::ST_PENDING;
            if ($hitRuns > 0) {
                $status = self::ST_CITED;

                // 掉榜判定：按**同一个关键词**的最近 N 次采样来看
                // 只要有一个命中过的关键词在最近 N 次里还在 → 仍然是 cited
                $stillCited = false;
                $comparable = false;
                $hitSet = array_values(array_unique($hitIds));

                foreach (array_keys($hitKeywords) as $kw) {
                    $sameKwRuns = DB::table('ai_visibility_runs')
                        ->where('keyword', $kw)
                        ->orderByDesc('id')
                        ->limit(self::DROP_WINDOW)
                        ->pluck('id')
                        ->map(static fn ($v) => (int) $v)
                        ->all();

                    if ($sameKwRuns === []) {
                        continue;
                    }
                    if (array_intersect($hitSet, $sameKwRuns) !== []) {
                        $stillCited = true;
                        break;
                    }
                    // 这个关键词后来采样过、但这篇没再出现 → 有判定依据
                    $comparable = true;
                }

                if (! $stillCited && $comparable) {
                    $status = self::ST_DROPPED;
                }
            }

            // ③ 可选：HTTP 探测死链（只认 404/410，403/503 是反爬不算死）
            if ($probeDead) {
                $code = $this->probeHttp((string) $rec->url);
                if ($code === 404 || $code === 410) {
                    $status = self::ST_DEAD;
                }
            }

            $out[$status === self::ST_CITED ? 'cited' : ($status === self::ST_PENDING ? 'pending' : ($status === self::ST_DROPPED ? 'dropped' : 'dead'))]++;

            if ((string) $rec->status !== $status || (int) ($rec->hit_runs ?? 0) !== $hitRuns) {
                $out['changed']++;
            }

            DB::table(self::RECORD_TABLE)->where('id', $id)->update([
                'status' => $status,
                'first_seen_at' => $firstSeen ?? $rec->first_seen_at,
                'last_seen_at' => $lastSeen ?? $rec->last_seen_at,
                'hit_runs' => $hitRuns,
                'best_rank' => $bestRank,
                'avg_rank' => $avgRank,
                'delay_days' => $delayDays,
                'updated_at' => now(),
            ]);
        }

        return $out;
    }

    /** 轻量 HTTP 探测，只取状态码 */
    private function probeHttp(string $url): int
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122 Safari/537.36',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $code;
        } catch (\Throwable) {
            return 0;
        }
    }

    // =========================================================================
    // 归因与统计
    // =========================================================================

    /**
     * 单条发布记录的完整归因：被哪些问题采到、每次排第几。
     *
     * @return array{hits:list<array<string,mixed>>,total:int,best:int|null,avg:float|null}
     */
    public function attribution(int $recordId): array
    {
        $empty = ['hits' => [], 'total' => 0, 'best' => null, 'avg' => null];
        if (! $this->ready()) {
            return $empty;
        }

        $rec = DB::table(self::RECORD_TABLE)->where('id', $recordId)->first();
        if ($rec === null) {
            return $empty;
        }

        $urlKey = (string) ($rec->url_key ?? '');
        $domain = (string) ($rec->domain ?? '');
        if ($urlKey === '' || $domain === '') {
            return $empty;
        }

        $sources = DB::table('ai_visibility_sources as s')
            ->join('ai_visibility_runs as r', 'r.id', '=', 's.ai_visibility_run_id')
            ->where('s.domain', $domain)
            ->orderByDesc('s.id')
            ->limit(500)
            ->get(['s.id', 's.ai_visibility_run_id', 's.url', 's.rank', 's.created_at', 'r.keyword', 'r.provider_type']);

        $hits = [];
        $ranks = [];
        foreach ($sources as $s) {
            if (self::urlKey((string) ($s->url ?? '')) !== $urlKey) {
                continue;
            }
            $ranks[] = $s->rank !== null ? (int) $s->rank : null;
            $hits[] = [
                'run_id' => (int) $s->ai_visibility_run_id,
                'keyword' => (string) ($s->keyword ?? ''),
                'rank' => $s->rank !== null ? (int) $s->rank : null,
                'seen_at' => (string) ($s->created_at ?? ''),
                'provider' => (string) ($s->provider_type ?? ''),
            ];
        }

        $validRanks = array_values(array_filter($ranks, static fn ($v) => $v !== null));

        return [
            'hits' => $hits,
            'total' => count($hits),
            'best' => $validRanks !== [] ? min($validRanks) : null,
            'avg' => $validRanks !== [] ? round(array_sum($validRanks) / count($validRanks), 1) : null,
        ];
    }

    /**
     * 按平台统计 —— 回答"哪个平台发出去最有效"。
     *
     * @return list<array<string,mixed>>
     */
    public function platformStats(): array
    {
        if (! $this->ready()) {
            return [];
        }

        $rows = DB::table(self::RECORD_TABLE)
            ->selectRaw('platform, platform_name, count(*) as pubs, '
                .'count(*) filter (where status in (\'cited\',\'dropped\')) as cited, '
                .'count(*) filter (where status = \'pending\') as pending, '
                .'count(*) filter (where status = \'dropped\') as dropped, '
                .'min(best_rank) as best_rank, '
                .'avg(best_rank) as avg_rank, '
                .'avg(delay_days) as avg_delay')
            ->groupBy(['platform', 'platform_name'])
            ->orderByRaw('count(*) desc')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $pubs = (int) $r->pubs;
            $cited = (int) $r->cited;
            $out[] = [
                'platform' => (string) $r->platform,
                'name' => (string) ($r->platform_name ?: self::labelFor((string) $r->platform)),
                'pubs' => $pubs,
                'cited' => $cited,
                'pending' => (int) $r->pending,
                'dropped' => (int) $r->dropped,
                'rate' => $pubs > 0 ? (int) round($cited / $pubs * 100) : 0,
                'best_rank' => $r->best_rank !== null ? (int) $r->best_rank : null,
                'avg_rank' => $r->avg_rank !== null ? round((float) $r->avg_rank, 1) : null,
                'avg_delay' => $r->avg_delay !== null ? round((float) $r->avg_delay, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * 总览统计卡数据。
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        if (! $this->ready()) {
            return ['assets' => 0, 'published' => 0, 'pubs' => 0, 'cited' => 0, 'rate' => 0, 'avg_delay' => null, 'avg_rank' => null];
        }

        $assets = (int) DB::table(self::ASSET_TABLE)->count();
        $published = (int) DB::table(self::ASSET_TABLE)->where('status', '!=', 'draft')->count();
        $pubs = (int) DB::table(self::RECORD_TABLE)->count();
        $cited = (int) DB::table(self::RECORD_TABLE)->whereIn('status', [self::ST_CITED, self::ST_DROPPED])->count();
        $avgDelay = DB::table(self::RECORD_TABLE)->whereNotNull('delay_days')->avg('delay_days');
        $avgRank = DB::table(self::RECORD_TABLE)->whereNotNull('best_rank')->avg('best_rank');

        return [
            'assets' => $assets,
            'published' => $published,
            'pubs' => $pubs,
            'cited' => $cited,
            'rate' => $pubs > 0 ? (int) round($cited / $pubs * 100) : 0,
            'avg_delay' => $avgDelay !== null ? round((float) $avgDelay, 1) : null,
            'avg_rank' => $avgRank !== null ? round((float) $avgRank, 1) : null,
        ];
    }

    /**
     * 已发布但还没被采到的记录（"哪些发了没动静"）。
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    public function staleRecords(int $days = 7, int $limit = 30)
    {
        if (! $this->ready()) {
            return collect();
        }

        return DB::table(self::RECORD_TABLE.' as r')
            ->join(self::ASSET_TABLE.' as a', 'a.id', '=', 'r.asset_id')
            ->select('r.*', 'a.title as asset_title')
            ->where('r.status', self::ST_PENDING)
            ->where('r.published_at', '<=', now()->subDays($days))
            ->orderBy('r.published_at')
            ->limit($limit)
            ->get();
    }

    /** 采集数据的时间跨度（用来说明"多久才有结论"） */
    public function collectionSpan(): array
    {
        try {
            $row = DB::table('ai_visibility_runs')
                ->selectRaw('count(*) as runs, min(created_at) as first_at, max(created_at) as last_at')
                ->first();

            return [
                'runs' => (int) ($row->runs ?? 0),
                'first_at' => (string) ($row->first_at ?? ''),
                'last_at' => (string) ($row->last_at ?? ''),
            ];
        } catch (\Throwable) {
            return ['runs' => 0, 'first_at' => '', 'last_at' => ''];
        }
    }
}
