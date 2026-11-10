<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class PaginationObserverTest extends TestCase
{
    public function testTotalIsReadBeforeReentryWhileObserversKeepTheirOriginalOrder(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $events = [];
            $connection->onQuery(function ($rows) use ($connection, &$events): void {
                if (isset($rows[0]['id'])) {
                    $events[] = 'low-main';
                    $this->assertSame(1, $connection->getLastAffectRows());
                    $connection->rawQuery('SELECT 1 AS probe');
                } else { $events[] = isset($rows[0]['probe']) ? 'low-probe' : 'low-count'; }
            });
            $db->setOnQuery(static function ($result) use (&$events): void { $events[] = $result->getRawSql() === null ? 'global-main' : 'global-count'; });
            $this->queuePage($peer);
            $this->textRows($peer, 'count', 37);
            $this->textRows($peer, 'probe', 1);
            $result = $db->query($this->page());
            $this->assertSame([['id' => 7]], $result->getResult());
            $this->assertSame(37, $db->getTotalCount($result));
            $this->assertSame(['low-main', 'low-probe', 'low-count', 'global-main', 'global-count'], $events);
            $commands = $this->commands($peer, 5);
            $this->assertSame("\x03SELECT FOUND_ROWS() as count", $commands[3]);
            $this->assertSame("\x03SELECT 1 AS probe", $commands[4]);
            $this->assertScopeCleared($connection);
        });
    }

    public function testCountUsesAnObserverRegisteredByTheMainObserver(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $events = [];
            $connection->onQuery(function ($rows) use ($connection, &$events): void {
                $events[] = 'original';
                $connection->onQuery(static function ($rows) use (&$events): void { $events[] = 'replacement-' . (isset($rows[0]['count']) ? 'count' : 'other'); });
            });
            $this->queuePage($peer); $this->textRows($peer, 'count', 37);
            $result = $db->query($this->page());
            $this->assertSame(37, $db->getTotalCount($result));
            $this->assertSame(['original', 'replacement-count'], $events);
            $this->assertScopeCleared($connection);
        });
    }

    public function testMainObserverFailureDiscardsThePrefetchedCountNotification(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('main observer'); $low = 0; $global = [];
            $connection->onQuery(static function () use ($failure, &$low): void { $low++; throw $failure; });
            $db->setOnQuery(static function ($result) use (&$global): void { $global[] = $result; });
            $this->queuePage($peer); $this->textRows($peer, 'count', 37);
            try { $db->query($this->page()); $this->fail('Expected observer failure'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertSame(1, $low);
            $this->assertCount(1, $global);
            $this->assertNull($db->getTotalCount($global[0]));
            $this->assertScopeCleared($connection);
        });
    }

    public function testCountObserverFailureBelongsToTheCountAndDoesNotLeakIntoAnotherQuery(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('count observer'); $events = []; $global = [];
            $connection->onQuery(static function ($rows) use ($failure, &$events): void {
                $events[] = isset($rows[0]['id']) ? 'main' : 'count';
                if (isset($rows[0]['count'])) { throw $failure; }
            });
            $db->setOnQuery(static function ($result) use (&$global): void { $global[] = $result; });
            $this->queuePage($peer); $this->textRows($peer, 'count', 37);
            try { $db->query($this->page()); $this->fail('Expected count observer failure'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertSame(['main', 'count'], $events);
            $this->assertCount(2, $global);
            $this->assertSame([['id' => 7]], $global[0]->getResult());
            $this->assertSame($failure, $global[1]->getException());
            $this->assertNull($global[1]->getResult());
            $this->assertNull($db->getTotalCount($global[0]));
            $this->assertScopeCleared($connection);
            $connection->onQuery(static function (): void {});
            $this->reply($peer, "\0\0\0\x02\0\0\0", 1);
            $this->assertTrue($db->rawQuery('DO 1')->getResult());
            $this->assertNull($global[2]->getException());
        });
    }

    public function testCountSqlFailureStillNotifiesTheMainObserverFirst(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $events = []; $global = [];
            $connection->onQuery(static function () use (&$events): void { $events[] = 'low-main'; });
            $db->setOnQuery(static function ($result) use (&$events, &$global): void { $events[] = $result->getRawSql() === null ? 'global-main' : 'global-count'; $global[] = $result; });
            $this->queuePage($peer); $this->reply($peer, "\xff" . pack('v', 1064) . '#42000count failed', 1);
            try { $db->query($this->page()); $this->fail('Expected count SQL error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame(1064, $error->getCode()); $original = $error; }
            $this->assertSame(['low-main', 'global-main', 'global-count'], $events);
            $this->assertSame($original, $global[1]->getException());
            $this->assertScopeCleared($connection);
        });
    }

    public function testMainObserverErrorKeepsItsPriorityOverAPrefetchedCountError(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('main observer first'); $global = [];
            $connection->onQuery(static function () use ($failure): void { throw $failure; });
            $db->setOnQuery(static function ($result) use (&$global): void { $global[] = $result; });
            $this->queuePage($peer); $this->reply($peer, "\xff" . pack('v', 1064) . '#42000count failed', 1);
            try { $db->query($this->page()); $this->fail('Expected main observer error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertCount(1, $global);
            $this->assertScopeCleared($connection);
        });
    }

    public function testCustomConnectionsStillFetchTheCountOnce(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->onlyMethods(['query', 'rawQuery'])->getMock();
        $connection->connectionName = 'default';
        $connection->expects($this->once())->method('query')->willReturn([['id' => 7]]);
        $connection->expects($this->once())->method('rawQuery')->with('SELECT FOUND_ROWS() as count')->willReturn([['count' => 37]]);
        $db = new FastDb(); $events = [];
        $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
        $current->setValue($db, [Coroutine::getCid() => ['default' => $connection]]);
        $db->setOnQuery(static function ($result) use (&$events): void { $events[] = $result->getRawSql() === null ? 'main' : 'count'; });
        try {
            $result = $db->query($this->page());
            $this->assertSame(37, $db->getTotalCount($result));
            $this->assertSame(['main', 'count'], $events);
        } finally { $current->setValue($db, []); }
    }

    private function page(): QueryBuilder { return (new QueryBuilder())->setQueryOption('SQL_CALC_FOUND_ROWS')->get('sample', 1, ['id']); }

    private function withConnection(callable $test): void
    {
        $check = function () use ($test): void {
            [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
            $config = new Config(['timeout' => 0.03]); $protocol = new ProtocolConnection($config);
            $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol);
            (new \ReflectionProperty($stream, 'socket'))->setValue($stream, $socket);
            $state = (new \ReflectionProperty($protocol, 'state'))->getValue($protocol); $state->established(); $state->applyServerStatus(2);
            $connection = new Connection($config); $connection->connectionName = 'default';
            (new \ReflectionProperty(Client::class, 'mysqlClient'))->setValue($connection, $protocol);
            $db = new FastDb(); $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
            $current->setValue($db, [Coroutine::getCid() => ['default' => $connection]]);
            try { $test($db, $connection, $peer); }
            finally { $current->setValue($db, []); $connection->close(); $peer->close(); }
        };
        if (Coroutine::getCid() < 0) { Coroutine\run($check); } else { $check(); }
    }

    private function queuePage(Socket $peer): void
    {
        $this->reply($peer, "\0" . pack('VvvCv', 7, 1, 0, 0, 0), 1);
        $this->reply($peer, $this->column('id'), 2); $this->reply($peer, "\xfe\0\0\x02\0", 3);
        $this->reply($peer, "\1", 1); $this->reply($peer, $this->column('id'), 2); $this->reply($peer, "\xfe\0\0\x02\0", 3);
        $this->reply($peer, "\0\0" . pack('P', 7), 4); $this->reply($peer, "\xfe\0\0\x02\0", 5);
    }
    private function textRows(Socket $peer, string $name, int $value): void
    {
        $this->reply($peer, "\1", 1); $this->reply($peer, $this->column($name), 2); $this->reply($peer, "\xfe\0\0\x02\0", 3);
        $value = (string) $value; $this->reply($peer, chr(strlen($value)) . $value, 4); $this->reply($peer, "\xfe\0\0\x02\0", 5);
    }
    private function column(string $name): string
    {
        $packet = ''; foreach (['def', '', '', '', $name, $name] as $part) { $packet .= chr(strlen($part)) . $part; }
        return $packet . "\x0c" . pack('vV', 33, 255) . "\x08" . pack('vC', 0, 0) . "\0\0";
    }
    private function reply(Socket $peer, string $payload, int $sequence): void { $this->assertSame(strlen($payload) + 4, $peer->sendAll(substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload, 0.1)); }
    private function commands(Socket $peer, int $count): array
    {
        $commands = [];
        for ($i = 0; $i < $count; $i++) {
            $header = $peer->recvAll(4, 0.1); $this->assertIsString($header); $this->assertSame(4, strlen($header));
            $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
            $commands[] = $peer->recvAll($length, 0.1);
        }
        return $commands;
    }
    private function assertScopeCleared(Connection $connection): void
    {
        $this->assertNull((new \ReflectionProperty(Connection::class, 'executionFrame'))->getValue($connection));
    }
}
