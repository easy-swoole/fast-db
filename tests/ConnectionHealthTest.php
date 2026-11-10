<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\Exception as QueryException;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class ConnectionHealthTest extends TestCase
{
    public function testInitialAndExpiredProbesRefreshTheSharedHealthWindow(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $this->reply($peer, $this->ok());
            $this->assertTrue($connection->beforeUse());
            $this->assertSame("\x03select 1", $this->receive($peer));
            $this->assertTrue($connection->intervalCheck());
            $this->assertNoCommand($peer);

            $this->setTick($connection, hrtime(true) - 3_000_000_001);
            $started = hrtime(true);
            $this->reply($peer, $this->ok());
            $this->assertTrue($connection->intervalCheck());
            $this->assertSame("\x03select 1", $this->receive($peer));
            $this->assertGreaterThanOrEqual($started, $this->tick($connection));
            $this->assertTrue($connection->beforeUse());
            $this->assertNoCommand($peer);
        });
    }

    public static function queryModes(): array { return [['raw'], ['prepared']]; }

    #[DataProvider('queryModes')]
    public function testSuccessfulQueriesSkipProbesUntilTheirActivityExpires(string $mode): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer) use ($mode): void {
            $this->setTick($connection, hrtime(true) - 4_000_000_000);
            $started = hrtime(true);
            $this->query($connection, $peer, $mode, $this->ok());
            $this->assertGreaterThanOrEqual($started, $this->tick($connection));
            $this->assertTrue($connection->beforeUse());
            $this->assertTrue($connection->intervalCheck());
            $this->assertNoCommand($peer);
        });
    }

    #[DataProvider('queryModes')]
    public function testQueryErrorsClearRecentActivityAndRequireAProbe(string $mode): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer) use ($mode): void {
            $this->setTick($connection, hrtime(true));
            try {
                $this->query($connection, $peer, $mode, $this->error());
                $this->fail('Expected the server query error');
            } catch (QueryException $error) {
                $this->assertSame(1064, $error->getCode());
            }
            // 预处理执行失败后仍会关闭语句；读取这些命令以清空测试端数据。
            $this->drainQuery($peer, $mode);
            $this->assertNull($this->tick($connection));
            $this->reply($peer, $this->ok());
            $this->assertTrue($connection->beforeUse());
            $this->assertSame("\x03select 1", $this->receive($peer));
        });
    }

    public function testFailedProbeDoesNotLeaveARecentActivityMarker(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $this->setTick($connection, hrtime(true) - 4_000_000_000);
            $this->reply($peer, $this->error());
            $this->assertFalse($connection->beforeUse());
            $this->assertSame("\x03select 1", $this->receive($peer));
            $this->assertNull($this->tick($connection));
            $this->reply($peer, $this->ok());
            $this->assertTrue($connection->intervalCheck());
            $this->assertSame("\x03select 1", $this->receive($peer));
        });
    }

    public function testKnownDisconnectedProtocolIsRejectedDespiteRecentActivity(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer, ProtocolConnection $protocol): void {
            $this->setTick($connection, hrtime(true));
            $protocol->close();
            $this->assertFalse($connection->beforeUse());
            $this->assertFalse($connection->intervalCheck());
            $this->assertNull($this->tick($connection));
        });
    }

    public function testCloseClearsActivityBeforeAnotherProtocolCanBeAttached(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $this->setTick($connection, hrtime(true));
            $connection->close();
            $this->assertNull($this->tick($connection));
            $this->assertFalse($connection->beforeUse());
        });
    }

    public function testCallingConnectOnAnExistingSessionDoesNotRenewActivity(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $expired = hrtime(true) - 4_000_000_000;
            $this->setTick($connection, $expired);
            $this->assertTrue($connection->connect());
            $this->assertSame($expired, $this->tick($connection));
            $this->reply($peer, $this->ok());
            $this->assertTrue($connection->beforeUse());
            $this->assertSame("\x03select 1", $this->receive($peer));
        });
    }

    public function testTransactionCommandsRefreshActivityAndKeepTransactionState(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $this->setTick($connection, hrtime(true) - 4_000_000_000);
            $this->reply($peer, $this->ok(1));
            $this->assertTrue($connection->beginTransaction(TransactionStartFlags::None));
            $this->assertSame("\x03START TRANSACTION", $this->receive($peer));
            $this->assertTrue($connection->isInTransaction);
            $this->assertTrue($connection->beforeUse());
            $this->assertNoCommand($peer);

            $this->setTick($connection, hrtime(true) - 4_000_000_000);
            $this->reply($peer, $this->ok());
            $this->assertTrue($connection->rollbackTransaction(TransactionCompletionFlags::NoChainNoRelease));
            $this->assertSame("\x03ROLLBACK AND NO CHAIN NO RELEASE", $this->receive($peer));
            $this->assertFalse($connection->isInTransaction);
            $this->assertTrue($connection->intervalCheck());
            $this->assertNoCommand($peer);
        });
    }

    /** 通过成对 Socket 使用真实协议，并在每次操作前预置响应报文。 */
    private function withConnection(callable $test): void
    {
        $check = function () use ($test): void {
            [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
            $config = new Config(['timeout' => 0.03]);
            $protocol = new ProtocolConnection($config);
            $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol);
            (new \ReflectionProperty($stream, 'socket'))->setValue($stream, $socket);
            $state = (new \ReflectionProperty($protocol, 'state'))->getValue($protocol);
            $state->established();
            $state->applyServerStatus(2);
            $connection = new Connection($config);
            (new \ReflectionProperty(Client::class, 'mysqlClient'))->setValue($connection, $protocol);
            try { $test($connection, $peer, $protocol); }
            finally { $connection->close(); $peer->close(); }
        };
        if (Coroutine::getCid() < 0) { Coroutine\run($check); }
        else { $check(); }
    }

    private function query(Connection $connection, Socket $peer, string $mode, string $result): void
    {
        if ($mode === 'prepared') {
            // COM_STMT_PREPARE 成功响应：语句编号为 7，没有结果列和参数。
            $this->reply($peer, "\0" . pack('VvvCv', 7, 0, 0, 0, 0));
        }
        $this->reply($peer, $result);
        if ($mode === 'raw') { $connection->rawQuery('DO 1'); }
        else { $builder = new QueryBuilder(); $connection->query($builder->raw('DO 1')); }
        $this->drainQuery($peer, $mode);
    }

    private function drainQuery(Socket $peer, string $mode): void
    {
        if ($mode === 'raw') { $this->assertSame("\x03DO 1", $this->receive($peer)); return; }
        $this->assertSame("\x16DO 1", $this->receive($peer));
        $this->assertSame(0x17, ord($this->receive($peer)[0]));
        $this->assertSame("\x19" . pack('V', 7), $this->receive($peer));
    }

    private function tick(Connection $connection): int|float|null
    {
        return (new \ReflectionProperty(Connection::class, 'lastActiveTick'))->getValue($connection);
    }

    private function setTick(Connection $connection, int|float $tick): void
    {
        (new \ReflectionProperty(Connection::class, 'lastActiveTick'))->setValue($connection, $tick);
    }

    private function ok(int $status = 2): string { return "\0\0\0" . pack('v', $status) . "\0\0"; }
    private function error(): string { return "\xff" . pack('v', 1064) . '#42000test query error'; }

    private function reply(Socket $peer, string $payload): void
    {
        $packet = substr(pack('V', strlen($payload)), 0, 3) . "\x01" . $payload;
        $this->assertSame(strlen($packet), $peer->sendAll($packet, 0.1));
    }

    private function receive(Socket $peer): string
    {
        $header = $peer->recvAll(4, 0.1);
        $this->assertIsString($header);
        $this->assertSame(4, strlen($header));
        $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
        $payload = $peer->recvAll($length, 0.1);
        $this->assertIsString($payload);
        $this->assertSame($length, strlen($payload));
        return $payload;
    }

    private function assertNoCommand(Socket $peer): void
    {
        $this->assertFalse($peer->recv(1, 0.001), 'A recently active connection must not send a health probe');
        $this->assertSame(SOCKET_ETIMEDOUT, $peer->errCode);
    }
}
