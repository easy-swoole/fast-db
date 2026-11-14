<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class BulkConnectionTest extends TestCase
{
    private mixed $previousDb;
    private FastDb $db;
    private array $clients = [];
    private array $writes = [];
    private array $visibleAtExecution = [];

    protected function setUp(): void
    {
        $singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->previousDb = $singleton->getValue(); $this->db = new FastDb(); $singleton->setValue(null, $this->db);
        BulkPinnedRecord::$constructions = 0;
        foreach (['default', 'other'] as $name) {
            $client = $this->createStub(BulkPinnedConnection::class); $client->connectionName = $name;
            $client->method('isInTransaction')->willReturnCallback(static fn(): bool => $client->transactionActive);
            $client->method('getLastAffectRows')->willReturnCallback(static fn () => (new \ReflectionProperty(Client::class, 'lastAffectRows'))->getValue($client));
            $client->method('getLastInsertId')->willReturnCallback(static fn () => (new \ReflectionProperty(Client::class, 'lastInsertId'))->getValue($client));
            $client->method('query')->willReturnCallback(function (QueryBuilder $query) use ($client, $name): bool {
                $this->writes[$name][] = $query->getLastBindParams();
                $this->visibleAtExecution[] = $this->db->selectConnection();
                (new \ReflectionProperty(Client::class, 'lastAffectRows'))->setValue($client, count($query->getLastBindParams()));
                (new \ReflectionProperty(Client::class, 'lastInsertId'))->setValue($client, 0);
                return true;
            });
            $this->clients[$name] = $client;
        }
        $this->bind($this->clients);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) { $client->transactionActive = false; }
        $this->db->setOnQuery(static function (): void {}); $this->bind([]);
        (new \ReflectionProperty(FastDb::class, 'instance'))->setValue(null, $this->previousDb);
    }

    public static function methods(): array { return [['insert'], ['upsert']]; }

    #[DataProvider('methods')]
    public function testCallbacksCanSelectAnotherConnectionAndExplicitlyReturnToTheEntryConnection(string $method): void
    {
        $this->clients['default']->transactionActive = true;
        $seen = [];
        $this->db->setOnQuery(function (QueryResult $result) use (&$seen): void {
            $seen[] = [$result->getConnectionName(), $this->db->selectConnection()];
            $this->db->selectConnection(count($seen) === 1 ? 'other' : 'default');
        });
        $this->assertSame(3, $this->write($method, [['id' => 1], ['id' => 2], ['id' => 3]], 'bulk_pinned'));
        $this->assertSame([['default', 'default'], ['default', 'other']], $seen);
        $this->assertSame([[1, 2], [3]], $this->writes['default']);
        $this->assertArrayNotHasKey('other', $this->writes);
        $this->assertSame('default', $this->db->selectConnection());
        $this->assertTrue($this->clients['default']->isInTransaction());
        $this->assertSame(0, BulkPinnedRecord::$constructions);
    }

    #[DataProvider('methods')]
    public function testGeneratorAndCallbackSelectionEffectsRemainVisibleWhileAllWritesStayAtTheEntry(string $method): void
    {
        $producer = []; $callbacks = [];
        $rows = (function () use (&$producer): \Generator {
            $this->db->selectConnection('other'); yield ['id' => 1];
            $producer[] = $this->db->selectConnection(); yield ['id' => 2];
            $producer[] = $this->db->selectConnection(); yield ['id' => 3];
        })();
        $this->db->setOnQuery(function () use (&$callbacks): void {
            $callbacks[] = $this->db->selectConnection();
            if (count($callbacks) === 1) { $this->db->selectConnection('third'); }
        });
        $this->assertSame(3, $this->write($method, $rows));
        $this->assertSame(['other', 'third'], $producer);
        $this->assertSame(['other', 'third'], $callbacks);
        $this->assertSame(['other', 'third'], $this->visibleAtExecution);
        $this->assertSame([[1, 2], [3]], $this->writes['default']);
        $this->assertArrayNotHasKey('other', $this->writes);
        $this->assertSame('third', $this->db->selectConnection());
        $this->assertSame(1, BulkPinnedRecord::$constructions);
    }

    #[DataProvider('methods')]
    public function testModelInitializationCanChooseThePinnedConnectionWithoutOverwritingTheProducerSelection(string $method): void
    {
        $rows = (function (): \Generator { $this->db->selectConnection('producer'); yield ['id' => 1]; yield ['id' => 2]; yield ['id' => 3]; })();
        $this->assertSame(3, $this->write($method, $rows, model: BulkRoutedRecord::class));
        $this->assertSame([[1, 2], [3]], $this->writes['other']);
        $this->assertArrayNotHasKey('default', $this->writes);
        $this->assertSame(['producer', 'producer'], $this->visibleAtExecution);
        $this->assertSame('producer', $this->db->selectConnection());
        $this->assertSame(1, BulkPinnedRecord::$constructions);
    }

    #[DataProvider('methods')]
    public function testPoolFailureReportsThePinnedConnectionAndPreservesErrorCallbackSelection(string $method): void
    {
        $this->bind([]); $this->db->addDb(new Config());
        $failure = new \RuntimeException('pool unavailable');
        $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['getObj'])->getMock();
        $pool->expects($this->once())->method('getObj')->willThrowException($failure);
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($this->db, ['default' => $pool]);
        $rows = (function (): \Generator { $this->db->selectConnection('other'); yield ['id' => 1]; })();
        $observed = null;
        try {
            $this->write($method, $rows, 'bulk_pinned', function (QueryResult $result) use (&$observed): void {
                $observed = $result; $this->assertSame('other', $this->db->selectConnection());
                $this->db->selectConnection('after-failure'); throw new \RuntimeException('entity logger');
            });
            $this->fail('Expected pool error');
        } catch (\EasySwoole\FastDb\Exception\RuntimeError $error) {
            $this->assertSame($failure, $error->getPrevious());
            $this->assertSame($error, $observed->getException());
        }
        $this->assertSame('default', $observed->getConnectionName());
        $this->assertSame('after-failure', $this->db->selectConnection());
        $this->assertSame([], $this->writes);
    }

    #[DataProvider('methods')]
    public function testSqlFailureRestoresVisibilityBeforeBothObserversAndKeepsTheOriginalException(string $method): void
    {
        $failure = new \RuntimeException('SQL rejected');
        $client = $this->createStub(BulkPinnedConnection::class); $client->connectionName = 'default';
        $client->method('query')->willThrowException($failure);
        $this->clients['default'] = $client; $this->bind($this->clients);
        $rows = (function (): \Generator { $this->db->selectConnection('producer'); yield ['id' => 1]; })();
        $seen = [];
        $this->db->setOnQuery(function (QueryResult $result) use (&$seen, $failure): void {
            $this->assertSame($failure, $result->getException());
            $seen[] = ['global', $result->getConnectionName(), $this->db->selectConnection()];
            $this->db->selectConnection('global-selected'); throw new \RuntimeException('global logger');
        });
        try {
            $this->write($method, $rows, 'bulk_pinned', function (QueryResult $result) use (&$seen, $failure): void {
                $this->assertSame($failure, $result->getException());
                $seen[] = ['entity', $result->getConnectionName(), $this->db->selectConnection()];
                $this->db->selectConnection('entity-selected'); throw new \RuntimeException('entity logger');
            });
            $this->fail('Expected SQL error');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame([['global', 'default', 'producer'], ['entity', 'default', 'global-selected']], $seen);
        $this->assertSame('entity-selected', $this->db->selectConnection());
    }

    private function write(string $method, iterable $rows, ?string $table = null, ?callable $onQuery = null, string $model = BulkPinnedRecord::class): int|string
    {
        return $method === 'insert'
            ? $model::fastInsertAll($rows, tableName: $table, onQuery: $onQuery, batchSize: 2)
            : $model::fastUpsert($rows, updateColumns: ['id'], tableName: $table, onQuery: $onQuery, batchSize: 2);
    }
    private function bind(array $connections): void { (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, $connections === [] ? [] : [Coroutine::getCid() => $connections]); }
}

class BulkPinnedRecord extends AbstractEntity
{
    public static int $constructions = 0;
    #[Property]
    #[PrimaryKey]
    public int $id;
    public function __construct(?array $data = null) { self::$constructions++; parent::__construct($data); }
    public function tableName(): string { return 'bulk_pinned'; }
}
class BulkRoutedRecord extends BulkPinnedRecord
{
    public function __construct(?array $data = null) { parent::__construct($data); FastDb::getInstance()->selectConnection('other'); }
}

/** 离线连接桩自行模拟事务状态，不向生产 Connection 写入重复状态。 */
class BulkPinnedConnection extends Connection
{
    public bool $transactionActive = false;
    public function isInTransaction(): bool { return $this->transactionActive; }
}
