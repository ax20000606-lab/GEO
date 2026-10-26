<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 补齐 tasks_ai_quality_prompt_required CHECK 约束的迁移定义。
 *
 * 背景：该 CHECK 约束最初是直接在数据库上手工执行的（见 P0 项改造），
 * 没有对应的迁移文件。后果是「用 migrations 从零建库」会缺少这条约束，
 * 导致「ai_quality_enabled=true 但 ai_quality_prompt_id 为 NULL」这种非法状态
 * 在全新环境里可以被写入，而生产环境却被拦住 —— 环境之间行为不一致。
 *
 * 约束语义：
 *   NOT ai_quality_enabled OR ai_quality_prompt_id IS NOT NULL
 *   - ai_quality_enabled = false  -> 恒为 true，不限制 prompt_id 是否有值
 *   - ai_quality_enabled = true   -> 要求 prompt_id 非 NULL（开启质检必须选方案）
 *
 * 幂等性：先查 pg_constraint 判断是否已存在，已存在则跳过。
 * 这样在生产库（约束已手工建好）上执行是完全 no-op，在全新库上才真正生效。
 */
return new class extends Migration
{
    private const TABLE = 'tasks';

    private const CONSTRAINT = 'tasks_ai_quality_prompt_required';

    private const DEFINITION = 'CHECK ((NOT ai_quality_enabled) OR (ai_quality_prompt_id IS NOT NULL))';

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        if ($this->constraintExists(self::TABLE, self::CONSTRAINT)) {
            return;
        }

        DB::statement(
            'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CONSTRAINT.' '.self::DEFINITION
        );
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        if (! $this->constraintExists(self::TABLE, self::CONSTRAINT)) {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.self::CONSTRAINT);
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * 判断某个表上是否已存在指定名称的约束（用于让迁移可重复执行）。
     */
    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::table('pg_constraint')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')
            ->where('pg_class.relname', $table)
            ->where('pg_constraint.conname', $constraint)
            ->exists();
    }
};
