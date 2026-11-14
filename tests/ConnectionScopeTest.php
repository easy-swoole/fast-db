<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config as FastDbConfig;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class ConnectionScopeTest extends TestCase
{
    private ?\ReflectionProperty $singleton = null;
    private mixed $originalInstance = null;
    private FastDb $db;
    private array $connections = [];
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('SQLite verifies scope connection routing without MySQL.'); }
        $this->db = new FastDb();
        foreach (['a', 'b', 'c'] as $name) {
            $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $database->exec('CREATE TABLE scope_events (id INTEGER PRIMARY KEY, label TEXT)');
            $database->exec("CREATE TABLE scope_marker AS SELECT '" . $name . "' AS value");
            $connection = new ConnectionScopeSqliteClient($database);
            $connection->connectionName = $name;
            $this->connections[$name] = $connection;
            $this->db->addDb(new FastDbConfig(), $name);
        }
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, [Coroutine::getCid() => $this->connections]);
        (new \ReflectionProperty(FastDb::class, 'selectConnection'))->setValue($this->db, [Coroutine::getCid() => 'a']);
        $this->db->setOnQuery(function (QueryResult $result): void { $this->events[] = $result; });
        $this->singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->originalInstance = $this->singleton->getValue();
        $this->singleton->setValue(null, $this->db);
    }

    protected function tearDown(): void
    {
        if ($this->singleton === null) { return; }
        $this->db->setOnQuery(static function (): void {});
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, []);
        $this->singleton->setValue(null, $this->originalInstance);
    }

    private function marker(): string { return $this->db->rawQuery('SELECT value FROM scope_marker')->getResultOne()['value']; }
    private function labels(string $name): array { return $this->connections[$name]->database->query('SELECT label FROM scope_events ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN); }

    public function testScopeRoutesRealQueriesAndReturnsTheExactCallbackValue(): void
    {
        $value = new \stdClass();
        $actual = $this->db->withConnection('b', function () use ($value): object {
            $this->assertSame('b', $this->db->selectConnection());
            $this->assertSame('b', $this->marker());
            $this->db->rawQuery("INSERT INTO scope_events (label) VALUES ('only-b')");
            return $value;
        });
        $this->assertSame($value, $actual);
        $this->assertSame('a', $this->db->selectConnection());
        $this->assertSame([], $this->labels('a'));
        $this->assertSame(['only-b'], $this->labels('b'));
        $this->assertSame(['b', 'b'], array_map(static fn(QueryResult $event): string => $event->getConnectionName(), $this->events));
        $this->assertFalse($this->connections['b']->database->inTransaction());
    }

    public function testTheCallbackReceivesTheActualFacade(): void
    {
        $this->assertSame('b', $this->db->withConnection('b', function (FastDb $db): string {
            $this->assertSame($this->db, $db);
            return $db->rawQuery('SELECT value FROM scope_marker')->getResultOne()['value'];
        }));
        $this->assertSame('a', $this->db->selectConnection());
    }

    public function testAConnectionScopeDoesNotAcquireOrRecycleAClientByItself(): void
    {
        $contexts = $this->connections;
        unset($contexts['b']);
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, [Coroutine::getCid() => $contexts]);
        $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['getObj', 'recycleObj'])->getMock();
        $pool->expects($this->never())->method('getObj');
        $pool->expects($this->never())->method('recycleObj');
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($this->db, ['b' => $pool]);
        $this->assertSame(42, $this->db->withConnection('b', static fn(): int => 42));
        $this->assertSame('a', $this->db->selectConnection());
        $this->assertArrayNotHasKey('b', (new \ReflectionProperty(FastDb::class, 'currentConnection'))->getValue($this->db)[Coroutine::getCid()]);
    }

    public function testNestedScopesRestoreAtoBtoAWithoutMovingWrites(): void
    {
        $result = $this->db->withConnection('b', function (): array {
            $this->db->rawQuery("INSERT INTO scope_events (label) VALUES ('b-first')");
            $inner = $this->db->withConnection('a', function (): string {
                $this->assertSame('a', $this->marker());
                $this->db->rawQuery("INSERT INTO scope_events (label) VALUES ('a-inner')");
                return 'inner-result';
            });
            $this->assertSame('b', $this->db->selectConnection());
            $this->db->rawQuery("INSERT INTO scope_events (label) VALUES ('b-last')");
            return [$inner, $this->marker()];
        });
        $this->assertSame(['inner-result', 'b'], $result);
        $this->assertSame(['a-inner'], $this->labels('a'));
        $this->assertSame(['b-first', 'b-last'], $this->labels('b'));
        $this->assertSame('a', $this->db->selectConnection());
    }

    public function testCallbackFailureRestoresSelectionAndPreservesTheOriginalException(): void
    {
        $failure = new \RuntimeException('scope callback failed');
        try {
            $this->db->withConnection('b', function () use ($failure): void {
                $this->db->rawQuery("INSERT INTO scope_events (label) VALUES ('committed-b')");
                throw $failure;
            });
            $this->fail('The callback exception must propagate');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame('a', $this->db->selectConnection());
        $this->assertSame(['committed-b'], $this->labels('b'), 'A connection scope does not implicitly start or roll back a transaction.');
        $this->assertSame([], $this->labels('a'));
    }

    public function testCaughtInnerScopeFailureRestoresTheOuterScopeBeforeItContinues(): void
    {
        $failure = new \RuntimeException('inner a failed');
        $this->assertSame('b', $this->db->withConnection('b', function () use ($failure): string {
            try { $this->db->withConnection('a', static function () use ($failure): void { throw $failure; }); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertSame('b', $this->db->selectConnection());
            return $this->marker();
        }));
        $this->assertSame('a', $this->db->selectConnection());
    }

    public function testAnExplicitSelectionInsideTheCallbackDoesNotReplaceTheOuterRestoreTarget(): void
    {
        $this->assertSame('c', $this->db->withConnection('b', function (): string {
            $this->db->selectConnection('c');
            $this->db->rawQuery("INSERT INTO scope_events (label) VALUES ('explicit-c')");
            return $this->marker();
        }));
        $this->assertSame('a', $this->db->selectConnection());
        $this->assertSame([], $this->labels('b'));
        $this->assertSame(['explicit-c'], $this->labels('c'));
    }

    public function testFalseAndNullAreReturnedWithoutChangingTheConnectionScopeContract(): void
    {
        $this->assertFalse($this->db->withConnection('b', static fn(): bool => false));
        $this->assertNull($this->db->withConnection('b', static fn() => null));
        $this->assertSame('a', $this->db->selectConnection());
        $this->assertSame([], $this->events);
    }

    public function testUnregisteredConnectionFailureRestoresSelection(): void
    {
        try {
            $this->db->withConnection('not-registered', function (): void { $this->db->rawQuery('SELECT 1'); });
            $this->fail('A query on an unregistered connection must fail');
        } catch (RuntimeError|\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame('a', $this->db->selectConnection());
        $this->assertSame('a', $this->marker());
    }

    public static function invalidNames(): array { return [[''], ['0']]; }

    #[DataProvider('invalidNames')]
    public function testInvalidScopeNamesFailBeforeInvokingTheCallback(string $name): void
    {
        $calls = 0;
        try { $this->db->withConnection($name, static function () use (&$calls): void { $calls++; }); $this->fail('Invalid name must fail'); }
        catch (RuntimeError $error) { $this->assertStringContainsString('not register yet', $error->getMessage()); }
        $this->assertSame(0, $calls);
        $this->assertSame('a', $this->db->selectConnection());
        $this->assertSame([], $this->events);
    }

    public function testAnAlreadyBorrowedClientCanBeScopedWithoutARegisteredPoolConfig(): void
    {
        $this->connections['c']->database->exec("UPDATE scope_marker SET value='borrowed'");
        $this->connections['c']->connectionName = 'borrowed';
        $contexts = $this->connections;
        $contexts['borrowed'] = $this->connections['c'];
        unset($contexts['c']);
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, [Coroutine::getCid() => $contexts]);
        $this->assertSame('borrowed', $this->db->withConnection('borrowed', function (): string { return $this->marker(); }));
        $this->assertSame('a', $this->db->selectConnection());
    }

    public function testCoroutineScopesDoNotOverwriteOtherCoroutinesSelectedConnections(): void
    {
        $check = function (): void {
            $this->db->selectConnection('a');
            $channel = new Coroutine\Channel(2);
            foreach (['b' => 0.005, 'c' => 0.001] as $name => $delay) {
                Coroutine::create(function () use ($name, $delay, $channel): void {
                    try {
                        $property = new \ReflectionProperty(FastDb::class, 'currentConnection');
                        $contexts = $property->getValue($this->db);
                        $contexts[Coroutine::getCid()] = $this->connections;
                        $property->setValue($this->db, $contexts);
                        $this->db->selectConnection('a');
                        $inside = $this->db->withConnection($name, function () use ($delay): string {
                            Coroutine::sleep($delay);
                            return $this->marker();
                        });
                        $channel->push([$inside, $this->db->selectConnection()]);
                    } catch (\Throwable $error) { $channel->push($error); }
                });
            }
            $results = [$channel->pop(1), $channel->pop(1)];
            foreach ($results as $result) { if ($result instanceof \Throwable) { throw $result; } }
            sort($results);
            $this->assertSame([['b', 'a'], ['c', 'a']], $results);
            $this->assertSame('a', $this->db->selectConnection());
        };
        if (Coroutine::getCid() >= 0) { $check(); }
        else { Coroutine\run($check); }
    }
}

class ConnectionScopeSqliteClient extends Connection
{
    public array $queries = [];
    public function __construct(public \PDO $database) { parent::__construct(new Config()); }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array { return $this->executeSql($builder->getLastPrepareQuery(), $builder->getLastBindParams()); }
    public function rawQuery(string $query, ?float $timeout = null): bool|array { return $this->executeSql($query, []); }
    private function executeSql(string $query, array $parameters): bool|array
    {
        $this->queries[] = $query;
        $statement = $this->database->prepare($query);
        $statement->execute($parameters);
        $this->lastAffectRows = $statement->rowCount(); $this->lastInsertId = 0;
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
}
