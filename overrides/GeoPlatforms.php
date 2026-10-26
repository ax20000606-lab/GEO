<?php

namespace App\Support\GeoAudit;

/**
 * 平台档案：每个平台一套写法约束 + 一套可机器校验的规则。
 *
 * ── 为什么要有这个东西 ──────────────────────────────────────────────
 * 知乎长文和小红书笔记不是"改写"关系，是两种文体：
 *   知乎 2000+ 字分点论述，小红书 300–600 字结论前置 + emoji + 标签。
 * 所以平台必须是一等公民——生成时按平台模板写，而不是写完再改。
 *
 * ── 数据从哪来 ────────────────────────────────────────────────────
 * storage/app/geo-platforms.json。改这个文件即时生效，不用重建容器。
 * 加平台、调字数、加禁用词都只改 JSON，不碰代码。
 *
 * ── 校验为什么用规则 ──────────────────────────────────────────────
 * 字数、标题长度、禁用词、标签数量都是可数的，规则可复现、零幻觉。
 * 只有"这个标题够不够吸引人"这种才需要模型，而那不该作为硬性通过条件。
 */
final class GeoPlatforms
{
    /**
     * 读全部平台档案。
     *
     * @return array<string,array<string,mixed>> slug => profile
     */
    public static function all(): array
    {
        try {
            $file = storage_path('app/geo-platforms.json');
            if (! is_file($file)) {
                return [];
            }
            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data) || empty($data['platforms'])) {
                return [];
            }

            $out = [];
            foreach ((array) $data['platforms'] as $p) {
                if (! is_array($p) || empty($p['slug'])) {
                    continue;
                }
                $out[(string) $p['slug']] = $p;
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public static function defaultSlug(): string
    {
        try {
            $file = storage_path('app/geo-platforms.json');
            if (is_file($file)) {
                $data = json_decode((string) file_get_contents($file), true);
                $slug = (string) (is_array($data) ? ($data['default'] ?? '') : '');
                if ($slug !== '' && self::get($slug) !== null) {
                    return $slug;
                }
            }
        } catch (\Throwable) {
            // 落到下面的兜底
        }

        return 'zhihu';
    }

    /** @return array<string,mixed>|null */
    public static function get(string $slug): ?array
    {
        $all = self::all();

        return $all[$slug] ?? null;
    }

    /**
     * 生成注入 prompt 的平台约束段。
     */
    public static function promptBlock(array $p): string
    {
        $words = (array) ($p['words'] ?? [800, 1500]);
        $min = (int) ($words[0] ?? 800);
        $max = (int) ($words[1] ?? 1500);

        // 给模型的目标区间要收窄：直接给 [$min, $max] 时它经常顶格甚至溢出
        // （实测小红书要求 300–600 → 641；今日头条要求 800–1500 → 1832）。
        // 收窄到区间 10%–55% 位置后命中率高很多。
        $span = $max - $min;
        $aimMin = (int) round($min + $span * 0.10);
        $aimMax = (int) round($min + $span * 0.55);

        $lines = [
            '## 目标平台：'.($p['name'] ?? ''),
            '- 字数：**目标 '.$aimMin.'–'.$aimMax.' 字**（中文字符），硬上限 '.$max.' 字。'
                .'**超过 '.$max.' 字本次输出即作废**。',
            '- **写完请自己数一遍正文字数**（只数正文，不含标题和 JSON-LD）。'
                .'如果超了，删掉最次要的一节再输出——宁可少一节，也不要超字数。',
            '- 标题：不超过 '.((int) ($p['title_max'] ?? 30)).' 字。',
            '- 结构：'.(string) ($p['structure'] ?? ''),
            '- 语气：'.(string) ($p['tone'] ?? ''),
        ];

        if (! empty($p['outline']) && is_array($p['outline'])) {
            $lines[] = '- 建议骨架：'.implode(' → ', array_map('strval', $p['outline']));
        }

        if (! empty($p['required']) && is_array($p['required'])) {
            $lines[] = '- 必须包含：'.implode('；', array_map('strval', $p['required']));
        }

        if (! empty($p['forbidden']) && is_array($p['forbidden'])) {
            $lines[] = '- **禁止出现这些词**（合规硬约束，出现即作废）：'.implode('、', array_map('strval', $p['forbidden']));
        }

        if (! empty($p['no_markdown'])) {
            $lines[] = '- **不要使用 markdown 语法**：不要写 `#` 标题、不要用 `**加粗**`、不要用 `-` 列表。'
                .'这个平台不渲染 markdown，请用换行、空行、序号或 emoji 来做结构。';
        }

        return implode("\n", $lines);
    }

    /**
     * 按平台规则做可机器校验的检查。
     *
     * @param  string  $markdown  模型产出的正文（已剥掉 JSON-LD）
     * @param  string  $title  提取到的标题
     * @return array{items:list<array{label:string,ok:bool,detail:string,soft:bool}>,pass:int,total:int}
     */
    public static function check(string $markdown, string $title, array $p): array
    {
        $items = [];
        $plain = preg_replace('/```.*?```/su', '', $markdown) ?? $markdown;
        $textLen = mb_strlen(preg_replace('/\s+/u', '', $plain) ?? '', 'UTF-8');

        // ── 字数 ──
        $words = (array) ($p['words'] ?? [0, 0]);
        $min = (int) ($words[0] ?? 0);
        $max = (int) ($words[1] ?? 0);
        if ($min > 0 && $max > 0) {
            $items[] = [
                'label' => '字数',
                'ok' => $textLen >= $min && $textLen <= $max,
                'detail' => $textLen.' 字（要求 '.$min.'–'.$max.'）',
                'soft' => false,
            ];
        }

        // ── 标题长度 ──
        $titleMax = (int) ($p['title_max'] ?? 0);
        if ($titleMax > 0 && $title !== '') {
            $tLen = mb_strlen($title, 'UTF-8');
            $items[] = [
                'label' => '标题长度',
                'ok' => $tLen <= $titleMax,
                'detail' => $tLen.' 字（上限 '.$titleMax.'）',
                'soft' => false,
            ];
        }

        // ── 禁用词 ──
        $forbidden = (array) ($p['forbidden'] ?? []);
        $hits = [];
        foreach ($forbidden as $w) {
            $w = trim((string) $w);
            if ($w === '') {
                continue;
            }
            if (mb_strpos($plain, $w) !== false) {
                $hits[] = $w;
            }
        }
        if ($forbidden !== []) {
            $items[] = [
                'label' => '合规禁用词',
                'ok' => $hits === [],
                'detail' => $hits === [] ? '未命中' : '命中 '.count($hits).' 处：'.implode('、', array_slice($hits, 0, 6)),
                'soft' => false,
            ];
        }

        // ── 结构化检查（来自 checks 配置）──
        foreach ((array) ($p['checks'] ?? []) as $c) {
            if (! is_array($c) || empty($c['type'])) {
                continue;
            }
            $type = (string) $c['type'];
            $label = (string) ($c['label'] ?? $type);

            switch ($type) {
                case 'hashtags':
                    $n = preg_match_all('/#[^\s#]{1,20}/u', $plain) ?: 0;
                    $cmin = (int) ($c['min'] ?? 1);
                    $cmax = (int) ($c['max'] ?? 10);
                    $items[] = ['label' => $label, 'ok' => $n >= $cmin && $n <= $cmax, 'detail' => $n.' 个', 'soft' => false];
                    break;

                case 'min_headings':
                    $n = self::countHeadings($plain);
                    $need = (int) ($c['min'] ?? 2);
                    $items[] = ['label' => $label, 'ok' => $n >= $need, 'detail' => $n.' 个', 'soft' => false];
                    break;

                case 'min_sections':
                    $n = self::countSections($plain);
                    $need = (int) ($c['min'] ?? 3);
                    $items[] = ['label' => $label, 'ok' => $n >= $need, 'detail' => $n.' 段', 'soft' => false];
                    break;

                case 'max_paragraphs':
                    $n = self::countSections($plain);
                    $limit = (int) ($c['max'] ?? 12);
                    $items[] = ['label' => $label, 'ok' => $n <= $limit, 'detail' => $n.' 段', 'soft' => true];
                    break;

                case 'ending_question':
                    $tail = mb_substr($plain, max(0, mb_strlen($plain, 'UTF-8') - 160), 160, 'UTF-8');
                    $ok = (bool) preg_match('/[？?]/u', $tail);
                    $items[] = ['label' => $label, 'ok' => $ok, 'detail' => $ok ? '有' : '结尾 160 字内没有问句', 'soft' => false];
                    break;

                case 'max_sentence_len':
                    $longest = self::longestSentence($plain);
                    $limit = (int) ($c['max'] ?? 25);
                    $items[] = ['label' => $label, 'ok' => $longest <= $limit, 'detail' => '最长 '.$longest.' 字', 'soft' => true];
                    break;

                default:
                    break;
            }
        }

        $pass = 0;
        foreach ($items as $it) {
            if ($it['ok']) {
                $pass++;
            }
        }

        return ['items' => $items, 'pass' => $pass, 'total' => count($items)];
    }

    /** 统计小标题：markdown 的 ##/###，或中文「一、二、」编号 */
    private static function countHeadings(string $text): int
    {
        $md = preg_match_all('/^#{2,4}\s+\S/mu', $text) ?: 0;
        $cn = preg_match_all('/^\s*(?:[一二三四五六七八九十]+[、.．]|\d+[、.．]\s*\S)/mu', $text) ?: 0;

        return max($md, $cn, preg_match_all('/^\*\*[^*\n]{2,30}\*\*\s*$/mu', $text) ?: 0);
    }

    /** 统计段落数（空行分隔的非空块） */
    private static function countSections(string $text): int
    {
        $blocks = preg_split('/\R\s*\R/u', trim($text)) ?: [];

        return count(array_filter($blocks, static fn ($b): bool => trim((string) $b) !== ''));
    }

    /** 最长句子字数（按中英文句读切） */
    private static function longestSentence(string $text): int
    {
        $parts = preg_split('/[。！？!?；;\n]+/u', $text) ?: [];
        $max = 0;
        foreach ($parts as $p) {
            $len = mb_strlen(trim((string) preg_replace('/\s+/u', '', (string) $p)), 'UTF-8');
            $max = max($max, $len);
        }

        return $max;
    }
}
