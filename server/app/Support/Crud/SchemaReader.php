<?php

namespace App\Support\Crud;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL information_schema 内省（§10 M5）：列定义 + 列注释 + 主键探测。
 * 只查当前 search_path 的表（插件表约定在 public）。
 */
class SchemaReader
{
    /** @return list<Column> 按 ordinal_position 排序 */
    public function columns(string $table): array
    {
        $qualified = 'public.'.$table;

        $rows = DB::select(<<<'SQL'
            select
                c.column_name,
                c.udt_name,
                c.is_nullable,
                c.column_default,
                c.character_maximum_length,
                col_description(to_regclass(?)::oid, c.ordinal_position) as column_comment,
                exists (
                    select 1
                    from information_schema.table_constraints tc
                    join information_schema.key_column_usage kcu
                      on kcu.constraint_name = tc.constraint_name and kcu.table_schema = tc.table_schema
                    where tc.table_schema = c.table_schema
                      and tc.table_name = c.table_name
                      and tc.constraint_type = 'PRIMARY KEY'
                      and kcu.column_name = c.column_name
                ) as is_primary
            from information_schema.columns c
            where c.table_schema = current_schema()
              and c.table_name = ?
            order by c.ordinal_position
        SQL, [$qualified, $table]);

        if ($rows === []) {
            throw new CrudException("数据表 [{$table}] 不存在（当前连接 search_path 内无此表）");
        }

        return array_map(fn ($r) => new Column(
            name: $r->column_name,
            udtName: $r->udt_name,
            nullable: $r->is_nullable === 'YES',
            hasDefault: $r->column_default !== null,
            maxLength: $r->character_maximum_length !== null ? (int) $r->character_maximum_length : null,
            comment: $r->column_comment !== null ? (string) $r->column_comment : null,
            primaryKey: (bool) $r->is_primary,
        ), $rows);
    }
}
