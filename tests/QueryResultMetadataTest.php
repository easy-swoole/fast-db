<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryResultMetadataTest extends TestCase
{
    public static function modes(): array { return [[true], [false]]; }

    #[DataProvider('modes')]
    public function testMetadataIsCapturedBeforeCallbackRunsAnotherQuery(bool $raw): void
    {
        $affected = 2;
        $insertId = '18446744073709551615';
        $client = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['query', 'rawQuery', 'getLastAffectRows', 'getLastInsertId'])->getMock();
        $method = $raw ? 'rawQuery' : 'query';
        $client->expects($this->exactly(2))->method($method)->willReturnCallback(
            static function () use (&$affected, &$insertId): bool {
                static $count = 0;
                if (++$count === 2) { $affected = 0; $insertId = 0; }
                return true;
            }
        );
        $client->method('getLastAffectRows')->willReturnCallback(static function () use (&$affected) { return $affected; });
        $client->method('getLastInsertId')->willReturnCallback(static function () use (&$insertId) { return $insertId; });
        $db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($db,
            [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $nested = false;
        $execute = static function () use ($db, $raw): QueryResult {
            if ($raw) { return $db->rawQuery('INSERT INTO sample VALUES (1)'); }
            $q = new QueryBuilder(); $q->raw('INSERT INTO sample VALUES (1)'); return $db->query($q);
        };
        $db->setOnQuery(function (QueryResult $result) use (&$nested, $execute): void {
            if (!$nested) {
                $nested = true;
                $execute();
                $this->assertSame(2, $result->getAffectedRows());
                $this->assertSame('18446744073709551615', $result->getInsertId());
            }
        });
        $result = $execute();
        $this->assertSame(0, $affected);
        $this->assertSame(2, $result->getAffectedRows());
        $this->assertSame('18446744073709551615', $result->getInsertId());
        $this->assertFalse(method_exists($result, 'getConnection'));
    }

    public function testUnassignedAndFailedResultsHaveNullMetadata(): void
    {
        $result = new QueryResult(0.0, 0.0, 0.0, 'default');
        $this->assertNull($result->getAffectedRows());
        $this->assertNull($result->getInsertId());
        $result = new QueryResult(0.0, 0.0, 0.0, 'default', affectedRows: '18446744073709551615');
        $this->assertSame('18446744073709551615', $result->getAffectedRows());
    }

    public function testLogGettersKeepOriginalSqlAndBindsWithoutExposingTheBuilder(): void
    {
        $builder = (new QueryBuilder())->noBackslashEscapes()->raw('SELECT ? AS value', ["a'b\\c"]);
        $loggedSql = $builder->getLastQuery();
        $result = new QueryResult(0.0, 0.0, 0.0, 'default', [['value' => 1]], builder: $builder);
        $builder->noBackslashEscapes(false)->raw('SELECT 2');

        $params = $result->getLastBindParams();
        $params[0] = 'changed';
        $rows = $result->getResult();
        $rows[0]['value'] = 9;
        $this->assertSame('SELECT ? AS value', $result->getLastPrepareQuery());
        $this->assertSame(["a'b\\c"], $result->getLastBindParams());
        $this->assertSame($loggedSql, $result->getLastQuery());
        $this->assertSame(['value' => 1], $result->getResultOne());
        $this->assertFalse(method_exists($result, 'getQueryBuilder'));
        $this->assertFalse(method_exists($result, 'setResult'));

        $raw = new QueryResult(0.0, 0.0, 0.0, 'default', rawSql: 'SELECT 3');
        $this->assertSame('SELECT 3', $raw->getLastQuery());
        $this->assertSame('SELECT 3', $raw->getLastPrepareQuery());
        $this->assertSame([], $raw->getLastBindParams());
    }
}
