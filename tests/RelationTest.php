<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Tests\Model\StudentModel;
use EasySwoole\FastDb\Tests\Model\StudentInfoModel;
use EasySwoole\FastDb\Tests\Model\StudentScoreModel;
use EasySwoole\FastDb\Tests\Model\SexEnum;

final class RelationTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = FastDb::getInstance()->addDb(new Config(MYSQL_CONFIG));
        $db->setOnQuery(static function (): void {});
        foreach (['student', 'student_info', 'student_score'] as $table) {
            $this->truncateTable($table);
        }
        $db->rawQuery("INSERT INTO student (id,name) VALUES (1,'one'),(2,'two')");
        $db->rawQuery('INSERT INTO student_info (studentId,address,note,sex) VALUES (1,\'{"city":"厦门"}\',\'original\',1)');
        $db->rawQuery("INSERT INTO student_score (student_id,course_id,score) VALUES (1,1,80),(1,2,90),(2,1,100)");
    }

    protected function tearDown(): void
    {
        try {
            foreach (['student', 'student_info', 'student_score'] as $table) {
                $this->truncateTable($table);
            }
            FastDb::getInstance()->setOnQuery(static function (): void {});
        } finally {
            parent::tearDown();
        }
    }

    public function testOneConvertsFieldsAndTracksOnlyChanges(): void
    {
        $student = new StudentModel(['id' => 1]);
        $info = $student->studentInfo();
        $this->assertInstanceOf(StudentInfoModel::class, $info);
        $this->assertSame('厦门', $info->address->city);
        $this->assertSame(SexEnum::MALE, $info->sex);
        $queries = [];
        FastDb::getInstance()->setOnQuery(static function ($result) use (&$queries): void {
            $queries[] = $result->getQueryBuilder()?->getLastPrepareQuery();
        });
        $this->assertTrue($info->update());
        $this->assertSame([], $queries);
        $info->note = 'changed';
        $this->assertTrue($info->update());
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('`address`', $queries[0]);
        $this->assertSame('changed', StudentInfoModel::findRecord($info->id)->note);
    }

    public function testOneMissingAndDuplicateRecords(): void
    {
        $this->assertNull((new StudentModel(['id' => 2]))->studentInfo());
        FastDb::getInstance()->rawQuery("INSERT INTO student_info (studentId,note) VALUES (1,'duplicate')");
        $this->expectException(RuntimeError::class);
        (new StudentModel(['id' => 1]))->studentInfo();
    }

    public function testManyPaginationConditionsAndReset(): void
    {
        $student = new StudentModel(['id' => 1]);
        $student->queryLimit()->where('score', 85, '>')->orderBy('score', 'DESC')->page(1, true, 1);
        $scores = $student->score();
        $this->assertSame(1, $scores->totalCount());
        $this->assertCount(1, $scores->list());
        $score = $scores->list()[0];
        $this->assertInstanceOf(StudentScoreModel::class, $score);
        $this->assertSame(90, $score->score);
        $this->assertSame(1, $score->student_id);
        $this->assertTrue($score->update());
        $this->assertCount(2, $student->score()->list());
        $this->assertSame([], (new StudentModel(['id' => 99]))->score()->list());
    }

    public function testArrayResultsAndExplicitRelation(): void
    {
        $student = new ExplicitRelationStudent(['id' => 1]);
        $student->queryLimit()->fields(['id', 'note'], true);
        $this->assertSame(['id' => 1, 'note' => 'original'], $student->infoBy(new Relate(StudentInfoModel::class, 'studentId', 'id')));
        $student->queryLimit()->fields(['score'], true)->orderBy('score', 'ASC');
        $this->assertSame([['score' => 80], ['score' => 90]], $student->score()->list());
    }

    public function testInvalidRelationFields(): void
    {
        $student = new ExplicitRelationStudent(['id' => 1]);
        foreach ([new Relate(StudentInfoModel::class, 'missing'), new Relate(StudentInfoModel::class, 'studentId', 'missing')] as $relate) {
            try {
                $student->infoBy($relate);
                $this->fail('Expected invalid relation to fail');
            } catch (RuntimeError $error) {
                $this->assertStringContainsString('missing', $error->getMessage());
            }
        }
    }

    public function testRelationOutputLimitationsAreExplicit(): void
    {
        $student = new StudentModel(['id' => 1]);
        $student->queryLimit()->hideFields('note')->persistFieldLimit();
        $info = $student->studentInfo();
        // 当前关联查询不传播隐藏字段；调用者可在返回实体上显式设置。
        $this->assertArrayHasKey('note', $info->toArray());
        $info->queryLimit()->hideFields('note');
        $this->assertArrayNotHasKey('note', json_decode(json_encode($info), true));
        $student->queryLimit()->hideFields('score')->persistFieldLimit();
        $score = $student->score()->list()[0];
        $this->assertArrayHasKey('score', $score->toArray());
    }

    public function testMissingRelationAttributeFailsBeforeQuery(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('did not define Relate attribute');
        (new ExplicitRelationStudent(['id' => 1]))->withoutAttribute();
    }

    public function testCachedOneAndManyAvoidQueriesAndAreIsolated(): void
    {
        $count = 0;
        FastDb::getInstance()->setOnQuery(static function () use (&$count): void { $count++; });
        $student = new CachedRelationStudent(['id' => 1]);
        $info = $student->studentInfo();
        $this->assertSame($info, $student->studentInfo());
        $scores = $student->score();
        $this->assertSame($scores, $student->score());
        $this->assertSame(2, $count);
        (new CachedRelationStudent(['id' => 1]))->studentInfo();
        $this->assertSame(3, $count);
        $student->id = 2;
        $this->assertNull($student->studentInfo());
        $this->assertSame(4, $count);
        $this->assertSame($student, $student->clearRelationCache());
        $student->id = 1;
        $this->assertNotSame($info, $student->studentInfo());
        $this->assertSame(5, $count);
    }

    public function testNullAndEmptyResultsAreCached(): void
    {
        $count = 0;
        FastDb::getInstance()->setOnQuery(static function () use (&$count): void { $count++; });
        $student = new CachedRelationStudent(['id' => 99]);
        $this->assertNull($student->studentInfo());
        $this->assertNull($student->studentInfo());
        $this->assertSame([], $student->score()->list());
        $this->assertSame([], $student->score()->list());
        $this->assertSame(2, $count);
    }

    public function testCacheIncludesPaginationConditionsAndReturnType(): void
    {
        $count = 0;
        FastDb::getInstance()->setOnQuery(static function () use (&$count): void { $count++; });
        $student = new CachedRelationStudent(['id' => 1]);
        $student->queryLimit()->page(1, true, 1)->orderBy('score', 'ASC');
        $first = $student->score();
        $this->assertSame(2, $first->totalCount());
        $student->queryLimit()->page(1, true, 1)->orderBy('score', 'ASC');
        $this->assertSame($first, $student->score());
        $this->assertSame(2, $count); // SELECT 和 FOUND_ROWS 都被缓存。
        $student->queryLimit()->page(2, true, 1)->orderBy('score', 'ASC');
        $this->assertSame(90, $student->score()->list()[0]->score);
        $this->assertSame(4, $count);
        $student->queryLimit()->where('score', 85, '>');
        $this->assertCount(1, $student->score()->list());
        $this->assertSame(5, $count);
        $this->assertCount(2, $student->score()->list()); // 命中前的条件已清理。
        $this->assertSame(6, $count);
        $student->queryLimit()->returnAsArray();
        $this->assertIsArray($student->studentInfo());
        $this->assertInstanceOf(StudentInfoModel::class, $student->studentInfo());
        $this->assertSame(8, $count);
        $student->queryLimit()->fields(['id', 'note'], true);
        $this->assertSame(['id' => 1, 'note' => 'original'], $student->studentInfo());
        $this->assertSame(9, $count);
    }

    public function testDisabledCacheAndDuplicateFailuresStillQuery(): void
    {
        FastDb::getInstance()->rawQuery("INSERT INTO student_info (studentId,note) VALUES (1,'duplicate')");
        $count = 0;
        FastDb::getInstance()->setOnQuery(static function () use (&$count): void { $count++; });
        $plain = new StudentModel(['id' => 2]);
        $plain->studentInfo();
        $plain->studentInfo();
        $plain->score();
        $plain->score();
        $this->assertSame(4, $count);
        $cached = new CachedRelationStudent(['id' => 1]);
        for ($i = 0; $i < 2; $i++) {
            try {
                $cached->studentInfo();
                $this->fail('Expected duplicate failure');
            } catch (RuntimeError $error) {
                $this->assertStringContainsString('more than one', $error->getMessage());
            }
        }
        $this->assertSame(6, $count);
    }
}

class CachedRelationStudent extends StudentModel
{
    #[Relate(targetEntity: StudentInfoModel::class, targetProperty: 'studentId', cacheResult: true)]
    public function studentInfo()
    {
        return $this->relateOne();
    }

    #[Relate(targetEntity: StudentScoreModel::class, targetProperty: 'student_id', cacheResult: true)]
    public function score()
    {
        return $this->relateMany();
    }
}

class ExplicitRelationStudent extends StudentModel
{
    public function withoutAttribute(): mixed
    {
        return $this->relateOne();
    }

    public function infoBy(Relate $relate): mixed
    {
        return $this->relateOne($relate, 'student_info');
    }
}
