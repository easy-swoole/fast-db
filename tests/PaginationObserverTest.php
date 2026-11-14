<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class PaginationObserverTest extends TestCase
{
    public function testFastDbExecutesOnlyTheSuppliedSqlEvenWithTheTotalOption(): void
    {
        $this->withConnection(function (FastDb $db, Socket $peer): void {
            $events = [];
            $db->setOnQuery(static function (QueryResult $result) use (&$events): void { $events[] = $result; });
            $builder = (new QueryBuilder())->withTotalCount()->get('sample', 1, ['id']);
            $this->queuePage($peer);

            $result = $db->query($builder);

            $this->assertSame([['id' => 7]], $result->getResult());
            $this->assertSame([$result], $events);
            $this->assertCount(1, $db->getQueryStack());
            $this->assertStringContainsString('SQL_CALC_FOUND_ROWS', $this->commands($peer, 3)[0]);
            $this->assertNextQuerySucceeds($db, $peer);
            $this->assertSame("\x03DO 1", $this->commands($peer, 1)[0]);
        });
    }

    public function testEntityReadsTotalOnTheSameSessionAfterNotifyingTheMainQuery(): void
    {
        $this->withConnection(function (FastDb $db, Socket $peer): void {
            $events = [];
            $db->setOnQuery(function (QueryResult $result) use ($db, &$events): void {
                $isCount = $result->getRawSql() !== null;
                $events[] = $isCount ? 'global-count' : 'global-main';
                $this->assertCount($isCount ? 2 : 1, $db->getQueryStack());
                $this->assertSame('reporting', $result->getConnectionName());
            });
            $entity = $this->page();
            $entity->setOnQuery(static function () use (&$events): void { $events[] = 'entity-main'; });
            $this->queuePage($peer);
            $this->textRows($peer, 'count', 37);

            $list = $entity->all();

            $this->assertSame([['id' => 7]], $list->list());
            $this->assertSame(37, $list->totalCount());
            $this->assertSame(['global-main', 'entity-main', 'global-count'], $events);
            $this->assertSame([null, 'SELECT FOUND_ROWS() as count'], array_column($db->getQueryStack(), 'rawQuery'));
            $this->assertSame("\x03SELECT FOUND_ROWS() as count", $this->commands($peer, 4)[3]);
        }, 'reporting');
    }

    public static function failingObservers(): array { return [['global'], ['entity']]; }

    #[DataProvider('failingObservers')]
    public function testMainObserverFailureStopsBeforeCountSql(string $observer): void
    {
        $this->withConnection(function (FastDb $db, Socket $peer) use ($observer): void {
            $failure = new \RuntimeException('main observer failed');
            $callback = static function () use ($failure): void { throw $failure; };
            $entity = $this->page();
            if ($observer === 'global') { $db->setOnQuery($callback); }
            else { $entity->setOnQuery($callback); }
            $this->queuePage($peer);

            try { $entity->all(); $this->fail('Expected observer failure'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }

            $this->assertCount(1, $db->getQueryStack());
            $this->assertNull($db->getQueryStack(-1)->exception);
            $this->assertNextQuerySucceeds($db, $peer);
            $this->assertSame("\x03DO 1", $this->commands($peer, 4)[3]);
        });
    }

    public function testMainSqlFailureStopsBeforeCountSql(): void
    {
        $this->withConnection(function (FastDb $db, Socket $peer): void {
            $this->reply($peer, "\xff" . pack('v', 1064) . '#42000main failed', 1);

            try { $this->page()->all(); $this->fail('Expected main SQL error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) {
                $this->assertSame(1064, $error->getCode());
                $this->assertSame($error, $db->getQueryStack(-1)->exception);
            }

            $this->assertCount(1, $db->getQueryStack());
            $this->assertNextQuerySucceeds($db, $peer);
            $this->assertSame("\x03DO 1", $this->commands($peer, 2)[1]);
        });
    }

    public function testCountObserverFailurePropagatesAfterCountSqlIsRecorded(): void
    {
        $this->withConnection(function (FastDb $db, Socket $peer): void {
            $failure = new \RuntimeException('count observer failed');
            $db->setOnQuery(function (QueryResult $result) use ($failure): void {
                if ($result->getRawSql() === 'SELECT FOUND_ROWS() as count') {
                    $this->assertSame(['count' => 37], $result->getResultOne());
                    throw $failure;
                }
            });
            $this->queuePage($peer);
            $this->textRows($peer, 'count', 37);

            try { $this->page()->all(); $this->fail('Expected observer failure'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }

            $this->assertSame([null, 'SELECT FOUND_ROWS() as count'], array_column($db->getQueryStack(), 'rawQuery'));
            $this->assertNull($db->getQueryStack(-1)->exception);
            $this->assertNextQuerySucceeds($db, $peer);
        });
    }

    public function testCountSqlFailureIsLoggedAndPropagates(): void
    {
        $this->withConnection(function (FastDb $db, Socket $peer): void {
            $events = [];
            $db->setOnQuery(static function (QueryResult $result) use (&$events): void { $events[] = $result; });
            $this->queuePage($peer);
            $this->reply($peer, "\xff" . pack('v', 1064) . '#42000count failed', 1);

            try { $this->page()->all(); $this->fail('Expected count SQL error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) {
                $this->assertSame(1064, $error->getCode());
                $this->assertSame($error, $db->getQueryStack(-1)->exception);
                $this->assertSame($error, $events[1]->getException());
            }

            $this->assertSame([null, 'SELECT FOUND_ROWS() as count'], array_map(static fn(QueryResult $result) => $result->getRawSql(), $events));
            $this->assertSame([['id' => 7]], $events[0]->getResult());
            $this->assertNull($events[0]->getException());
            $this->assertNextQuerySucceeds($db, $peer);
        });
    }

    private function page(): AbstractEntity
    {
        $entity = new class extends AbstractEntity {
            public function tableName(): string { return 'sample'; }
        };
        $entity->queryLimit()->fields(['id'], true)->limit(1, true);
        return $entity;
    }

    private function withConnection(callable $test, string $name = 'default'): void
    {
        $check = function () use ($test, $name): void {
            [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
            $config = new Config(['timeout' => 0.03]); $protocol = new ProtocolConnection($config);
            $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol);
            (new \ReflectionProperty($stream, 'socket'))->setValue($stream, $socket);
            $state = (new \ReflectionProperty($protocol, 'state'))->getValue($protocol); $state->established(); $state->applyServerStatus(2);
            $connection = new Connection($config); $connection->connectionName = $name;
            (new \ReflectionProperty(Client::class, 'mysqlClient'))->setValue($connection, $protocol);
            $db = (new FastDb())->configureQueryStack(true)->selectConnection($name);
            $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
            $current->setValue($db, [Coroutine::getCid() => [$name => $connection]]);
            $instance = new \ReflectionProperty(FastDb::class, 'instance');
            $previous = $instance->getValue();
            $instance->setValue(null, $db);
            try { $test($db, $peer); }
            finally {
                $instance->setValue(null, $previous);
                $current->setValue($db, []);
                $connection->close();
                $peer->close();
            }
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
