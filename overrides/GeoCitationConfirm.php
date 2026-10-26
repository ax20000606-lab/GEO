<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;

/**
 * 引用确认：把搜索结果喂给模型，让它像 AI 搜索那样作答，并标出实际引用了哪几条。
 *
 * 为什么需要这个：搜索接口只返回结果列表，不知道 AI 最后用了谁。
 * 「被搜到」是必要条件，不是充分条件——要在候选里被选中，才算真的进了答案。
 *
 * ⚠️ 口径必须说清楚（这是模拟，不是 DeepSeek 网页版的原始引用）：
 *   我们只喂给它 10 条候选，所以引用率天然偏高（实测能到 9/10）。
 *   真实 AI 搜索会检索更多、只挑少数引用（行业数据约 15%）。
 *   这个数字的用途是"在那 10 条候选里，我会不会选你"，
 *   而不是"AI 从全网检索后引用你的概率"。
 */
final class GeoCitationConfirm
{
    /** 单次确认的输出上限：答案 + JSON 尾巴，1500 够用 */
    private const MAX_TOKENS = 1600;

    private const TIMEOUT = 90;

    /** 喂给模型的候选条数。太多会稀释注意力，太少判断没意义 */
    private const MAX_CANDIDATES = 10;

    /**
     * 对一条采样做引用确认。
     *
     * @param  string  $keyword  用户问题
     * @param  list<array{Url:string,Title:string,Snippet:string}>  $webResults
     * @return array{ok:bool,cited:list<string>,answer:string,model:string,elapsed_ms:int,error:string}
     */
    public function confirm(string $keyword, array $webResults): array
    {
        $fail = static fn (string $msg): array => [
            'ok' => false, 'cited' => [], 'answer' => '', 'model' => '', 'elapsed_ms' => 0, 'error' => $msg,
        ];

        if ($webResults === []) {
            return $fail('没有搜索结果可供确认');
        }

        $lines = [];
        $index = 1;
        foreach (array_slice($webResults, 0, self::MAX_CANDIDATES) as $it) {
            $title = mb_substr(trim((string) ($it['Title'] ?? '')), 0, 40, 'UTF-8');
            $url = trim((string) ($it['Url'] ?? ''));
            $snippet = mb_substr(trim((string) ($it['Snippet'] ?? '')), 0, 100, 'UTF-8');
            if ($url === '') {
                continue;
            }
            $lines[] = '['.$index.'] 标题：'.$title."\n"
                .'    网址：'.$url."\n"
                .'    摘要：'.$snippet;
            $index++;
        }

        if ($lines === []) {
            return $fail('搜索结果缺少可用网址');
        }

        $sys = '你是 AI 搜索助手。你要基于给定的搜索结果回答问题，并如实标注你实际引用了哪几条。';
        $user = '用户问题：'.$keyword."\n\n可用搜索结果：\n".implode("\n\n", $lines)."\n\n"
            ."请基于以上结果回答用户问题，要求：\n"
            ."1. 像 AI 助手那样自然作答，只使用上面给出的信息，不要编造\n"
            ."2. 引用某条信息时在句末标出编号，如[1]；不需要每条都引用，只用真正支撑你论点的\n"
            ."3. 回答完后另起一行输出 ---CITED--- 然后一行 JSON：{\"used\":[编号]}\n"
            ."4. used 只填真正用来组织答案的条目编号，没用到的不写。一条都没用就输出空数组";

        $t = microtime(true);
        try {
            $res = GeoChat::complete($sys, $user, self::MAX_TOKENS, 0.3, self::TIMEOUT);
        } catch (\Throwable $e) {
            return $fail('模型调用失败：'.mb_substr($e->getMessage(), 0, 120, 'UTF-8'));
        }

        $content = (string) $res['content'];
        $pos = strpos($content, '---CITED---');
        $answer = $pos !== false ? trim(substr($content, 0, $pos)) : trim($content);
        $tail = $pos !== false ? trim(substr($content, $pos + strlen('---CITED---'))) : '';

        // 解析 used 编号 → 对应的 url
        $usedNums = [];
        if ($tail !== '') {
            if (preg_match('/\{.*\}/s', $tail, $m)) {
                $json = json_decode($m[0], true);
                if (is_array($json) && isset($json['used']) && is_array($json['used'])) {
                    $usedNums = array_map('intval', $json['used']);
                }
            }
        }

        // 兜底：模型没给 JSON 时，从答案正文里的 [n] 角标提取
        if ($usedNums === []) {
            if (preg_match_all('/\[(\d{1,2})\]/', $answer, $m)) {
                $usedNums = array_map('intval', $m[1]);
            }
        }

        $urls = [];
        $idx = 1;
        foreach (array_slice($webResults, 0, self::MAX_CANDIDATES) as $it) {
            $url = trim((string) ($it['Url'] ?? ''));
            if ($url === '') {
                continue;
            }
            if (in_array($idx, $usedNums, true)) {
                $urls[] = $url;
            }
            $idx++;
        }

        return [
            'ok' => true,
            'cited' => array_values(array_unique($urls)),
            'answer' => $answer,
            'model' => (string) ($res['model'] ?? ''),
            'elapsed_ms' => (int) round((microtime(true) - $t) * 1000),
            'error' => '',
        ];
    }

    /**
     * 对一条采样记录做确认并落库（写进 analysis_json，不新增字段）。
     *
     * @param  object  $run  ai_visibility_runs 的一行（需含 id / keyword / raw_response_json）
     * @return array{ok:bool,cited_count:int,total:int,error:string}
     */
    public function confirmRun(object $run): array
    {
        $raw = json_decode((string) ($run->raw_response_json ?? ''), true);
        $items = (array) ($raw['Result']['WebResults'] ?? []);
        if ($items === []) {
            return ['ok' => false, 'cited_count' => 0, 'total' => 0, 'error' => '没有原始搜索结果'];
        }

        $res = $this->confirm((string) $run->keyword, $items);
        if (! $res['ok']) {
            return ['ok' => false, 'cited_count' => 0, 'total' => count($items), 'error' => $res['error']];
        }

        try {
            $existing = json_decode((string) (DB::table('ai_visibility_runs')->where('id', $run->id)->value('analysis_json') ?? ''), true);
            $payload = is_array($existing) ? $existing : [];
            $payload['cited_urls'] = $res['cited'];
            $payload['cited_answer'] = mb_substr($res['answer'], 0, 4000, 'UTF-8');
            $payload['cited_model'] = $res['model'];
            $payload['cited_at'] = now()->toDateTimeString();
            $payload['cited_scope'] = '模拟确认：仅在该题返回的 '.count($items).' 条候选里判断，非全网检索';
            DB::table('ai_visibility_runs')->where('id', $run->id)->update([
                'analysis_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'cited_count' => 0, 'total' => count($items), 'error' => '写入失败：'.mb_substr($e->getMessage(), 0, 80, 'UTF-8')];
        }

        return ['ok' => true, 'cited_count' => count($res['cited']), 'total' => count($items), 'error' => ''];
    }

    /**
     * 批量确认：给一批采样补上引用标记。已有的默认跳过（增量跑）。
     *
     * @return array{done:int,skipped:int,failed:int,cited:int}
     */
    public function batch(int $limit = 20, bool $force = false, ?string $keyword = null): array
    {
        $out = ['done' => 0, 'skipped' => 0, 'failed' => 0, 'cited' => 0];

        try {
            // 只给"会展示出来"的题做确认：声量对标只统计标了 B/C/D 的题，
            // 给未分类的老词花钱确认完也看不到，纯浪费。
            $q = DB::table('ai_visibility_runs as r')
                ->join('keywords as k', 'k.keyword', '=', 'r.keyword')
                ->whereIn('k.category', ['B', 'C', 'D'])
                ->whereNotNull('r.raw_response_json')
                ->select('r.id', 'r.keyword', 'r.raw_response_json', 'r.analysis_json')
                ->orderBy('r.id');
            if ($keyword !== null && $keyword !== '') {
                $q->where('r.keyword', $keyword);
            }
            $rows = $q->limit(500)->get();
        } catch (\Throwable) {
            return $out;
        }

        foreach ($rows as $run) {
            $existing = json_decode((string) ($run->analysis_json ?? ''), true);
            if (! $force && is_array($existing) && isset($existing['cited_urls'])) {
                $out['skipped']++;
                continue;
            }
            if ($out['done'] >= $limit) {
                break;
            }
            $r = $this->confirmRun($run);
            if ($r['ok']) {
                $out['done']++;
                $out['cited'] += (int) $r['cited_count'];
            } else {
                $out['failed']++;
            }
        }

        return $out;
    }
}
