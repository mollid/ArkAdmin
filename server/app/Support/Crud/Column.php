<?php

namespace App\Support\Crud;

/** information_schema 单列定义（只读 DTO） */
final class Column
{
    public function __construct(
        public readonly string $name,
        /** information_schema udt_name：int4/int8/int2/varchar/text/bool/numeric/timestamp/date/jsonb… */
        public readonly string $udtName,
        public readonly bool $nullable,
        public readonly bool $hasDefault,
        /** character_maximum_length（仅 varchar 等有长度概念的类型） */
        public readonly ?int $maxLength,
        /** col_description 取得的列注释（无则 null） */
        public readonly ?string $comment,
        public readonly bool $primaryKey,
    ) {}

    /** 主键与框架维护的审计列不进表单/fillable（id 仍作模型主键，时间戳走 timestamps()） */
    public function skipForm(): bool
    {
        return $this->primaryKey || in_array($this->name, ['created_at', 'updated_at'], true);
    }
}
