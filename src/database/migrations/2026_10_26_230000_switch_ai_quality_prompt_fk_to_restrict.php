<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 把 tasks.ai_quality_prompt_id 的外键从 ON DELETE SET NULL 改成 ON DELETE RESTRICT。
 *
 * 背景：原外键是 SET NULL。删除一条仍被任务引用的质检方案（prompt）时，
 * PostgreSQL 会先自动把 tasks.ai_quality_prompt_id 置 NULL，然后这一行就撞上
 * CHECK 约束 tasks_ai_quality_prompt_required，报出：
 *
 *   ERROR: new row for relation "tasks" violates check constraint
 *          "tasks_ai_quality_prompt_required"
 *   CONTEXT: SQL statement "UPDATE ONLY tasks SET ai_quality_prompt_id = NULL WHERE ..."
 *
 * 运维看到的是「check 约束被违反」，会误以为是数据写错了；实际原因是
 * 「删除了一条还在被使用的质检方案」。改成 RESTRICT 后，数据库直接拒绝删除，
 * 报错变成外键错误：
 *
 *   ERROR: update or delete on table "prompts" violates foreign key constraint
 *          "tasks_ai_quality_prompt_id_foreign" on table "tasks"
 *   DETAIL: Key (id)=(5) is still referenced from table "tasks".
 *
 * 行为变化（已实测确认，这是有意的收紧）：
 *
 *   | 场景                                  | SET NULL（改前）      | RESTRICT（改后）      |
 *   |---------------------------------------|-----------------------|-----------------------|
 *   | task 开着质检，删它引用的 prompt       | 报 CHECK 错误         | 报外键错误            |
 *   | task 已关质检，删它引用的 prompt       | **成功**（置 NULL）   | 报外键错误（变严格）  |
 *   | 先把 prompt_id 置 NULL，再删 prompt    | 成功                  | 成功                  |
 *
 * 即：改前「把质检关掉就能删掉对应 prompt」，改后必须先显式把 prompt_id 置 NULL。
 * 这个收紧不影响后台 UI —— AiPromptController::destroy() 用
 * Task::withTrashed()->where('ai_quality_prompt_id', $id)->count() 判断占用，
 * 不区分 ai_quality_enabled，本来就会拦住（报「in use」），与 RESTRICT 语义一致。
 *
 * 注意：本迁移**只改单列外键**。复合外键 tasks_ai_quality_prompt_type_fk
 * 无法带 ON DELETE 动作（它含生成列，PG 禁止），继续保持默认的 NO ACTION。
 * 因此删除被引用 prompt 时，报错里点名的约束名可能是复合外键
 * （RI 触发器按 tgname 顺序触发，复合外键更早创建就先报），
 * 但同样是外键错误而非 CHECK 错误，语义已经准确。
 *
 * 幂等性：先读 pg_constraint.confdeltype，若已经是 RESTRICT 则跳过。
 *   confdeltype: a=NO ACTION, r=RESTRICT, c=CASCADE, n=SET NULL, d=SET DEFAULT
 */
return new class extends Migration
{
    private const TABLE = 'tasks';

    private const CONSTRAINT = 'tasks_ai_quality_prompt_id_foreign';

    private const COLUMN = 'ai_quality_prompt_id';

    private const PARENT_TABLE = 'prompts';

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        // 已经是 RESTRICT 就跳过，避免重复执行时无谓地重建约束。
        if ($this->currentOnDeleteAction() === 'r') {
            return;
        }

        $this->rebuildForeignKey('ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        // 回滚到 SET NULL；若当前已是 SET NULL 则跳过。
        if ($this->currentOnDeleteAction() === 'n') {
            return;
        }

        $this->rebuildForeignKey('ON DELETE SET NULL');
    }

    /**
     * 重建单列外键以应用新的 ON DELETE 动作。
     *
     * 用事务包住 DROP + ADD，避免中途失败后留下「外键已被删掉」的危险中间态。
     */
    private function rebuildForeignKey(string $onDeleteAction): void
    {
        DB::transaction(function () use ($onDeleteAction): void {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.self::CONSTRAINT);

            DB::statement(
                'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CONSTRAINT
                .' FOREIGN KEY ('.self::COLUMN.') REFERENCES '.self::PARENT_TABLE.' (id) '
                .$onDeleteAction
            );
        });
    }

    /**
     * 返回当前外键的 ON DELETE 动作代码；约束不存在时返回 null。
     *
     * 返回值含义：a=NO ACTION, r=RESTRICT, c=CASCADE, n=SET NULL, d=SET DEFAULT
     */
    private function currentOnDeleteAction(): ?string
    {
        $row = DB::table('pg_constraint')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')
            ->where('pg_class.relname', self::TABLE)
            ->where('pg_constraint.conname', self::CONSTRAINT)
            ->first(['pg_constraint.confdeltype']);

        if ($row === null) {
            return null;
        }

        return (string) $row->confdeltype;
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
