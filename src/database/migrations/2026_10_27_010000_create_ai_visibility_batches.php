<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 覆盖核查的历史批次留档：ai_visibility_batches + ai_visibility_batch_runs。
 *
 * ── 为什么需要这两张表 ────────────────────────────────────────────────
 * 核查结果原来只存在 session（geo_cov_ctx / geo_cov_questions / geo_cov_results）。
 * 浏览器关掉、或者超过 session 生命周期（默认 2 小时），结果就没了——
 * 而「上一次跑成什么样、这次比上次好了没有」正是运营最需要的对照。
 *
 * 采样本身已经落在上游 ai_visibility_runs / ai_visibility_sources 里，
 * 缺的只是「这一批是哪一次核查跑出来的」。所以这里只加一层"批次"外壳：
 *   ai_visibility_batches       一次核查 = 一条记录（品牌/题数/采样次数/结果状态）
 *   ai_visibility_batch_runs    批次 ↔ 采样 的关联（多对多）
 *
 * ── 为什么不直接给 ai_visibility_runs 加 batch_id ──────────────────────
 * ai_visibility_runs 是上游表（数据中心 / AI 可见性 / 声量对标都在读）。
 * 加字段意味着所有写入路径都要知道批次概念，一处漏写就是脏数据；
 * 出问题要回滚也只能 ALTER 上游表。用关联表则完全不碰上游结构，
 * 回滚 = DROP 这两张新表，上游数据一行不动。
 *
 * ── has_site 为什么是快照 ─────────────────────────────────────────────
 * 品牌档案以后可能从"无官网"改成"有官网"。历史报告如果跟着档案变，
 * 等于篡改历史：当时按无官网口径跑出来的结论，事后会被算成另一套分母。
 * 所以核查那一刻的档案状态必须自己存一份。
 */
return new class extends Migration
{
    private const TABLE_BATCHES = 'ai_visibility_batches';

    private const TABLE_BATCH_RUNS = 'ai_visibility_batch_runs';

    /** 关联表上 run_id 的反向索引（按 run 找回批次用，例如数据中心反查来源） */
    private const RUN_INDEX = 'ai_visibility_batch_runs_run_idx';

    public function up(): void
    {
        // ---------- 批次元信息 ----------
        // 幂等：容器里 migrations 目录不一定挂载，这个文件主要用于留档，
        // DDL 也可能已经手工执行过；重复 migrate 不应该炸。
        if (! Schema::hasTable(self::TABLE_BATCHES)) {
            DB::statement(
                'CREATE TABLE '.self::TABLE_BATCHES.' ('
                .'id              bigserial PRIMARY KEY,'
                .'brand_slug      varchar(64)  NOT NULL,'
                .'brand_name      varchar(128),'
                .'site_url        varchar(255),'
                // 核查那一刻品牌档案是否有官网（口径快照，不随档案变化）
                .'has_site        boolean      NOT NULL DEFAULT true,'
                // 本次计划跑多少题
                .'questions_count int          NOT NULL DEFAULT 0,'
                // 每题采样几次
                .'rounds          int          NOT NULL DEFAULT 1,'
                // 实际产生多少条 run（含失败重试产生的）
                .'runs_count      int          NOT NULL DEFAULT 0,'
                // 有多少条 run 拿到了有效信源
                .'valid_count     int          NOT NULL DEFAULT 0,'
                // running / completed / aborted
                .'status          varchar(24)  NOT NULL DEFAULT \'running\','
                // 哪个入口发起的核查（现在只有 coverage_check，留扩展位）
                .'source          varchar(32)  NOT NULL DEFAULT \'coverage_check\','
                .'created_by      bigint,'
                .'created_at      timestamp(0),'
                .'updated_at      timestamp(0)'
                .')'
            );
        }

        // 报告页下拉：按品牌取最近 N 条 → (brand_slug, created_at DESC) 是最常用的路径
        if (! $this->indexExists(self::TABLE_BATCHES, 'ai_visibility_batches_brand_created_idx')) {
            DB::statement(
                'CREATE INDEX ai_visibility_batches_brand_created_idx ON '.self::TABLE_BATCHES
                .' (brand_slug, created_at DESC)'
            );
        }

        // ---------- 批次 ↔ 采样 关联 ----------
        if (! Schema::hasTable(self::TABLE_BATCH_RUNS)) {
            DB::statement(
                'CREATE TABLE '.self::TABLE_BATCH_RUNS.' ('
                .'batch_id bigint NOT NULL,'
                .'run_id   bigint NOT NULL,'
                .'PRIMARY KEY (batch_id, run_id)'
                .')'
            );
        }

        // 反向查询：给定一条 run 找到它属于哪一批（联查 / 排查用）
        if (! $this->indexExists(self::TABLE_BATCH_RUNS, self::RUN_INDEX)) {
            DB::statement(
                'CREATE INDEX '.self::RUN_INDEX.' ON '.self::TABLE_BATCH_RUNS.' (run_id)'
            );
        }
    }

    public function down(): void
    {
        // 先删关联表（它引用 batches），再删批次表。
        // 不加外键约束，所以顺序其实无所谓；保持这个顺序是为了读起来不费解。
        Schema::dropIfExists(self::TABLE_BATCH_RUNS);
        Schema::dropIfExists(self::TABLE_BATCHES);
    }

    /**
     * 索引是否存在（PG 专用：靠 pg_indexes 判断，不依赖 Doctrine DBAL）。
     *
     * Schema::hasTable() 在部分驱动上会返回缓存的旧结果，所以索引单独查一遍。
     */
    private function indexExists(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        try {
            $row = DB::selectOne(
                'select 1 as ok from pg_indexes where schemaname = current_schema() and tablename = ? and indexname = ? limit 1',
                [$table, $index]
            );
        } catch (\Throwable $e) {
            // 非 PG 驱动（sqlite 等）不支持 pg_indexes：退回"当作不存在"，
            // 建索引语句本身在该驱动下也会失败，交由调用方的 hasTable 分支兜住。
            return false;
        }

        return $row !== null;
    }
};
