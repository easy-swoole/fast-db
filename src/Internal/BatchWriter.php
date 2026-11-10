<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\Mysqli\QueryBuilder;

/** 分批直接写入数据库格式的行，不创建逐行实体或管理事务。 */
final class BatchWriter
{
    /** @param callable(): array{string, string} $resolveTarget */
    public static function write(iterable $rows, ?array $updates, int $batchSize,
        callable $resolveTarget, callable $execute): int|string
    {
        if ($batchSize < 1) { throw new \InvalidArgumentException('Batch size must be greater than zero'); }
        if ($updates === []) { throw new \InvalidArgumentException('Upsert requires update columns'); }
        $columnSet = null;
        $batch = [];
        $target = null;
        $total = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                throw new \InvalidArgumentException('Every batch row must be a non-empty associative array');
            }
            // 驱动校验批内全部行；这里只比较各批首行，保证字段集合跨批一致。
            if ($batch === []) {
                if ($columnSet === null) { $columnSet = array_fill_keys(array_keys($row), true); }
                elseif (count($row) !== count($columnSet) || array_diff_key($columnSet, $row) !== []) {
                    throw new \InvalidArgumentException('All batch rows must contain exactly the same columns');
                }
            }
            // 按当前行顺序取值，解除标量引用；列名校验、排序及 upsert 整理由驱动负责。
            $snapshot = [];
            foreach ($row as $column => $value) { $snapshot[$column] = $value; }
            $batch[] = $snapshot;
            if (count($batch) === $batchSize) {
                $target ??= $resolveTarget();
                $total = self::flush($batch, $updates, $target, $execute, $total);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $target ??= $resolveTarget();
            $total = self::flush($batch, $updates, $target, $execute, $total);
        }
        return $total;
    }

    private static function flush(array $rows, ?array $updates, array $target, callable $execute, int|string $total): int|string
    {
        [$table, $connection] = $target;
        $query = new QueryBuilder();
        if ($updates === null) { $query->insertAll($table, $rows); }
        else { $query->upsert($table, $rows, $updates); }
        $affected = $execute($query, $connection)->getAffectedRows();
        if ($affected === null || (is_int($affected) && $affected < 0)
            || (is_string($affected) && preg_match('/\A[0-9]+\z/D', $affected) !== 1)) {
            throw new RuntimeError('A successful batch write must report a non-negative affected row count');
        }
        return self::add($total, $affected);
    }

    /** 不依赖 BCMath；超过 PHP_INT_MAX 后使用十进制字符串，避免 float 丢失精度。 */
    private static function add(int|string $left, int|string $right): int|string
    {
        if (is_int($left) && is_int($right) && $left <= PHP_INT_MAX - $right) { return $left + $right; }
        $a = ltrim((string) $left, '0');
        $b = ltrim((string) $right, '0');
        $result = '';
        $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry > 0; $i--, $j--) {
            $digit = ($i >= 0 ? ord($a[$i]) - 48 : 0) + ($j >= 0 ? ord($b[$j]) - 48 : 0) + $carry;
            $result .= chr(48 + $digit % 10);
            $carry = intdiv($digit, 10);
        }
        $result = $result === '' ? '0' : strrev($result);
        $maximum = (string) PHP_INT_MAX;
        return strlen($result) < strlen($maximum) || (strlen($result) === strlen($maximum) && strcmp($result, $maximum) <= 0)
            ? (int) $result : $result;
    }
}
