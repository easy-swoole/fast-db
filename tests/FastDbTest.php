<?php
declare(strict_types=1);
/**
 * This file is part of EasySwoole.
 *
 * @link     https://www.easyswoole.com
 * @document https://www.easyswoole.com
 * @contact  https://www.easyswoole.com/Preface/contact.html
 * @license  https://github.com/easy-swoole/easyswoole/blob/3.x/LICENSE
 */

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\QueryBuilder;

final class FastDbTest extends BaseTestCase
{
    public const ERROR_CONFIG = [
        'host'              => '127.0.0.1',
        'port'              => 3306,
        'user'              => 'error',
        'password'          => 'error',
        'database'          => 'error',
        'timeout'           => 5,
        'charset'           => 'utf8mb4',
        'autoPing'          => 5,
        'name'              => self::ERROR_NAME,
        'useMysqli'         => USE_MYSQLI,
        'intervalCheckTime' => 10 * 1000,
        'maxIdleTime'       => 15,
        'maxObjectNum'      => 20,
        'minObjectNum'      => 5,
        'getObjectTimeout'  => 3.0,
        'loadAverageTime'   => 0.001,
    ];
    public const ERROR_NAME = 'error';

    private function getFastDb(string $name = 'default'): FastDb
    {
        $fastDb = new FastDb();
        if ($name === self::ERROR_NAME) {
            $config = new Config(self::ERROR_CONFIG);
        } else {
            $config = new Config(MYSQL_CONFIG);
        }
        $config->setName($name);
        $fastDb->addDb($config);
        // debug
//        $fastDb->setOnQuery(function (QueryResult $queryResult) {
//            if ($queryResult->getQueryBuilder()) {
//                echo $queryResult->getQueryBuilder()->getLastQuery() . "\n";
//            } else {
//                echo $queryResult->getRawSql() . "\n";
//            }
//        });
        return $fastDb;
    }

    public function testAddDb()
    {
        $fastDb = $this->getFastDb();
        $this->assertInstanceOf(FastDb::class, $fastDb);
    }

    public function testTestDb()
    {
        $fastDb = $this->getFastDb();
        $testResult = $fastDb->testDb();
        $this->assertSame(true, $testResult);

        try {
            $connectionName = "noAddConnectionName";
            $fastDb->testDb($connectionName);
        } catch (\Throwable $throwable) {
            $this->assertInstanceOf(RuntimeError::class, $throwable);
            $this->assertSame("connection {$connectionName} no register yet", $throwable->getMessage());
        }

        try {
            $this->getFastDb(self::ERROR_NAME)->testDb(self::ERROR_NAME);
        } catch (\Throwable $throwable) {
            if (USE_MYSQLI) {
                $this->assertInstanceOf(\mysqli_sql_exception::class, $throwable);
                $this->assertSame("Access denied for user 'error'@'localhost' (using password: YES)", $throwable->getMessage());
            } else {
                $this->assertInstanceOf(RuntimeError::class, $throwable);
                $this->assertSame("SQLSTATE[28000] [1045] Access denied for user 'error'@'localhost' (using password: YES)", $throwable->getMessage());
            }
        }
    }

    public function testSelectConnection()
    {
        $name = 'foo';
        $config = new Config(MYSQL_CONFIG);
        $config->setName($name);
        $fastDb = (new FastDb())->addDb($config);
        $fastDb->selectConnection($name);
        $selectName = $fastDb->selectConnection();
        $this->assertSame($name, $selectName);
        $fastDb->reset();
    }

    public function testInvoke()
    {
        $fastDb = $this->getFastDb();
        $builder = new QueryBuilder();
        $builder->raw("select 1 as result");
        $result = $fastDb->invoke(function (Connection $connection) use ($builder) {
            return $connection->query($builder);
        });
        $this->assertSame(1, $result[0]['result']);
    }

//    public function testRecycleContext()
//    {
//        $fastDb = $this->getFastDb();
//
//        try {
//            $fastDb->begin();
//            $fastDb->recycleContext();
//            $result = $fastDb->currentConnection();
//            $this->assertNull($result);
//            // business
//            $this->mockTransactionBusiness($fastDb);
//            $fastDb->commit();
//        } catch (\Throwable $throwable) {
//            $fastDb->rollback();
//            throw $throwable;
//        }
//    }

    private function mockTransactionBusiness($fastDb)
    {
        $table = 'easyswoole_user';
        $fastDb->rawQuery("truncate {$table}");
        $builder = new QueryBuilder();
        $insert = [
            'id'   => 1,
            'name' => 'easyswoole1'
        ];
        $builder->insert($table, $insert);
        $fastDb->query($builder);
        $builder = new QueryBuilder();
        $builder->where('id', 1)->delete($table);
        $fastDb->query($builder);
    }

    public function testBegin()
    {
        $fastDb = $this->getFastDb();

        // not invoke client
        try {
            $result = $fastDb->begin();
            $this->assertSame(true, $result);
            // business
            $this->mockTransactionBusiness($fastDb);
            $fastDb->commit();
        } catch (\Throwable $throwable) {
            $fastDb->rollback();
            throw $throwable;
        }

        // not invoke client begin multiple times
        try {
            $fastDb->begin();
            $result = $fastDb->begin();
            $this->assertSame(true, $result);
            // business
            $this->mockTransactionBusiness($fastDb);
            $fastDb->commit();
        } catch (\Throwable $throwable) {
            $fastDb->rollback();
            throw $throwable;
        }

        // invoke client
        $fastDb->invoke(function (Connection $client) use ($fastDb) {
            try {
                $result = $fastDb->begin($client);
                $this->assertSame(true, $result);
                // business
                $this->mockTransactionBusiness($fastDb);
                $fastDb->commit($client);
            } catch (\Throwable $throwable) {
                $fastDb->rollback($client);
                throw $throwable;
            }
        });

        // invoke client with begin multiple times
        $fastDb->invoke(function (Connection $client) use ($fastDb) {
            try {
                $fastDb->begin($client);
                $result = $fastDb->begin($client);
                $this->assertSame(true, $result);
                // business
                $this->mockTransactionBusiness($fastDb);
                $fastDb->commit($client);
            } catch (\Throwable $throwable) {
                $fastDb->rollback($client);
                throw $throwable;
            }
        });

        // error pool
        $errorFastDb = $this->getFastDb(self::ERROR_NAME)->selectConnection(self::ERROR_NAME);
        try {
            $errorFastDb->begin();
            $errorFastDb->commit();
        } catch (\Throwable $throwable) {
            $this->assertInstanceOf(RuntimeError::class, $throwable);
            if (USE_MYSQLI) {
                $this->assertSame("connection error error case initObject fail after 3 times case Access denied for user 'error'@'localhost' (using password: YES)", $throwable->getMessage());
            } else {
                $this->assertSame("connection error error case initObject fail after 3 times case connection [error@127.0.0.1]  connect error: SQLSTATE[28000] [1045] Access denied for user 'error'@'localhost' (using password: YES)", $throwable->getMessage());
            }
            $errorFastDb->rollback();
        }
    }

    public function testCommit()
    {
        $fastDb = $this->getFastDb();

        // not invoke client
        try {
            $fastDb->begin();
            // business
            $this->mockTransactionBusiness($fastDb);
            $result = $fastDb->commit();
            $this->assertSame(true, $result);
        } catch (\Throwable $throwable) {
            $fastDb->rollback();
            throw $throwable;
        }

        // invoke client
        $fastDb->invoke(function (Connection $client) use ($fastDb) {
            try {
                $fastDb->begin($client);
                // business
                $this->mockTransactionBusiness($fastDb);
                $result = $fastDb->commit($client);
                $this->assertSame(true, $result);
            } catch (\Throwable $throwable) {
                $fastDb->rollback($client);
                throw $throwable;
            }
        });

        // not in transaction
        $result = $fastDb->commit();
        $this->assertSame(true, $result);
    }

    public function testRollback()
    {
        $fastDb = $this->getFastDb();

        // not invoke client
        $fastDb->begin();
        // business
        $this->mockTransactionBusiness($fastDb);
        $result = $fastDb->rollback();
        $this->assertSame(true, $result);

        // invoke client
        $fastDb->invoke(function (Connection $client) use ($fastDb) {
            $fastDb->begin($client);
            // business
            $this->mockTransactionBusiness($fastDb);
            $result = $fastDb->rollback($client);
            $this->assertSame(true, $result);
        });

        // not in transaction
        $result = $fastDb->rollback();
        $this->assertSame(true, $result);
    }

    public function testQuery()
    {
        $fastDb = $this->getFastDb();

        $sql = "select 1";
        $builder = new QueryBuilder();
        $builder->raw($sql);
        $res = $fastDb->query($builder)->getResultOne();
        $this->assertSame(1, $res[1]);

        $fastDb->rawQuery('truncate easyswoole_user');
        $builder = new QueryBuilder();
        $builder->insertAll('easyswoole_user', [
            ['id' => 1, 'name' => 'easyswoole1'],
            ['id' => 2, 'name' => 'easyswoole2'],
        ]);

        $builder = new QueryBuilder();
        $builder->get('easyswoole_user');
        $res = $fastDb->query($builder)->getResult();
        $this->assertNotNull($res);
        $this->assertIsArray($res);
    }

    public function testRawQuery()
    {
        $sql = "SELECT 1 as res;";
        $result = $this->getFastDb()->rawQuery($sql)->getResultOne();
        $this->assertSame('1', $result['res']);
    }

    public function testCurrentConnection()
    {
        $fastDb = $this->getFastDb();
        $fastDb->rawQuery("show tables");
        $connectionObj = $fastDb->currentConnection();
        $this->assertInstanceOf(Connection::class, $connectionObj);
        $fastDb->reset();

        $fastDb = $this->getFastDb();
        $connectionObj = $fastDb->currentConnection();
        $this->assertNull($connectionObj);
        $fastDb->reset();
    }

    public function testIsInTransaction()
    {
        $fastDb = $this->getFastDb();
        $fastDb->invoke(function (Connection $connection) use ($fastDb) {
            $result = $fastDb->isInTransaction($connection);
            $this->assertSame(false, $result);
        });

        $fastDb->invoke(function (Connection $connection) use ($fastDb) {
            $fastDb->begin($connection);
            $result = $fastDb->isInTransaction($connection);
            $this->assertSame(true, $result);
        });
    }

    public function testGetConfig()
    {
        $configObj = $this->getFastDb()->getConfig('default');
        $this->assertInstanceOf(Config::class, $configObj);
        $config = $configObj->toArray();
        $this->assertIsArray($config);
        $this->assertNotEmpty($config);
        foreach ($config as $k => $v) {
            if (isset(MYSQL_CONFIG[$k])) {
                $this->assertSame(MYSQL_CONFIG[$k], $v);
            }
        }
    }
}
