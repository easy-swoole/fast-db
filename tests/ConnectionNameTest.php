<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Tests\Model\User;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class ConnectionNameTest extends TestCase
{
    public static function operations(): array
    {
        $cases = [];
        foreach (['query', 'rawQuery', 'begin', 'commit', 'rollback'] as $operation) {
            foreach ([false, true] as $fails) {
                $cases[$operation . ($fails ? ' failure' : ' success')] = [$operation, $fails];
            }
        }
        return $cases;
    }

    #[DataProvider('operations')]
    public function testResultAndStackUseActualConnectionDespiteSelectionChange(string $operation, bool $fails): void
    {
        $client = $this->createStub(Connection::class);
        $client->connectionName = 'actual';
        $client->isInTransaction = in_array($operation, ['commit', 'rollback'], true);
        $db = (new FastDb())->addDb(new Config(), 'other')->isEnableQueryStack(true);
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($db,
            [Coroutine::getCid() => ['default' => $client]]);
        $failure = new \RuntimeException('SQL error');
        $method = in_array($operation, ['begin', 'commit', 'rollback'], true) ? $operation . 'Transaction' : $operation;
        $client->method($method)->willReturnCallback(static function () use ($db, $fails, $failure): bool {
            $db->selectConnection('other');
            if ($fails) { throw $failure; }
            return true;
        });
        $logged = null;
        $db->setOnQuery(static function (QueryResult $result) use (&$logged, $db): void {
            $logged = $result;
            $db->selectConnection('other');
        });
        try {
            if ($operation === 'query') {
                $result = $db->query(static function (QueryBuilder $query) use ($db): void {
                    // 构建器回调已切换选中连接，SQL 仍由进入 query 时取得的连接执行。
                    $db->selectConnection('other');
                    $query->raw('SELECT 1');
                });
            } elseif ($operation === 'rawQuery') {
                $result = $db->rawQuery('SELECT 1');
            } else {
                $result = $db->$operation($client);
            }
            $this->assertFalse($fails);
        } catch (\RuntimeException $error) {
            $this->assertTrue($fails);
            $this->assertSame($failure, $error);
        }
        $this->assertInstanceOf(QueryResult::class, $logged);
        $this->assertSame('actual', $logged->getConnectionName());
        $this->assertSame('actual', $db->getQueryStack(-1)->connectionName);
        $this->assertSame('other', $db->selectConnection());
        if ($operation === 'query' || $operation === 'rawQuery') {
            if (!$fails) { $this->assertSame($logged, $result); }
        }
    }

    public function testEntityFailureCallbackKeepsOriginatingConnectionName(): void
    {
        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $db = (new FastDb())->addDb(new Config(), 'other')->isEnableQueryStack(true);
        $client = $this->createStub(Connection::class);
        $client->connectionName = 'actual';
        $failure = new \RuntimeException('original SQL failure');
        $client->method('query')->willThrowException($failure);
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($db,
            [Coroutine::getCid() => ['default' => $client]]);
        $instance->setValue(null, $db);
        $entityResult = null;
        try {
            $db->setOnQuery(static function () use ($db): void {
                $db->selectConnection('other');
                throw new \RuntimeException('logger failure');
            });
            $user = new User();
            $user->setOnQuery(static function (QueryResult $result) use (&$entityResult): void { $entityResult = $result; });
            try {
                $user->all();
                $this->fail('Expected SQL failure');
            } catch (\RuntimeException $error) {
                $this->assertSame($failure, $error);
            }
            $this->assertSame('actual', $entityResult->getConnectionName());
            $this->assertSame('actual', $db->getQueryStack(-1)->connectionName);
            $this->assertSame($failure, $entityResult->getException());
        } finally { $instance->setValue(null, $original); }
    }
}
