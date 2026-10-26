<?php

namespace App\Support\GeoAudit;

use App\Models\AdminAiSetting;
use App\Models\AiModel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * 统一选模器：所有本地扩展的 AI 调用都从这里拿模型。
 *
 * 解决三个问题：
 *   ① 之前 4 个文件各写了一遍「system_only 优先 + id 排序」，改规则要改四处；
 *   ② 没法统一控制"这次用哪个"——改了也不生效，因为每处逻辑独立；
 *   ③ 一个模型挂了整条链路就断，没有自动切换。
 *
 * 实时切换靠什么：读 admin_ai_settings.default_chat_model_id（GEOFlow 原生就有的
 * 「个人默认 AI 设置」）。每次调用现查库，用户在 AI 配置器页面改完立刻生效，
 * 不需要重启、不需要清缓存。
 *
 * 自动切换靠什么：candidates() 返回一条按优先级排好的候选链，调用方逐个试，
 * 一个失败换下一个。
 */
final class GeoModelPicker
{
    /** @var array<string,array<int,object>> 单次请求内的缓存，避免一次生成打十几次库 */
    private static array $cache = [];

    /**
     * 候选模型链：已按优先级排序，已过滤掉不能用的。
     *
     * 排序规则：
     *   1. 管理员设的「默认模型」永远排第一
     *   2. 其余按 failover_priority 升序（数字小的优先），再按 id
     *
     * @return list<AiModel>
     */
    public static function candidates(string $type = 'chat'): array
    {
        $cacheKey = $type.'|'.self::actorId();
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $models = AiModel::query()
                ->where('model_type', $type)
                ->where('status', 'active')
                ->when(Schema::hasColumn('ai_models', 'archived_at'), fn ($q) => $q->whereNull('archived_at'))
                ->orderBy('failover_priority')
                // failover_priority 相同时，系统级模型优先（原有默认行为，别让升级后行为突变）
                ->orderByRaw("CASE WHEN access_scope = 'system_only' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->get()
                ->filter(static fn (AiModel $m): bool => self::usable($m))
                ->values()
                ->all();
        } catch (\Throwable) {
            return self::$cache[$cacheKey] = [];
        }

        // 管理员默认模型提到最前，其余保持原顺序
        $pref = self::preferredId($type);
        if ($pref > 0) {
            $head = [];
            $tail = [];
            foreach ($models as $m) {
                if ((int) $m->getKey() === $pref) {
                    $head[] = $m;
                } else {
                    $tail[] = $m;
                }
            }
            $models = array_merge($head, $tail);
        }

        return self::$cache[$cacheKey] = $models;
    }

    /**
     * 取第一个可用模型。没有可用的就抛异常（调用方会显示成"后台没有可用模型"）。
     */
    public static function pick(string $type = 'chat'): AiModel
    {
        $list = self::candidates($type);

        if ($list === []) {
            throw new \RuntimeException(
                $type === 'embedding' ? '后台没有可用的向量模型' : '后台没有可用的聊天模型'
            );
        }

        return $list[0];
    }

    /**
     * 当前管理员设的默认模型 ID；没设或设置已失效返回 0。
     */
    public static function preferredId(string $type = 'chat'): int
    {
        $adminId = self::actorId();
        if ($adminId <= 0) {
            return 0;
        }

        try {
            if (! Schema::hasTable('admin_ai_settings')) {
                return 0;
            }
            $row = AdminAiSetting::query()->where('admin_id', $adminId)->first();
            if ($row === null) {
                return 0;
            }
            $col = $type === 'embedding' ? 'default_embedding_model_id' : 'default_chat_model_id';

            return (int) ($row->{$col} ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 给页面显示用：当前用哪个、后面还有哪些备选。
     *
     * @return array{current:?array<string,mixed>,chain:list<array<string,mixed>>,preferred:int}
     */
    public static function describe(string $type = 'chat'): array
    {
        $list = self::candidates($type);
        $chain = [];
        foreach ($list as $i => $m) {
            $chain[] = [
                'id' => (int) $m->getKey(),
                'name' => (string) $m->name,
                'model_id' => (string) $m->model_id,
                'scope' => (string) ($m->access_scope ?? ''),
                'priority' => (int) ($m->failover_priority ?? 100),
                'order' => $i + 1,
                'is_preferred' => (int) $m->getKey() === self::preferredId($type),
            ];
        }

        return [
            'current' => $chain[0] ?? null,
            'chain' => $chain,
            'preferred' => self::preferredId($type),
        ];
    }

    /** 清掉单次请求内的缓存（同一请求里改了设置后需要重新算） */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * 这个模型现在能不能用。
     *
     * 额度判断：daily_limit 为 0 表示不限；用了多少看 used_today 与 usage_date，
     * 跨天了要当作 0（GEOFlow 自己也是这么重置的，这里跟着同口径）。
     */
    private static function usable(AiModel $m): bool
    {
        $limit = (int) ($m->daily_limit ?? 0);
        if ($limit <= 0) {
            return true;
        }

        $used = (int) ($m->used_today ?? 0);
        $date = $m->usage_date;
        if ($date !== null && (string) $date !== '' && substr((string) $date, 0, 10) !== now()->toDateString()) {
            $used = 0;
        }

        return $used < $limit;
    }

    private static function actorId(): int
    {
        try {
            $id = Auth::id();

            return $id !== null ? (int) $id : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
