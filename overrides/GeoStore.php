<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GEO 扩展产出物仓库（落库 / 列表 / 详情 / 对比）
 *
 * 表：geo_ai_outputs
 *   kind = audit（页面诊断快照） | advise（AI 解读） | plan（执行方案）
 * 设计取舍：不走 migration，首次使用时自建表，避免影响镜像升级。
 */
final class GeoStore
{
    private const TABLE = 'geo_ai_outputs';

    /** 记录上限：只保留最近 10 条 */
    public const KEEP = 10;

    public function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /** @param array<string,mixed> $meta */
    public function save(string $kind, ?string $subType, ?string $url, ?string $title, string $content, array $meta = [], ?int $adminId = null): ?int
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $id = (int) DB::table(self::TABLE)->insertGetId([
                'kind' => $kind,
                'sub_type' => $subType,
                'admin_id' => $adminId,
                'url' => mb_substr((string) $url, 0, 1000),
                'title' => mb_substr((string) $title, 0, 300),
                'content' => $content,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
            $this->trim(self::KEEP);

            return $id;
        } catch (\Throwable) {
            return null;
        }
    }

    /** 只保留最新 N 条，多余的直接清掉（避免无限膨胀） */
    public function trim(int $keep = self::KEEP): void
    {
        if (! $this->available()) {
            return;
        }
        try {
            $cutoff = DB::table(self::TABLE)->orderByDesc('id')->skip($keep)->value('id');
            if ($cutoff !== null) {
                DB::table(self::TABLE)->where('id', '<=', (int) $cutoff)->delete();
            }
        } catch (\Throwable) {
            // 静默：清理失败不影响主流程
        }
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    public function recent(int $limit = 20, ?string $kind = null, ?string $url = null)
    {
        if (! $this->available()) {
            return collect();
        }
        $q = DB::table(self::TABLE)->orderByDesc('id')->limit($limit);
        if ($kind !== null && $kind !== '') {
            $q->where('kind', $kind);
        }
        if ($url !== null && $url !== '') {
            $q->where('url', $url);
        }

        return $q->get(['id', 'kind', 'sub_type', 'url', 'title', 'meta', 'created_at']);
    }

    public function find(int $id): ?object
    {
        if (! $this->available()) {
            return null;
        }

        return DB::table(self::TABLE)->where('id', $id)->first();
    }

    /**
     * 取同一 URL 的上一份诊断快照（用于改前/改后对比）。
     *
     * @return object|null
     */
    public function previousAudit(string $url, ?int $excludeId = null): ?object
    {
        if (! $this->available() || $url === '') {
            return null;
        }
        $q = DB::table(self::TABLE)->where('kind', 'audit')->where('url', $url)->orderByDesc('id');
        if ($excludeId !== null) {
            $q->where('id', '<', $excludeId);
        }

        return $q->first(['id', 'meta', 'created_at']);
    }

    /**
     * 取最近一条同 kind/sub_type/url 的完整内容。
     *
     * 用途：点「下载 Markdown」时优先用这份，而不是为了下载再跑一次模型。
     * 之前下载链接每次都会重走「抓页面 → 诊断 → 调模型」，用户点完等 20 秒
     * 以为按钮坏了，其实是在默默重新生成。
     */
    public function latestContent(string $kind, ?string $subType = null, ?string $url = null): ?object
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $q = DB::table(self::TABLE)->where('kind', $kind)->orderByDesc('id');
            if ($subType !== null && $subType !== '') {
                $q->where('sub_type', $subType);
            }
            if ($url !== null && $url !== '') {
                $q->where('url', $url);
            }

            return $q->first(['id', 'content', 'title', 'created_at']);
        } catch (\Throwable) {
            return null;
        }
    }

    public function delete(int $id): bool
    {
        if (! $this->available()) {
            return false;
        }

        return DB::table(self::TABLE)->where('id', $id)->delete() > 0;
    }
}
