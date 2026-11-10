<?php
declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class RelationDeclarationTest extends TestCase
{
    public function testExplicitTypeNeedsNoConfigurationAndDoesNotExecuteTheGetter(): void
    {
        $parent = new DeclarationParent(['id'=>1, 'tenant'=>7]);
        $plan = $parent->__relationPlan('plain');
        $this->assertSame('many', $plan->kind);
        $this->assertSame(1, $plan->selfValue);
        $this->assertSame('id', $plan->relate->selfProperty);
        $this->assertSame('children', $plan->tableName);
        $this->assertSame([], $plan->query->__getQueryBuilder()->get('children')->getLastBindParams());
    }

    public function testProtectedConfigurationUsesEachParentAndKeepsExistingQueryReferences(): void
    {
        foreach ([7,8] as $tenant) {
            $class = $tenant === 7 ? DeclarationParent::class : DeclarationChildParent::class;
            $parent = new $class(['id'=>1,'tenant'=>$tenant]);
            $held = $parent->queryLimit()->where('existing', 5);
            $builder = $held->__getQueryBuilder();
            $plan = $parent->__relationPlan('configured');
            $this->assertSame('one', $plan->kind);
            $this->assertSame($held, $parent->queryLimit());
            $this->assertSame($builder, $held->__getQueryBuilder());
            $this->assertSame([5], $builder->get('children')->getLastBindParams());
            $query = $plan->query->__getQueryBuilder()->get('children');
            $this->assertSame([5,$tenant], $query->getLastBindParams());
        }
    }

    public function testInheritedPublicConfigurationAndArrayMode(): void
    {
        $plan = (new DeclarationChildParent(['id'=>1]))->__relationPlan('publicConfigured');
        $this->assertTrue($plan->query->getFields()['returnAsArray']);
        $this->assertSame([3], $plan->query->__getQueryBuilder()->get('children')->getLastBindParams());
    }

    public static function invalidConfigurations(): array
    {
        return [['legacy'],['privateConfig'],['staticConfig'],['unknownConfig']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidConfigurations')]
    public function testInvalidDeclarationsFailClearly(string $name): void
    {
        $this->expectException(RuntimeError::class);
        (new DeclarationParent(['id'=>1]))->__relationPlan($name);
    }

    public function testConfigurationFailurePropagatesWithoutChangingTheHeldQuery(): void
    {
        $parent = new DeclarationParent(['id'=>1]);
        $held = $parent->queryLimit()->where('keep', 9);
        try { $parent->__relationPlan('failing'); $this->fail('Expected configure error'); }
        catch (\LogicException $error) { $this->assertSame('configuration failed', $error->getMessage()); }
        $this->assertSame($held, $parent->queryLimit());
        $this->assertSame([9], $held->__getQueryBuilder()->get('children')->getLastBindParams());
    }

    public function testPreloadDeclarationRejectsProtectedGetters(): void
    {
        $this->expectException(RuntimeError::class);
        (new DeclarationParent(['id'=>1]))->queryLimit()->withRelations('protectedGetter');
    }

    public function testInvalidTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Relate(DeclarationTarget::class,'parent_id',type:'invalid');
    }

    public function testGetterKindMustMatchTheExplicitDeclaration(): void
    {
        $this->expectException(RuntimeError::class);
        (new DeclarationParent(['id'=>1]))->mismatch();
    }
}

class DeclarationTarget extends AbstractEntity
{
    #[Property(isPrimaryKey:true)] public int $id;
    #[Property] public int $parent_id;
    public function tableName(): string { return 'children'; }
}
class DeclarationParent extends AbstractEntity
{
    #[Property(isPrimaryKey:true)] public int $id;
    #[Property] public int $tenant;
    public function tableName(): string { return 'parents'; }
    #[Relate(DeclarationTarget::class,'parent_id',type:'many')]
    protected function protectedGetter() { return $this->relateMany(); }
    #[Relate(DeclarationTarget::class,'parent_id',type:'many')]
    public function plain() { throw new \LogicException('Getter must not run'); }
    #[Relate(DeclarationTarget::class,'parent_id',type:'one',configure:'protectedQuery')]
    public function configured() { throw new \LogicException('Getter must not run'); }
    protected function protectedQuery(QueryBuilder $query): void { $query->where('tenant',$this->tenant); }
    #[Relate(DeclarationTarget::class,'parent_id',type:'many',configure:'publicQuery',returnAsArray:true)]
    public function publicConfigured() { return $this->relateMany(); }
    public function publicQuery(QueryBuilder $query): void { $query->where('active',3); }
    #[Relate(DeclarationTarget::class,'parent_id')]
    public function legacy() { return $this->relateMany(); }
    #[Relate(DeclarationTarget::class,'parent_id',type:'many',configure:'hiddenQuery')]
    public function privateConfig() {}
    private function hiddenQuery(QueryBuilder $query): void {}
    #[Relate(DeclarationTarget::class,'parent_id',type:'many',configure:'staticQuery')]
    public function staticConfig() {}
    public static function staticQuery(QueryBuilder $query): void {}
    #[Relate(DeclarationTarget::class,'parent_id',type:'many',configure:'missing')]
    public function unknownConfig() {}
    #[Relate(DeclarationTarget::class,'parent_id',type:'many',configure:'failQuery')]
    public function failing() {}
    protected function failQuery(QueryBuilder $query): void { $query->where('temporary',1);throw new \LogicException('configuration failed'); }
    #[Relate(DeclarationTarget::class,'parent_id',type:'one')]
    public function mismatch() { return $this->relateMany(); }
}
class DeclarationChildParent extends DeclarationParent {}
