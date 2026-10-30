<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 标题反推 AI 提问：geo_title_prompt_batches + geo_title_prompt_questions。
 *
 * ── 为什么运行时不靠这个文件 ──────────────────────────────────────────────
 * docker 里 `database/migrations` 没有挂载，新 migration 进不了容器，
 * `php artisan migrate` 永远看不到它。所以真正的建表由
 * `GeoTitleQuestionStore::ensureSchema()` 在页面/接口入口懒执行。
 * 本文件与 `overrides/geo-title-prompt-schema.sql` 一样，只用于留档：
 * 让后来的人能 `git log` 到「这两张表是什么时候、因为什么加的」。
 *
 * ── 为什么两张表而不是一张带 parent_id 的自关联表 ────────────────────────
 * 批次行数 << 问题行数（1 : 十几），自关联会把「取这一批的主清单」变成
 * 递归查询；而且批次上有 7 个与单条问题无关的统计列（token/耗时/retry_reason），
 * 混在一张表里每行都要重复存一遍。
 *
 * ── scoped_count 与 primary_count 为什么必须是两个字段 ────────────────────
 * A 类问题按决策 2 要出现在清单里，但不计入「本篇能抢」。
 * 用一个字段兼两义，校验条件就会被 A 类污染：出一堆 A 类就能把
 * 5<=n<=8 的检查骗过去，而运营实际一条也写不出来。
 *
 * ── rejected 为什么存批次不存问题表 ─────────────────────────────────────
 * 规则原文是「抢不到：不输出，也不出现在任何清单里」。落进问题表就违规；
 * 但完全不记又无法复盘「为什么这条被淘汰」。存批次级 jsonb 是折中：
 * 不进清单，但可追问。
 */
return new class extends Migration
{
    private const TABLE_BATCHES = 'geo_title_prompt_batches';

    private const TABLE_QUESTIONS = 'geo_title_prompt_questions';

    public function up(): void
    {
        // ---------- 批次表 ----------
        // 幂等：DDL 可能已被 ensureSchema() 手工跑过，重复 migrate 不应该炸。
        if (! Schema::hasTable(self::TABLE_BATCHES)) {
            DB::statement(
                'CREATE TABLE '.self::TABLE_BATCHES.' ('
                .'id                bigserial      PRIMARY KEY,'
                .'brand_slug        varchar(64)    NOT NULL,'
                .'brand_name        varchar(128),'
                .'title             varchar(500)   NOT NULL,'
                .'title_hash        char(64)       NOT NULL,'
                .'industry_template varchar(32)    NOT NULL DEFAULT \'skincare\','
                .'prompt_version    varchar(24)    NOT NULL,'
                .'model_id          varchar(128),'
                .'model_name        varchar(128),'
                .'seed              bigint,'
                .'temperature       numeric(3,2)   NOT NULL DEFAULT 0.20,'
                .'status            varchar(16)    NOT NULL DEFAULT \'running\','
                .'primary_count     int            NOT NULL DEFAULT 0,'
                .'scoped_count      int            NOT NULL DEFAULT 0,'
                .'marginal_count    int            NOT NULL DEFAULT 0,'
                .'angle_count       int            NOT NULL DEFAULT 0,'
                .'stage_count       int            NOT NULL DEFAULT 0,'
                .'class_counts      jsonb,'
                .'skipped_cells     jsonb,'
                .'llm_calls         int            NOT NULL DEFAULT 0,'
                .'prompt_tokens     int            NOT NULL DEFAULT 0,'
                .'completion_tokens int            NOT NULL DEFAULT 0,'
                .'elapsed_ms        int            NOT NULL DEFAULT 0,'
                .'retry_reason      varchar(32),'
                .'warnings          jsonb,'
                .'rejected          jsonb,'
                .'raw_json          jsonb,'
                .'declaration       text           NOT NULL,'
                .'created_by        bigint,'
                .'created_at        timestamp(0),'
                .'updated_at        timestamp(0)'
                .')'
            );
        }

        foreach ([
            'geo_tpb_brand_created_idx' => 'brand_slug, created_at DESC',
            'geo_tpb_title_hash_idx' => 'title_hash, created_at DESC',
            'geo_tpb_status_created_idx' => 'status, created_at DESC',
        ] as $index => $columns) {
            if (! $this->indexExists(self::TABLE_BATCHES, $index)) {
                DB::statement('CREATE INDEX '.$index.' ON '.self::TABLE_BATCHES.' ('.$columns.')');
            }
        }

        // ---------- 问题表 ----------
        if (! Schema::hasTable(self::TABLE_QUESTIONS)) {
            DB::statement(
                'CREATE TABLE '.self::TABLE_QUESTIONS.' ('
                .'id              bigserial    PRIMARY KEY,'
                .'batch_id        bigint       NOT NULL REFERENCES '.self::TABLE_BATCHES.'(id) ON DELETE CASCADE,'
                .'brand_slug      varchar(64)  NOT NULL,'
                .'list_kind       varchar(8)   NOT NULL,'
                .'position        int          NOT NULL DEFAULT 0,'
                .'question        varchar(255) NOT NULL,'
                .'angle           varchar(16)  NOT NULL,'
                .'stage           varchar(4)   NOT NULL,'
                .'class           char(1)      NOT NULL,'
                .'intent          varchar(16)  NOT NULL,'
                .'brand_relevance varchar(16)  NOT NULL,'
                .'route           varchar(16)  NOT NULL,'
                .'fit             varchar(8)   NOT NULL,'
                .'cell            varchar(24)  NOT NULL,'
                .'why             text         NOT NULL,'
                // 运营口径三件套：全部服务端确定性推导，模型不给 -> NOT NULL 安全
                .'concern         varchar(32)  NOT NULL,'
                .'concern_alt     varchar(32),'
                .'expected        text         NOT NULL,'
                .'content_type    varchar(32)  NOT NULL,'
                .'angles_touched  jsonb        NOT NULL,'
                .'model_angle     varchar(16),'
                .'model_stage     varchar(8),'
                .'model_class     char(1),'
                .'corrected       boolean      NOT NULL DEFAULT false,'
                .'created_at      timestamp(0),'
                .'CONSTRAINT geo_tpq_stage_chk  CHECK (stage  IN (\'S0\',\'S1\',\'S2\',\'S3a\',\'S3b\',\'S4\',\'S5\')),'
                .'CONSTRAINT geo_tpq_angle_chk  CHECK (angle  IN (\'definition\',\'comparison\',\'pricing\',\'review\',\'use_case\',\'trust\')),'
                .'CONSTRAINT geo_tpq_class_chk  CHECK (class  IN (\'A\',\'B\',\'C\',\'D\')),'
                .'CONSTRAINT geo_tpq_intent_chk CHECK (intent IN (\'transactional\',\'commercial\',\'informational\',\'navigational\')),'
                .'CONSTRAINT geo_tpq_fit_chk    CHECK (fit    IN (\'can\',\'marginal\')),'
                .'CONSTRAINT geo_tpq_list_chk   CHECK (list_kind IN (\'primary\',\'marginal\')),'
                .'CONSTRAINT geo_tpq_route_chk  CHECK (route  IN (\'direct\',\'sidelong\',\'own_source\',\'source_build\')),'
                .'CONSTRAINT geo_tpq_relv_chk   CHECK (brand_relevance IN (\'own\',\'competitor\',\'both\',\'none\')),'
                .'CONSTRAINT geo_tpq_concern_chk CHECK (concern IN (\'\u4ef7\u683c\',\'\u8d28\u91cf\',\'\u5b89\u5168\',\'\u6548\u679c\',\'\u552e\u540e\',\'\u771f\u5b9e\u6027\',\'\u65f6\u6548\u6027\',\'\u5176\u4ed6\')),'
                .'CONSTRAINT geo_tpq_concern_alt_chk CHECK (concern_alt IS NULL OR (concern_alt IN (\'\u4ef7\u683c\',\'\u8d28\u91cf\',\'\u5b89\u5168\',\'\u6548\u679c\',\'\u552e\u540e\',\'\u771f\u5b9e\u6027\',\'\u65f6\u6548\u6027\',\'\u5176\u4ed6\') AND concern_alt <> concern)),'
                .'CONSTRAINT geo_tpq_ctype_chk CHECK (content_type IN ('
                .'\'symptom_science\',\'category_primer\',\'ingredient_explainer\',\'category_comparison\','
                .'\'brand_comparison\',\'price_range_primer\',\'price_comparison\',\'price_transparency\','
                .'\'price_page\',\'category_review_roundup\',\'pros_cons_analysis\',\'user_evidence_roundup\','
                .'\'longterm_review\',\'scene_science\',\'audience_buying_guide\',\'audience_fit_comparison\','
                .'\'usage_guide\',\'category_pitfall_guide\',\'brand_reliability_comparison\','
                .'\'prospectus_and_policy\',\'authentic_channel_guide\',\'counterfeit_rights_guide\')),'
                .'CONSTRAINT geo_tpq_expected_len_chk CHECK (char_length(expected) BETWEEN 4 AND 20),'
                .'CONSTRAINT geo_tpq_touched_chk CHECK ('
                .'jsonb_typeof(angles_touched) = \'array\''
                .' AND CASE WHEN jsonb_typeof(angles_touched) = \'array\' THEN jsonb_array_length(angles_touched) ELSE 0 END >= 1'
                .' AND angles_touched <@ \'["definition","comparison","pricing","review","use_case","trust"]\'::jsonb)'
                .')'
            );
        }

        foreach ([
            'geo_tpq_batch_list_idx' => 'batch_id, list_kind, position',
            'geo_tpq_brand_stage_idx' => 'brand_slug, stage',
            'geo_tpq_brand_class_idx' => 'brand_slug, class',
            'geo_tpq_brand_ctype_idx' => 'brand_slug, content_type',
            'geo_tpq_brand_concern_idx' => 'brand_slug, concern',
        ] as $index => $columns) {
            if (! $this->indexExists(self::TABLE_QUESTIONS, $index)) {
                DB::statement('CREATE INDEX '.$index.' ON '.self::TABLE_QUESTIONS.' ('.$columns.')');
            }
        }

        if (! $this->indexExists(self::TABLE_QUESTIONS, 'geo_tpq_batch_question_uidx')) {
            DB::statement(
                'CREATE UNIQUE INDEX geo_tpq_batch_question_uidx ON '.self::TABLE_QUESTIONS.' (batch_id, question)'
            );
        }
    }

    public function down(): void
    {
        // 先删问题表（它引用批次表）；外键是 ON DELETE CASCADE，
        // 但回滚时仍然显式按依赖顺序删，日志更好读。
        Schema::dropIfExists(self::TABLE_QUESTIONS);
        Schema::dropIfExists(self::TABLE_BATCHES);
    }

    /**
     * 索引是否存在（PG 专用：查 pg_indexes，不依赖 Doctrine DBAL）。
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
            return false;
        }

        return $row !== null;
    }
};
