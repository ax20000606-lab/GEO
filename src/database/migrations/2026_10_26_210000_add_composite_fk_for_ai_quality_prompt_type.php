<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-7：用复合外键堵住「tasks.ai_quality_prompt_id 有值但指向的 prompt type 不是 quality_check」。
 *
 * 背景：上一轮加的 CHECK 约束 tasks_ai_quality_prompt_required 只能保证 prompt_id 非 NULL，
 * 无法跨表校验 prompts.type（CHECK 不允许引用其他表）。因此这里用「生成列 + 复合外键」实现：
 *
 *   tasks.ai_quality_prompt_expected_type  恒为 'quality_check' 的 STORED 生成列
 *   FOREIGN KEY (ai_quality_prompt_id, ai_quality_prompt_expected_type)
 *     REFERENCES prompts (id, type)
 *
 * 效果：
 *   - ai_quality_prompt_id 指向 type='quality_check' 的 prompt  -> 通过
 *   - ai_quality_prompt_id 指向 type='content' 的 prompt        -> 违反外键，插入/更新直接失败
 *   - ai_quality_prompt_id 为 NULL                              -> MATCH SIMPLE 语义下外键不校验，放行
 *     （正好对应「关闭质检时允许无质检方案」）
 *
 * 已实测约束（PostgreSQL 16.15）：
 *   - 生成列可以作为外键的子列；
 *   - 复合外键**不能**带 ON DELETE SET NULL / ON UPDATE SET NULL，PG 报
 *     "invalid ON DELETE action for foreign key constraint containing generated column"。
 *     父表 prompts 的 SET NULL 语义仍由已有的单列外键 tasks_ai_quality_prompt_id_foreign 提供，
 *     两个外键共存无冲突（删除 prompt 时单列外键先把 prompt_id 置 NULL，复合外键此时 MATCH SIMPLE 放行）；
 *   - 不能用 MATCH FULL，否则 prompt_id 为 NULL 时整行被拒，会破坏「关闭质检」的合法场景。
 */
return new class extends Migration
{
    private const UNIQUE_CONSTRAINT = 'prompts_id_type_unique';

    private const GENERATED_COLUMN = 'ai_quality_prompt_expected_type';

    private const COMPOSITE_FK = 'tasks_ai_quality_prompt_type_fk';

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        // 复合外键的父键必须是唯一键，prompts 原本只有主键 id 唯一。
        if (! $this->constraintExists('prompts', self::UNIQUE_CONSTRAINT)) {
            DB::statement('ALTER TABLE prompts ADD CONSTRAINT '.self::UNIQUE_CONSTRAINT.' UNIQUE (id, type)');
        }

        // 恒为 'quality_check' 的 STORED 生成列，只读，应用层无法伪造。
        if (! Schema::hasColumn('tasks', self::GENERATED_COLUMN)) {
            DB::statement(
                'ALTER TABLE tasks ADD COLUMN '.self::GENERATED_COLUMN." varchar(50) GENERATED ALWAYS AS ('quality_check') STORED"
            );
        }

        if (! $this->constraintExists('tasks', self::COMPOSITE_FK)) {
            DB::statement(
                'ALTER TABLE tasks ADD CONSTRAINT '.self::COMPOSITE_FK
                .' FOREIGN KEY (ai_quality_prompt_id, '.self::GENERATED_COLUMN.')'
                .' REFERENCES prompts (id, type)'
            );
        }
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        if ($this->constraintExists('tasks', self::COMPOSITE_FK)) {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT '.self::COMPOSITE_FK);
        }

        if (Schema::hasColumn('tasks', self::GENERATED_COLUMN)) {
            DB::statement('ALTER TABLE tasks DROP COLUMN '.self::GENERATED_COLUMN);
        }

        if ($this->constraintExists('prompts', self::UNIQUE_CONSTRAINT)) {
            DB::statement('ALTER TABLE prompts DROP CONSTRAINT '.self::UNIQUE_CONSTRAINT);
        }
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
