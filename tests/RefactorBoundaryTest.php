<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Beans\ArrayList;
use EasySwoole\FastDb\Internal\TransactionSql;
use EasySwoole\FastDb\Tests\Model\User;
use EasySwoole\FastDb\Internal\EntityState;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefactorBoundaryTest extends TestCase
{
    public static function transactionSql(): array
    {
        return [['ROLLBACK', false], ['/* trace */ ROLLBACK AND CHAIN', false],
            ["-- trace\nROLLBACK WORK", false], ['/*!80000 ROLLBACK */', false],
            ['ROLLBACK TO SAVEPOINT s', null], ['ROLLBACK WORK TO s', null],
            ['COMMIT AND CHAIN', true], ['START TRANSACTION', true], ['BEGIN', true], ['SELECT 1', null]];
    }

    #[DataProvider('transactionSql')]
    public function testTransactionBoundaryRecognition(string $sql, ?bool $expected): void
    {
        $this->assertSame($expected, TransactionSql::completion($sql));
    }

    public function testSparseCollectionUsesKeysForIdentityRemoval(): void
    {
        $a = new User(['id' => 1]); $b = new User(['id' => 2]); $c = new User(['id' => 3]);
        $list = new ArrayList([$a, $b, $c]); unset($list[0]);
        $this->assertSame($b, $list->first());
        $list->remove($b);
        $this->assertSame([$c], $list->list());
    }

    public function testClonedEntityHasIndependentStateAndQueryOwner(): void
    {
        $a = new User(['id' => 1, 'name' => 'A']); $a->queryLimit()->fields(['name']);
        $b = clone $a;
        $this->assertSame($b, $b->queryLimit()->returnEntity());
        $b->setData(['name' => 'B'], true);
        $this->assertSame('A', $a->name);
        $a->queryLimit()->fields(['id']);
        $this->assertSame(['name'], $b->queryLimit()->getFields()['fields']);
        $property = new \ReflectionProperty(\EasySwoole\FastDb\AbstractInterface\AbstractEntity::class, 'entityState');
        $this->assertNotSame($property->getValue($a), $property->getValue($b));
        $this->assertSame('A', $property->getValue($a)->baseline['name']);
    }

    public function testFailedWriteConsumesQueryOptionsWithoutAcceptingChanges(): void
    {
        $instance = new \ReflectionProperty(\EasySwoole\FastDb\FastDb::class, 'instance');
        $original = $instance->getValue();
        $db = $this->createStub(\EasySwoole\FastDb\FastDb::class);
        $db->method('query')->willThrowException(new \RuntimeException('SQL failure'));
        $instance->setValue(null, $db);
        try {
            $user = new User(['id' => 1, 'name' => 'A']);
            $user->name = 'B';
            $user->queryLimit()->fields(['name']);
            try { $user->update(); $this->fail('Expected SQL failure'); }
            catch (\RuntimeException $error) { $this->assertSame('SQL failure', $error->getMessage()); }
            $this->assertNull($user->queryLimit()->getFields());
            $state = (new \ReflectionProperty(\EasySwoole\FastDb\AbstractInterface\AbstractEntity::class, 'entityState'))->getValue($user);
            $this->assertSame('A', $state->baseline['name']);
            $this->assertSame('B', $user->name);
        } finally { $instance->setValue(null, $original); }
    }

    public function testTransactionTokenDoesNotConfuseLaterRollbackWithEarlierCommit(): void
    {
        $client = new Connection(new Config());
        $context = $client->getTransactionContext();
        $first = $context->token();
        $client->finishTransaction(true);
        $second = $context->token();
        $client->finishTransaction(false);
        $this->assertFalse($first->wasRolledBack());
        $this->assertTrue($second->wasRolledBack());
        $this->assertNotSame($first, $context->token());
    }
}
