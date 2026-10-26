<?php

namespace App\Support\GeoAudit;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;

/**
 * GEO 执行方案生成器
 *
 * 参考 GEOrankHub /plans 的输入结构复刻：
 *   ① 目标与业务背景（目标/品牌/官网/行业/受众）
 *   ② 执行条件（阶段/周期/资源/市场）
 *   ③ 本次重点（多选）
 *   ④ 边界与补充（竞品/限制/背景）
 *
 * 与诊断器的分工：诊断器给「事实」，本类给「计划」。
 * 若填了官网 URL，会先跑一遍规则诊断，把真实分数作为方案依据（避免空谈）。
 */
final class GeoPlanGenerator
{
    private const MAX_TOKENS = 5200;

    private const TIMEOUT = 120;

    public const STAGES = [
        'new_site' => '新站 / 新品牌',
        'has_seo' => '已有 SEO 内容',
        'has_audit' => '已有诊断报告',
        'has_team' => '已有内容团队',
        'low_visibility' => 'AI 答案曝光不足',
    ];

    public const TIMELINES = [
        '30' => '30 天快速启动',
        '60' => '60 天建立基础能力',
        '90' => '90 天完整推进',
        '180' => '6 个月长期增长',
    ];

    public const RESOURCES = [
        'solo' => '1–2 人兼职推进',
        'team' => '市场 + 内容团队',
        'tech' => '有技术支持',
        'vendor' => '外部服务商协同',
    ];

    public const FOCUS = [
        'ai_recommendation' => 'AI 答案推荐率',
        'page_cited' => '官网页面被引用',
        'content_structure' => '内容结构优化',
        'schema' => 'Schema / 技术标记',
        'keywords_faq' => '关键词和问答长尾',
        'conversion' => '获客转化路径',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function generate(array $input): array
    {
        $started = microtime(true);

        $brand = trim((string) ($input['brand'] ?? ''));
        $goal = trim((string) ($input['goal'] ?? ''));
        $url = trim((string) ($input['url'] ?? ''));
        if ($brand === '' && $goal === '') {
            throw new RuntimeException('请至少填写「品牌/产品名称」或「本次目标」');
        }

        $focus = array_values(array_filter((array) ($input['focus'] ?? []), static fn ($f): bool => is_string($f) && isset(self::FOCUS[$f])));
        $timeline = (string) ($input['timeline'] ?? '90');
        $timelineDays = isset(self::TIMELINES[$timeline]) ? (int) $timeline : 90;

        // 有 URL 就先跑规则诊断，拿真实事实当依据
        $audit = null;
        if ($url !== '') {
            $candidate = (new GeoAuditor())->run($url);
            if (($candidate['ok'] ?? false) === true) {
                $audit = $candidate;
            }
        }

        $model = $this->resolveModel();
        $apiKey = app(ApiKeyCrypto::class)->decrypt((string) $model->getRawOriginal('api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('模型密钥不可用');
        }

        $payload = [
            'model' => (string) $model->model_id,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->userPrompt($input, $audit, $focus, $timelineDays)],
            ],
            'temperature' => 0.35,
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
        if (! is_array($json)) {
            throw new RuntimeException('模型返回无法解析（HTTP '.$status.'）');
        }
        if (isset($json['error']['message'])) {
            throw new RuntimeException('模型返回错误：'.mb_substr((string) $json['error']['message'], 0, 160));
        }
        $content = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            $content = trim((string) ($json['choices'][0]['message']['reasoning_content'] ?? ''));
        }
        if ($content === '') {
            throw new RuntimeException('模型没有产出方案内容，请重试');
        }

        // 从正文末尾的 ```json 块解析任务清单（解析失败不影响主流程）
        $tasks = $this->extractTasks($content);
        // 解析完把这段结构化数据从正文里剥掉——它是喂系统的，不该出现在给用户看的方案里
        $content = $this->stripTaskBlock($content);

        return [
            'ok' => true,
            'markdown' => $content,
            'tasks' => $tasks,
            'brand' => $brand,
            'goal' => $goal,
            'url' => $url,
            'timeline' => $timelineDays,
            'focus' => array_map(static fn (string $k): string => self::FOCUS[$k], $focus),
            'model' => (string) $model->model_id,
            'model_name' => (string) $model->name,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'usage' => [
                'prompt_tokens' => (int) ($json['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($json['usage']['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($json['usage']['total_tokens'] ?? 0),
            ],
            'audit_total' => $audit !== null ? (int) ($audit['total'] ?? 0) : null,
            'audit_grade' => $audit !== null ? (string) ($audit['grade'] ?? '') : null,
            'audit_evidence' => $audit !== null ? $this->auditSummary($audit) : [],
        ];
    }

    /** 从正文里删掉任务清单的代码块，避免用户在方案里看到一坨 JSON */
    private function stripTaskBlock(string $markdown): string
    {
        $clean = preg_replace('/\n?```(?:json|JSON)?\s*\{[^`]*?"tasks"\s*:[^`]*?\}\s*```/su', '', $markdown);
        if (! is_string($clean)) {
            $clean = $markdown;
        }
        // 兜底：万一没有代码围栏，直接截掉以 {"tasks" 开头到文末的部分
        $pos = strpos($clean, '{"tasks"');
        if ($pos !== false) {
            $clean = substr($clean, 0, $pos);
        }

        return rtrim($clean);
    }

    /**
     * 从模型输出里取任务清单。
     * 策略：①整体解析 ```json 块；②解析失败（常见于输出被截断）时逐个对象抢救。
     *
     * @return list<array{name:string,priority:string,owner:string,estimate_hours:int,depends_on:string}>
     */
    private function extractTasks(string $markdown): array
    {
        // ① 优先整体解析代码块（从后往前找，避免正文里的示例块干扰）
        if (preg_match_all('/```(?:json|JSON)?\s*(.+?)\s*```/su', $markdown, $m)) {
            foreach (array_reverse($m[1]) as $raw) {
                $decoded = json_decode(trim($raw), true);
                if (is_array($decoded) && is_array($decoded['tasks'] ?? null)) {
                    $normalized = $this->normalizeTasks($decoded['tasks']);
                    if ($normalized !== []) {
                        return $normalized;
                    }
                }
            }
        }

        // ② 兜底：逐个 { ... } 对象单独解析，能救回几条算几条
        if (preg_match_all('/\{[^{}]*"name"\s*:\s*"[^"]*"[^{}]*\}/u', $markdown, $mm)) {
            $salvaged = [];
            foreach ($mm[0] as $obj) {
                $decoded = json_decode($obj, true);
                if (is_array($decoded) && isset($decoded['name'])) {
                    $salvaged[] = $decoded;
                }
            }
            if ($salvaged !== []) {
                return $this->normalizeTasks($salvaged);
            }
        }

        return [];
    }

    /** @param list<mixed> $rows @return list<array{name:string,priority:string,owner:string,estimate_hours:int,depends_on:string}> */
    private function normalizeTasks(array $rows): array
    {
        $out = [];
        foreach ($rows as $task) {
            if (! is_array($task)) {
                continue;
            }
            $name = trim((string) ($task['name'] ?? ''));
            if ($name === '' || mb_strlen($name, 'UTF-8') > 80) {
                continue;
            }
            $priority = strtoupper(trim((string) ($task['priority'] ?? 'P1')));
            $out[] = [
                'name' => $name,
                'priority' => in_array($priority, ['P0', 'P1', 'P2'], true) ? $priority : 'P1',
                'owner' => mb_substr(trim((string) ($task['owner'] ?? '内容')), 0, 10, 'UTF-8'),
                'estimate_hours' => max(1, min(200, (int) ($task['estimate_hours'] ?? 8))),
                'depends_on' => mb_substr(trim((string) ($task['depends_on'] ?? '')), 0, 60, 'UTF-8'),
            ];
        }

        return array_slice($out, 0, 8);
    }

    private function resolveModel(): AiModel
    {
        return GeoModelPicker::pick();
    }

    private function systemPrompt(): string
    {
        return '你是资深 GEO（生成式引擎优化）顾问，为公司内部团队写可执行方案。'
            ."\n".'铁律：'
            ."\n".'1. 只能使用用户给出的信息与诊断事实，不得编造排名、流量、竞品数据、客户案例。'
            ."\n".'2. 每条任务必须能落到人头：做什么、产出什么、多久、谁验收。'
            ."\n".'3. 中文 Markdown，结论先行，表格优先，篇幅 1000–1500 字。'
            ."\n".'4. 不解释 GEO 是什么，不写"综上所述"这类空话。'
            ."\n".'5. 资源不足时明确说"这个阶段不做"，而不是排一个做不到的计划。'
            ."\n".'6. 正文写完后，必须再输出一个 ```json 代码块，内容是可直接落库执行的任务清单，格式：{"tasks":[{"name":"任务名（≤40字，动词开头）","priority":"P0|P1|P2","owner":"技术|内容|市场|设计","estimate_hours":8,"depends_on":"前置任务名或空"}]}。任务数量 5–8 条，必须是本方案里真实提到的动作，不要编新任务。';
    }

    /** @param array<string,mixed> $audit @param list<string> $focus */
    private function userPrompt(array $input, ?array $audit, array $focus, int $timelineDays): string
    {
        $lines = [
            '【业务背景】',
            '目标：'.($input['goal'] ?? '（未填）'),
            '品牌/产品：'.($input['brand'] ?? '（未填）'),
            '官网：'.($input['url'] ?? '（未填）'),
            '行业：'.($input['industry'] ?? '（未填）'),
            '目标受众：'.($input['audience'] ?? '（未填）'),
            '',
            '【执行条件】',
            '当前阶段：'.(self::STAGES[(string) ($input['stage'] ?? '')] ?? '（未选择）'),
            '执行周期：'.$timelineDays.' 天',
            '团队资源：'.(self::RESOURCES[(string) ($input['resources'] ?? '')] ?? '（未选择）'),
            '主要市场/语言：'.($input['market'] ?? '（未填）'),
            '',
            '【本次重点】'.($focus !== [] ? implode('、', $focus) : '（未选择，按诊断缺口自动判断）'),
            '',
            '【边界与补充】',
            '竞品/对标：'.($input['competitors'] ?? '（未填）'),
            '限制条件：'.($input['constraints'] ?? '（未填）'),
            '补充背景：'.($input['context'] ?? '（未填）'),
        ];

        if ($audit !== null) {
            $lines[] = '';
            $lines[] = '【官网规则诊断事实（刚抓取，真实数据）】';
            $lines[] = 'GEO 总分：'.$audit['total'].'/100（'.$audit['grade'].'）';
            $lines[] = '六维：'.implode('；', array_map(
                static fn ($k, $v): string => $k.'='.$v,
                array_keys($audit['dimension_scores'] ?? []),
                array_values($audit['dimension_scores'] ?? [])
            ));
            $lines[] = '关键缺口：'.$this->auditSummaryText($audit);
            $lines[] = '已检测 Schema：'.(($audit['schema_found'] ?? []) !== [] ? implode('、', $audit['schema_found']) : '无');
        } else {
            $lines[] = '';
            $lines[] = '【官网诊断事实】未提供可抓取的官网，请只按给定背景规划，不要假设分数。';
        }

        $lines[] = '';
        $lines[] = '【输出结构要求】';
        $lines[] = '1. 一句话判断：以现状看，这个周期内能达成什么、不能达成什么。';
        $lines[] = '2. 现状与差距（若有诊断数据，引用具体分值作证据；没有就基于背景判断并注明"待验证"）。';
        $lines[] = '3. 目标与指标：给出 3 个可量化指标（起点值/目标值/统计口径）。';
        $lines[] = '4. 三阶段计划表：阶段｜时间窗｜核心动作｜交付物｜验收指标｜负责人角色。按 '.$timelineDays.' 天拆分。';
        $lines[] = '5. 任务拆解表：任务｜优先级 P0/P1/P2｜预估工时｜依赖｜验收方式。控制在 8–12 条。';
        $lines[] = '6. 复测方法：多久复测、用什么工具、看哪几个数。';
        $lines[] = '7. 明确写出「本阶段不做什么」及原因。';

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function auditSummary(array $audit): array
    {
        $out = [];
        foreach (($audit['modules'] ?? []) as $m) {
            if ((int) $m['score'] < 70) {
                $out[] = $m['chapter'].' / '.$m['name'].'（'.$m['score'].' 分）';
            }
        }

        return array_slice($out, 0, 8);
    }

    private function auditSummaryText(array $audit): string
    {
        $items = $this->auditSummary($audit);

        return $items !== [] ? implode('；', $items) : '各项基本达标，机会在长尾与深度';
    }
}
