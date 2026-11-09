<?php
declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Internal\RelationPlan;
use PHPUnit\Framework\TestCase;

final class RelationPlanCaptureTest extends TestCase
{
    private \ReflectionProperty $singleton;
    private mixed $previous;
    private FastDb $db;

    protected function setUp(): void
    {
        $this->db = $this->getMockBuilder(FastDb::class)->onlyMethods(['query', 'rawQuery'])->getMock();
        $this->db->expects($this->never())->method('query');
        $this->db->expects($this->never())->method('rawQuery');
        $this->singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->previous = $this->singleton->getValue();
        $this->singleton->setValue(null, $this->db);
    }

    protected function tearDown(): void
    {
        $this->singleton->setValue(null, $this->previous);
    }

    public function testCaptureKeepsPerParentConditionsAndTypedRelationKindWithoutSql(): void
    {
        $first = new CapturePlanParent(['id' => 1, 'tenant' => 7]);
        $second = new CapturePlanParent(['id' => 2, 'tenant' => 8]);
        foreach ([[$first, 7], [$second, 8]] as [$parent, $tenant]) {
            $plan = $parent->__relationPlan('children');
            $this->assertInstanceOf(RelationPlan::class, $plan);
            $this->assertSame('many', $plan->kind);
            $this->assertSame(CapturePlanChild::class, $plan->relate->targetEntity);
            $this->assertSame('id', $plan->relate->selfProperty);
            $this->assertSame('parent_id', $plan->relate->targetProperty);
            $this->assertSame('children', $plan->tableName);
            $this->assertSame('default', $plan->connectionName);
            $this->assertSame(['id', 'parent_id'], $plan->query->getFields()['fields']);
            $builder = $plan->query->__getQueryBuilder()->get('children');
            $this->assertSame([$tenant], $builder->getLastBindParams());
            $this->assertStringContainsString('ORDER BY id DESC', $builder->getLastPrepareQuery());
        }
    }

    public function testExplicitDefinitionTableAndConnectionAreCapturedAndRoutingIsRestored(): void
    {
        $parent = new CapturePlanParent(['id' => 1, 'tenant' => 9]);
        $plan = $parent->__relationPlan('profile');
        $this->assertSame('one', $plan->kind);
        $this->assertSame('group_code', $plan->relate->targetProperty);
        $this->assertSame('tenant', $plan->relate->selfProperty);
        $this->assertSame('profiles_9', $plan->tableName);
        $this->assertSame('tenant_9', $plan->connectionName);
        $this->assertSame('default', $this->db->selectConnection());
    }

    public function testOldQueryAndBuilderReferencesAreRestoredOnCapture(): void
    {
        $parent = new CapturePlanParent(['id' => 1, 'tenant' => 7]);
        $heldQuery = $parent->queryLimit()->where('before', 5)->fields(['old']);
        $heldBuilder = $heldQuery->__getQueryBuilder();
        $plan = $parent->__relationPlan('children');
        $this->assertSame($heldQuery, $parent->queryLimit());
        $this->assertSame($heldBuilder, $parent->queryLimit()->__getQueryBuilder());
        $this->assertNotSame($heldQuery, $plan->query);
        $this->assertSame(['old'], $heldQuery->getFields()['fields']);
        $heldBuilder->get('children');
        $plan->query->__getQueryBuilder()->get('children');
        $this->assertSame([5], $heldBuilder->getLastBindParams());
        $this->assertSame([5, 7], $plan->query->__getQueryBuilder()->getLastBindParams());
    }

    public function testNestedCaptureRestoresOuterScopeAndSelectedConnection(): void
    {
        $parent = new CapturePlanParent(['id' => 1, 'tenant' => 7]);
        $outer = $parent->__relationPlan('nested');
        $this->assertSame('many', $outer->kind);
        $this->assertSame('one', $parent->nestedKind);
        $this->assertSame('default', $outer->connectionName);
        $this->assertSame('default', $this->db->selectConnection());
        $this->assertCount(0, (new \ReflectionProperty(AbstractEntity::class, 'relationPlanScopes'))->getValue());
    }

    public function testSwallowedSignalIsRejectedAndDoesNotPoisonFollowingCapture(): void
    {
        $parent = new CapturePlanParent(['id' => 1, 'tenant' => 7]);
        foreach (['swallowed', 'swallowedTyped', 'noRelation'] as $method) {
            try {
                $parent->__relationPlan($method);
                $this->fail('Capture must reject ' . $method);
            } catch (RuntimeError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('many', $parent->__relationPlan('children')->kind);
        $this->assertSame('default', $this->db->selectConnection());
    }

    public function testInvalidEntryMethodsAreRejectedBeforeBusinessSideEffects(): void
    {
        $parent = new CapturePlanParent(['id' => 1, 'tenant' => 7]);
        CapturePlanParent::$businessCalls = 0;
        foreach (['unmarkedBusiness', 'delete', 'hidden', 'staticRelation', 'needsArgument', 'missing'] as $method) {
            try {
                $parent->__relationPlan($method);
                $this->fail('Capture must reject ' . $method);
            } catch (RuntimeError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, CapturePlanParent::$businessCalls);
    }

    public function testOriginalFailureRestoresQueryRoutingAndCaptureScope(): void
    {
        $parent = new CapturePlanParent(['id' => 1, 'tenant' => 7]);
        $held = $parent->queryLimit()->fields(['id']);
        try {
            $parent->__relationPlan('failure');
            $this->fail('Expected the wrapper failure');
        } catch (\LogicException $error) {
            $this->assertSame('wrapper failure', $error->getMessage());
        }
        $this->assertSame($held, $parent->queryLimit());
        $this->assertSame('default', $this->db->selectConnection());
        $this->assertCount(0, (new \ReflectionProperty(AbstractEntity::class, 'relationPlanScopes'))->getValue());
    }
}

class CapturePlanChild extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property] public int $parent_id;
    #[Property] public int $group_code;
    public function tableName(): string { return 'children'; }
}

class CapturePlanParent extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property] public int $tenant;
    public ?string $nestedKind = null;
    public static int $businessCalls = 0;
    public function tableName(): string { return 'parents'; }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    public function children(): ListResult
    {
        $this->queryLimit()->where('tenant', $this->tenant)->orderBy('id', 'DESC')->fields(['id', 'parent_id']);
        return $this->relateMany();
    }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    public function profile(): ?CapturePlanChild
    {
        FastDb::getInstance()->selectConnection('tenant_' . $this->tenant);
        return $this->relateOne(new Relate(CapturePlanChild::class, 'group_code', 'tenant'), 'profiles_' . $this->tenant);
    }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    public function nested(): ListResult
    {
        $this->nestedKind = $this->__relationPlan('profile')->kind;
        return $this->relateMany();
    }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    public function swallowed(): mixed
    {
        try { return $this->relateMany(); } catch (\Throwable) { return null; }
    }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    public function swallowedTyped(): CapturePlanChild
    {
        try { return $this->relateOne(); } catch (\Throwable) { return null; }
    }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    public function noRelation(): mixed { return null; }

    public function unmarkedBusiness(): void { self::$businessCalls++; }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    protected function hidden(): mixed { self::$businessCalls++; return null; }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    public static function staticRelation(): mixed { self::$businessCalls++; return null; }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    public function needsArgument(int $value): mixed { self::$businessCalls++; return null; }

    #[Relate(CapturePlanChild::class, 'parent_id')]
    public function failure(): mixed
    {
        FastDb::getInstance()->selectConnection('failed-route');
        $this->queryLimit()->where('discarded', 1);
        throw new \LogicException('wrapper failure');
    }
}
