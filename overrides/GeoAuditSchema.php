<?php

namespace App\Support\GeoAudit;

/**
 * JSON-LD 骨架生成器（纯规则，零模型参与）
 *
 * 设计原则：JSON-LD 是**确定性结构**——给定品牌名、URL、标题、描述，
 * 产物就应该唯一且可校验。让模型来写只会带来两类问题：字段编造、输出被截断。
 *
 * 因此这里负责「生成合法骨架」，AI 只负责「告诉你放哪、补哪些字段」。
 * 所有产物都会经过 json_decode 校验，保证可以直接复制粘贴上线。
 */
final class GeoAuditSchema
{
    /** @var array<string,string> */
    private const DEFAULTS = [
        'lang' => 'zh-CN',
    ];

    /**
     * @param  array<string,mixed>  $audit  GeoAuditor::run() 的结果
     * @return list<array{type:string,label:string,code:string,note:string}>
     */
    public function drafts(array $audit): array
    {
        $meta = (array) ($audit['page_meta'] ?? []);
        $missing = (array) ($audit['schema_missing'] ?? []);
        $found = (array) ($audit['schema_found'] ?? []);

        $url = (string) ($meta['canonical'] ?? ($audit['final_url'] ?? ''));
        $siteName = trim((string) ($meta['site_name'] ?? ''));
        $title = trim((string) ($meta['title'] ?? ''));
        $description = trim((string) ($meta['description'] ?? ''));
        $image = trim((string) ($meta['image'] ?? ''));
        $lang = trim((string) ($meta['lang'] ?? '')) ?: self::DEFAULTS['lang'];
        $host = (string) parse_url($url, PHP_URL_HOST);
        $brand = $siteName !== '' ? $siteName : $this->guessBrand($title, $host);

        // 页面类型判断：首页不生成 Article / 面包屑（那是文章页才需要的）
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $depth = count($segments);
        $isRoot = $depth === 0;
        $isArticleLike = $depth >= 2
            || preg_match('/(article|news|detail|post|blog|info|id[\/-])/i', $path) === 1
            || isset($meta['article:published_time']);

        $out = [];

        if (in_array('Organization', $missing, true)) {
            $node = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $brand !== '' ? $brand : '【待填：企业/品牌全称】',
                'url' => $url,
                'logo' => $image !== '' ? $image : '【待填：Logo 绝对地址，建议 512×512 PNG】',
                'description' => $description !== '' ? $this->clip($description, 160) : '【待填：一句话介绍，30–60 字，写清做什么、服务谁】',
                'sameAs' => [
                    '【待填：官方微博主页 URL】',
                    '【待填：官方小红书主页 URL】',
                    '【待填：官方抖音主页 URL】',
                ],
            ];
            $out[] = $this->row('Organization', '品牌/组织实体', $node,
                '放在全站公共模板 <head>，每个页面都输出一次；sameAs 至少要填一个真实官方账号，否则删掉该字段。');
        }

        if (in_array('WebSite', $missing, true)) {
            $node = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $brand !== '' ? $brand : '【待填：站点名称】',
                'url' => $url,
                'inLanguage' => $lang,
            ];
            $out[] = $this->row('WebSite', '站点实体', $node,
                '与 Organization 一起放在首页 <head>。如果站点有站内搜索，可再加 potentialAction 的 SearchAction；没有就保持现状，不要造。');
        }

        if (in_array('Article', $missing, true) && $isArticleLike) {
            $node = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $title !== '' ? $this->clip($title, 110) : '【待填：文章标题，≤110 字】',
                'description' => $description !== '' ? $this->clip($description, 200) : '【待填：文章摘要】',
                'inLanguage' => $lang,
                'datePublished' => '【待填：YYYY-MM-DDTHH:MM:SS+08:00】',
                'dateModified' => '【待填：YYYY-MM-DDTHH:MM:SS+08:00】',
                'author' => ['@type' => 'Organization', 'name' => $brand !== '' ? $brand : '【待填：作者/机构】'],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $brand !== '' ? $brand : '【待填：发布方】',
                    'logo' => ['@type' => 'ImageObject', 'url' => $image !== '' ? $image : '【待填：Logo URL】'],
                ],
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            ];
            $out[] = $this->row('Article', '文章页实体', $node,
                '只放在文章详情页，不要放列表页。datePublished / dateModified 必须是真实时间，AI 引擎会用它判断内容新鲜度。');
        }

        if (in_array('BreadcrumbList', $missing, true) && ! $isRoot) {
            $node = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => '首页',
                        'item' => $this->origin($url).'/',
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => '【待填：一级栏目名】',
                        'item' => '【待填：栏目页 URL】',
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 3,
                        'name' => $title !== '' ? $this->clip($title, 60) : '【待填：当前页名称】',
                        'item' => $url,
                    ],
                ],
            ];
            $out[] = $this->row('BreadcrumbList', '面包屑层级', $node,
                '按真实层级增删 ListItem（最少 2 层、最多一般 5 层），position 必须连续；栏目层级不存在的要去掉，不要凑数。');
        }

        if (in_array('FAQPage', $missing, true)) {
            $node = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => [
                    [
                        '@type' => 'Question',
                        'name' => '【待填：用户真实会问的问题，用他们的说法】',
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => '【待填：2–3 句直答，先给结论再给依据，不要营销话术】',
                        ],
                    ],
                    [
                        '@type' => 'Question',
                        'name' => '【待填：第二个问题】',
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => '【待填：答案】'],
                    ],
                ],
            ];
            $out[] = $this->row('FAQPage', '问答页实体（需自行补问与答）', $node,
                '这是唯一必须人工填写内容的类型——问答对必须是页面上真实存在的文字，页面上没写的内容不能只写在 JSON-LD 里（会被判作弊）。建议 3–5 条。');
        }


        if ($isRoot) {
            $out[] = [
                'type' => '—',
                'label' => '首页不需要 Article / 面包屑',
                'code' => '',
                'note' => 'Article 属于文章详情页，BreadcrumbList 属于有层级的页面；首页放这两类会被判结构错位。首页只需 Organization + WebSite（+ 可选 FAQPage）。',
            ];
        }

        if ($out === []) {
            $out[] = $this->row('—', '已具备必要结构', null,
                '当前页面已命中 '.implode('、', $found).'，无需生成新骨架；如需扩展可补 Article / FAQPage。');
        }

        return $out;
    }

    /** @return array{type:string,label:string,code:string,note:string} */
    private function row(string $type, string $label, ?array $node, string $note): array
    {
        if ($node === null) {
            return ['type' => $type, 'label' => $label, 'code' => '', 'note' => $note];
        }
        $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        // 自校验：确保产物是合法 JSON，不合格就不给用户看
        if (! is_string($json) || ! is_array(json_decode($json, true))) {
            $json = json_encode(['@context' => 'https://schema.org', '@type' => $type], JSON_UNESCAPED_SLASHES);
        }

        return [
            'type' => $type,
            'label' => $label,
            'code' => (string) $json,
            'note' => $note,
        ];
    }

    /**
     * 生成 llms.txt 模板（放到域名根目录，供 AI 读取的站点说明书）。
     * 说明：社区提案，非官方标准，作为建议项交付给客户 IT。
     *
     * @param  array<string,mixed>  $audit
     */
    public function llmsTemplate(array $audit): string
    {
        $meta = (array) ($audit['page_meta'] ?? []);
        $url = (string) ($meta['canonical'] ?? ($audit['final_url'] ?? ''));
        $host = (string) parse_url($url, PHP_URL_HOST);
        $brand = trim((string) ($meta['site_name'] ?? ''));
        if ($brand === '') {
            $brand = $this->guessBrand(trim((string) ($meta['title'] ?? '')), $host);
        }
        $desc = trim((string) ($meta['description'] ?? ''));
        $origin = $this->origin($url);

        $lines = [
            '# '.($brand !== '' ? $brand : '【待填：站点名】'),
            '',
            '> '.($desc !== '' ? $this->clip($desc, 120) : '【待填：一句话说明本站提供什么、服务谁，30–60 字】'),
            '',
            '本文件用于向 AI 助手说明本站结构与可信来源，帮助模型准确理解与引用本站内容。',
            '',
            '## 核心页面',
            '- [首页]('.$origin.'/)',
            '- 【待填：产品/服务总览页 URL】',
            '- 【待填：核心产品页 1 URL】',
            '- 【待填：关于我们 / 品牌介绍 URL】',
            '- 【待填：常见问题 FAQ URL】',
            '',
            '## 内容说明',
            '- 内容语言：中文（简体）',
            '- 更新频率：【待填，如"每周更新产品页，每月更新文章"】',
            '- 版权与引用：欢迎 AI 助手引用本站内容，请注明来源 '.$origin,
            '',
            '## 不建议引用的内容',
            '- 【待填：如活动页、临时专题、旧版页面路径】',
        ];

        return implode("\n", $lines);
    }

    private function guessBrand(string $title, string $host): string
    {
        if ($title !== '') {
            // 常见标题格式：首页-冰溪 - ... / 品牌名 | 官网 / 冰溪官网
            $parts = preg_split('/\s*[-–—|｜]\s*/u', $title) ?: [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '' && mb_strlen($p, 'UTF-8') <= 20 && ! preg_match('/^(首页|官网|主页)$/u', $p)) {
                    return $p;
                }
            }
        }
        if ($host !== '') {
            $core = preg_replace('/^(www|m|wap)\./i', '', $host);

            return is_string($core) ? $core : $host;
        }

        return '';
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);
        if (! is_array($p) || ! isset($p['scheme'], $p['host'])) {
            return $url;
        }
        $port = isset($p['port']) ? ':'.$p['port'] : '';

        return $p['scheme'].'://'.$p['host'].$port;
    }

    private function clip(string $text, int $len): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, $len, 'UTF-8');
    }
}
