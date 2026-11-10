<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\Mysqli\Config as DriverConfig;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class ContextAllocationTest extends TestCase
{
    public function testPlainQueriesReuseTheContextOwnedByTheirActualConnection(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->onlyMethods(['rawQuery'])->getMock();
        $connection->connectionName = 'default'; $connection->expects($this->exactly(2))->method('rawQuery')->willReturn([['value' => 1]]);
        $db = new FastDb(); $cid = Coroutine::getCid();
        $this->set($db, 'currentConnection', [$cid => ['default' => $connection]]);
        try {
            $db->rawQuery('SELECT 1'); $context = $db->getTransactionContext();
            $db->rawQuery('SELECT 1');
            $this->assertSame($context, $db->getTransactionContext());
            $this->assertSame($context, $connection->getTransactionContext());
            $this->assertTrue($context->belongsTo($connection));
            $this->assertFalse($context->isActive());
        } finally { $this->set($db, 'currentConnection', []); }
    }

    public function testTokensAreStableWithinAGenerationAndRetainTheirOriginalOutcome(): void
    {
        $connection = $this->connection(); $context = $connection->getTransactionContext();
        $version = $context->version(); $context->finish(true);
        $this->assertSame($version + 1, $context->version());
        $first = $context->token(); $this->assertSame($first, $context->token());
        $context->finish(true);
        $second = $context->token(); $this->assertNotSame($first, $second);
        $context->finish(false);
        $this->assertFalse($first->wasRolledBack());
        $this->assertTrue($second->wasRolledBack());
        $this->assertNotSame($second, $context->token());
    }

    public function testBaselineRollbackWorksWithoutRequestingAToken(): void
    {
        $connection = $this->connection(); $connection->isInTransaction = true;
        $context = $connection->getTransactionContext(); $entity = (object) ['value' => 7];
        $context->rememberBaseline($entity, ['value' => 0], static function ($entity, $baseline): void { $entity->value = $baseline['value']; });
        $connection->finishTransaction(false);
        $this->assertSame(0, $entity->value);
        $this->assertFalse($connection->isInTransaction);
    }

    public function testExternalContextCanLaterBeBorrowedAndReleasedByTheFacade(): void
    {
        $connection = $this->connection(); $context = $connection->getTransactionContext();
        $db = (new FastDb())->addDb(new Config());
        $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['getObj', 'recycleObj'])->getMock();
        $pool->expects($this->once())->method('getObj')->willReturn($connection);
        $pool->expects($this->once())->method('recycleObj')->with($connection)->willReturnCallback(static function (Connection $client): bool {
            $client->objectRestore();
            return true;
        });
        $this->set($db, 'pools', ['default' => $pool]);
        $this->assertSame($context, $db->getTransactionContext($connection));
        $this->assertSame($context, $db->getTransactionContext($connection));
        $db->invoke(function (Connection $borrowed) use ($db, $context, $connection): void {
            $this->assertSame($connection, $borrowed);
            $this->assertSame($context, $db->getTransactionContext($borrowed));
        });
        $this->assertNull($db->currentConnection());
        $this->assertNotSame($context, $db->getTransactionContext($connection));
    }

    public function testRepeatedRegistrationStillValidatesActiveTransactionOwnership(): void
    {
        $db = new FastDb(); $connection = $this->connection(); $context = $db->getTransactionContext($connection);
        $connection->isInTransaction = true; $channel = new Coroutine\Channel(1);
        Coroutine::create(static function () use ($db, $connection, $channel): void {
            try { $db->getTransactionContext($connection); $channel->push(null); }
            catch (\Throwable $error) { $channel->push($error); }
        });
        $this->assertInstanceOf(RuntimeError::class, $channel->pop(0.5));
        $this->assertSame($context, $db->getTransactionContext($connection));
        $this->assertTrue($context->isActive());
        $connection->finishTransaction(true);
    }

    public function testSameNameConnectionsKeepIndependentContextsAndRestoringOneDoesNotAffectTheOther(): void
    {
        $db = new FastDb(); $firstConnection = $this->connection(); $first = $db->getTransactionContext($firstConnection);
        $secondConnection = $this->connection(); $second = $db->getTransactionContext($secondConnection);
        $this->assertNotSame($first, $second);
        $this->assertSame($first, $db->getTransactionContext($firstConnection));
        $this->assertSame($second, $db->getTransactionContext($secondConnection));
        $secondConnection->objectRestore();
        $this->assertNotSame($second, $db->getTransactionContext($secondConnection));
        $this->assertSame($first, $db->getTransactionContext($firstConnection));
    }

    private function connection(): Connection { $connection = new Connection(new DriverConfig()); $connection->connectionName = 'default'; return $connection; }
    private function set(FastDb $db, string $name, array $value): void { (new \ReflectionProperty(FastDb::class, $name))->setValue($db, $value); }
}
