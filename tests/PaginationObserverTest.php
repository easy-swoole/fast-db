<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class PaginationObserverTest extends TestCase
{
    public function testTotalIsPrefetchedOnTheMainClientBeforeGlobalReentryAndBuilderMutation(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $events = [];
            $db->configureQueryStack(true);
            $page = $this->page();
            $sql = $page->getLastPrepareQuery();
            $db->setOnQuery(function (QueryResult $result) use ($db, $page, &$events): void {
                if ($result->getRawSql() === null) {
                    $events[] = 'global-main';
                    $this->assertSame(37, $result->getTotalCount());
                    $this->assertSame(1, $result->getAffectedRows());
                    $page->raw('SELECT changed_by_observer');
                    $db->rawQuery('SELECT 1 AS probe');
                } else {
                    $events[] = $result->getRawSql() === 'SELECT FOUND_ROWS() as count' ? 'global-count' : 'global-probe';
                }
            });
            $this->queuePage($peer);
            $this->textRows($peer, 'count', 37);
            $this->textRows($peer, 'probe', 1);
            $result = $db->query($page, onQuery: static function (QueryResult $result) use (&$events): void {
                $events[] = $result->getRawSql() === null ? 'local-main' : 'local-count';
            });
            $this->assertSame([['id' => 7]], $result->getResult());
            $this->assertSame($sql, $result->getLastPrepareQuery());
            $this->assertSame(37, $result->getTotalCount());
            $this->assertSame(['global-main', 'global-probe', 'global-count', 'local-main'], $events);
            $this->assertSame([null, 'SELECT FOUND_ROWS() as count', 'SELECT 1 AS probe'], array_column($db->getQueryStack(), 'rawQuery'));
            $commands = $this->commands($peer, 5);
            $this->assertSame("\x03SELECT FOUND_ROWS() as count", $commands[3]);
            $this->assertSame("\x03SELECT 1 AS probe", $commands[4]);
            $this->assertNextQuerySucceeds($db, $peer);
        });
    }

    public function testCompletionReentryPreservesResultAndRecordedPageSql(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $db->configureQueryStack(true);
            $page = $this->page();
            $sql = $page->getLastPrepareQuery();
            $this->queuePage($peer);
            $this->textRows($peer, 'count', 37);
            $this->textRows($peer, 'probe', 1);

            $result = $db->query($page, onComplete: static function (QueryResult $result) use ($db, $page): void {
                $page->raw('SELECT changed_by_completion');
                $db->rawQuery('SELECT 1 AS probe');
            });

            $stack = $db->getQueryStack();
            $this->assertSame([null, 'SELECT FOUND_ROWS() as count', 'SELECT 1 AS probe'], array_column($stack, 'rawQuery'));
            $this->assertSame($sql, $stack[0]->getLastPrepareQuery());
            $this->assertSame($sql, $result->getLastPrepareQuery());
        });
    }

    public function testGlobalObserverCannotChangeRecordingOfTheAlreadyExecutedPage(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $db->setOnQuery(static function () use ($db): void { $db->configureQueryStack(true); });
            $this->queuePage($peer); $this->textRows($peer, 'count', 37);
            $db->query($this->page());
            $this->assertNull($db->getQueryStack());
            $db->setOnQuery(static function () use ($db): void { $db->configureQueryStack(false); });
            $this->queuePage($peer); $this->textRows($peer, 'count', 37);
            $db->query($this->page());
            $this->assertSame([null, 'SELECT FOUND_ROWS() as count'], array_column($db->getQueryStack(), 'rawQuery'));
            $this->assertNextQuerySucceeds($db, $peer);
            $this->assertCount(2, $db->getQueryStack());
        });
    }

    public function testCountUsesTheGlobalObserverRegisteredByTheMainObserver(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $events = [];
            $db->setOnQuery(static function (QueryResult $result) use ($db, &$events): void {
                $events[] = 'original-main';
                $db->setOnQuery(static function (QueryResult $result) use (&$events): void {
                    $events[] = $result->getRawSql() === 'SELECT FOUND_ROWS() as count' ? 'replacement-count' : 'replacement-other';
                });
            });
            $this->queuePage($peer); $this->textRows($peer, 'count', 37);
            $result = $db->query($this->page());
            $this->assertSame(37, $result->getTotalCount());
            $this->assertSame(['original-main', 'replacement-count'], $events);
            $this->assertNextQuerySucceeds($db, $peer);
        });
    }

    public function testMainGlobalObserverFailureSkipsCountNotificationWithoutChangingSuccessfulSql(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('main global observer'); $global = [];
            $db->configureQueryStack(true);
            $db->setOnQuery(static function (QueryResult $result) use ($failure, &$global): void { $global[] = $result; throw $failure; });
            $this->queuePage($peer); $this->textRows($peer, 'count', 37);
            try { $db->query($this->page()); $this->fail('Expected observer failure'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertCount(1, $global);
            $this->assertSame([['id' => 7]], $global[0]->getResult());
            $this->assertNull($global[0]->getException());
            $this->assertSame(37, $global[0]->getTotalCount());
            $this->assertSame([null, 'SELECT FOUND_ROWS() as count'], array_column($db->getQueryStack(), 'rawQuery'));
            $this->assertNull($db->getQueryStack(-1)->exception);
            $this->assertNextQuerySucceeds($db, $peer);
        });
    }

    public function testCountGlobalObserverFailureDoesNotBecomeASqlErrorOrLeakIntoTheNextQuery(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('count global observer'); $global = [];
            $db->setOnQuery(static function (QueryResult $result) use ($failure, &$global): void {
                $global[] = $result;
                if ($result->getRawSql() === 'SELECT FOUND_ROWS() as count') { throw $failure; }
            });
            $this->queuePage($peer); $this->textRows($peer, 'count', 37);
            try { $db->query($this->page()); $this->fail('Expected count observer failure'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertCount(2, $global);
            $this->assertSame([['id' => 7]], $global[0]->getResult());
            $this->assertSame([['count' => 37]], $global[1]->getResult());
            $this->assertNull($global[1]->getException());
            $this->assertSame(37, $global[0]->getTotalCount());
            $this->reply($peer, "\0\0\0\x02\0\0\0", 1);
            $this->assertTrue($db->rawQuery('DO 1')->getResult());
            $this->assertNull($global[2]->getException());
        });
    }

    public function testCountSqlFailureStillNotifiesTheGlobalMainBeforeTheCount(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $events = []; $global = [];
            $db->setOnQuery(static function (QueryResult $result) use (&$events, &$global): void {
                $events[] = $result->getRawSql() === null ? 'global-main' : 'global-count'; $global[] = $result;
            });
            $this->queuePage($peer); $this->reply($peer, "\xff" . pack('v', 1064) . '#42000count failed', 1);
            try { $db->query($this->page()); $this->fail('Expected count SQL error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame(1064, $error->getCode()); $original = $error; }
            $this->assertSame(['global-main', 'global-count'], $events);
            $this->assertSame([['id' => 7]], $global[0]->getResult());
            $this->assertNull($global[0]->getException());
            $this->assertSame($original, $global[1]->getException());
            $this->assertNull($global[1]->getResult());
            $this->assertNull($global[0]->getTotalCount());
            $this->assertNextQuerySucceeds($db, $peer);
        });
    }

    public function testGlobalObserverFailureDoesNotReplaceThePrefetchedCountSqlError(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('global observer failure'); $global = [];
            $db->configureQueryStack(true);
            $db->setOnQuery(static function (QueryResult $result) use ($failure, &$global): void { $global[] = $result; throw $failure; });
            $this->queuePage($peer); $this->reply($peer, "\xff" . pack('v', 1064) . '#42000count failed', 1);
            try { $db->query($this->page()); $this->fail('Expected the original count SQL error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame(1064, $error->getCode()); $this->assertSame($error, $db->getQueryStack(-1)->exception); }
            $this->assertCount(1, $global);
            $this->assertNull($global[0]->getException());
            $this->assertNextQuerySucceeds($db, $peer);
        });
    }

    public function testCustomConnectionsUseTheExplicitTimeoutForBothQueries(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->onlyMethods(['query', 'rawQuery'])->getMock();
        $connection->connectionName = 'default';
        $query = $this->page();
        $connection->expects($this->once())->method('query')->with($query, 0.2)->willReturn([['id' => 7]]);
        $connection->expects($this->once())->method('rawQuery')->with('SELECT FOUND_ROWS() as count', 0.2)->willReturn([['count' => 37]]);
        $db = new FastDb(); $events = [];
        $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
        $current->setValue($db, [Coroutine::getCid() => ['default' => $connection]]);
        $db->setOnQuery(static function (QueryResult $result) use (&$events): void { $events[] = $result->getRawSql() === null ? 'main' : 'count'; });
        try {
            $result = $db->query($query, timeout: 0.2);
            $this->assertSame(37, $result->getTotalCount());
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
    private function assertNextQuerySucceeds(FastDb $db, Socket $peer): void
    {
        $db->setOnQuery(static function (): void {});
        $this->reply($peer, "\0\0\0\x02\0\0\0", 1);
        $next = $db->rawQuery('DO 1');
        $this->assertTrue($next->getResult());
        $this->assertNull($next->getException());
    }
}
