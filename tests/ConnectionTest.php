<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    private function connection(\mysqli $mysql): Connection
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['mysqlClient'])
            ->getMock();
        $connection->method('mysqlClient')->willReturn($mysql);
        return $connection;
    }

    public function testRestoreAllowsStartingANewTransaction(): void
    {
        $mysql = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['rollback', 'begin_transaction'])
            ->getMock();
        $mysql->expects($this->once())->method('rollback')->willReturn(true);
        $mysql->expects($this->once())->method('begin_transaction')->willReturn(true);
        $connection = $this->connection($mysql);
        $connection->isInTransaction = true;

        $connection->objectRestore();

        $this->assertFalse($connection->isInTransaction);
        $this->assertTrue((new FastDb())->begin($connection));
        $this->assertTrue($connection->isInTransaction);
    }

    public function testForceRollbackRemainsEnabledAcrossRestores(): void
    {
        $mysql = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()->onlyMethods(['rollback'])->getMock();
        $mysql->expects($this->exactly(2))->method('rollback')->willReturn(true);
        $connection = $this->connection($mysql);
        $connection->isForceRollback = true;
        $connection->isInTransaction = true;

        $connection->objectRestore();
        $connection->objectRestore();

        $this->assertFalse($connection->isInTransaction);
        $this->assertTrue($connection->isForceRollback);
    }

    public function testFailedRollbackRejectsRestore(): void
    {
        $mysql = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()->onlyMethods(['rollback'])->getMock();
        $mysql->expects($this->once())->method('rollback')->willReturn(false);
        $connection = $this->connection($mysql);
        $connection->isInTransaction = true;

        $this->expectException(RuntimeError::class);
        $connection->objectRestore();
    }

    public function testRollbackExceptionIsPropagated(): void
    {
        $mysql = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()->onlyMethods(['rollback'])->getMock();
        $mysql->expects($this->once())->method('rollback')
            ->willThrowException(new \RuntimeException('rollback failed'));
        $connection = $this->connection($mysql);
        $connection->isInTransaction = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback failed');
        $connection->objectRestore();
    }
}
