<?php
declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertBean;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class ChangesTest extends TestCase
{
    private ?\ReflectionProperty $singleton = null;
    private mixed $previous;
    private FastDb $db;
    private ChangesConnection $connection;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('SQLite is required for change-state write regression.'); }
        $pdo = new \PDO('sqlite::memory:', options:[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE changes_records (id TEXT PRIMARY KEY, name TEXT, score INTEGER, tags TEXT, payload TEXT, profile TEXT)');
        $pdo->exec("INSERT INTO changes_records VALUES ('record-a','old',1,'[1]','[1]','{\"city\":\"old\"}')");
        $this->connection = new ChangesConnection($pdo);
        $this->connection->connectionName = 'default';
        $this->db = new FastDb();
        (new \ReflectionProperty(FastDb::class,'currentConnection'))->setValue($this->db,
            [Coroutine::getCid()=>['default'=>$this->connection]]);
        $this->singleton = new \ReflectionProperty(FastDb::class,'instance');
        $this->previous = $this->singleton->getValue();
        $this->singleton->setValue(null,$this->db);
        ChangesRecord::$updates = 0;
    }

    protected function tearDown(): void
    {
        if ($this->singleton === null) { return; }
        $this->db->setOnQuery(static function(): void {});
        (new \ReflectionProperty(FastDb::class,'currentConnection'))->setValue($this->db,[]);
        $this->singleton->setValue(null,$this->previous);
    }

    private function record(): ChangesRecord
    {
        return new ChangesRecord(['id'=>'record-a','name'=>'old','score'=>1,'tags'=>'[1]','payload'=>'[1]','profile'=>['city'=>'old']]);
    }

    public function testChangesFollowTheCurrentBaselineAndDisappearWhenValuesAreRestored(): void
    {
        $record = $this->record();
        $this->assertFalse($record->hasChange());
        $this->assertSame([], $record->getChanges());
        $record->name = 'new';
        $this->assertTrue($record->hasChange());
        $this->assertTrue($record->hasChange('name'));
        $this->assertFalse($record->hasChange('score'));
        $this->assertSame(['name'=>'new'], $record->getChanges());
        $record->name = 'old';
        $record->transient = 'changed';
        $this->assertFalse($record->hasChange());
        $this->assertFalse($record->hasChange('transient'));
        $this->assertFalse($record->hasChange('unknown'));
        $this->assertSame([], $record->getChanges());
    }

    public function testNullAndZeroRemainRealChangedValues(): void
    {
        $record = $this->record();
        $record->name = null;
        $record->score = 0;
        $this->assertTrue($record->hasChange('name'));
        $this->assertTrue($record->hasChange('score'));
        $this->assertSame(['name'=>null,'score'=>0], $record->getChanges());
    }

    public function testMutableConvertedFieldsUseTheirDatabaseComparisonValue(): void
    {
        $record = $this->record();
        $record->tags->append(2);
        $this->assertTrue($record->hasChange('tags'));
        $this->assertSame(['tags'=>$record->tags], $record->getChanges());
        $record->tags = new ConvertList([1]);
        $this->assertFalse($record->hasChange('tags'));
        $this->assertSame([], $record->getChanges());
    }

    public function testConvertedBeanRetainsPropertyIdentityAndComparesItsContents(): void
    {
        $record = $this->record();
        $record->profile->city = 'new';
        $this->assertTrue($record->hasChange('profile'));
        $changes = $record->getChanges();
        $this->assertSame(['profile'=>$record->profile], $changes);
        $this->assertSame($record->profile, $changes['profile']);
        // 返回的就是当前属性对象，通过结果修改 Bean 与直接修改属性一致。
        $changes['profile']->city = 'old';
        $this->assertSame('old', $record->profile->city);
        $this->assertFalse($record->hasChange('profile'));
        $record->profile = new ChangesProfile(['city'=>'old']);
        $this->assertSame([], $record->getChanges());
        $record->profile = null;
        $this->assertSame(['profile'=>null], $record->getChanges());
    }

    public function testChangeChecksReturnPropertyValuesWithoutSavingOrQuerying(): void
    {
        $record = $this->record();
        $record->name = 'new';
        $this->assertTrue($record->hasChange('name'));
        $this->assertTrue($record->hasChange());
        $this->assertSame(['name' => 'new'], $record->getChanges());
        $this->assertSame(0, ChangesRecord::$updates);
        $this->assertSame([], $this->connection->queries);
        $this->assertNull((new \ReflectionProperty(AbstractEntity::class, 'queryBuilder'))->getValue($record));
    }

    public function testSuccessfulWritesAcceptConvertedBaselines(): void
    {
        $record = $this->record();
        $record->tags->append(2);
        $record->setData(['payload' => [2]]);
        $record->profile->city = 'new';
        $this->assertSame(['tags'=>$record->tags,'payload'=>$record->payload,'profile'=>$record->profile], $record->getChanges());
        $this->assertTrue($record->update());
        $this->assertFalse($record->hasChange());
        $this->assertSame([], $record->getChanges());
        $stored = $this->connection->database->query("SELECT tags,payload,profile FROM changes_records WHERE id='record-a'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['tags'=>'[1,2]','payload'=>'[2]','profile'=>'{"city":"new"}'], $stored);
    }

    public function testChecksIgnoreFieldLimitsAndPreserveTheHeldQueryConfiguration(): void
    {
        $record = $this->record();
        $record->name = 'new';
        $record->score = 2;
        $query = $record->queryLimit()->fields(['name'])->hideFields('score')->where('id','record-a');
        $this->assertTrue($record->hasChange('score'));
        $this->assertSame(['name'=>'new','score'=>2], $record->getChanges());
        $this->assertSame($query, $record->queryLimit());
        $this->assertSame(['fields'=>['name'],'returnAsArray'=>false], $query->getFields());
        $this->assertSame(['score'], $query->getHideFields());
        $this->assertSame(['record-a'], $query->__getQueryBuilder()->get('changes_records')->getLastBindParams());
        $this->assertSame([], $this->connection->queries);
    }

    public function testPartialUpdatesOnlyClearTheSuccessfullyWrittenFields(): void
    {
        $record = $this->record();
        $record->name = 'new';
        $record->score = 2;
        $record->queryLimit()->fields(['name']);
        $this->assertTrue($record->update());
        $this->assertFalse($record->hasChange('name'));
        $this->assertTrue($record->hasChange('score'));
        $this->assertSame(['score'=>2], $record->getChanges());
        $this->assertTrue($record->update());
        $this->assertFalse($record->hasChange());
        $this->assertSame([], $record->getChanges());
        $this->assertCount(2, $this->connection->queries);
        $stored = $this->connection->database->query("SELECT name,score FROM changes_records WHERE id='record-a'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['name'=>'new','score'=>2], $stored);
    }

    public function testFailedSqlLeavesChangesPending(): void
    {
        $record = $this->record();
        $record->name = 'pending';
        $record->queryLimit()->where('missing_column',1);
        try { $record->update(); $this->fail('Expected SQL failure'); }
        catch (\PDOException) { $this->addToAssertionCount(1); }
        $this->assertTrue($record->hasChange('name'));
        $this->assertSame(['name'=>'pending'], $record->getChanges());
    }

    public function testExplicitBaselineAcceptanceOnlyClearsTheAcceptedFields(): void
    {
        $record = $this->record();
        $record->name = 'accepted';
        $record->score = 2;
        $record->setData(['name'=>'accepted'],true);
        $this->assertFalse($record->hasChange('name'));
        $this->assertSame(['score'=>2], $record->getChanges());
    }

    public static function observerModes(): array { return [['global'],['entity']]; }

    #[DataProvider('observerModes')]
    public function testChangesMadeAfterSuccessfulSqlRemainPending(string $mode): void
    {
        $record = $this->record();
        $record->name = 'saved';
        $entered = false;
        $observer = static function (QueryResult $result) use ($record,&$entered): void {
            if ($entered) { return; }
            $entered = true;
            self::assertSame([], $record->getChanges());
            $record->name = 'after-observer';
        };
        if ($mode === 'global') { $this->db->setOnQuery($observer); }
        else { $record->setOnQuery($observer); }
        $this->assertTrue($record->update());
        $this->assertSame(['name'=>'after-observer'], $record->getChanges());
        $this->assertTrue($record->update());
        $this->assertFalse($record->hasChange());
        $this->assertSame([], $record->getChanges());
    }

    public function testInsertAcceptsItsWrittenValuesWithoutChangingNewEntitySemantics(): void
    {
        $record = new ChangesRecord(['id'=>'record-new','name'=>'initial']);
        $this->assertFalse($record->hasChange());
        $record->name = 'inserted';
        $this->assertSame(['name'=>'inserted'], $record->getChanges());
        $this->assertTrue($record->insert());
        $this->assertFalse($record->hasChange());
        $this->assertSame([], $record->getChanges());
    }
}

#[OnUpdate('beforeUpdate')]
class ChangesRecord extends AbstractEntity
{
    public static int $updates = 0;
    #[Property]
    #[PrimaryKey]
    public string $id;
    #[Property] public ?string $name;
    #[Property] public ?int $score;
    #[Property(convertObject:ConvertList::class)] public ?ConvertList $tags;
    #[Property(convertObject: ConvertList::class)] public ?ConvertList $payload;
    #[Property(convertObject:ChangesProfile::class)] public ?ChangesProfile $profile;
    public string $transient = 'initial';
    public function tableName(): string { return 'changes_records'; }
    protected function beforeUpdate(): void { self::$updates++; }
}

class ChangesProfile extends ConvertBean
{
    public string $city = '';
}

class ChangesConnection extends Connection
{
    public array $queries = [];
    public function __construct(public \PDO $database) { parent::__construct(new Config()); }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        $sql = $builder->getLastPrepareQuery();
        $this->queries[] = $sql;
        $statement = $this->database->prepare($sql);
        $statement->execute($builder->getLastBindParams());
        $this->lastAffectRows = $statement->rowCount();
        $this->lastInsertId = 0;
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
}
