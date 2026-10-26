<?php

namespace App\Services\Outbound;

use App\Contracts\Outbound\HostResolver;
use Closure;

final class SystemHostResolver implements HostResolver
{
    /** @var Closure(string): array<int, array<string, mixed>> */
    private readonly Closure $lookup;

    /**
     * @param  (Closure(string): array<int, array<string, mixed>>)|null  $lookup
     * @param  (Closure(string, int): array<int, array<string, mixed>>)|null  $dnsLookup
     */
    public function __construct(?Closure $lookup = null, ?Closure $dnsLookup = null)
    {
        if ($lookup instanceof Closure) {
            $this->lookup = $lookup;

            return;
        }

        $dnsLookup ??= static fn (string $host, int $type): array => @dns_get_record($host, $type) ?: [];
        $this->lookup = static function (string $host) use ($dnsLookup): array {
            // 【本地修复】先查 /etc/hosts。
            // 原因：dns_get_record() 只做真实 DNS 查询，**不读 hosts 文件**，
            // 而 Docker 的 extra_hosts（api.deepseek.com → 固定 IP）写在 hosts 里。
            // 于是固定 IP 形同虚设，国内 DNS 对 api.deepseek.com 间歇性解析失败时，
            // 出站校验会抛 OutboundRequestFailedException → 被判不可重试 → 前端报"模型调用失败"。
            // 这里把 hosts 命中的 IP 作为首选，DNS 结果并到后面作为后备。
            $hostsIps = self::ipsFromHostsFile($host);
            if ($hostsIps !== []) {
                $fromHosts = array_map(static function (string $ip): array {
                    $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

                    return [
                        'type' => $isV6 ? 'AAAA' : 'A',
                        'ip' => $ip,
                        'ipv6' => $isV6 ? $ip : null,
                    ];
                }, $hostsIps);

                return array_merge($fromHosts, [
                    ...$dnsLookup($host, DNS_A),
                    ...$dnsLookup($host, DNS_AAAA),
                ]);
            }

            $records = [
                ...$dnsLookup($host, DNS_A),
                ...$dnsLookup($host, DNS_AAAA),
            ];

            foreach ($records as $record) {
                if (in_array(strtoupper((string) ($record['type'] ?? '')), ['A', 'AAAA'], true)) {
                    return $records;
                }
            }

            return [...$records, ...$dnsLookup($host, DNS_CNAME)];
        };
    }

    public function resolve(string $host): array
    {
        return $this->resolveHost(strtolower(rtrim($host, '.')), [], 0);
    }

    /**
     * @param  array<string, true>  $visited
     * @return list<string>
     */
    private function resolveHost(string $host, array $visited, int $depth): array
    {
        if ($depth > 8 || isset($visited[$host])) {
            return [];
        }

        $visited[$host] = true;
        $addresses = [];
        $aliases = [];
        foreach (($this->lookup)($host) as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($type === 'A' && filter_var($record['ip'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $addresses[] = (string) $record['ip'];
            } elseif ($type === 'AAAA' && filter_var($record['ipv6'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $addresses[] = strtolower((string) $record['ipv6']);
            } elseif ($type === 'CNAME') {
                $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
                if ($target !== '') {
                    $aliases[] = $target;
                }
            }
        }

        // 系统解析器通常会在 CNAME 记录旁返回最终 A/AAAA 地址，直接使用可避免重复 DNS 查询。
        if ($addresses !== []) {
            return array_values(array_unique($addresses));
        }

        foreach (array_unique($aliases) as $target) {
            $addresses = [...$addresses, ...$this->resolveHost($target, $visited, $depth + 1)];
        }

        return array_values(array_unique($addresses));
    }

    /**
     * 【本地新增】从 /etc/hosts 读取某个域名的 IPv4/IPv6 地址。
     * 只做字面匹配，不递归 CNAME（hosts 不支持别名链）。
     *
     * @return list<string>
     */
    private static function ipsFromHostsFile(string $host): array
    {
        $path = '/etc/hosts';
        if (! is_readable($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $ips = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 2) {
                continue;
            }
            $ip = array_shift($parts);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            foreach ($parts as $name) {
                if (strtolower(rtrim($name, '.')) === $host) {
                    $ips[] = $ip;
                    break;
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
