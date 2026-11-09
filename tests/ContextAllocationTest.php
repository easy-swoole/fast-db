<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\TransactionContext;
use EasySwoole\Mysqli\Config as DriverConfig;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class ContextAllocationTest extends TestCase
{
    public function testPlainQueriesPreserveRegistrationWithoutCreatingAToken(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->onlyMethods(['rawQuery'])->getMock();
        $connection->connectionName = 'default'; $connection->expects($this->exactly(2))->method('rawQuery')->willReturn([['value' => 1]]);
        $db = new FastDb(); $cid = Coroutine::getCid();
        $this->set($db, 'currentConnection', [$cid => ['default' => $connection]]);
        try {
            $db->rawQuery('SELECT 1'); $context = $db->getTransactionContext();
            $db->rawQuery('SELECT 1');
            $this->assertSame($context, $db->getTransactionContext());
            $this->assertNull($this->tokenValue($context));
            $this->assertSame($context, $this->get($db, 'transactionContexts')[$cid]['default']);
            $this->assertArrayHasKey($cid, $this->get($db, 'contextCleanupRegistered'));
        } finally { $this->set($db, 'currentConnection', []); }
    }

    public function testTokensAreStableWithinAGenerationAndRetainTheirOriginalOutcome(): void
    {
        $connection = $this->connection(); $context = $connection->getTransactionContext();
        $this->assertNull($this->tokenValue($context));
        $version = $context->version(); $context->finish(true);
        $this->assertSame($version + 1, $context->version());
        $this->assertNull($this->tokenValue($context));
        $first = $context->token(); $this->assertSame($first, $context->token());
        $context->finish(true); $this->assertNull($this->tokenValue($context));
        $second = $context->token(); $this->assertNotSame($first, $second);
        $context->finish(false);
        $this->assertFalse($first->wasRolledBack());
        $this->assertTrue($second->wasRolledBack());
        $this->assertNull($this->tokenValue($context));
        $this->assertNotSame($second, $context->token());
    }

    public function testBaselineRollbackWorksWithoutRequestingAToken(): void
    {
        $connection = $this->connection(); $connection->isInTransaction = true;
        $context = $connection->getTransactionContext(); $entity = (object) ['value' => 7];
        $context->rememberBaseline($entity, ['value' => 0], static function ($entity, $baseline): void { $entity->value = $baseline['value']; });
        $this->assertNull($this->tokenValue($context));
        $connection->finishTransaction(false);
        $this->assertSame(0, $entity->value);
        $this->assertNull($this->tokenValue($context));
        $this->assertFalse($connection->isInTransaction);
    }

    public function testMatchingRegistrationStillCreatesMissingCleanupAndAllowsOwnershipUpgrade(): void
    {
        $cid = Coroutine::getCid(); $connection = $this->connection(); $context = $connection->getTransactionContext();
        $db = (new FastDb())->addDb(new Config());
        $this->set($db, 'transactionContexts', [$cid => ['default' => $context]]);
        $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['getObj', 'recycleObj'])->getMock();
        $pool->expects($this->once())->method('getObj')->willReturn($connection);
        $pool->expects($this->once())->method('recycleObj')->with($connection)->willReturn(true);
        $this->set($db, 'pools', ['default' => $pool]);
        $this->assertSame($context, $db->getTransactionContext($connection));
        $this->assertSame(false, $this->get($db, 'contextCleanupRegistered')[$cid]);
        $this->assertSame($context, $db->getTransactionContext($connection));
        $db->invoke(function (Connection $borrowed) use ($db, $cid, $context): void {
            $this->assertSame($context, $db->getTransactionContext($borrowed));
            $this->assertSame(true, $this->get($db, 'contextCleanupRegistered')[$cid]);
        });
        $this->assertArrayNotHasKey('default', $this->get($db, 'transactionContexts')[$cid]);
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

    public function testSameNameReplacementConnectionReplacesTheProtectedRegistration(): void
    {
        $db = new FastDb(); $first = $db->getTransactionContext($this->connection());
        $secondConnection = $this->connection(); $second = $db->getTransactionContext($secondConnection);
        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->get($db, 'transactionContexts')[Coroutine::getCid()]['default']);
        $secondConnection->objectRestore();
        $this->assertNotSame($second, $db->getTransactionContext($secondConnection));
    }

    private function connection(): Connection { $connection = new Connection(new DriverConfig()); $connection->connectionName = 'default'; return $connection; }
    private function tokenValue(TransactionContext $context): mixed { return (new \ReflectionProperty(TransactionContext::class, 'token'))->getValue($context); }
    private function set(FastDb $db, string $name, array $value): void { (new \ReflectionProperty(FastDb::class, $name))->setValue($db, $value); }
    private function get(FastDb $db, string $name): array { return (new \ReflectionProperty(FastDb::class, $name))->getValue($db); }
}
