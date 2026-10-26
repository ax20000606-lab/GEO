<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiSourceProvider;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;

/**
 * 搜索源客户端（原 DoubaoSearchCustomClient 的本地扩展版）
 *
 * ── 为什么要改这个文件 ────────────────────────────────────────────────
 * GEOFlow 原生只认豆包 Search Custom 协议：
 *   请求  {Query, SearchType, Count, NeedSummary, Filter:{NeedContent,...}}
 *   响应  {Result:{WebResults:[{Url,Title,SiteName,Snippet,Summary,Content,
 *                             PublishTime,RankScore,AuthInfoLevel}]}}
 *
 * 而 DeepSeek 在 2026-10-17 上线了自研搜索 API：
 *   请求  {query, count}
 *   响应  {results:[{title,url,snippet,summary,content,site_name,date,
 *                    rerank_score,authority}]}
 *
 * 两边字段是一一对应的，只是命名风格不同。所以这里不重写整条链路，
 * 只做两件事：
 *   1. 识别到 DeepSeek 端点时，换成 DeepSeek 的请求体；
 *   2. 把 DeepSeek 的响应「翻译」成豆包结构，交回原生 normalizer。
 *
 * 好处：normalizer、入库、统计口径全部走原生实现，一行不改；
 *      数据照样进 ai_visibility_runs / ai_visibility_sources，
 *      数据中心的 KPI（提及率 / 前三率 / 第一推荐位率）直接生效。
 *
 * 字段映射（左边 GEOFlow 认，右边 DeepSeek 给）：
 *   Url←url  Title←title  SiteName←site_name  Snippet←snippet
 *   Summary←summary  Content←content  PublishTime←date
 *   RankScore←rerank_score  AuthInfoLevel←authority
 *
 * 注：DeepSeek 的 authority 是 0~4 的权威度，-1 表示未知；
 *     normalizer 存的是字符串，所以 -1 转空串（等价于 null）。
 */

final class DoubaoSearchCustomClient
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiVisibilityHttpClientFactory $httpClientFactory,
        private readonly AiVisibilityResultNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function search(AiSourceProvider $provider, string $query, array $options = []): AiVisibilityResult
    {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('搜索查询词为空');
        }

        $endpoint = $this->endpoint($provider);
        $apiKey = $this->apiKey($provider);
        $isDeepSeek = $this->isDeepSeek($provider, $endpoint);

        $payload = $isDeepSeek
            ? $this->buildDeepSeekPayload($query, $options)
            : $this->buildPayload($query, $options);

        $startedAt = hrtime(true);
        $response = $this->httpClientFactory
            ->jsonRequest($apiKey)
            ->post($endpoint, $payload);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                '搜索请求失败：HTTP %d %s',
                $response->status(),
                mb_substr(trim($response->body()), 0, 300, 'UTF-8')
            ));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('搜索接口返回了非 JSON 结构');
        }

        // 关键：把 DeepSeek 结果翻译成豆包结构，后续全部走原生逻辑
        if ($isDeepSeek) {
            $json = $this->deepSeekToDoubaoShape($json);
        }

        return $this->normalizer->normalizeDoubaoSearchCustom($json, [
            'endpoint' => $endpoint,
            'payload' => $payload,
            'adapter' => $isDeepSeek ? 'deepseek_search' : 'doubao_search_custom',
        ], $latencyMs);
    }

    /** 端点或 provider_key 里含 deepseek 就走 DeepSeek 协议 */
    private function isDeepSeek(AiSourceProvider $provider, string $endpoint): bool
    {
        $haystack = mb_strtolower((string) ($provider->provider_key ?? '').' '.$endpoint, 'UTF-8');

        return str_contains($haystack, 'deepseek');
    }

    /**
     * DeepSeek 自研搜索的请求体。
     * 文档只要求 query；count 用来控制返回条数（实测 1~20 有效）。
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildDeepSeekPayload(string $query, array $options): array
    {
        $count = max(1, min(20, (int) ($options['count'] ?? config('geoflow.ai_visibility.default_search_count', 10))));

        return [
            'query' => $query,
            'count' => $count,
        ];
    }

    /**
     * 把 DeepSeek 的 {results:[...]} 翻译成豆包的 {Result:{WebResults:[...]}}。
     * 只做字段改名与类型归一，不做任何裁剪——原始内容全保留，便于日后复核。
     *
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    private function deepSeekToDoubaoShape(array $json): array
    {
        $items = [];

        foreach (array_values((array) ($json['results'] ?? [])) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = (string) ($item['url'] ?? '');
            $authority = $item['authority'] ?? null;

            $items[] = [
                'Url' => $url,
                'Title' => (string) ($item['title'] ?? ''),
                'SiteName' => (string) ($item['site_name'] ?? ''),
                'Snippet' => (string) ($item['snippet'] ?? ''),
                'Summary' => (string) ($item['summary'] ?? ''),
                'Content' => (string) ($item['content'] ?? ''),
                'PublishTime' => (string) ($item['date'] ?? ''),
                'RankScore' => $item['rerank_score'] ?? null,
                // -1 = 权威度未知，转空串让 normalizer 记 null，避免污染统计
                'AuthInfoLevel' => (is_numeric($authority) && (int) $authority >= 0) ? (string) (int) $authority : '',
                'SourceType' => 'deepseek_search',
                'Host' => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
            ];
        }

        return [
            'Result' => [
                'WebResults' => $items,
                'LogId' => (string) ($json['log_id'] ?? ''),
                'TimeCost' => $json['time_cost'] ?? null,
                'SearchContext' => null,
            ],
        ];
    }

    /**
     * 豆包 Search Custom 原始请求体（保持与原实现一致，未改动）
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildPayload(string $query, array $options): array
    {
        $filter = array_filter([
            'NeedContent' => $options['need_content'] ?? true,
            'NeedUrl' => $options['need_url'] ?? true,
            'Sites' => $options['sites'] ?? null,
            'BlockHosts' => $options['block_hosts'] ?? null,
            'TimeRange' => $options['time_range'] ?? null,
            'ContentFormats' => $options['content_formats'] ?? 'Markdown',
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return array_filter([
            'Query' => $query,
            'SearchType' => $options['search_type'] ?? 'web',
            'Count' => max(1, min(20, (int) ($options['count'] ?? config('geoflow.ai_visibility.default_search_count', 10)))),
            'NeedSummary' => $options['need_summary'] ?? true,
            'AuthInfoLevel' => $options['auth_info_level'] ?? null,
            'Filter' => $filter,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function endpoint(AiSourceProvider $provider): string
    {
        $endpoint = trim((string) ($provider->endpoint_url ?? ''));
        if ($endpoint === '') {
            $endpoint = trim((string) config('geoflow.ai_visibility.doubao_search_endpoint', ''));
        }

        if ($endpoint === '') {
            throw new RuntimeException('搜索源 Endpoint 为空');
        }

        return $endpoint;
    }

    private function apiKey(AiSourceProvider $provider): string
    {
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($provider->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('搜索源 API Key 为空');
        }

        return $apiKey;
    }
}
