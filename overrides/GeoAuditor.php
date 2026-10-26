<?php

namespace App\Support\GeoAudit;

/**
 * GEO 页面诊断器（纯规则引擎，零第三方依赖，不调用大模型）
 *
 * - 24 项诊断模块 / 6 个维度 / 总分与等级 / P0-P1-P2 行动路线
 * - 结果可复现：同一份 HTML 输入必然得到同一份评分
 *
 * 说明：这是本地扩展文件（通过 docker volume 挂载进容器），不属于 GEOFlow 官方代码。
 */
final class GeoAuditor
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const TIMEOUT = 25;

    private const AUTHORITY = [
        'gov.cn', 'edu.cn', 'ac.cn', 'arxiv.org', 'doi.org', 'wikipedia.org',
        'xinhuanet.com', 'people.com.cn', 'cctv.com', 'gmw.cn', 'chinadaily.com.cn',
        '36kr.com', 'huxiu.com', 'tmtpost.com', 'caixin.com', 'yicai.com',
        'nature.com', 'science.org', 'ieee.org', 'acm.org', 'who.int',
    ];

    private const SOCIAL = [
        'weibo.com', 'zhihu.com', 'xiaohongshu.com', 'douyin.com', 'bilibili.com',
        'mp.weixin.qq.com', 'toutiao.com', 'kuaishou.com',
    ];

    private const RECOMMENDED_SCHEMA = ['Organization', 'WebSite', 'Article', 'FAQPage', 'BreadcrumbList'];

    /** @return array<string,mixed> */
    public function run(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'url' => '', 'error' => '请输入网址'];
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'url' => $url, 'error' => '网址格式不正确'];
        }

        [$html, $finalUrl, $status, $elapsedMs, $error] = $this->fetch($url);
        if ($html === '') {
            return ['ok' => false, 'url' => $url, 'error' => $error !== '' ? $error : 'HTTP '.$status, 'elapsed_ms' => $elapsedMs];
        }

        $p = $this->parse($html);
        $textLen = mb_strlen(preg_replace('/\s+/u', '', $p['text']) ?? '', 'UTF-8');
        $schemaTypes = $this->extractSchemaTypes($p['jsonld']);
        $host = (string) parse_url($finalUrl, PHP_URL_HOST);

        $internal = $external = $authority = $social = 0;
        foreach ($p['links'] as $href) {
            $abs = $this->absolutize($href, $finalUrl);
            $h = (string) parse_url($abs, PHP_URL_HOST);
            if ($h === '' || $h === $host) {
                $internal++;
                continue;
            }
            $external++;
            foreach (self::AUTHORITY as $a) {
                if (str_contains($h, $a)) {
                    $authority++;
                    break;
                }
            }
            foreach (self::SOCIAL as $s) {
                if (str_contains($h, $s)) {
                    $social++;
                    break;
                }
            }
        }
        if (in_array('Organization', $schemaTypes, true)) {
            $social++;
        }

        $title = $p['title'];
        $desc = $p['meta']['description'] ?? $p['meta']['og:description'] ?? '';
        $ogKeys = array_values(array_filter(
            ['og:title', 'og:description', 'og:image', 'og:type'],
            static fn (string $k): bool => trim((string) ($p['meta'][$k] ?? '')) !== ''
        ));
        $twitter = trim((string) ($p['meta']['twitter:card'] ?? ''));
        $questionMarks = preg_match_all('/[？?]/u', $p['text']);
        $firstPara = $p['paragraphs'][0] ?? '';
        $readingMin = max(0.1, $textLen / 400);

        $modules = [];
        $add = static function (string $chapter, string $name, int $score, string $evidence, string $suggestion) use (&$modules): void {
            $modules[] = [
                'chapter' => $chapter, 'name' => $name, 'score' => max(0, min(100, $score)),
                'evidence' => $evidence, 'suggestion' => $suggestion,
            ];
        };

        // 一、抓取入口
        $add('一、抓取入口', 'HTTPS 协议', str_starts_with(strtolower($finalUrl), 'https') ? 100 : 0,
            '当前协议：'.(str_starts_with(strtolower($finalUrl), 'https') ? 'HTTPS' : 'HTTP'),
            '改用 HTTPS，HTTP 站点会被部分 AI 抓取器降权');
        $canonical = trim($p['canonical']);
        if ($canonical !== '') {
            $add('一、抓取入口', 'Canonical 规范', str_starts_with(strtolower($canonical), 'http') ? 100 : 70,
                mb_substr($canonical, 0, 80), 'Canonical 建议写成绝对地址');
        } else {
            $add('一、抓取入口', 'Canonical 规范', 0, '未声明 canonical', '补 <link rel="canonical">，避免多 URL 稀释权重');
        }
        $viewport = trim((string) ($p['meta']['viewport'] ?? ''));
        $add('一、抓取入口', 'Viewport 适配', $viewport !== '' ? 100 : 0,
            'viewport='.($viewport !== '' ? mb_substr($viewport, 0, 40) : '缺失'), '补 viewport meta，移动端抓取语义更稳');
        $add('一、抓取入口', 'Lang 声明', trim($p['lang']) !== '' ? 100 : 0,
            'html lang='.($p['lang'] !== '' ? $p['lang'] : '缺失'), '补 html lang="zh-CN"');

        // 二、预览元信息
        $titleLen = mb_strlen($title, 'UTF-8');
        $add('二、预览元信息', 'Title 完整度', $this->band($titleLen, [[15, 100], [10, 75], [1, 45]]),
            $titleLen.' 字符：'.mb_substr($title, 0, 50), '标题建议 15–40 字符，把核心实体放前面');
        $descLen = mb_strlen($desc, 'UTF-8');
        $add('二、预览元信息', 'Meta Description', $this->band($descLen, [[80, 100], [50, 78], [1, 45]]),
            $descLen.' 字符', '描述建议 80–160 字符，写成可摘录的一句话');
        $add('二、预览元信息', '开放图谱 OG', $this->band(count($ogKeys), [[4, 100], [3, 82], [2, 62], [1, 35]]),
            '已声明：'.($ogKeys !== [] ? implode(', ', $ogKeys) : '无'), '补齐 og:title / og:description / og:image / og:type');
        $add('二、预览元信息', '社媒卡片', $twitter !== '' ? 100 : 40,
            'twitter:card='.($twitter !== '' ? $twitter : '缺失'), '补 twitter:card=summary_large_image');

        // 三、结构化语义
        $covered = array_values(array_filter(self::RECOMMENDED_SCHEMA, static fn (string $t): bool => in_array($t, $schemaTypes, true)));
        $missing = array_values(array_filter(self::RECOMMENDED_SCHEMA, static fn (string $t): bool => ! in_array($t, $schemaTypes, true)));
        $add('三、结构化语义', 'Schema 覆盖率', (int) round(count($covered) / count(self::RECOMMENDED_SCHEMA) * 100),
            '命中 '.count($covered).'/'.count(self::RECOMMENDED_SCHEMA).'：'.($covered !== [] ? implode(', ', $covered) : '无'),
            '补 '.($missing !== [] ? implode(', ', $missing) : '（已齐全）'));
        $add('三、结构化语义', 'Organization 实体', in_array('Organization', $schemaTypes, true) ? 100 : 0,
            'Organization '.(in_array('Organization', $schemaTypes, true) ? '已声明' : '缺失'), '补 Organization，声明品牌名、logo、sameAs');
        $add('三、结构化语义', 'FAQ 问答', in_array('FAQPage', $schemaTypes, true) ? 100 : 0,
            'FAQPage '.(in_array('FAQPage', $schemaTypes, true) ? '已声明' : '缺失'), '补 FAQPage，问答是 AI 最易摘录的结构');
        $add('三、结构化语义', '面包屑导航', in_array('BreadcrumbList', $schemaTypes, true) ? 100 : 0,
            'BreadcrumbList '.(in_array('BreadcrumbList', $schemaTypes, true) ? '已声明' : '缺失'), '补 BreadcrumbList，帮助机器理解站点层级');

        // 四、内容组织
        $h1 = $p['h1'];
        $h2 = $p['h2'];
        $add('四、内容组织', 'H1 唯一性', $h1 === 1 ? 100 : 30,
            'H1 数量 '.$h1.($h1 > 0 ? '：'.mb_substr((string) ($p['h1Text'][0] ?? ''), 0, 40) : ''), '全页只保留一个 H1');
        $add('四、内容组织', '标题层级', $this->band($h2, [[2, 100], [1, 70]], 30),
            'H1×'.$h1.' / H2×'.$h2.' / H3×'.$p['h3'], '用 H2 拆章节，保证机器能切块');
        $add('四、内容组织', '正文深度', $this->band($textLen, [[1500, 100], [800, 80], [400, 55]], 25),
            '正文约 '.$textLen.' 字', '正文建议 1500 字以上，形成可摘录段落');
        $add('四、内容组织', '阅读时长', ($readingMin >= 3 && $readingMin <= 10) ? 100 : ($readingMin > 10 ? 85 : 55),
            '约 '.round($readingMin, 1).' 分钟', '控制在 3–10 分钟阅读长度');

        // 五、答案表达
        $add('五、答案表达', '首段直答', $this->band(mb_strlen($firstPara, 'UTF-8'), [[80, 100], [40, 70], [1, 45]]),
            '首段 '.mb_strlen($firstPara, 'UTF-8').' 字符', '首段直接给结论，别绕背景');
        $add('五、答案表达', '列表表达', $this->band($p['lists'], [[4, 100], [2, 80], [1, 55]], 25),
            '列表 '.$p['lists'].' 个', '用列表承载要点，便于 AI 拆分');
        $add('五、答案表达', 'FAQ 语气', $this->band($questionMarks, [[5, 100], [2, 70], [1, 45]], 20),
            '问句 '.$questionMarks.' 处', '增加问答式小节，匹配用户真实提问');
        $clickable = count($p['links']) + count($p['buttons']);
        $add('五、答案表达', 'CTA 清晰度', $this->band($clickable, [[5, 100], [2, 75], [1, 55]], 35),
            '可点击项约 '.$clickable.' 个', '给出明确的下一步动作入口');

        // 六、引用可信
        $add('六、引用可信', '外部链接广度', $this->band($external, [[8, 100], [4, 80], [1, 55]], 20),
            '外链 '.$external.' 个', '增加外部权威引用，构建引用网络');
        $add('六、引用可信', '权威引用', $this->band($authority, [[2, 100], [1, 70]], 30),
            '权威域名 '.$authority.' 个', '引用政府/学术/行业权威来源');
        $add('六、引用可信', '站内支撑链接', $this->band($internal, [[15, 100], [5, 80], [1, 55]], 25),
            '内链 '.$internal.' 个', '补齐站内主题链路');
        $add('六、引用可信', '社交背书', $social >= 1 ? 100 : 40,
            '社交/组织信号 '.$social.' 处', '补 sameAs 指向官方社媒');

        // 汇总
        $chapters = [];
        foreach ($modules as $m) {
            $chapters[$m['chapter']][] = $m;
        }
        $dimensions = [];
        foreach ($chapters as $chap => $rows) {
            $dimensions[$chap] = (int) round(array_sum(array_column($rows, 'score')) / max(1, count($rows)));
        }
        $total = (int) round(array_sum(array_column($modules, 'score')) / max(1, count($modules)));
        $grade = $total >= 85 ? '优秀' : ($total >= 70 ? '良好' : ($total >= 55 ? '待优化' : '亟需整改'));

        $ranked = $modules;
        usort($ranked, static fn (array $a, array $b): int => [$a['score'], $a['chapter']] <=> [$b['score'], $b['chapter']]);
        $p0 = array_slice($ranked, 0, 4);
        $p1 = array_slice($ranked, 4, 4);
        $p0Names = array_column($p0, 'name');
        $p1Names = array_column($p1, 'name');
        $p2 = array_values(array_filter($modules, static fn (array $m): bool => $m['score'] < 90
            && ! in_array($m['name'], $p0Names, true) && ! in_array($m['name'], $p1Names, true)));

        $funnel = [
            ['抓取可达', $dimensions['一、抓取入口'] ?? 0],
            ['实体理解', $dimensions['三、结构化语义'] ?? 0],
            ['答案表达', $dimensions['五、答案表达'] ?? 0],
            ['信任构建', $dimensions['六、引用可信'] ?? 0],
            ['AI 引用准备', (int) round(((($dimensions['二、预览元信息'] ?? 0) + ($dimensions['四、内容组织'] ?? 0)) / 2))],
        ];

        return [
            'ok' => true,
            'url' => $url,
            'final_url' => $finalUrl,
            'http_status' => $status,
            'elapsed_ms' => $elapsedMs,
            'total' => $total,
            'grade' => $grade,
            'dimension_scores' => $dimensions,
            'modules' => $modules,
            'chapters' => array_keys($chapters),
            'funnel' => $funnel,
            'schema_found' => $schemaTypes,
            'schema_missing' => $missing,
            'llms' => $this->checkLlmsTxt($this->originOf($finalUrl)),
            'page_meta' => [
                'title' => $title,
                'description' => $desc,
                'site_name' => trim((string) ($p['meta']['og:site_name'] ?? '')),
                'image' => trim((string) ($p['meta']['og:image'] ?? '')),
                'lang' => trim((string) $p['lang']),
                'canonical' => $canonical !== '' ? $canonical : $finalUrl,
                'host' => $host,
            ],
            'stats' => [
                'text_len' => $textLen, 'h1' => $h1, 'h2' => $h2, 'h3' => $p['h3'],
                'external_links' => $external, 'internal_links' => $internal,
                'authority_links' => $authority, 'lists' => $p['lists'],
                'questions' => $questionMarks, 'title_len' => $titleLen, 'desc_len' => $descLen,
            ],
            'roadmap' => ['P0' => $p0, 'P1' => $p1, 'P2' => $p2],
        ];
    }

    // ------------------------------------------------------------------
    // 抓取
    // ------------------------------------------------------------------
    /** @return array{0:string,1:string,2:int,3:int,4:string} */
    private function fetch(string $url): array
    {
        $started = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err = (string) curl_error($ch);
        curl_close($ch);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if (! is_string($body) || $body === '') {
            return ['', $finalUrl !== '' ? $finalUrl : $url, $status, $elapsed, $err !== '' ? $err : 'HTTP '.$status];
        }
        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'GB18030');
        }

        return [$body, $finalUrl !== '' ? $finalUrl : $url, $status, $elapsed, ''];
    }

    // ------------------------------------------------------------------
    // 解析
    // ------------------------------------------------------------------
    /** @return array<string,mixed> */
    private function parse(string $html): array
    {
        $out = [
            'title' => '', 'lang' => '', 'meta' => [], 'canonical' => '',
            'h1' => 0, 'h2' => 0, 'h3' => 0, 'h1Text' => [],
            'jsonld' => [], 'links' => [], 'buttons' => [], 'lists' => 0,
            'paragraphs' => [], 'text' => '',
        ];

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $loaded) {
            return $out;
        }
        $xp = new \DOMXPath($dom);

        $out['title'] = trim((string) $xp->evaluate('string(//title)'));
        $out['lang'] = trim((string) $xp->evaluate('string(//html/@lang)'));
        $out['canonical'] = trim((string) $xp->evaluate('string(//link[contains(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"canonical")]/@href)'));

        foreach ($xp->query('//meta') as $node) {
            /** @var \DOMElement $node */
            $key = strtolower(trim($node->getAttribute('property') !== '' ? $node->getAttribute('property') : $node->getAttribute('name')));
            if ($key !== '' && ! isset($out['meta'][$key])) {
                $out['meta'][$key] = trim($node->getAttribute('content'));
            }
        }

        foreach (['h1', 'h2', 'h3'] as $tag) {
            $nodes = $xp->query('//'.$tag);
            $out[$tag] = $nodes instanceof \DOMNodeList ? $nodes->length : 0;
            if ($tag === 'h1' && $nodes instanceof \DOMNodeList) {
                foreach ($nodes as $n) {
                    $out['h1Text'][] = trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '');
                }
            }
        }

        foreach ($xp->query('//script[@type]') as $node) {
            /** @var \DOMElement $node */
            if (str_contains(strtolower($node->getAttribute('type')), 'ld+json')) {
                $raw = trim($node->textContent);
                if ($raw !== '') {
                    $out['jsonld'][] = $raw;
                }
            }
        }

        foreach ($xp->query('//a[@href]') as $node) {
            /** @var \DOMElement $node */
            $href = trim($node->getAttribute('href'));
            if ($href !== '' && ! str_starts_with($href, 'javascript:')) {
                $out['links'][] = $href;
            }
        }

        foreach ($xp->query('//button') as $node) {
            $t = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            if ($t !== '') {
                $out['buttons'][] = $t;
            }
        }

        $out['lists'] = ($xp->query('//ul|//ol')?->length) ?? 0;

        $paras = [];
        foreach ($xp->query('//p') as $node) {
            $t = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            if ($t !== '') {
                $paras[] = $t;
            }
        }
        $out['paragraphs'] = $paras;

        $bodyText = $xp->query('//body');
        $out['text'] = $bodyText instanceof \DOMNodeList && $bodyText->length > 0
            ? (string) preg_replace('/\s+/u', ' ', $bodyText->item(0)->textContent)
            : '';

        return $out;
    }

    /** @param list<string> $blocks @return list<string> */
    private function extractSchemaTypes(array $blocks): array
    {
        $types = [];
        foreach ($blocks as $raw) {
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                if (preg_match_all('/"@type"\s*:\s*"([A-Za-z]+)"/', $raw, $m)) {
                    foreach ($m[1] as $t) {
                        $types[$t] = true;
                    }
                }
                continue;
            }
            $stack = [$data];
            while ($stack !== []) {
                $node = array_pop($stack);
                if (is_array($node)) {
                    if (isset($node['@type'])) {
                        if (is_string($node['@type'])) {
                            $types[$node['@type']] = true;
                        } elseif (is_array($node['@type'])) {
                            foreach ($node['@type'] as $t) {
                                if (is_string($t)) {
                                    $types[$t] = true;
                                }
                            }
                        }
                    }
                    foreach ($node as $v) {
                        if (is_array($v)) {
                            $stack[] = $v;
                        }
                    }
                }
            }
        }
        $list = array_keys($types);
        sort($list);

        return $list;
    }

    private function absolutize(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }
        $parts = parse_url($base);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $href;
        }
        $root = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($href, '/')) {
            return $root.$href;
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) (strrpos($path, '/') ?: 0) + 1);

        return $root.($dir !== '' ? $dir : '/').$href;
    }

    /** 取协议+域名（用于探测 /llms.txt） */
    private function originOf(string $url): string
    {
        $p = parse_url($url);
        if (! is_array($p) || ! isset($p['scheme'], $p['host'])) {
            return '';
        }

        return $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }

    /**
     * 探测站点根目录的 llms.txt（给 AI 看的站点说明书）。
     * 社区提案，非官方标准，报告中只能作为"建议项"。
     *
     * @return array{url:string,exists:bool,status:int,bytes:int,links:int}
     */
    private function checkLlmsTxt(string $origin): array
    {
        $out = ['url' => $origin !== '' ? $origin.'/llms.txt' : '', 'exists' => false, 'status' => 0, 'bytes' => 0, 'links' => 0];
        if ($origin === '') {
            return $out;
        }

        $ch = curl_init($out['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => self::UA,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $out['status'] = $status;
        if (is_string($body) && $status >= 200 && $status < 300 && trim($body) !== '') {
            $looksHtml = preg_match('/<html|<body|<!doctype/i', mb_substr($body, 0, 200)) === 1;
            if (! $looksHtml) {
                $out['exists'] = true;
                $out['bytes'] = strlen($body);
                $out['links'] = (int) preg_match_all('/https?:\/\/[^\s)]+/u', $body);
            }
        }

        return $out;
    }

    /** @param list<array{0:int|float,1:int}> $table */
    private function band(int|float $value, array $table, int $default = 0): int
    {
        foreach ($table as [$threshold, $score]) {
            if ($value >= $threshold) {
                return $score;
            }
        }

        return $default;
    }
}
