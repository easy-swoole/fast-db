<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\TransactionContext;
use EasySwoole\Mysqli\Config;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class TransactionContextTest extends TestCase
{
    private function connection(string $name): Connection
    {
        $client = new Connection(new Config());
        $client->connectionName = $name;
        return $client;
    }

    private function remember(TransactionContext $context, object $entity, int $value): void
    {
        $context->rememberBaseline($entity, ['value' => $value], static function ($entity, $baseline): void {
            $entity->value = $baseline['value'];
        });
    }

    public function testDifferentNamesAndConnectionInstancesStayIsolated(): void
    {
        $db = new FastDb();
        $a = $this->connection('a');
        $b = $this->connection('b');
        $replacement = $this->connection('a');
        $a->isInTransaction = $b->isInTransaction = $replacement->isInTransaction = true;
        $ca = $db->getTransactionContext($a);
        $cb = $db->getTransactionContext($b);
        $cr = $db->getTransactionContext($replacement);
        $this->assertNotSame($ca, $cb);
        $this->assertNotSame($ca, $cr);
        $this->assertSame(Coroutine::getCid(), $ca->coroutineId);
        $this->assertSame('a', $ca->connectionName);
        $this->assertTrue($ca->belongsTo($a));
        $x = (object) ['value' => 5];
        $y = (object) ['value' => 6];
        $this->remember($ca, $x, 0);
        $this->remember($ca, $x, 4); // 同一事务内重复登记时，必须保留首次基准。
        $this->remember($cb, $y, 1);
        $a->finishTransaction(false);
        $this->assertSame(0, $x->value);
        $this->assertSame(6, $y->value);
        $this->assertTrue($cb->isActive());
        $b->finishTransaction(true);
        $b->finishTransaction(false);
        $this->assertSame(6, $y->value);
    }

    public function testContextDoesNotLeakAcrossCoroutinesOrBorrowings(): void
    {
        $client = $this->connection('default');
        $db = new FastDb();
        $first = $db->getTransactionContext($client);
        $client->objectRestore();
        $channel = new Coroutine\Channel(1);
        Coroutine::create(static function () use ($client, $db, $channel): void {
            $context = $db->getTransactionContext($client);
            $client->isInTransaction = true;
            $channel->push([$context, Coroutine::getCid()]);
        });
        [$second, $cid] = $channel->pop(1);
        $this->assertNotSame($first, $second);
        $this->assertSame($cid, $second->coroutineId);
        $this->assertNotSame($first->coroutineId, $second->coroutineId);
        $first->finish(false); // 旧上下文不得结束后续借用中的事务。
        $this->assertTrue($second->isActive());
        $client->finishTransaction(true);
        $client->objectRestore();
        $third = $db->getTransactionContext($client);
        $this->assertNotSame($second, $third);
        $this->assertSame(Coroutine::getCid(), $third->coroutineId);
    }

    public function testContextDoesNotKeepEntitiesOrConnectionsAlive(): void
    {
        $client = $this->connection('default');
        $client->isInTransaction = true;
        $context = $client->getTransactionContext();
        $entity = (object) ['value' => 1];
        $ref = \WeakReference::create($entity);
        $connectionRef = \WeakReference::create($client);
        $this->remember($context, $entity, 0);
        unset($entity, $client);
        $this->assertNull($ref->get());
        $this->assertNull($connectionRef->get());
        $this->assertFalse($context->isActive());
        $context->finish(false);
    }
}
