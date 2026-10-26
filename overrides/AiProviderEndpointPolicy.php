<?php

namespace App\Services\GeoFlow\AiVisibility;

/**
 * 出站端点策略（本地扩展版）
 *
 * 原实现把搜索 API 的可信域名写死成只有 `feedcoopapi.com`（豆包搜索），
 * 所以就算在「搜索源」里填了 DeepSeek 的端点，也会被这道校验直接拒掉，
 * 表现为采集永远回落到"纯 DeepSeek 分析"路径（不是真实采样）。
 *
 * 这里只做一件事：把可信搜索域名提成常量，并加入 `deepseek.com`。
 * 校验逻辑（必须 https、禁止 URL 内嵌账号密码、禁止 IP 直连、
 * 必须精确匹配或子域匹配）完全保留，安全性没有被放宽。
 *
 * 要再接别的搜索厂商（博查、Serper 等），往 SEARCH_HOSTS 里加一行即可。
 */
final class AiProviderEndpointPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private const MODEL_HOSTS = [
        'ark' => ['volces.com'],
        'deepseek' => ['deepseek.com'],
    ];

    /**
     * 可接受的搜索 API 主机。
     * feedcoopapi.com —— 豆包 Search Custom（官方原生支持）
     * deepseek.com    —— DeepSeek 自研搜索 API（2026-10-17 上线，$0.005/次）
     *
     * @var list<string>
     */
    private const SEARCH_HOSTS = [
        'feedcoopapi.com',
        'deepseek.com',
    ];

    public function acceptsModelApi(string $bindingType, string $url): bool
    {
        $trustedHosts = self::MODEL_HOSTS[$bindingType] ?? [];

        return $this->isTrustedHttpsUrl($url, $trustedHosts);
    }

    public function acceptsSearchApi(string $url): bool
    {
        return $this->isTrustedHttpsUrl($url, self::SEARCH_HOSTS);
    }

    public function sameOrigin(string $firstUrl, string $secondUrl): bool
    {
        $first = $this->origin($firstUrl);
        $second = $this->origin($secondUrl);

        return $first !== null && $first === $second;
    }

    /**
     * @param  list<string>  $trustedHosts
     */
    private function isTrustedHttpsUrl(string $url, array $trustedHosts): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return false;
        }

        foreach ($trustedHosts as $trustedHost) {
            if ($host === $trustedHost || str_ends_with($host, '.'.$trustedHost)) {
                return true;
            }
        }

        return false;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($scheme === '' || $host === '') {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $scheme.'://'.$host.':'.$port;
    }
}
