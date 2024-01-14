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

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Tests\Model\Address;
use EasySwoole\FastDb\Tests\Model\SexEnum;
use EasySwoole\FastDb\Tests\Model\StudentInfoModel;
use EasySwoole\FastDb\Tests\Model\StudentModel;
use EasySwoole\FastDb\Tests\Model\User;
use EasySwoole\Mysqli\QueryBuilder;

final class ModelTest extends BaseTestCase
{
    protected $tableName = 'student';

    protected function setUp(): void
    {
        parent::setUp();
        $configObj = new Config(MYSQL_CONFIG);
        FastDb::getInstance()->addDb($configObj);
        FastDb::getInstance()->setOnQuery(function (QueryResult $queryResult) {
            if ($queryResult->getQueryBuilder()) {
                echo $queryResult->getQueryBuilder()->getLastQuery() . "\n";
            } else {
                echo $queryResult->getRawSql() . "\n";
            }
        });
    }

    private function find(array $where): ?StudentModel
    {
        return StudentModel::findRecord($where);
    }

    private function checkRowExists(array $where): StudentModel
    {
        $student = $this->find($where);
        $this->assertNotNull($student);
        $this->assertInstanceOf(StudentModel::class, $student);
        return $student;
    }

    public function testTableName()
    {
        $this->assertSame($this->tableName, (new StudentModel())->tableName());
    }

    public function testInsert(): AbstractEntity
    {
        // truncate
        $this->truncateTable($this->tableName);
        $model = new StudentModel();
        $model->id = 1;
        $model->name = 'EasySwoole1';
        $result = $model->insert();
        $this->assertTrue($result);
        $this->assertSame(1, $model->id);

        $existModel = $this->checkRowExists(['id' => 1]);
        $this->assertSame(1, $existModel->id);
        $this->assertSame('EasySwoole1', $existModel->name);

        return $model;
    }

    public function testInsert1()
    {
        $this->truncateTable($this->tableName);
        $model = new StudentModel();
        $model->setData([
            'id'   => 1,
            'name' => 'easyswoole1',
        ]);
        $result = $model->insert();
        $this->assertTrue($result);
        $existModel = $this->checkRowExists(['id' => 1]);
        $this->assertSame(1, $existModel->id);
        $this->assertSame('easyswoole1', $existModel->name);

        $this->truncateTable($this->tableName);
        $model = new StudentModel([
            'id'   => 1,
            'name' => 'easyswoole1',
        ]);
        $result = $model->insert();
        $this->assertTrue($result);
        $existModel = $this->checkRowExists(['id' => 1]);
        $this->assertSame(1, $existModel->id);
        $this->assertSame('easyswoole1', $existModel->name);

        // ON DUPLICATE KEY UPDATE
        $anotherModel = new StudentModel();
        $anotherModel->name = 'easyswoole100';
        $updateDuplicateCols = ['name'];
        $result = $anotherModel->insert($updateDuplicateCols);
        $this->assertTrue($result);

        $anotherModel->name = 'easyswoole100';
        $updateDuplicateCols = ['name', 'id' => 0];
        $result = $anotherModel->insert($updateDuplicateCols);
        $this->assertTrue($result);
    }

    public function testInsertAll()
    {
        $model = new User();

        $this->truncateTable($model->tableName());
        $list = [
            ['name' => 'easyswoole1', 'email' => 'easyswoole1@qq.com'],
            ['name' => 'easyswoole2', 'email' => 'easyswoole2@qq.com']
        ];
        $model->insertAll($list);
        $replace = [
            ['id' => 1, 'name' => 'easyswoole_1', 'email' => 'easyswoole1@qq.com'],
            ['id' => 2, 'name' => 'easyswoole_2', 'email' => 'easyswoole2@qq.com']
        ];
        $users = $model->insertAll($replace);
        $this->assertIsArray($users);
        $this->assertEquals(2, count($users));
        foreach ($users as $user) {
            $this->assertInstanceOf(User::class, $user);
            $this->assertIsInt($user->id);
            $this->assertStringStartsWith('easyswoole_', $user->name);
            $this->assertStringStartsWith('easyswoole', $user->email);
        }

        $this->truncateTable($model->tableName());
        $list = [
            ['name' => 'easyswoole-1', 'email' => 'easyswoole1@qq.com'],
            ['name' => 'easyswoole-2', 'email' => 'easyswoole2@qq.com']
        ];
        $users = $model->insertAll($list);
        $this->assertIsArray($users);
        $this->assertEquals(2, count($users));
        foreach ($users as $user) {
            $this->assertInstanceOf(User::class, $user);
            $this->assertIsInt($user->id);
            $this->assertStringStartsWith('easyswoole-', $user->name);
            $this->assertStringStartsWith('easyswoole', $user->email);
        }

        $this->truncateTable($model->tableName());
        $list = [
            ['name' => 'easyswoole-1', 'email' => 'easyswoole1@qq.com'],
            ['name' => 'easyswoole-2', 'email' => 'easyswoole2@qq.com']
        ];
        $model->queryLimit()->fields(null, true);
        $users = $model->insertAll($list);
        $this->assertIsArray($users);
        $this->assertEquals(2, count($users));
        foreach ($users as $user) {
            $this->assertIsArray($user);
            $this->assertIsInt($user['id']);
            $this->assertStringStartsWith('easyswoole-', $user['name']);
            $this->assertStringStartsWith('easyswoole', $user['email']);
        }

        // not replace
        $this->truncateTable($model->tableName());
        $list = [
            ['id' => 1, 'name' => 'easyswoole-1', 'email' => 'easyswoole1@qq.com'],
            ['id' => 2, 'name' => 'easyswoole-2', 'email' => 'easyswoole2@qq.com']
        ];
        $users = $model->insertAll($list, false);
        $this->assertIsArray($users);
        $this->assertEquals(2, count($users));
        foreach ($users as $user) {
            $this->assertInstanceOf(User::class, $user);
            $this->assertIsInt($user->id);
            $this->assertStringStartsWith('easyswoole-', $user->name);
            $this->assertStringStartsWith('easyswoole', $user->email);
        }

        // not transaction
        $this->truncateTable($model->tableName());
        $list = [
            ['id' => 1, 'name' => 'easyswoole-1', 'email' => 'easyswoole1@qq.com'],
            ['id' => 2, 'name' => 'easyswoole-2', 'email' => 'easyswoole2@qq.com']
        ];
        $users = $model->insertAll($list, false, false);
        $this->assertIsArray($users);
        $this->assertEquals(2, count($users));
        foreach ($users as $user) {
            $this->assertInstanceOf(User::class, $user);
            $this->assertIsInt($user->id);
            $this->assertStringStartsWith('easyswoole-', $user->name);
            $this->assertStringStartsWith('easyswoole', $user->email);
        }
    }

    public function testUpdate(): void
    {
        $model = $this->testInsert();

        // Update an existing record
        // eg.1
        $updateName = 'EasySwoole11';
        $model->name = $updateName;
        $result = $model->update();
        $this->assertTrue($result);

        $existModel = $this->checkRowExists(['id' => 1]);
        $this->assertSame($updateName, $existModel->name);

        // eg.2
        $updateName = 'EasySwoole111';
        $whereModel = new StudentModel();
        $whereModel->id = 1;
        $whereModel->name = $updateName;
        $result = $whereModel->update();
        $this->assertTrue($result);

        $existModel = $this->checkRowExists(['id' => 1]);
        $this->assertSame($updateName, $existModel->name);

        // eg.3
        $student = StudentModel::findRecord(1);
        $student->name = 'easyswoole.eg3';
        $result = $student->update();
        $this->assertTrue($result);

        // Update a record that does not exist
        $model = new StudentModel(['id' => 999]);
        // or
        // $model->id = 999;
        $model->name = 'EasySwoole999';
        $result = $model->update();
        $this->assertFalse($result);
    }

    public function testUpdateWithLimit()
    {
        $id = 1;
        $model = $this->testInsert();
        $result = $model->updateWithLimit([
            'name' => 'easyswoole1_update',
        ], ['id' => $id]);
        $this->assertSame(1, $result);

        $result = $model->updateWithLimit([
            'name' => 'easyswoole1_update1',
        ], function (Query $query) {
            $query->where('id', 1);
        });
        $this->assertSame(1, $result);

        // Update a record that does not exist
        $result = $model->updateWithLimit([
            'name' => 'easyswoole1_update',
        ], ['id' => 999]);
        $this->assertSame(0, $result);
    }

    public function testFastUpdate(): void
    {
        $id = 1;
        $this->testInsert();

        // 1. with data in array format as update conditions
        $arrayUpdateWhere = ['id' => $id];
        $update = ['name' => 'EasySwoole666'];
        $result = StudentModel::fastUpdate($arrayUpdateWhere, $update);
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // check update result
        $student = $this->checkRowExists(['id' => 1]);
        $this->assertSame($update['name'], $student->name);

        // 2. with callable as update conditions
        $callableUpdateWhere = function (QueryBuilder $queryBuilder) use ($id) {
            $queryBuilder->where('id', $id);
        };
        $update = ['name' => 'EasySwoole777'];
        $result = StudentModel::fastUpdate($callableUpdateWhere, $update);
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // check update result
        $student = $this->checkRowExists(['id' => 1]);
        $this->assertSame($update['name'], $student->name);

        // 3. with primary key id
        $this->testInsert();
        $primaryKeyId = 1;
        $update = ['name' => 'EasySwoole777'];
        $result = StudentModel::fastUpdate($primaryKeyId, $update);
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // check update result
        $student = $this->checkRowExists(['id' => 1]);
        $this->assertSame($update['name'], $student->name);

        // 4. with the given table name
        $this->testInsert();
        $primaryKeyIdStr = '1';
        $result = StudentModel::fastDelete($primaryKeyIdStr, 'student');
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // check exists
        $student = $this->find(['id' => 1]);
        $this->assertNull($student);
    }

    public function testDelete(): void
    {
        // eg.1 Delete an existing record
        $student = $this->find(['id' => 1]);
        if (is_null($student)) {
            $student = $this->testInsert();
        }
        $this->assertNotNull($student);
        $this->assertInstanceOf(StudentModel::class, $student);

        // eg.1.1
        $whereModel = new StudentModel();
        $whereModel->id = 1;
        $result = $whereModel->delete();
        $this->assertTrue($result);
        $student = $this->find(['id' => 1]);
        $this->assertNull($student);

        $this->testInsert();
        $model = StudentModel::findRecord(1);
        $result = $model->delete();
        $this->assertTrue($result);
        $student = $this->find(['id' => 1]);
        $this->assertNull($student);

        // eg.1.2
        // first insert
        $model = $this->testInsert();
        // then delete
        $result = $model->delete();
        $this->assertTrue($result);

        $student = $this->find(['id' => 1]);
        $this->assertNull($student);

        // eg.2 Delete a record that does not exist
        $model = new StudentModel(['id' => 999]);
        // or
        // $model->id = 999;
        $result = $model->delete();
        $this->assertFalse($result);
    }

    public function testFastDelete(): void
    {
        $id = 1;

        // 1. with array
        $this->testInsert();
        $arrayWhere = ['id' => $id];
        $result = StudentModel::fastDelete($arrayWhere);
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // check exists
        $student = $this->find(['id' => 1]);
        $this->assertNull($student);

        // 2. with callable
        $this->testInsert();
        $callableWhere = function (QueryBuilder $queryBuilder) use ($id) {
            $queryBuilder->where('id', $id);
        };
        $result = StudentModel::fastDelete($callableWhere);
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // check exists
        $student = $this->find(['id' => 1]);
        $this->assertNull($student);

        // 3. with primary key id
        $this->testInsert();
        $primaryKeyId = 1;
        $result = StudentModel::fastDelete($primaryKeyId);
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // check exists
        $student = $this->find(['id' => 1]);
        $this->assertNull($student);

        // 4. with the given table name
        $this->testInsert();
        $primaryKeyIdStr = '1';
        $result = StudentModel::fastDelete($primaryKeyIdStr, 'student');
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // check exists
        $student = $this->find(['id' => 1]);
        $this->assertNull($student);

        $this->testInsert();
        $primaryKeyIdStr = '1,2';
        $result = StudentModel::fastDelete($primaryKeyIdStr, 'student');
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // check exists
        $student = $this->find(['id' => 1]);
        $this->assertNull($student);

        // Delete a record that does not exist
        $arrayWhere = ['id' => 888];
        $result = StudentModel::fastDelete($arrayWhere);
        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }

    public function testFindRecord()
    {
        $this->testInsert();
        // 1. with primary key as query conditions
        $student = StudentModel::findRecord(1);
        $this->assertInstanceOf(StudentModel::class, $student);
        $this->assertSame(1, $student->id);
        $this->assertSame('EasySwoole1', $student->name);

        // 2. with array as query conditions
        $student = StudentModel::findRecord(['name' => 'EasySwoole1']);
        $this->assertInstanceOf(StudentModel::class, $student);
        $this->assertSame(1, $student->id);
        $this->assertSame('EasySwoole1', $student->name);

        // 3. with callable as query conditions
        $student = StudentModel::findRecord(function (\EasySwoole\Mysqli\QueryBuilder $query) {
            $query->where('name', 'EasySwoole1');
        });
        $this->assertInstanceOf(StudentModel::class, $student);
        $this->assertSame(1, $student->id);
        $this->assertSame('EasySwoole1', $student->name);

        // Query a record that does not exist
        $arrayWhere = ['name' => 'EasySwoole888'];
        $result = StudentModel::findRecord($arrayWhere);
        $this->assertNull($result);
    }

    public function testFind()
    {
        $this->testInsert();
        $studentModel = new StudentModel();
        $studentModel->queryLimit()->where('name', 'EasySwoole1');
        $result = $studentModel->find();
        $this->assertInstanceOf(StudentModel::class, $result);
        $this->assertSame(1, $result->id);
        $this->assertSame('EasySwoole1', $result->name);

        // Query a record that does not exist
        $studentModel = new StudentModel();
        $studentModel->queryLimit()->where('name', 'EasySwoole888');
        $result = $studentModel->find();
        $this->assertNull($result);
    }

    public function testFindAll()
    {
        $this->testInsert();
        (new StudentModel())->insertAll([['id' => 2, 'name' => 'EasySwoole2']], false);
        // 1. with primary key str as query conditions
        $list = StudentModel::findAll('1,2', null, true);
        $this->assertIsArray($list);
        $this->assertEquals(2, count($list));
        foreach ($list as $student) {
            $this->assertIsArray($student);
            $this->assertStringStartsWith('EasySwoole', $student['name']);
        }

        // 2. with array as query conditions
        $list = StudentModel::findAll(['name' => 'EasySwoole1'], null, true);
        $this->assertIsArray($list);
        $this->assertEquals(1, count($list));
        foreach ($list as $student) {
            $this->assertIsArray($student);
            $this->assertSame('EasySwoole1', $student['name']);
        }

        // 3. with callable as query conditions
        $list = StudentModel::findAll(function (\EasySwoole\Mysqli\QueryBuilder $query) {
            $query->where('name', 'EasySwoole1')->limit(3)->orderBy('id', 'asc');
        });
        $this->assertIsArray($list);
        $this->assertEquals(1, count($list));
        foreach ($list as $student) {
            $this->assertInstanceOf(StudentModel::class, $student);
            $this->assertSame('EasySwoole1', $student->name);
        }

        // Query not exist records
        $list = StudentModel::findAll(['name' => 'easyswoole666']);
        $this->assertIsArray($list);
        $this->assertEmpty($list);
    }

    public function testAll()
    {
        $this->truncateTable($this->tableName);

        // get empty list
        $model = new StudentModel();
        $listResult = $model->all();
        $this->assertInstanceOf(ListResult::class, $listResult);
        $this->assertIsArray($listResult->list());
        $this->assertIsArray($listResult->toArray());
        $this->assertSame(0, $listResult->count());
        $this->assertNull($listResult->totalCount());
        $this->assertEmpty($listResult->list());
        $this->assertEmpty($listResult->toArray());

        // ready data
        $this->testInsert();
        $model = new StudentModel();
        $model->id = 2;
        $model->name = 'EasySwoole2';
        $result = $model->insert();
        $this->assertTrue($result);

        // get one
        $model = new StudentModel();
        $model->queryLimit()->where('id', 1);
        $listResult = $model->all();
        $this->assertSame(1, $listResult->count());
        $student = $listResult->first();
        $this->assertNotNull($student);
        $this->assertInstanceOf(StudentModel::class, $student);
        $this->assertSame($student->id, 1);

        // get all
        $model = new StudentModel();
        $listResult = $model->all();
        $this->assertSame(2, $listResult->count());
        $list = $listResult->list();
        foreach ($list as $item) {
            $this->assertNotNull($item);
            $this->assertInstanceOf(StudentModel::class, $student);
        }
    }

    public function testToArray()
    {
        $model = $this->testInsert();
        $array = $model->toArray();
        $this->assertIsArray($array);
        $this->assertSame(1, $array['id']);
        $this->assertSame('EasySwoole1', $array['name']);
    }

    public function testConvertField()
    {
        $studentInfoModel = new StudentInfoModel();
        $this->truncateTable($studentInfoModel->tableName());

        $address = new Address([
            'province' => 'FuJian',
            'city'     => 'XiaMen'
        ]);
        $sex     = SexEnum::MALE;
        $model   = new StudentInfoModel([
            'studentId' => 1,
            'address'   => $address->toValue(),
            'sex'       => $sex->toValue(),
            'note'      => 'this is note',
        ]);
        $result  = $model->insert();
        $this->assertSame(1, $model->id);
        $this->assertTrue($result);

        $studentInfo = StudentInfoModel::findRecord(1);
        $this->assertSame('FuJian', $studentInfo->address->province);
        $this->assertSame('XiaMen', $studentInfo->address->city);
        $this->assertSame(SexEnum::MALE, $studentInfo->sex);

        $this->truncateTable($studentInfoModel->tableName());
    }

    private function mockUserData(int $count = 20)
    {
        $user = new User();
        $this->truncateTable($user->tableName());
        $inserts = [];
        $ids = range(1, $count);
        foreach ($ids as $num) {
            $inserts[] = ['name' => 'easyswoole' . $num, 'status' => 0];
        }
        $user->insertAll($inserts);
    }

    public function testChunk()
    {
        $this->mockUserData();

        $user = new User();
        $user->chunk(function (User $user) {
            // 处理 user 模型对象
            $user->updateWithLimit(['status' => 1]);
        }, 1);

        // check the result after chunk
        $list = User::findAll();
        foreach ($list as $model) {
            $this->assertSame(1, $model->status);
        }

        $this->truncateTable($user->tableName());
    }

    public function testPage()
    {
        $this->mockUserData(25);

        $user = new User();
        $user->queryLimit()->page(1, false, 10);
        $resultObject = $user->all();
        foreach ($resultObject as $oneUser) {
            $this->assertStringStartsWith('easyswoole', $oneUser->name);
        }
        $this->assertNull($resultObject->totalCount());

        $user = new User();
        $user->queryLimit()->page(1, true, 10);
        $resultObject = $user->all();
        foreach ($resultObject as $user) {
            $this->assertStringStartsWith('easyswoole', $oneUser->name);
        }
        $this->assertSame(25, $resultObject->totalCount());

        $this->truncateTable($user->tableName());
    }

    public function testCount()
    {
        $total = 20;
        $this->mockUserData($total);

        $user = new User();
        $count = $user->count();
        $this->assertSame($total, $count);

        $count = $user->count('id', 'name');
        $this->assertSame(1, $count);

        $user->queryLimit()->fields(['id', 'name']);
        $result = $user->count(null, 'name');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame(1, $result['id']);
        $this->assertSame(1, $result['name']);

        $this->truncateTable($user->tableName());
    }

    public function testMax()
    {
        $total = 20;
        $this->mockUserData($total);

        $user = new User();
        $result = $user->max('id');
        $this->assertSame((float)$total, $result);

        $result = $user->max('id', 'name');
        $this->assertSame((float)1, $result);

        $result = $user->max(['id', 'name'], 'name');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame(1.0, $result['id']);
        $this->assertSame(0.0, $result['name']);

        $this->truncateTable($user->tableName());
    }

    public function testMin()
    {
        $total = 20;
        $this->mockUserData($total);

        $user = new User();
        $result = $user->min('id');
        $this->assertSame(1.0, $result);

        $result = $user->min('id', 'name');
        $this->assertSame(1.0, $result);

        $result = $user->min(['id', 'name'], 'name');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame(1.0, $result['id']);
        $this->assertSame(0.0, $result['name']);

        $this->truncateTable($user->tableName());
    }

    public function testAvg()
    {
        $total = 20;
        $this->mockUserData($total);

        $user = new User();
        $result = $user->avg('id');
        $this->assertSame(10.5, $result);

        $result = $user->avg('id', 'name');
        $this->assertSame(1.0, $result);

        $result = $user->avg(['id', 'name'], 'name');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame(1.0, $result['id']);
        $this->assertSame(0.0, $result['name']);

        $this->truncateTable($user->tableName());
    }

    public function testSum()
    {
        $total = 20;
        $this->mockUserData($total);

        $user = new User();
        $result = $user->sum('id');
        $this->assertSame(210.0, $result);

        $result = $user->sum('id', 'name');
        $this->assertSame(1.0, $result);

        $result = $user->sum(['id', 'name'], 'name');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame(1.0, $result['id']);
        $this->assertSame(0.0, $result['name']);

        $this->truncateTable($user->tableName());
    }

    public function testQueryLimit()
    {
        $model = new StudentModel();
        $queryLimit = $model->queryLimit();
        $this->assertNotNull($queryLimit);
        $this->assertInstanceOf(Query::class, $queryLimit);
    }

    public function testJsonSerialize()
    {
        $this->testInsert();
        $student = StudentModel::findRecord(['id' => 1]);
        $json = '{"id":1,"name":"EasySwoole1"}';
        $this->assertSame($json, json_encode($student));
    }
}
