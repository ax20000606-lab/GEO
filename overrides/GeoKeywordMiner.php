<?php

namespace App\Support\GeoAudit;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;

/**
 * 关键词挖掘：意图挖掘（AI 生成问题集）+ 真实采集（百度联想词）
 *
 * 边界说明（重要，不要吹）：
 * - 意图挖掘由模型生成，属于"推测"，不是搜索量数据
 * - 百度联想词是**真实补全数据**（suggestion.baidu.com 公开接口，GBK 编码）
 * - 小红书 / 抖音联想词需要登录态与签名，服务端无法采集 → 不伪造，明确标注不支持
 * - 没有任何"截图"：服务端没有浏览器环境。留痕用「采集时间 + 可验证的来源」代替
 */
final class GeoKeywordMiner
{
    private const MAX_TOKENS = 2600;

    private const TIMEOUT = 90;

    private const MAX_SEEDS = 8;

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * 步骤一：AI 生成问题集 + 供采集的核心词
     *
     * @param  array{industry?:string,brand?:string,audience?:string,seed?:string}  $input
     * @return array{questions:list<array{question:string,intent:string,audience:string}>,seeds:list<string>,model:string,elapsed_ms:int,usage:array<string,int>}
     */
    public function expand(array $input): array
    {
        $industry = trim((string) ($input['industry'] ?? ''));
        $brand = trim((string) ($input['brand'] ?? ''));
        $audience = trim((string) ($input['audience'] ?? ''));
        $seed = trim((string) ($input['seed'] ?? ''));

        if ($industry === '' && $seed === '') {
            throw new RuntimeException('请至少填写"行业/品类"或"起始关键词"');
        }

        $started = microtime(true);
        $model = $this->resolveModel();
        $apiKey = app(ApiKeyCrypto::class)->decrypt((string) $model->getRawOriginal('api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('模型密钥不可用');
        }

        $prompt = implode("\n", [
            '请为下面这个业务做 AI 搜索意图挖掘。',
            '',
            '行业/品类：'.($industry !== '' ? $industry : '（未填）'),
            '品牌/产品：'.($brand !== '' ? $brand : '（未填）'),
            '目标受众：'.($audience !== '' ? $audience : '（未填）'),
            '起始关键词：'.($seed !== '' ? $seed : '（未填）'),
            '',
            '输出一个 ```json 代码块，结构：',
            '{"questions":[{"question":"用户会原话怎么问（口语，不要营销腔）","intent":"认知|比较|选购|避坑|售后","audience":"人群"}],"seeds":["用于搜索框联想词采集的核心词，3–8 个，2–6 字，不要长句"]}',
            '',
            '要求：',
            '1. questions 给 18–25 条，覆盖 5 类意图，每类至少 3 条；',
            '2. 问题必须是用户真实会打出来的话（含口语、方言词、错误叫法都可以）；',
            '3. seeds 是能拿去搜索框试的关键词根，不要写成完整问句；',
            '4. 不要编造具体数字、销量、认证。',
        ]);

        $payload = [
            'model' => (string) $model->model_id,
            'messages' => [
                ['role' => 'system', 'content' => '你是 GEO 关键词与意图研究分析师。只输出结构化结果，不解释过程。'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.5,
            'max_tokens' => self::MAX_TOKENS,
            'stream' => false,
        ];

        $ch = curl_init(rtrim((string) $model->api_url, '/').'/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer '.$apiKey],
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = (string) curl_error($ch);
        curl_close($ch);

        if (! is_string($body) || $body === '') {
            throw new RuntimeException('模型请求失败：'.($err !== '' ? $err : 'HTTP '.$status));
        }
        $json = json_decode($body, true);
        if (! is_array($json) || isset($json['error']['message'])) {
            throw new RuntimeException('模型返回异常：'.mb_substr((string) ($json['error']['message'] ?? 'HTTP '.$status), 0, 120));
        }
        $content = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('模型没有产出内容，请重试');
        }

        $parsed = $this->parseJson($content);
        $questions = [];
        foreach ((array) ($parsed['questions'] ?? []) as $q) {
            if (! is_array($q)) {
                continue;
            }
            $text = trim((string) ($q['question'] ?? ''));
            if ($text === '' || mb_strlen($text, 'UTF-8') > 120) {
                continue;
            }
            $intent = trim((string) ($q['intent'] ?? '选购'));
            $questions[] = [
                'question' => $text,
                'intent' => in_array($intent, ['认知', '比较', '选购', '避坑', '售后'], true) ? $intent : '选购',
                'audience' => mb_substr(trim((string) ($q['audience'] ?? '')), 0, 20, 'UTF-8'),
            ];
        }
        $seeds = [];
        foreach ((array) ($parsed['seeds'] ?? []) as $s) {
            $s = trim((string) $s);
            if ($s !== '' && mb_strlen($s, 'UTF-8') <= 12) {
                $seeds[] = $s;
            }
        }
        $seeds = array_slice(array_values(array_unique($seeds)), 0, self::MAX_SEEDS);

        if ($questions === [] && $seeds === []) {
            throw new RuntimeException('模型输出无法解析为问题集，请重试');
        }

        return [
            'questions' => $questions,
            'seeds' => $seeds,
            'model' => (string) $model->model_id,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'usage' => [
                'prompt_tokens' => (int) ($json['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($json['usage']['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($json['usage']['total_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * 步骤二：百度搜索框联想词（真实补全数据，公开接口）
     *
     * @param  list<string>  $seeds
     * @return array<string,list<string>> 种子词 => 联想词
     */
    public function baiduSuggest(array $seeds): array
    {
        $out = [];
        foreach (array_slice($seeds, 0, self::MAX_SEEDS) as $seed) {
            $seed = trim($seed);
            if ($seed === '') {
                continue;
            }
            $out[$seed] = $this->fetchBaiduSuggest($seed);
        }

        return $out;
    }

    /** @return list<string> */
    private function fetchBaiduSuggest(string $wd): array
    {
        // 百度补全接口要求 GBK 编码的 wd
        $encoded = @iconv('UTF-8', 'GBK//IGNORE', $wd);
        if ($encoded === false) {
            return [];
        }
        $url = 'https://suggestion.baidu.com/su?ie=gbk&cb=cb&wd='.urlencode($encoded);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_REFERER => 'https://www.baidu.com/',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (! is_string($body) || $body === '') {
            return [];
        }

        // 响应是 GBK 的 JSONP：cb({q:"...",p:false,s:["...","..."]});
        $json = trim($body);
        $json = preg_replace('/^[^(]*\(/', '', $json) ?? $json;
        $json = preg_replace('/\);?\s*$/', '', $json) ?? $json;
        $decoded = @json_decode($json, true);
        if (! is_array($decoded)) {
            // 兜底：直接抠出 s 数组里的字符串
            if (! preg_match('/s:\[(.*?)\]/s', $json, $m)) {
                return [];
            }
            preg_match_all('/"([^"]*)"/', $m[1], $mm);
            $words = $mm[1] ?? [];
        } else {
            $words = array_values(array_filter((array) ($decoded['s'] ?? []), 'is_string'));
        }

        $words = array_map(static fn (string $w): string => trim((string) @iconv('GBK', 'UTF-8//IGNORE', $w)), $words);
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== '' && mb_strlen($w, 'UTF-8') <= 40));

        return array_slice(array_values(array_unique($words)), 0, 10);
    }

    /** @return array<string,mixed> */
    private function parseJson(string $content): array
    {
        if (preg_match_all('/```(?:json|JSON)?\s*(.+?)\s*```/su', $content, $m)) {
            foreach (array_reverse($m[1]) as $raw) {
                $decoded = json_decode(trim($raw), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        $decoded = json_decode(trim($content), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveModel(): AiModel
    {
        return GeoModelPicker::pick();
    }
}
