<?php

namespace App\Support\GeoAudit;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;

/**
 * GEO 扩展共用的模型调用小工具。
 *
 * 为什么不直接用 GEOFlow 内部的 AI 调用链：
 *   官方链路绑定了 execution context / 用量审计 / 权限解析，适合"生产任务"；
 *   这些扩展工具是"诊断与测量"，需要轻量、可控超时、可指定 temperature。
 *   两条链路都读 `ai_models` 表，密钥都用 ApiKeyCrypto 解密，口径一致。
 */
final class GeoChat
{
    /**
     * 取一台可用的聊天模型。
     * 优先 system_only（平台自带、额度稳定），否则退回任意 active chat 模型。
     */
    public static function resolveModel(): AiModel
    {
        // 统一走 GeoModelPicker：它会读管理员的「个人默认 AI 设置」，
        // 没设才按 failover_priority 排。改设置即时生效，不用重启。
        return GeoModelPicker::pick();
    }

    /**
     * 列出可选的聊天模型（给页面做下拉用）。
     * 只列 active 的 chat 模型；embedding 之类不参与文本生成。
     *
     * @return list<array{id:int,name:string,model_id:string,scope:string}>
     */
    public static function chatModels(): array
    {
        try {
            return AiModel::query()
                ->where('model_type', 'chat')
                ->where('status', 'active')
                ->orderByRaw("CASE WHEN access_scope = 'system_only' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->get(['id', 'name', 'model_id', 'access_scope'])
                ->map(static fn ($m): array => [
                    'id' => (int) $m->id,
                    'name' => (string) $m->name,
                    'model_id' => (string) $m->model_id,
                    'scope' => (string) $m->access_scope,
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 按 ID 取模型；找不到就回退到默认可用模型。
     */
    private static function modelById(?int $modelId): AiModel
    {
        if ($modelId !== null && $modelId > 0) {
            $model = AiModel::query()
                ->whereKey($modelId)
                ->where('model_type', 'chat')
                ->where('status', 'active')
                ->first();
            if ($model instanceof AiModel) {
                return $model;
            }
        }

        return GeoModelPicker::pick();
    }

    /**
     * 单轮补全（带自动换模）。
     *
     * 选模顺序：管理员设的默认模型 → failover_priority 小的 → 其余。
     * 前一个失败（超时、限流、密钥失效）会自动换下一个，只有全部失败才抛异常。
     *
     * @param  int|null  $modelId  指定模型；null = 走统一选模器
     * @param  bool  $failover  指定模型失败后要不要继续试别的。默认要——
     *                          不然一个模型抽风整个功能就断了。想严格只测某一个就传 false。
     * @return array{content:string,model:string,prompt_tokens:int,completion_tokens:int,total_tokens:int,elapsed_ms:int}
     */
    public static function complete(
        string $system,
        string $user,
        int $maxTokens = 900,
        float $temperature = 0.3,
        int $timeout = 60,
        ?int $modelId = null,
        bool $failover = true,
        ?bool $thinking = false,
        int $budget = 150,
    ): array {
        $chain = self::chainFor($modelId, $failover);
        if ($chain === []) {
            throw new RuntimeException('后台没有可用的聊天模型');
        }

        // 整条链共用一份时间预算。
        // 不加这个，一篇文章最坏会变成「豆包 21s 失败 + DeepSeek 45s + DeepSeek 45s = 111s」，
        // 再叠上渲染，就顶到网关超时、前端收到 502（实测过一次 155 秒 502）。
        $chainStarted = microtime(true);
        $errors = [];

        foreach ($chain as $model) {
            $elapsed = microtime(true) - $chainStarted;
            $remaining = $budget - $elapsed;

            // 剩不到 25 秒就别开新模型了，开了也大概率来不及
            if ($remaining < 25) {
                @error_log(sprintf('[GeoChat] 时间预算用尽（已用 %.1fs / 预算 %ds），跳过剩余 %d 个模型',
                    $elapsed, $budget, count($chain) - count($errors)));

                break;
            }

            $modelTimeout = (int) min($timeout, $remaining);
            $t0 = microtime(true);
            try {
                $res = self::attempt($model, $system, $user, $maxTokens, $temperature, $modelTimeout, $thinking);
                // 记录实际用了哪个模型、花了多久。排查"网页端超时但命令行正常"这类问题时，
                // 光看结果没用，必须知道链上每个模型各花了多少时间。
                @error_log(sprintf(
                    '[GeoChat] OK model=%s#%s model_id=%s took=%.1fs think=%s',
                    (string) $model->name, (string) $model->getKey(), (string) $model->model_id,
                    microtime(true) - $t0, $thinking ? 'on' : 'off'
                ));

                return $res;
            } catch (\Throwable $e) {
                @error_log(sprintf(
                    '[GeoChat] FAIL model=%s#%s took=%.1fs err=%s',
                    (string) $model->name, (string) $model->getKey(),
                    microtime(true) - $t0, mb_substr($e->getMessage(), 0, 120, 'UTF-8')
                ));
                $errors[] = (string) $model->name.'（'.$model->model_id.'）：'
                    .mb_substr($e->getMessage(), 0, 90, 'UTF-8');
            }
        }

        throw new RuntimeException('所有模型都调用失败：'.implode('；', array_slice($errors, 0, 3)));
    }

    /**
     * 候选链。
     *
     * 指定了模型时：它排第一；$failover 为 true 才在它后面接上其余候选。
     * 没指定时：直接用统一选模器的排序。
     *
     * @return list<AiModel>
     */
    private static function chainFor(?int $modelId, bool $failover): array
    {
        if ($modelId !== null && $modelId > 0) {
            $first = self::modelById($modelId);
            if (! $failover) {
                return [$first];
            }
            $rest = [];
            foreach (GeoModelPicker::candidates() as $m) {
                if ((int) $m->getKey() !== (int) $first->getKey()) {
                    $rest[] = $m;
                }
            }

            return array_merge([$first], $rest);
        }

        return GeoModelPicker::candidates();
    }

    /**
     * 用指定模型发一次请求。
     *
     * @return array{content:string,model:string,prompt_tokens:int,completion_tokens:int,total_tokens:int,elapsed_ms:int}
     */
    private static function attempt(
        AiModel $model,
        string $system,
        string $user,
        int $maxTokens,
        float $temperature,
        int $timeout,
        ?bool $thinking = false,
    ): array {
        $started = microtime(true);

        // getRawOriginal：拿到数据库里的密文，再解密；直接读属性会被解密器二次处理
        $apiKey = app(ApiKeyCrypto::class)->decrypt((string) $model->getRawOriginal('api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('模型密钥不可用');
        }

        $payload = [
            'model' => (string) $model->model_id,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'stream' => false,
        ];

        // 火山方舟（豆包）的 Seed 系列默认开启「深度思考」，思考产生的 reasoning tokens
        // 一样计费，而且慢得多。实测同一篇 400 字：开思考 69 秒 / 2865 tokens，
        // 关思考 14 秒 / 428 tokens —— 快 5 倍、省 85%。
        // 内容生成不需要思考过程，所以对豆包默认关掉。
        // （需要推理质量的任务，比如判断"AI 说的对不对"，可以传 $thinking = true 打开）
        if ($thinking !== null) {
            if (str_contains(strtolower((string) $model->api_url), 'volces.com')) {
                $payload['thinking'] = ['type' => $thinking ? 'enabled' : 'disabled'];
            }
        }

        $ch = curl_init(rtrim((string) $model->api_url, '/').'/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer '.$apiKey],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
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
            $msg = (string) ($json['error']['message'] ?? ('HTTP '.$status));

            throw new RuntimeException('模型返回异常：'.mb_substr($msg, 0, 120, 'UTF-8'));
        }

        $content = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('模型没有产出内容');
        }

        return [
            'content' => $content,
            'model' => (string) $model->model_id,
            'prompt_tokens' => (int) ($json['usage']['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($json['usage']['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($json['usage']['total_tokens'] ?? 0),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
