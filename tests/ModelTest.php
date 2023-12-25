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
use EasySwoole\FastDb\Tests\Model\StudentModel;
use EasySwoole\Mysqli\QueryBuilder;

final class ModelTest extends BaseTestCase
{
    protected $tableName = 'student';

    protected function setUp(): void
    {
        parent::setUp();
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

        $existModel = $this->checkRowExists(['id' => 1]);
        $this->assertSame(1, $existModel->id);
        $this->assertSame('EasySwoole1', $existModel->name);

        return $model;
    }

    /**
     * @depends testInsert
     */
    public function testUpdate(AbstractEntity $model): void
    {
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

        // Update a record that does not exist
        $model = new StudentModel(['id' => 999]);
        // or
        // $model->id = 999;
        $model->name = 'EasySwoole999';
        $result = $model->update();
        $this->assertFalse($result);
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
        $primaryKeyIdStr = '1';
        $update = ['name' => 'EasySwoole777'];
        $result = StudentModel::fastUpdate($callableUpdateWhere, $update);
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
        $primaryKeyIdStr = '1';
        $result = StudentModel::fastDelete($primaryKeyIdStr);
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

        // Delete a record that does not exist
        $arrayWhere = ['id' => 'EasySwoole888'];
        $result = StudentModel::fastDelete($arrayWhere);
        $this->assertIsInt($result);
        $this->assertSame(0, $result);
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

//    public function testChunk()
//    {
//
//    }

    public function testToArray()
    {
        $model = $this->testInsert();
        $array = $model->toArray();
        $this->assertIsArray($array);
        $this->assertSame(1, $array['id']);
        $this->assertSame('EasySwoole1', $array['name']);
    }

    public function testCount()
    {
        $this->testInsert();

        $model = new StudentModel();
        $result = $model->count();
        $this->assertSame(1, $result);
    }

    public function testSum()
    {
        $this->testInsert();

        $model = new StudentModel();
        $result = $model->sum('id');
        $this->assertSame(1, $result);
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
