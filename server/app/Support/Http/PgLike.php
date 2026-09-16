<?php

namespace App\Support\Http;

/**
 * PostgreSQL LIKE/ILIKE 通配符转义：PG 的默认转义符是反斜杠，
 * 用户输入中的 % _ \ 必须按字面量匹配，否则成为通配符。
 */
class PgLike
{
    /** @return string 已转义并包上 %…% 的模式串 */
    public static function wrap(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
