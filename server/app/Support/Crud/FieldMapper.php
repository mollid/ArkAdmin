<?php

namespace App\Support\Crud;

use Illuminate\Support\Str;

/**
 * PG udt_name → 生成物映射（唯一映射点，参照 FastAdmin Crud::getFieldType 的角色）：
 * phpType/cast 进 Model 与 Request，tsType/component/props 进 Vue 页面，rules 进校验。
 * v1 覆盖 CMS 类业务表常用类型集；未知类型显式拒绝，宁可报错不生成错误代码。
 */
class FieldMapper
{
    /** @return array{phpType:string, cast:?string, tsType:string, component:string, props:string, rules:list<string>, searchable:bool, longText:bool} */
    public static function map(Column $column): array
    {
        $required = (! $column->nullable && ! $column->hasDefault && ! $column->primaryKey) ? 'required' : 'nullable';
        $base = ['props' => '', 'searchable' => false, 'longText' => false, 'cast' => null];

        // array_merge：类型分支的键覆盖 $base（+ 运算符是左优先，覆盖会失效）
        $m = match ($column->udtName) {
            'int2', 'int4', 'int8' => array_merge($base, [
                'phpType' => 'int', 'cast' => 'integer', 'tsType' => 'number',
                'component' => 'el-input-number', 'rules' => [$required, 'integer'],
            ]),
            'bool' => array_merge($base, [
                'phpType' => 'bool', 'cast' => 'boolean', 'tsType' => 'boolean',
                'component' => 'el-switch', 'rules' => [$required, 'boolean'],
            ]),
            'varchar' => array_merge($base, [
                'phpType' => 'string', 'tsType' => 'string',
                'component' => 'el-input', 'searchable' => true,
                'rules' => [$required, 'string', ...($column->maxLength ? ["max:{$column->maxLength}"] : [])],
            ]),
            'text', 'jsonb' => array_merge($base, [
                'phpType' => 'string', 'tsType' => 'string',
                'component' => 'el-input', 'props' => 'type="textarea" :rows="4"',
                'longText' => true, 'rules' => [$required, 'string'],
            ]),
            'numeric' => array_merge($base, [
                'phpType' => 'string', 'tsType' => 'number',
                'component' => 'el-input-number', 'rules' => [$required, 'numeric'],
            ]),
            'timestamp', 'timestamptz' => array_merge($base, [
                'phpType' => 'string', 'cast' => 'datetime', 'tsType' => 'string',
                'component' => 'el-date-picker', 'props' => 'type="datetime" value-format="YYYY-MM-DD HH:mm:ss"',
                'rules' => [$required, 'date'],
            ]),
            'date' => array_merge($base, [
                'phpType' => 'string', 'cast' => 'date', 'tsType' => 'string',
                'component' => 'el-date-picker', 'props' => 'type="date" value-format="YYYY-MM-DD"',
                'rules' => [$required, 'date'],
            ]),
            default => throw new CrudException(
                "字段 [{$column->name}] 类型 [{$column->udtName}] 不在生成器支持范围内，请手工编写或扩展 FieldMapper"
            ),
        };

        return $m;
    }

    /** 表单标签：列注释优先，缺省用 headline 化的列名 */
    public static function label(Column $column): string
    {
        return $column->comment !== null && trim($column->comment) !== ''
            ? $column->comment
            : Str::headline($column->name);
    }
}
