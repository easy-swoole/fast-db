<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Exception\TimeoutException;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class TransactionScopeObserverTest extends TestCase
{
    public function testCommitObserverFailureDoesNotSendRollbackAfterACompletedCommit(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('commit logger');
            $db->setOnQuery(static function ($result) use ($failure): void {
                if (str_starts_with($result->getRawSql() ?? '', 'COMMIT')) { throw $failure; }
            });
            $this->reply($peer, 1); $this->reply($peer, 2);
            try { $db->transaction(static fn () => 'done'); $this->fail('Expected observer error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($connection->isInTransaction());
            $this->assertCommands($peer, ['START TRANSACTION', 'COMMIT']);
        });
    }

    public function testCommitObserverMayStartAnotherTransactionWithoutCleanupRollingItBack(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('observer after new begin'); $once = false;
            $db->setOnQuery(static function ($result) use ($db, $connection, $failure, &$once): void {
                if (!$once && str_starts_with($result->getRawSql() ?? '', 'COMMIT')) {
                    $once = true;
                    $db->begin($connection);
                    throw $failure;
                }
            });
            $this->reply($peer, 1); $this->reply($peer, 2); $this->reply($peer, 1);
            try { $db->transaction(static fn () => 'done'); $this->fail('Expected observer error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertTrue($connection->isInTransaction());
            $this->assertCommands($peer, ['START TRANSACTION', 'COMMIT', 'START TRANSACTION']);
        });
    }

    public function testRollbackObserverFailureKeepsTheOriginalBusinessException(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('business failure');
            $db->setOnQuery(static function ($result): void {
                if (str_starts_with($result->getRawSql() ?? '', 'ROLLBACK')) { throw new \RuntimeException('rollback logger'); }
            });
            $this->reply($peer, 1); $this->reply($peer, 2);
            try { $db->transaction(static function () use ($failure): void { throw $failure; }); $this->fail('Expected business error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($connection->isInTransaction());
            $this->assertCommands($peer, ['START TRANSACTION', 'ROLLBACK']);
        });
    }

    public function testBeginObserverFailureRollsBackTheStartedConnectionAndSkipsTheCallback(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('begin logger'); $entered = false;
            $db->setOnQuery(static function ($result) use ($failure): void {
                if (str_starts_with($result->getRawSql() ?? '', 'START TRANSACTION')) { throw $failure; }
            });
            $this->reply($peer, 1); $this->reply($peer, 2);
            try { $db->transaction(static function () use (&$entered): void { $entered = true; }); $this->fail('Expected begin error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($entered);
            $this->assertFalse($connection->isInTransaction());
            $this->assertCommands($peer, ['START TRANSACTION', 'ROLLBACK']);
        });
    }

    public function testCommitTimeoutDoesNotRetryOrSendRollbackOnAnotherSession(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer, ProtocolConnection $protocol): void {
            $this->reply($peer, 1);
            try { $db->transaction(static function (): void {}, timeout: 0.01); $this->fail('Expected commit timeout'); }
            catch (TimeoutException) { $this->addToAssertionCount(1); }
            $this->assertFalse($protocol->isConnected());
            $this->assertFalse($connection->isInTransaction());
            $this->assertCommands($peer, ['START TRANSACTION', 'COMMIT']);
        });
    }

    /** 使用真实连接和本地协议套接字，只预置服务端回复，不访问数据库。 */
    private function connected(callable $test): void
    {
        [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
        $config = new Config(['timeout' => 0.03]);
        $protocol = new ProtocolConnection($config);
        $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol);
        (new \ReflectionProperty($stream, 'socket'))->setValue($stream, $socket);
        $state = (new \ReflectionProperty($protocol, 'state'))->getValue($protocol);
        $state->established(); $state->applyServerStatus(2);
        $connection = new Connection($config); $connection->connectionName = 'default';
        (new \ReflectionProperty(Client::class, 'mysqlClient'))->setValue($connection, $protocol);
        $db = new FastDb();
        $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
        $current->setValue($db, [Coroutine::getCid() => ['default' => $connection]]);
        try { $test($db, $connection, $peer, $protocol); }
        finally { $current->setValue($db, []); $connection->close(); $peer->close(); }
    }

    private function reply(Socket $peer, int $status): void
    {
        $payload = "\0\0\0" . pack('v', $status) . "\0\0";
        $packet = substr(pack('V', strlen($payload)), 0, 3) . "\x01" . $payload;
        $this->assertSame(strlen($packet), $peer->sendAll($packet, 0.1));
    }

    private function assertCommands(Socket $peer, array $expected): void
    {
        foreach ($expected as $sql) {
            $header = $peer->recvAll(4, 0.1);
            $this->assertIsString($header);
            $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
            $payload = $peer->recvAll($length, 0.1);
            $this->assertIsString($payload);
            $this->assertSame("\x03", $payload[0]);
            $this->assertStringStartsWith($sql, substr($payload, 1));
        }
        $extra = $peer->recv(1, 0.002);
        $this->assertFalse(is_string($extra) && $extra !== '', 'Unexpected extra command');
    }
}
