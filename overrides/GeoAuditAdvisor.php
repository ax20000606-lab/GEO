<?php

namespace App\Support\GeoAudit;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;

/**
 * 官网诊断结果的 AI 解读器
 *
 * 职责分工（重要）：
 *   - GeoAuditor（规则引擎）负责「事实」：有没有 canonical、几个 H1、有没有 JSON-LD —— 可复现、零成本
 *   - 本类（AI 层）负责「判断与建议」：这些事实意味着什么、先改哪个、怎么改
 *
 * 调用的是 GEOFlow 后台已配置的聊天模型（默认 DeepSeek），复用其密钥与端点。
 */
final class GeoAuditAdvisor
{
    private const TYPES = ['explain', 'content', 'answers', 'plan'];

    private const MAX_TOKENS = 3200;

    private const TIMEOUT = 90;

    /** @param array<string,mixed> $audit */
    public function advise(array $audit, string $type = 'explain', ?int $modelId = null): array
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'explain';
        $started = microtime(true);

        $model = $this->resolveModel($modelId);
        $apiKey = app(ApiKeyCrypto::class)->decrypt((string) $model->getRawOriginal('api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('模型密钥不可用');
        }

        $payload = [
            'model' => (string) $model->model_id,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->userPrompt($audit, $type)],
            ],
            'temperature' => 0.3,
            'max_tokens' => self::MAX_TOKENS,
            'stream' => false,
        ];

        $url = rtrim((string) $model->api_url, '/').'/chat/completions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$apiKey,
            ],
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
        if (! is_array($json)) {
            throw new RuntimeException('模型返回无法解析（HTTP '.$status.'）');
        }
        if (isset($json['error']['message'])) {
            throw new RuntimeException('模型返回错误：'.mb_substr((string) $json['error']['message'], 0, 160));
        }

        $choice = $json['choices'][0]['message'] ?? [];
        $content = trim((string) ($choice['content'] ?? ''));
        if ($content === '') {
            $content = trim((string) ($choice['reasoning_content'] ?? ''));
        }
        if ($content === '') {
            throw new RuntimeException('模型没有产出内容，请稍后重试');
        }

        return [
            'ok' => true,
            'type' => $type,
            'type_label' => $this->typeLabel($type),
            'markdown' => $content,
            'model' => (string) $model->model_id,
            'model_name' => (string) $model->name,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'usage' => [
                'prompt_tokens' => (int) ($json['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($json['usage']['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($json['usage']['total_tokens'] ?? 0),
            ],
            'url' => (string) ($audit['final_url'] ?? $audit['url'] ?? ''),
            'total' => (int) ($audit['total'] ?? 0),
            'grade' => (string) ($audit['grade'] ?? ''),
        ];
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'content' => '内容结构优化建议',
            'answers' => '品牌可见性问答',
            'plan' => '行动方案拆解（30/60/90 天）',
            default => '诊断报告解读',
        };
    }

    private function resolveModel(?int $modelId): AiModel
    {
        if ($modelId !== null && $modelId > 0) {
            $model = AiModel::query()->whereKey($modelId)->first();
            if ($model instanceof AiModel) {
                return $model;
            }
        }

        // 统一走 GeoModelPicker：读管理员「个人默认 AI 设置」，改完即时生效
        return GeoModelPicker::pick();
    }

    private function systemPrompt(): string
    {
        return '你是资深 GEO（生成式引擎优化）顾问，服务对象是国内品牌官网的运营与内容团队。'
            ."\n".'写作要求：'
            ."\n".'1. 结论先行，中文 Markdown，多用小标题和表格，不要写"作为AI"之类的话。'
            ."\n".'2. 只能引用用户消息里提供的诊断事实，严禁编造未给出的数据、排名、流量或竞品信息。'
            ."\n".'3. 每条建议都要可执行：说清改哪里、改成什么、改完怎么验证。'
            ."\n".'4. 不解释 GEO 是什么，不说空话套话，不要重复用户已知的内容。'
            ."\n".'5. 篇幅控制在 800–1500 字。';
    }

    /** @param array<string,mixed> $audit */
    private function userPrompt(array $audit, string $type): string
    {
        $modules = [];
        foreach (($audit['modules'] ?? []) as $m) {
            $modules[] = sprintf('%s｜%s｜%d分｜%s', $m['chapter'], $m['name'], $m['score'], $m['evidence']);
        }
        $stats = $audit['stats'] ?? [];
        $dims = [];
        foreach (($audit['dimension_scores'] ?? []) as $k => $v) {
            $dims[] = $k.'='.$v;
        }

        $facts = implode("\n", [
            '目标网址：'.($audit['final_url'] ?? ''),
            'GEO 总分：'.($audit['total'] ?? 0).'/100（'.($audit['grade'] ?? '').'）',
            '六维得分：'.implode('；', $dims),
            '页面统计：正文约 '.($stats['text_len'] ?? 0).' 字；H1×'.($stats['h1'] ?? 0)
                .'；H2×'.($stats['h2'] ?? 0).'；列表 '.($stats['lists'] ?? 0).' 个'
                .'；外链 '.($stats['external_links'] ?? 0).'；内链 '.($stats['internal_links'] ?? 0)
                .'；title '.($stats['title_len'] ?? 0).' 字符；description '.($stats['desc_len'] ?? 0).' 字符',
            '已检测到 Schema：'.(($audit['schema_found'] ?? []) !== [] ? implode('、', $audit['schema_found']) : '无'),
            '建议补充 Schema：'.(($audit['schema_missing'] ?? []) !== [] ? implode('、', $audit['schema_missing']) : '无'),
            '',
            '24 项模块明细（章节｜模块｜得分｜证据）：',
            implode("\n", $modules),
        ]);

        $task = match ($type) {
            'content' => "请输出《内容结构优化建议》（控制在 1200 字以内，不要贴 JSON 代码）：\n"
                ."1) 先指出当前内容结构最影响 AI 抽取的 3 个问题（必须引用模块得分作证据）\n"
                .'2) 首页/栏目页/文章页分别该补什么模块（表格：页面类型｜要补的模块｜为什么｜验收标准）'."\n"
                .'3) FAQ 区块写法示范：3 条问题要贴合该品牌所在品类与页面已有内容，每条给出答案要点（2–3 句）'."\n"
                .'4) JSON-LD 骨架已由规则引擎生成并附在页面下方，你只需说明：每段放在哪里、哪些字段必须换成真实值、上线后怎么验证（不要重复输出 JSON）',
            'answers' => "请输出《品牌可见性问答》：\n"
                .'1) 列出 8–10 条「用户会在 AI 助手里这样问」的真实问题（围绕该品牌所在品类）'."\n"
                .'2) 用表格逐条判断：当前页面能否支撑 AI 给出准确回答（能/部分/不能）+ 缺什么证据'."\n"
                .'3) 指出最容易被 AI 误读或说错的 3 处风险（基于页面结构事实推断，不要编造具体事实）'."\n"
                .'4) 给出补齐这些答案所需的最小内容增量清单',
            'plan' => "请输出《30/60/90 天 GEO 行动方案》：\n"
                .'1) 三个阶段各一段：目标 / 关键动作 / 交付物 / 验收指标（用表格）'."\n"
                .'2) 任务拆解清单：任务名｜优先级(P0/P1/P2)｜负责人角色｜预估工时｜依赖'."\n"
                .'3) 明确「先做什么、为什么先做这个」，用当前分数和模块缺口作依据'."\n"
                .'4) 给出 30 天后复测的具体验证方法',
            default => "请输出《诊断报告解读》：\n"
                .'1) 一段总判断（解决"这个分数意味着什么"）'."\n"
                .'2) 用表格逐维度解读：维度｜分数｜业务含义｜影响什么'."\n"
                .'3) 按「投入产出比」排序，给出最值得先修的 5 件事（每件说清改法）'."\n"
                .'4) 指出哪些扣分项影响有限、可以先不管（避免无效加班）',
        };

        return "以下是规则引擎刚跑出的诊断事实（只信这些，不要补充别的数据）：\n\n```\n".$facts."\n```\n\n".$task;
    }
}
