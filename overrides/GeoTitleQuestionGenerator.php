<?php

namespace App\Support\GeoAudit;

/**
 * 标题反推提问 —— LLM 生成器（T03）。
 *
 * 职责边界：
 *   · 本类负责：拼 prompt → 调一次模型 → 校验 JSON → 交判定 → 落库
 *   · 本类不负责：判定（GeoTitleQuestionRules）/ 渲染（GeoTitleQuestionReport）
 *
 * 一次 run = 一次模型调用（最多重试 1 次），整链时间预算 90 秒（见 .env）。
 *
 * 为什么 $llm 允许外部注入：
 *   试跑 prompt 时不想每次都花真钱。/selftest 与「干跑」都用假 LLM，
 *   把真实 HTTP 调用隔离在最后一层。注入点只有一处，不会绕过真实链路。
 */
final class GeoTitleQuestionGenerator
{
    /**
     * prompt 复查的触发条件（满足任一才重开调参，否则不动）。
     *
     * 为什么写在这里而不是写进文档：改 prompt 的人一定会打开这个文件，
     * 但不一定会翻文档。条件是「有生死的」，所以必须放在他眼前。
     *
     * 背景：目前漂移 17%（交付口径），阶段全对。剩下 5 条都是角度级、
     * 且多数是模型比服务端更细。**用 3 个标题、36 条样本去压这 5 条
     * 等于拿 3 个样本拟合 6 维函数** —— 换个标题类型（孕期护肤 / 男士洁面）
     * 会以另一种形式回来。
     *
     * @var list<string>
     */
    public const PROMPT_REVIEW_TRIGGERS = [
        '累计跑过 10 个以上标题、100+ 条问题 → 样本够调了',
        '出现「角度覆盖 < 3」告警，但人工看明明覆盖了 → 漂移已影响业务判断',
        '有用户反馈「这条问题的角度标错了」→ 真实误判比统计更可靠',
    ];

    /** 期望模型给多少条候选（服务端会筛掉一部分，12 条才能筛出 5–8 条） */
    public const MAX_CANDIDATES = 12;

    /** 温度：这是「造句」不是「创意」，规则文档定的 0.2，不要调高 */
    public const TEMPERATURE = 0.2;

    /**
     * max_tokens（输出上限，不是预分配；没用到的部分不收费）。
     *
     * 演进过程，值得留着：
     *   900  → 12 条候选必被截断，JSON 少了右括号，整批 0 条
     *   2600 → 够用一段时间，但把 why 要求升级成「引用原词 + 用户处境 + 承接」
     *          之后，模型每条 why 从 1 句变成 2-3 句，2600 又被截断了（batch 23）
     *   4000 → 当前值。截断的代价不是"多花 token"，是"整批 12 条全废 + 重跑一次"，
     *          所以上限宁大勿小
     */
    public const MAX_TOKENS = 4000;

    /** 整链时间预算（秒）。与 .env 的 GEOFLOW_AI_WORKSPACE_MODEL_TOTAL_TIMEOUT 一致 */
    private const DEFAULT_TOTAL_TIMEOUT = 90;

    /**
     * 单次请求超时（秒）。**显式传 60，不读 .env 的 30。**
     *
     * 为什么不用 .env 的 GEOFLOW_AI_WORKSPACE_MODEL_ATTEMPT_TIMEOUT=30：
     *   DeepSeek 出 12 条候选实测 5–11 秒，但这是"顺利时"。30 秒在边界上 ——
     *   模型慢一点就被 curl 掐断，表现是「prompt_tokens 有、content 空」，
     *   然后重跑再花一次钱。60 秒留够余量，正常情况下完全用不到。
     *
     * 为什么不改 .env 的 30 → 90：
     *   那是全局值，内容工坊 / 质检 / 诊断全都跟着变，而且改完要重启容器。
     *   GeoChat::complete() 本来就收 $timeout 参数，本功能单独传即可。
     */
    private const ATTEMPT_TIMEOUT = 60;

    // =========================================================================
    // 主入口
    // =========================================================================

    /**
     * 跑一次生成。
     *
     * @param  string  $title  标题原文
     * @param  string  $brandSlug  品牌口径（空 = 走 resolveSlug）
     * @param  callable|null  $llm  注入的模型调用器：fn(string $system, string $user): array{content:string,model:string,prompt_tokens:int,completion_tokens:int,elapsed_ms:int}
     * @param  bool  $allowRetry  首轮 0 条有效候选时要不要重试一次
     * @return array<string,mixed> 见 return 处注释
     */
    public static function run(
        string $title,
        string $brandSlug = '',
        ?callable $llm = null,
        bool $allowRetry = true
    ): array {
        $title = trim($title);
        if ($title === '') {
            return self::fail('标题不能为空');
        }

        GeoTitleQuestionStore::ensureSchema();

        $brand = GeoShareOfVoice::brand(GeoShareOfVoice::resolveSlug($brandSlug));
        if ($brand === null) {
            return self::fail('品牌档案不存在，无法推导（矩阵依赖竞品/产品清单）');
        }

        $slots = GeoTitleQuestionSlots::parse($title, $brand);

        // 没有可用格 = 这个标题在这套矩阵下没有射程。
        // 这种情况不要浪费一次模型调用（一次几分钱，但更重要的是：模型只会编）。
        if ((array) ($slots['available_cells'] ?? []) === []) {
            $batchId = self::openBatch($title, $brand, $slots, 'failed');

            return self::finishBatch($batchId, [
                'ok' => false,
                'error' => '这个标题在当前 22 格矩阵下没有可用格（动作词未被任何格覆盖，且占位符缺失）',
                'retry_reason' => 'insufficient_coverage',
                'slots' => $slots,
            ]);
        }

        $batchId = self::openBatch($title, $brand, $slots, 'running');
        $llm ??= self::defaultLlm();

        $system = GeoTitleQuestionPrompt::system();
        $user = GeoTitleQuestionPrompt::user($title, $slots, $brand);

        $raw = null;
        $meta = [
            'model' => '', 'prompt_tokens' => 0, 'completion_tokens' => 0,
            'elapsed_ms' => 0, 'llm_calls' => 0, 'retry_reason' => null,
            'salvaged' => false,
        ];
        $parsed = ['items' => [], 'rejected' => [], 'why_missing' => 0, 'notes' => []];

        // ---- 第 1 次调用 ----
        $result = self::callOnce($llm, $system, $user, $meta);
        if ($result['error'] !== null) {
            return self::finishBatch($batchId, [
                'ok' => false,
                'error' => $result['error'],
                'retry_reason' => 'hard_invalid',
                'slots' => $slots,
                'meta' => $meta,
            ]);
        }
        $raw = $result['payload'];
        $parsed = self::normalizeItems($raw, $slots);

        // ---- 第 2 次调用（只在「一条有效候选都没有」时）----
        //
        // 为什么只在 0 条时重试：
        //   已经有 3 条有效候选时重试，最可能的结果是「3 条 + 另一批 3 条」，
        //   而多花的钱换不到更好的射程 —— 射程由标题决定，不由模型努力决定。
        if ($parsed['items'] === [] && $allowRetry) {
            $meta['llm_calls']++;
            $meta['retry_reason'] = 'hard_invalid';
            $userRetry = $user.GeoTitleQuestionPrompt::retryHint(
                $parsed['notes'] !== [] ? implode('；', array_slice($parsed['notes'], 0, 4)) : '输出不是合法 JSON 或 questions 为空。'
            );
            $second = self::callOnce($llm, $system, $userRetry, $meta);
            if ($second['error'] === null) {
                $raw = $second['payload'];
                $parsed = self::normalizeItems($raw, $slots);
            }
        }

        if ($parsed['items'] === []) {
            return self::finishBatch($batchId, [
                'ok' => false,
                'error' => '模型没有产出可用的候选问题'
                    .($parsed['notes'] !== [] ? '：'.implode('；', array_slice($parsed['notes'], 0, 3)) : ''),
                'retry_reason' => 'hard_invalid',
                'slots' => $slots,
                'meta' => $meta,
                'raw' => $raw,
            ]);
        }

        // ---- 交判定层（模型只给 q/angle/stage/why，其余全部服务端算）----
        $built = [];
        $corrected = 0;
        $whyMissing = 0;
        foreach ($parsed['items'] as $item) {
            $row = GeoTitleQuestionRules::makeItem(
                $item['q'],
                $slots,
                $brand,
                $item['why'],
                $item['angle'],
                $item['stage']
            );
            // 模型标注 vs 服务端复算：记录漂移，用于判断 prompt 是否讲清了定义
            $row['model_angle'] = $item['angle'];
            $row['model_stage'] = $item['stage'];
            $row['model_class'] = $item['class_hint'];
            $row['corrected'] = ($row['angle'] !== $item['angle']) || ($row['stage'] !== $item['stage']);
            if ($row['corrected']) {
                $corrected++;
            }
            if ($item['why_missing']) {
                $whyMissing++;
            }
            $built[] = $row;
        }

        $built = GeoTitleQuestionRules::dedupe($built);
        $verdict = GeoTitleQuestionRules::validate($built, $slots);
        $counts = $verdict['stats'];

        $meta['corrected'] = $corrected;
        $meta['why_missing'] = $whyMissing;
        $meta['candidates'] = count($parsed['items']);

        return self::finishBatch($batchId, [
            'ok' => true,
            'error' => null,
            'retry_reason' => $meta['retry_reason'],
            'slots' => $slots,
            'meta' => $meta,
            'raw' => $raw,
            'verdict' => $verdict,
            'rejected' => array_merge($parsed['rejected'], (array) ($verdict['rejected'] ?? [])),
            'counts' => $counts,
        ]);
    }

    // =========================================================================
    // 一次模型调用
    // =========================================================================

    /**
     * 调一次模型并解析 JSON。
     *
     * @param  callable  $llm
     * @param  array<string,mixed>  $meta  按引用累加 token / 耗时 / 调用次数
     * @return array{payload:?array<string,mixed>, error:?string}
     */
    private static function callOnce(callable $llm, string $system, string $user, array &$meta): array
    {
        $meta['llm_calls']++;
        $t0 = microtime(true);
        try {
            $res = $llm($system, $user);
        } catch (\Throwable $e) {
            return ['payload' => null, 'error' => '模型调用失败：'.mb_substr($e->getMessage(), 0, 160, 'UTF-8')];
        }
        $meta['elapsed_ms'] += (int) round((microtime(true) - $t0) * 1000);
        $meta['model'] = (string) ($res['model'] ?? $meta['model']);
        $meta['prompt_tokens'] += (int) ($res['prompt_tokens'] ?? 0);
        $meta['completion_tokens'] += (int) ($res['completion_tokens'] ?? 0);

        $content = (string) ($res['content'] ?? '');
        $metaExtract = GeoTitleQuestionPrompt::extractJsonMeta($content);
        $payload = $metaExtract['payload'];
        if ($metaExtract['salvaged']) {
            // 记在 meta 上：调用方要拿它把状态降成 degraded + 写 warning
            $meta['salvaged'] = true;
        }
        if ($payload === null) {
            return ['payload' => null, 'error' => '模型输出不是合法 JSON（前 120 字：'
                .mb_substr(trim($content), 0, 120, 'UTF-8').'）'];
        }

        return ['payload' => $payload, 'error' => null];
    }

    /**
     * 默认模型调用器：复用 GeoChat::complete（已解决选模、failover、整链预算）。
     *
     * 为什么指定 model_id=deepseek-v4-flash 而不是走统一选模器：
     *   ai_models 里 id=4 / id=5 是同名同 model_id 的重复行（历史冗余）。
     *   走选模器会在这两条之间随机落一条 —— 不影响结果，但「这一批到底用了哪条」
     *   会变得不可复现。这里按 model_id 精确取一条，批次表里的 model_id 才有意义。
     *
     * @return callable(string,string): array<string,mixed>
     */
    public static function defaultLlm(): callable
    {
        return static function (string $system, string $user): array {
            // 单次超时用本类的显式常量（60s），**不读 .env 的 30** —— 见 ATTEMPT_TIMEOUT 注释。
            // 整链预算仍读 .env：那是全局策略，运维调它时这里应该跟着生效。
            $total = (int) (env('GEOFLOW_AI_WORKSPACE_MODEL_TOTAL_TIMEOUT') ?: self::DEFAULT_TOTAL_TIMEOUT);

            return GeoChat::complete(
                $system,
                $user,
                self::MAX_TOKENS,
                self::TEMPERATURE,
                self::ATTEMPT_TIMEOUT,
                self::preferredModelId(),
                true,
                // 豆包会默认开「深度思考」，思考 token 一样计费且慢 5 倍。
                // 这是造句任务，不需要思考过程。
                false,
                $total
            );
        };
    }

    /** 优先用 deepseek-v4-flash（按 model_id 精确取一条，避开 id=4/5 的重复行） */
    public static function preferredModelId(): ?int
    {
        try {
            $id = \App\Models\AiModel::query()
                ->where('model_type', 'chat')
                ->where('status', 'active')
                ->where('model_id', 'deepseek-v4-flash')
                ->orderBy('id')
                ->value('id');

            return $id === null ? null : (int) $id;
        } catch (\Throwable) {
            // 查不到就让 GeoChat 走统一选模器，不要因此让整个功能不可用
            return null;
        }
    }

    // =========================================================================
    // 候选校验（H1–H8）
    // =========================================================================

    /**
     * 把模型输出整理成「可交给判定层」的候选。
     *
     * 这里只做**字段级**校验（能不能用），不做**语义级**判定（能不能抢）——
     * 后者是 C1/C2/C3 的事，在 Rules 里。分开的理由：字段错要打回给模型重试，
     * 语义不通过则是正常淘汰，两者处置完全不同。
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $slots
     * @return array{items:list<array<string,mixed>>, rejected:list<array<string,mixed>>, why_missing:int, notes:list<string>}
     */
    public static function normalizeItems(array $payload, array $slots): array
    {
        $cells = GeoTitleQuestionIndustry::contentTypeByCell();
        $list = $payload['questions'] ?? $payload['items'] ?? null;

        $notes = [];
        if (! is_array($list)) {
            // 兼容模型把数组直接放根上：{"0":{...},"1":{...}}
            $list = is_array($payload) && isset($payload[0]) ? $payload : null;
        }
        if (! is_array($list)) {
            return ['items' => [], 'rejected' => [], 'why_missing' => 0, 'notes' => ['缺少 questions 数组']];
        }

        $items = [];
        $rejected = [];
        $whyMissing = 0;
        $badAngle = 0;
        $badStage = 0;
        $badCell = 0;

        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $q = trim((string) ($row['q'] ?? $row['question'] ?? ''));
            if ($q === '') {
                continue;
            }
            if (mb_strlen($q) > 255) {
                $q = mb_substr($q, 0, 255);
            }
            $angle = strtolower(trim((string) ($row['angle'] ?? '')));
            // ⚠️ 不要用 strtoupper 归一化 stage：'S3a' 会变成 'S3A'，
            // 和枚举里的 'S3a' 对不上 —— 表现是「所有合法 stage 全被判非法」，
            // 而且报错信息看起来像模型的问题（实际是我的归一化写错）。
            // 改成大小写不敏感匹配，命中原值再往下走。
            $stageRaw = trim((string) ($row['stage'] ?? ''));
            $stage = GeoTitleQuestionStore::normalizeEnum($stageRaw, GeoTitleQuestionStore::MATRIX_STAGES);

            $angleNorm = GeoTitleQuestionStore::normalizeEnum($angle, GeoTitleQuestionStore::ANGLES);
            if ($angleNorm === null) {
                $badAngle++;
                $rejected[] = ['q' => $q, 'drop' => 'H_ANGLE', 'detail' => 'angle「'.$angle.'」不在 6 个枚举值内'];

                continue;
            }
            $angle = $angleNorm;
            if ($stage === null) {
                $badStage++;
                $rejected[] = ['q' => $q, 'drop' => 'H_STAGE', 'detail' => 'stage「'.$stageRaw.'」不在矩阵阶段内'];

                continue;
            }
            $cell = $angle.':'.$stage;
            if (! isset($cells[$cell])) {
                $badCell++;
                $rejected[] = ['q' => $q, 'drop' => 'INVALID_CELL', 'detail' => $cell.' 是空格（矩阵里不存在）'];

                continue;
            }

            $why = trim((string) ($row['why'] ?? ''));
            $missing = false;
            if ($why === '') {
                $missing = true;
                $whyMissing++;
            }
            if (mb_strlen($why) > 200) {
                $why = mb_substr($why, 0, 200);
            }

            $items[] = [
                'q' => $q,
                'angle' => $angle,
                'stage' => $stage,
                'why' => $why,
                'why_missing' => $missing,
                'class_hint' => (string) ($row['class'] ?? ''),
            ];
        }

        if ($badAngle > 0) {
            $notes[] = '有 '.$badAngle.' 条 angle 不在枚举内（只能是 definition/comparison/pricing/review/use_case/trust）';
        }
        if ($badStage > 0) {
            $notes[] = '有 '.$badStage.' 条 stage 不合法（只能是 S1/S2/S3a/S3b/S4/S5）';
        }
        if ($badCell > 0) {
            $notes[] = '有 '.$badCell.' 条 angle+stage 组合不是有效格（请严格按「可用格」菜单写）';
        }
        if ($whyMissing > 0) {
            $notes[] = '有 '.$whyMissing.' 条缺 why 字段';
        }

        return [
            'items' => $items,
            'rejected' => $rejected,
            'why_missing' => $whyMissing,
            'notes' => $notes,
        ];
    }

    // =========================================================================
    // 批次落库
    // =========================================================================

    /**
     * 开一个批次。
     *
     * 先建「running」批次再跑模型，是为了让页面在跑的过程中就能查到这条记录 ——
     * 90 秒里用户刷新页面不会看到「什么都没有」。
     *
     * @param  array<string,mixed>  $brand
     * @param  array<string,mixed>  $slots
     */
    private static function openBatch(string $title, array $brand, array $slots, string $status): int
    {
        return GeoTitleQuestionStore::createBatch([
            'brand_slug' => (string) ($brand['slug'] ?? ''),
            'brand_name' => (string) ($brand['name'] ?? ''),
            'title' => $title,
            'industry_template' => GeoTitleQuestionIndustry::TEMPLATE_KEY,
            'prompt_version' => GeoTitleQuestionIndustry::PROMPT_VERSION,
            'temperature' => self::TEMPERATURE,
            'status' => $status,
            'skipped_cells' => $slots['skipped_cells'] ?? [],
        ]);
    }

    /**
     * 收尾：写问题行 + 回填批次统计。无论成功失败都走这里。
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private static function finishBatch(int $batchId, array $payload): array
    {
        $meta = (array) ($payload['meta'] ?? []);
        $verdict = $payload['verdict'] ?? null;
        $counts = (array) ($payload['counts'] ?? []);
        $ok = (bool) ($payload['ok'] ?? false);

        $primary = $ok ? (array) ($verdict['primary'] ?? []) : [];
        $marginal = $ok ? (array) ($verdict['marginal'] ?? []) : [];
        $warnings = $ok ? (array) ($verdict['warnings'] ?? []) : [];
        $rejected = (array) ($payload['rejected'] ?? []);

        // 模型标注漂移：**必须报方向，不只报比例**。
        //
        // 教训：只看「漂移 39%」会得出「模型不行，改 prompt」，而实测拆开后有 10/14
        // 是服务端规则太贪 —— 改 prompt 会把服务端的错误固化进去。
        // 所以这里把模型更粗 / 更细 / 同级互换分开报，让人一眼看出该改哪边。
        $break = GeoTitleQuestionRules::driftBreakdown(array_merge($primary, $marginal));
        if ($break['drifted'] > 0 && $break['total'] > 0) {
            $pct = (int) round(100 * $break['drifted'] / $break['total']);
            $warnings[] = '模型标注漂移 '.$break['drifted'].'/'.$break['total'].'（'.$pct.'%）：'
                .'模型更粗 '.$break['coarser'].' 条 / 更细 '.$break['finer'].' 条 / 同级互换 '.$break['lateral'].' 条'
                .'（角度级 '.$break['angle_only'].'、阶段级 '.$break['stage_only'].'、两者都差 '.$break['both'].'）。'
                .'【要看方向决定改哪边：模型更粗 → 改 prompt；模型更细/同级 → 多半是服务端规则太贪，改词表】';
        }
        // 模型输出被 max_tokens 截断、靠抢救才拿到候选 —— 必须显式告警。
        //
        // 为什么不能悄悄放过：救回 9/12 条却写 completed，运营会以为 12 条都跑过；
        // 少掉的那几条可能正好是核心问题。**「少了但看起来正常」比「报错」危险。**
        // 状态不用单独设：只要 warnings 非空，finishBatch 就会写 degraded。
        if (($meta['salvaged'] ?? false) === true) {
            $warnings[] = '模型输出被截断（max_tokens 打满），已抢救回完整候选 '
                .(int) ($meta['candidates'] ?? 0).' 条（请求 '.self::MAX_CANDIDATES.' 条）。'
                .'抢救只保留截断点之前的完整 JSON 对象，所以候选数会偏少 —— '
                .'这一批的射程可能不完整，建议重跑或提高 max_tokens。';
        }

        if ((int) ($meta['why_missing'] ?? 0) > 0) {
            $warnings[] = '有 '.(int) $meta['why_missing'].' 条缺 why，已用服务端兜底文案填充（需人工复核）';
        }
        if ($ok && $counts !== [] && (int) ($counts['primary'] ?? 0) === 0) {
            $warnings[] = '模型产出了候选，但没有一条通过实体/动作/受众三判据 —— 这个标题的射程与候选不匹配';
        }

        $inserted = 0;
        if ($primary !== [] || $marginal !== []) {
            $inserted = GeoTitleQuestionStore::insertQuestions($batchId, array_merge($primary, $marginal));
            $expected = count($primary) + count($marginal);
            if ($inserted < $expected) {
                // insertOrIgnore 会静默吃掉冲突行 ——「返回 ok 但库里少了几条」是最难查的问题
                $warnings[] = '落库行数少于预期：期望 '.$expected.' 行，实际 '.$inserted
                    .' 行（可能有重复问题被唯一索引挡掉）';
            }
        }

        $status = 'failed';
        if ($ok) {
            $status = $warnings === [] ? 'completed' : 'degraded';
        }

        GeoTitleQuestionStore::updateBatch($batchId, [
            'status' => $status,
            'model_id' => (string) ($meta['model'] ?? ''),
            'model_name' => (string) ($meta['model'] ?? ''),
            'primary_count' => (int) ($counts['primary'] ?? 0),
            'scoped_count' => (int) ($counts['scoped'] ?? 0),
            'marginal_count' => (int) ($counts['marginal'] ?? 0),
            'angle_count' => (int) ($counts['angles'] ?? 0),
            'stage_count' => (int) ($counts['stages'] ?? 0),
            'class_counts' => (array) ($counts['classes'] ?? []),
            'skipped_cells' => $payload['slots']['skipped_cells'] ?? [],
            'llm_calls' => (int) ($meta['llm_calls'] ?? 0),
            'prompt_tokens' => (int) ($meta['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($meta['completion_tokens'] ?? 0),
            'elapsed_ms' => (int) ($meta['elapsed_ms'] ?? 0),
            'retry_reason' => $payload['retry_reason'] ?? null,
            'warnings' => $warnings,
            'rejected' => $rejected,
            'raw_json' => $payload['raw'] ?? null,
        ]);

        $batch = GeoTitleQuestionStore::findBatch($batchId);

        return [
            'ok' => $ok,
            'batch_id' => $batchId,
            'status' => $status,
            'error' => $payload['error'] ?? null,
            'inserted' => $inserted,
            'primary' => $primary,
            'marginal' => $marginal,
            'warnings' => $warnings,
            'rejected' => $rejected,
            'stats' => $counts,
            'slots' => $payload['slots'] ?? [],
            'meta' => $meta,
            'batch' => $batch,
        ];
    }

    /**
     * 统一的失败返回（还没建批次时用）。
     *
     * @return array<string,mixed>
     */
    private static function fail(string $message): array
    {
        return [
            'ok' => false,
            'batch_id' => 0,
            'status' => 'failed',
            'error' => $message,
            'inserted' => 0,
            'primary' => [],
            'marginal' => [],
            'warnings' => [],
            'rejected' => [],
            'stats' => [],
            'slots' => [],
            'meta' => [],
            'batch' => null,
        ];
    }
}
