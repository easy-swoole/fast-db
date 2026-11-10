<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\TransactionContext;
use Swoole\Coroutine;

/** 当前借用、当前事务代次的托管作用域；不保存已结束事务的历史。 */
final class TransactionScope
{
    public readonly TransactionContext $context;
    public readonly int $version;
    public int $depth = 0;
    public bool $rollbackOnly = false;
    public bool $unusable = false;

    private ?string $beginPermit = null;
    private ?object $wireTicket = null;

    public function __construct(TransactionContext $context)
    {
        $this->context = $context;
        $this->version = $context->version();
    }

    public function isCurrent(Connection $client, bool $requireActive = true): bool
    {
        return $this->context->belongsTo($client)
            && $client->ownsTransactionContext($this->context)
            && $this->context->version() === $this->version
            && Coroutine::getCid() === $this->context->coroutineId
            && ($client->connectionName ?? 'default') === $this->context->connectionName
            && (!$requireActive || $client->isInTransaction);
    }

    /** 拥有者首次开始事务时，只允许门面预检与实际发送检查各通过一次。 */
    public function authorizeBegin(string $sql): void
    {
        $tokens = self::tokens($sql);
        if (!self::isBegin($tokens)) { throw new RuntimeError('Only the owner initial BEGIN can be authorized'); }
        $this->beginPermit = $sql;
    }

    public function clearPermit(): void
    {
        $this->beginPermit = null;
        $this->wireTicket = null;
    }

    /** 门面入口未发送 SQL 的快捷返回也要检查；预检不消耗拥有者的首次开始授权。 */
    public function assertControl(Connection $client, string $sql): void
    {
        if ($this->beginPermit !== null && $this->beginPermit === $sql) {
            $this->assertCurrent($client, false);
            return;
        }
        $this->check($client, $sql);
    }

    public function enterExecution(Connection $client, ?string $sql): ?object
    {
        if ($this->beginPermit !== null && $this->beginPermit === $sql) {
            $this->assertCurrent($client, false);
            $frame = (object) ['previous' => $this->wireTicket];
            $this->beginPermit = null;
            $this->wireTicket = (object) ['sql' => $sql];
            return $frame;
        }
        $this->check($client, $sql);
        if ($this->wireTicket === null) { return null; }
        $frame = (object) ['previous' => $this->wireTicket];
        $this->wireTicket = null;
        return $frame;
    }

    public function leaveExecution(?object $frame): void
    {
        if ($frame !== null) { $this->wireTicket = $frame->previous; }
    }

    /** 仅消费 enterExecution 生成的票据；回调中的新调用不能重用它。 */
    public function assertWire(Connection $client, ?string $sql): void
    {
        $ticket = $this->wireTicket;
        // 其他协程或改名连接的失败调用不能消耗拥有者尚未使用的授权票据。
        if ($ticket !== null) { $this->assertCurrent($client, false); }
        $this->wireTicket = null;
        if ($ticket !== null && $ticket->sql === $sql) { return; }
        $this->check($client, $sql);
    }

    private function assertCurrent(Connection $client, bool $requireActive = true): void
    {
        if ($this->unusable) { throw new RuntimeError('Transaction cleanup failed; close or recycle the connection before reuse'); }
        if (!$this->isCurrent($client, $requireActive)) {
            throw new RuntimeError('Managed transaction no longer owns the active connection and transaction generation');
        }
    }

    private function check(Connection $client, ?string $sql): void
    {
        if ($this->unusable) { throw new RuntimeError('Transaction cleanup failed; close or recycle the connection before reuse'); }
        if ($this->depth === 0 && !$this->rollbackOnly) { return; }
        $this->assertCurrent($client);
        if ($sql === null) { throw new RuntimeError('Managed transactions require compiled SQL'); }
        $tokens = self::tokens($sql);
        if ($this->depth === 0 && $this->rollbackOnly && self::isFullRollback($tokens)) { return; }
        $command = $tokens[0];
        if (in_array($command, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'WITH', 'DO',
            'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'VALUES', 'TABLE'], true)) { return; }
        if ($command === 'SET' && !self::unsafeSet($tokens)) { return; }
        throw new RuntimeError($this->rollbackOnly
            ? 'Transaction is rollback-only; commit, restart and implicit transaction boundaries are not allowed'
            : 'Manual transaction control, implicit commits and dynamic SQL are not allowed inside transaction()');
    }

    private static function isBegin(array $tokens): bool
    {
        return $tokens[0] === 'BEGIN' || ($tokens[0] === 'START' && ($tokens[1] ?? null) === 'TRANSACTION');
    }

    private static function isFullRollback(array $tokens): bool
    {
        if ($tokens[0] !== 'ROLLBACK') { return false; }
        $offset = ($tokens[1] ?? null) === 'WORK' ? 2 : 1;
        foreach (array_slice($tokens, $offset) as $token) {
            if (!in_array($token, ['AND', 'NO', 'CHAIN', 'RELEASE'], true)) { return false; }
        }
        return true;
    }

    /** 只检查赋值左侧；SET @saved = @@autocommit 是读取，并不改变事务。 */
    private static function unsafeSet(array $tokens): bool
    {
        $start = 1;
        $depth = 0;
        $assignment = false;
        $left = [];
        for ($i = $start, $length = count($tokens); $i <= $length; $i++) {
            $token = $tokens[$i] ?? ',';
            if ($token === '(') { $depth++; }
            elseif ($token === ')') { $depth--; }
            if ($depth === 0 && $token === ',') {
                $userVariable = ($left[0] ?? null) === '@' && ($left[1] ?? null) !== '@';
                if (!$userVariable && (($left[0] ?? null) === 'CHARACTER' && ($left[1] ?? null) === 'SET')) { return true; }
                if (!$userVariable && array_intersect($left, ['AUTOCOMMIT', 'TRANSACTION', 'PASSWORD', 'TRANSACTION_ISOLATION', 'TRANSACTION_READ_ONLY', 'TX_ISOLATION', 'TX_READ_ONLY', 'SQL_MODE', 'NAMES', 'CHARSET', 'CHARACTER_SET_CLIENT', 'CHARACTER_SET_CONNECTION', 'CHARACTER_SET_RESULTS', 'COLLATION_CONNECTION']) !== []) { return true; }
                $left = []; $assignment = false;
            } elseif (!$assignment) {
                if ($depth === 0 && ($token === '=' || $token === ':=')) { $assignment = true; }
                else { $left[] = $token; }
            }
        }
        return false;
    }

    /** 完整扫描语句边界，普通数据操作只保留开头的少量标记，避免为批量写入构建完整语法树。 */
    private static function tokens(string $sql): array
    {
        $tokens = [];
        $ended = false;
        $hasSql = false;
        $emit = static function (string $token) use (&$tokens, &$ended, &$hasSql): void {
            if ($token === ';') {
                if ($ended || !$hasSql) { throw new RuntimeError('Multiple statements are not allowed inside transaction()'); }
                $ended = true;
                return;
            }
            if ($ended) { throw new RuntimeError('Multiple statements are not allowed inside transaction()'); }
            $hasSql = true;
            if (count($tokens) < 12 || ($tokens[0] ?? null) === 'SET') { $tokens[] = $token; }
        };
        self::scan($sql, $emit, $tokens);
        if ($tokens === []) { throw new RuntimeError('Managed transactions require a compiled SQL statement'); }
        return $tokens;
    }

    private static function scan(string $sql, callable $emit, array &$statementTokens): void
    {
        for ($i = 0, $length = strlen($sql); $i < $length;) {
            $char = $sql[$i];
            if (ord($char) <= 32) { $i++; continue; }
            if ($char === '#' || ($char === '-' && ($sql[$i + 1] ?? '') === '-'
                && ($i + 2 === $length || ord($sql[$i + 2]) <= 32))) {
                $end = strcspn($sql, "\r\n", $i); $i += $end; continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) { throw new RuntimeError('Unterminated SQL comment'); }
                $prefix = ($sql[$i + 2] ?? '') === '!' ? 3
                    : ((strtoupper($sql[$i + 2] ?? '') === 'M' && ($sql[$i + 3] ?? '') === '!') ? 4 : 0);
                if ($prefix !== 0) {
                    // 条件版本或 Maria 注释可能被服务端跳过，展开也可能掩盖真正语句。
                    throw new RuntimeError('Executable SQL comments are not allowed inside transaction()');
                }
                $i = $end + 2; continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char; $identifier = $quote !== "'"; $value = ''; $closed = false;
                for ($i++; $i < $length; $i++) {
                    $part = $sql[$i];
                    if ($part === '\\' && $i + 1 < $length) {
                        if (($statementTokens[0] ?? null) === 'SET' && in_array($sql[$i + 1], ["'", '"', '`'], true)) {
                            throw new RuntimeError('SQL-mode-dependent backslash quoting in SET is not allowed inside transaction(); bind the value instead');
                        }
                        if ($identifier) { $value .= $sql[$i + 1]; }
                        $i++; continue;
                    }
                    if ($part === $quote) {
                        if (($sql[$i + 1] ?? '') === $quote) { if ($identifier) { $value .= $quote; } $i++; continue; }
                        $i++; $closed = true; break;
                    }
                    if ($identifier) { $value .= $part; }
                }
                if (!$closed) { throw new RuntimeError('Unterminated SQL quoted value'); }
                $emit($identifier ? strtoupper($value) : "\0"); continue;
            }
            $ordinal = ord($char);
            if (($ordinal >= 65 && $ordinal <= 90) || ($ordinal >= 97 && $ordinal <= 122)
                || ($ordinal >= 48 && $ordinal <= 57) || $char === '_' || $char === '$') {
                $size = strspn($sql, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$', $i);
                $emit(strtoupper(substr($sql, $i, $size))); $i += $size; continue;
            }
            if ($char === ':' && ($sql[$i + 1] ?? '') === '=') { $emit(':='); $i += 2; continue; }
            $emit($char); $i++;
        }
    }
}
