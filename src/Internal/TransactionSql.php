<?php

namespace EasySwoole\FastDb\Internal;

/** 只识别事务边界，实际事务状态以协议为准。 */
final class TransactionSql
{
    public static function completion(?string $sql): ?bool
    {
        $sql ??= '';
        while (true) {
            $sql = ltrim($sql, " \t\n\r\0\x0B;");
            if (str_starts_with($sql, '#') || preg_match('/^--(?:\\s|$)/', $sql)) {
                $sql = substr($sql, strcspn($sql, "\r\n"));
                continue;
            }
            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/', 2);
                if ($end === false) { return null; }
                $body = str_starts_with($sql, '/*!') ? preg_replace('/^[0-9]{5,6}/', '', substr($sql, 3, $end - 3)) : '';
                $sql = $body . ' ' . substr($sql, $end + 2);
                continue;
            }
            break;
        }
        if (preg_match('/^ROLLBACK\\b(?!\\s+(?:WORK\\s+)?TO\\b)/i', $sql)) { return false; }
        if (preg_match('/^(?:COMMIT\\b|START\\s+TRANSACTION\\b|BEGIN\\b)/i', $sql)) { return true; }
        return null;
    }
}
