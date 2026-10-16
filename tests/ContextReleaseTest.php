<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\Mysqli\Config as DriverConfig;
use EasySwoole\Pool\ObjectInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class ContextReleaseTest extends TestCase
{
    private function db(int $maxConnections = 1): FastDb
    {
        $config = new Config(['maxObjectNum' => $maxConnections, 'minObjectNum' => 0, 'intervalCheckTime' => 0]);
        $db = (new FastDb())->addDb($config);
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, ['default' => new ReleasePool($config)]);
        return $db;
    }

    private function state(FastDb $db, string $name): array
    {
        return (new \ReflectionProperty(FastDb::class, $name))->getValue($db);
    }

    public static function modes(): array { return [['invoke'], ['recycle']]; }

    #[DataProvider('modes')]
    public function testRepeatedBorrowingsReleaseContextsBeforeCoroutineExit(string $mode): void
    {
        $db = $this->db();
        $refs = new \WeakMap();
        try {
            for ($i = 0; $i < 2000; $i++) {
                if ($mode === 'invoke') {
                    $db->invoke(static function (Connection $client) use ($db, $refs): void {
                        $refs[$db->getTransactionContext($client)] = true;
                    });
                } else {
                    $db->rawQuery('SELECT 1');
                    $refs[$db->getTransactionContext()] = true;
                    $db->recycleContext();
                }
            }
            $this->assertCount(0, $refs);
            $this->assertCount(1, $this->state($db, 'contextCleanupRegistered'));
            $pool = $this->state($db, 'pools')['default'];
            $this->assertSame([], (new \ReflectionProperty(\EasySwoole\Pool\AbstractPool::class, 'deferContextObj'))->getValue($pool));
        } finally {
            $db->recycleContext(false);
            $db->reset();
        }
    }

    public function testGuardPreservesActiveBaselineAndForcedRecycleReleasesIt(): void
    {
        $db = $this->db();
        $entity = (object) ['value' => 7];
        try {
            $db->begin();
            $context = $db->getTransactionContext();
            $weak = \WeakReference::create($context);
            $context->rememberBaseline($entity, ['value' => 0], static function ($entity, $baseline): void {
                $entity->value = $baseline['value'];
            });
            unset($context);
            try {
                $db->recycleContext();
                $this->fail('Expected transaction guard');
            } catch (RuntimeError $error) {
                $this->assertStringContainsString('active transaction', $error->getMessage());
            }
            $this->assertNotNull($weak->get());
            $this->assertTrue($db->isInTransaction());
            $this->assertSame(7, $entity->value);
            $db->recycleContext(false);
            $this->assertSame(0, $entity->value);
            $this->assertNull($weak->get());
            $this->assertNull($db->currentConnection());
            $db->begin();
            $this->assertTrue($db->getTransactionContext()->isActive());
            $db->commit();
            $db->recycleContext();
        } finally {
            $db->recycleContext(false);
            $db->reset();
        }
    }

    public function testCommitAndRollbackReleaseBaselinesAcrossBorrowings(): void
    {
        $db = $this->db();
        $refs = new \WeakMap();
        $entity = (object) ['value' => 0];
        try {
            for ($i = 0; $i < 1000; $i++) {
                $db->begin();
                $context = $db->getTransactionContext();
                $refs[$context] = true;
                $context->rememberBaseline($entity, ['value' => 0], static function ($entity, $baseline): void {
                    $entity->value = $baseline['value'];
                });
                $entity->value = 7;
                unset($context);
                if ($i % 2 === 0) {
                    $db->commit();
                    $this->assertSame(7, $entity->value);
                } else {
                    $db->rollback();
                    $this->assertSame(0, $entity->value);
                }
                $db->recycleContext();
            }
            $this->assertCount(0, $refs);
        } finally {
            $db->recycleContext(false);
            $db->reset();
        }
    }

    public function testCoroutineCleanupDoesNotEndAnotherCoroutineTransaction(): void
    {
        $db = $this->db(2);
        $ready = new Coroutine\Channel(2);
        $finished = new Coroutine\Channel(2);
        $release = [new Coroutine\Channel(1), new Coroutine\Channel(1)];
        $entities = [(object) ['value' => 7], (object) ['value' => 7]];
        $weak = [];
        try {
            foreach ([0, 1] as $index) {
                Coroutine::create(static function () use ($db, $index, $ready, $finished, $release, $entities, &$weak): void {
                    Coroutine::defer(static fn () => $finished->push($index));
                    $db->begin();
                    $context = $db->getTransactionContext();
                    $weak[$index] = \WeakReference::create($context);
                    $context->rememberBaseline($entities[$index], ['value' => 0], static function ($entity, $baseline): void {
                        $entity->value = $baseline['value'];
                    });
                    $ready->push($index);
                    $release[$index]->pop(2);
                });
            }
            $this->assertSame(0, $ready->pop(2));
            $this->assertSame(1, $ready->pop(2));
            $this->assertCount(2, $this->state($db, 'transactionContexts'));
            $release[0]->push(true);
            $this->assertSame(0, $finished->pop(2));
            $this->assertSame(0, $entities[0]->value);
            $this->assertNull($weak[0]->get());
            $this->assertSame(7, $entities[1]->value);
            $this->assertTrue($weak[1]->get()->isActive());
            $this->assertCount(1, $this->state($db, 'transactionContexts'));
            $release[1]->push(true);
            $this->assertSame(1, $finished->pop(2));
            $this->assertSame(0, $entities[1]->value);
            $this->assertNull($weak[1]->get());
            $this->assertSame([], $this->state($db, 'contextCleanupRegistered'));
        } finally { $db->reset(); }
    }

    public function testCoroutineExitRollsBackAndRemovesRegistrations(): void
    {
        $db = $this->db();
        $entity = (object) ['value' => 7];
        $finished = new Coroutine\Channel(1);
        $weak = null;
        try {
            Coroutine::create(static function () use ($db, $entity, $finished, &$weak): void {
                // 先登记通知，确保它在 FastDb 的退出清理完成后执行。
                Coroutine::defer(static fn () => $finished->push(true));
                $db->begin();
                $context = $db->getTransactionContext();
                $weak = \WeakReference::create($context);
                $context->rememberBaseline($entity, ['value' => 0], static function ($entity, $baseline): void {
                    $entity->value = $baseline['value'];
                });
            });
            $this->assertTrue($finished->pop(2));
            $this->assertSame(0, $entity->value);
            $this->assertNull($weak->get());
            foreach (['currentConnection', 'transactionContexts', 'contextCleanupRegistered'] as $name) {
                $this->assertSame([], $this->state($db, $name));
            }
        } finally { $db->reset(); }
    }
}

/** 走真实连接池生命周期，不建立网络连接。 */
class ReleaseConnection extends Connection
{
    public function rawQuery(string $query, ?float $timeout = null)
    {
        $this->lastAffectRows = 0;
        $this->lastInsertId = 0;
        return true;
    }
    public function beforeUse(): bool { return true; }
    public function intervalCheck(): bool { return true; }
}

class ReleasePool extends Pool
{
    protected function createObject(): ObjectInterface { return new ReleaseConnection(new DriverConfig()); }
}
