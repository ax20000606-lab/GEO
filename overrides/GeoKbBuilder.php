<?php

namespace App\Support\GeoAudit;

/**
 * 知识库批量导入：多个 URL → 抓取 → 清洗正文 → 合并成一份带来源标注的资料稿
 *
 * 设计取舍（重要）：
 * 本类**只负责生成资料稿，不直接写 knowledge_bases 表**。
 * 原因：GEOFlow 的切片/向量化链路是质检的地基，绕过官方入口直接插库
 * 会导致"有内容但没切片"，质检直接失效。所以这里产出 markdown，
 * 由用户走「知识库 → 新建」的官方入口粘贴（系统会自动切片）。
 */
final class GeoKbBuilder
{
    private const MAX_URLS = 10;

    private const MAX_CHARS_PER_PAGE = 20000;

    private const TIMEOUT = 20;

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** 明显是导航/页脚之类的容器，直接丢掉 */
    private const DROP_TAGS = ['script', 'style', 'noscript', 'nav', 'footer', 'header', 'aside', 'form', 'iframe', 'svg'];

    /**
     * @param  list<string>  $urls
     * @param  array{title?:string,keep_headings?:bool}  $options
     * @return array{ok:bool,markdown:string,pages:list<array{url:string,ok:bool,title:string,chars:int,error:string}>,total_chars:int}
     */
    public function build(array $urls, array $options = []): array
    {
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls), static fn (string $u): bool => $u !== '')));
        if ($urls === []) {
            return ['ok' => false, 'markdown' => '', 'pages' => [], 'total_chars' => 0];
        }
        $urls = array_slice($urls, 0, self::MAX_URLS);

        $kbTitle = trim((string) ($options['title'] ?? ''));
        $keepHeadings = (bool) ($options['keep_headings'] ?? true);

        $pages = [];
        $sections = [];
        $totalChars = 0;

        foreach ($urls as $url) {
            if (! preg_match('#^https?://#i', $url)) {
                $url = 'https://'.$url;
            }
            $page = $this->fetchAndClean($url, $keepHeadings);
            $pages[] = $page;
            if ($page['ok'] && $page['body'] !== '') {
                $totalChars += $page['chars'];
                $sections[] = '## '.$page['title']."\n\n"
                    .'> 来源：'.$page['url'].'　抓取时间：'.date('Y-m-d H:i')."\n\n"
                    .$page['body'];
            }
        }

        if ($sections === []) {
            return ['ok' => false, 'markdown' => '', 'pages' => $pages, 'total_chars' => 0];
        }

        $header = '# '.($kbTitle !== '' ? $kbTitle : '研究资料汇编（'.count($sections).' 个来源）')."\n\n"
            ."> 本文由 GEOFlow 批量采集生成，每条内容均标注原始来源，便于质检时溯源。\n"
            .'> 生成时间：'.date('Y-m-d H:i')."\n\n";

        $sourceList = "\n\n---\n\n## 来源清单\n\n";
        foreach ($pages as $i => $p) {
            if (! $p['ok']) {
                continue;
            }
            $sourceList .= ($i + 1).'. ['.$p['title'].']('.$p['url'].') — 约 '.$p['chars']." 字\n";
        }

        return [
            'ok' => true,
            'markdown' => $header.implode("\n\n---\n\n", $sections).$sourceList,
            'pages' => array_map(static fn (array $p): array => [
                'url' => $p['url'], 'ok' => $p['ok'], 'title' => $p['title'], 'chars' => $p['chars'], 'error' => $p['error'],
            ], $pages),
            'total_chars' => $totalChars,
        ];
    }

    /** @return array{ok:bool,url:string,title:string,chars:int,body:string,error:string} */
    private function fetchAndClean(string $url, bool $keepHeadings): array
    {
        $out = ['ok' => false, 'url' => $url, 'title' => '', 'chars' => 0, 'body' => '', 'error' => ''];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => ['Accept-Language: zh-CN,zh;q=0.9,en;q=0.8'],
        ]);
        $html = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = (string) curl_error($ch);
        curl_close($ch);

        if (! is_string($html) || $html === '') {
            $out['error'] = $err !== '' ? $err : 'HTTP '.$status;

            return $out;
        }
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'GB18030');
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $loaded) {
            $out['error'] = 'HTML 解析失败';

            return $out;
        }

        $xp = new \DOMXPath($dom);
        $title = trim((string) $xp->evaluate('string(//title)'));
        $h1 = trim((string) $xp->evaluate('string(//h1[1])'));
        if ($h1 !== '' && mb_strlen($h1, 'UTF-8') >= 4) {
            $title = $h1;
        }

        // 去掉噪音容器
        foreach (self::DROP_TAGS as $tag) {
            foreach ($xp->query('//'.$tag) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // 优先正文容器
        $root = null;
        foreach (['//article', '//main', '//*[contains(@class,"article")]', '//*[contains(@class,"content")]', '//body'] as $expr) {
            $nodes = $xp->query($expr);
            if ($nodes instanceof \DOMNodeList && $nodes->length > 0) {
                $root = $nodes->item(0);
                break;
            }
        }
        if ($root === null) {
            $out['error'] = '未找到正文容器';

            return $out;
        }

        $body = $this->nodeToMarkdown($root, $keepHeadings);
        $body = preg_replace('/\n{3,}/u', "\n\n", $body) ?? $body;
        $body = trim($body);

        // 兜底：结构化提取太少时（多见于 div 套 div、没有 p 标签的站点），
        // 直接取正文容器的纯文本，压缩空白后作为内容
        if (mb_strlen($body, 'UTF-8') < 300) {
            $rawText = $root->textContent;
            $rawText = preg_replace('/[ \t]+/u', ' ', (string) $rawText) ?? (string) $rawText;
            $rawText = preg_replace('/\n\s*\n\s*\n+/u', "\n\n", $rawText) ?? $rawText;
            $lines = array_values(array_filter(array_map('trim', explode("\n", $rawText)), static fn (string $l): bool => mb_strlen($l, 'UTF-8') >= 8));
            $fallback = implode("\n\n", $lines);
            if (mb_strlen($fallback, 'UTF-8') > mb_strlen($body, 'UTF-8')) {
                $body = $fallback;
            }
        }

        if (mb_strlen($body, 'UTF-8') < 80) {
            $out['error'] = '正文过短（可能是纯 JS 渲染页面，静态 HTML 里没有内容）';

            return $out;
        }
        if (mb_strlen($body, 'UTF-8') > self::MAX_CHARS_PER_PAGE) {
            $body = mb_substr($body, 0, self::MAX_CHARS_PER_PAGE, 'UTF-8')."\n\n（内容超长已截断）";
        }

        $out['ok'] = true;
        $out['title'] = $title !== '' ? $title : $url;
        $out['body'] = $body;
        $out['chars'] = mb_strlen($body, 'UTF-8');

        return $out;
    }

    private function nodeToMarkdown(\DOMNode $node, bool $keepHeadings): string
    {
        $buf = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $text = trim(preg_replace('/\s+/u', ' ', $child->textContent) ?? '');

            if ($text === '') {
                // 段落容器继续下钻
                if (in_array($tag, ['div', 'section', 'article', 'main', 'ul', 'ol'], true)) {
                    $inner = $this->nodeToMarkdown($child, $keepHeadings);
                    if ($inner !== '') {
                        $buf[] = $inner;
                    }
                }
                continue;
            }

            if ($keepHeadings && in_array($tag, ['h1', 'h2', 'h3', 'h4'], true)) {
                $level = ['h1' => '##', 'h2' => '###', 'h3' => '####', 'h4' => '#####'][$tag];
                $buf[] = $level.' '.$text;
                continue;
            }
            if ($tag === 'li') {
                $buf[] = '- '.$text;
                continue;
            }
            if ($tag === 'p' || $tag === 'blockquote' || $tag === 'td') {
                $buf[] = $text;
                continue;
            }
            if (in_array($tag, ['div', 'section', 'article', 'main', 'ul', 'ol'], true)) {
                $inner = $this->nodeToMarkdown($child, $keepHeadings);
                if ($inner !== '') {
                    $buf[] = $inner;
                }
            }
        }

        return trim(implode("\n\n", $buf));
    }
}
