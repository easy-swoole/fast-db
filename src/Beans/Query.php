<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Internal\EntityQueryBuilder;
use EasySwoole\FastDb\Internal\FieldSelection;
use EasySwoole\Mysqli\QueryBuilder;

class Query
{
    private ?QueryBuilder $queryBuilder = null;

    // 普通 SQL 查询不设置字段选项，首次设置时再创建筛选配置。
    private ?FieldSelection $selection = null;
    private array $relations = [];

    public function __construct(
        private readonly AbstractEntity $entity
    ){
    }

    /** 字段输出配置不需要 SQL 构造器，首次添加 SQL 条件或显式访问时再创建。 */
    private function builder(): QueryBuilder
    {
        return $this->queryBuilder ??= new EntityQueryBuilder();
    }

    function limit(int $num,bool $withTotalCount = false):Query
    {
        $this->page(null,$withTotalCount,$num);
        return $this;
    }

    function persistFieldLimit(bool $persistFieldLimit = true):Query
    {
        $this->selection ??= new FieldSelection();
        $this->selection->persistFieldLimit = $persistFieldLimit;
        return $this;
    }

    function isPersistFieldLimit():bool
    {
        return $this->selection?->persistFieldLimit ?? false;
    }

    function page(?int $page,bool $withTotalCount = false,int $pageSize = 10):Query
    {
        $builder = $this->builder();
        if($page >= 1){
            $builder->limit(($page - 1) * $pageSize, $pageSize);
        }else{
            $builder->limit($pageSize);
        }
        if($withTotalCount){
            $builder->withTotalCount();
        }
        return $this;
    }

    function fields(?array $fields = null,bool $returnAsArray = false):Query
    {
        $this->selection ??= new FieldSelection();
        $this->selection->fields = [
            'fields'=>$fields,
            'returnAsArray'=>$returnAsArray
        ];
        return $this;
    }

    function returnAsArray():Query
    {
        $selection = $this->selection ??= new FieldSelection();
        $selection->fields ??= ['fields'=>null];
        $selection->fields['returnAsArray'] = true;
        return $this;
    }

    function hideFields(array|string $hideFields):Query
    {
        if(is_string($hideFields)){
            $hideFields = [$hideFields];
        }
        $this->selection ??= new FieldSelection();
        $this->selection->hideFields = $hideFields;
        return $this;
    }

    function getHideFields():?array
    {
        return $this->selection?->hideFields;
    }

    function getFields():?array
    {
        return $this->selection?->fields;
    }

    /** @internal 输出与写入共用字段白名单，隐藏字段仅作用于输出。 */
    public function filterProperties(array $properties, bool $forOutput = false): array
    {
        if ($this->selection !== null) {
            $properties = $this->selection->filterProperties($properties, $forOutput);
        }
        return !$forOutput && $this->queryBuilder !== null
            ? self::filterWriteFields($properties, $this->queryBuilder) : $properties;
    }

    /** @internal 转换前筛选属性，构建 SQL 前再按同一规则确认实际写入的列。 */
    public static function filterWriteFields(array $values, QueryBuilder $builder): array
    {
        $fields = $builder->getField();
        // mysqli 的 getField() 把默认通配符和显式白名单 ['*'] 都返回为同一个数组。
        if ($fields === ['*'] && !($builder instanceof EntityQueryBuilder && $builder->hasExplicitFields())) {
            return $values;
        }
        return array_filter($values, static fn ($field) => in_array($field, $fields), ARRAY_FILTER_USE_KEY);
    }

    /** 声明本次查询需要批量加载的关系方法；空数组清除声明。 */
    public function withRelations(string|array $relations): Query
    {
        $names = is_string($relations) ? [$relations] : $relations;
        foreach ($names as &$name) {
            $name = (new \ReflectionMethod($this->entity, $name))->getName();
        }
        unset($name);
        $this->relations = array_values(array_unique($names));
        return $this;
    }

    /** @internal */
    public function getRelations(): array { return $this->relations; }

    function groupBy(string $groupByField):Query
    {
        $this->builder()->groupBy($groupByField);
        return $this;
    }

    function orderBy($orderByField, $orderbyDirection = "DESC", $customFieldsOrRegExp = null):Query
    {
        $this->builder()->orderBy($orderByField, $orderbyDirection, $customFieldsOrRegExp);
        return $this;
    }

    function where(string $col, mixed $whereValue, $operator = '=', $cond = 'AND'):Query
    {
        $this->builder()->where($col,$whereValue,$operator,$cond);
        return $this;
    }

    /** @internal 构建 SQL 前调用，使已有条件始终与主键或关联外键约束共同成立。 */
    function whereRequired(string $col, mixed $whereValue): Query
    {
        $this->builder()->groupExistingWhere()->where($col, $whereValue, '=', 'AND');
        return $this;
    }

    function orWhere(string $col, mixed $whereValue, $operator = '='):Query
    {
        $this->builder()->orWhere($col,$whereValue,$operator,"OR");
        return $this;
    }

    function join($joinTable, $joinCondition, $joinType = ''):Query
    {
        $this->builder()->join($joinTable,$joinCondition,$joinType);
        return $this;
    }

    function func(callable $func):Query
    {
        call_user_func($func,$this->builder());
        return $this;
    }

    function selectForUpdate():Query
    {
        $this->builder()->selectForUpdate();
        return $this;
    }

    function returnEntity():AbstractEntity
    {
        return $this->entity;
    }

    function __getQueryBuilder():QueryBuilder
    {
        return $this->builder();
    }

    /** 插入后清理 SQL 条件并保留输出配置。 */
    public function withoutSqlConditions(): Query
    {
        $copy = new Query($this->entity);
        $copy->selection = $this->selection === null ? null : clone $this->selection;
        return $copy;
    }

    /** 克隆实体时重新绑定所有者，同时复制 SQL 条件和输出配置。 */
    public function forEntity(AbstractEntity $entity): Query
    {
        $copy = new Query($entity);
        $copy->queryBuilder = $this->queryBuilder === null ? null : clone $this->queryBuilder;
        $copy->selection = $this->selection === null ? null : clone $this->selection;
        $copy->relations = $this->relations;
        return $copy;
    }

    function __clone()
    {
        // 查询条件和输出配置分别复制，后续修改不影响原查询。
        $this->queryBuilder = $this->queryBuilder === null ? null : clone $this->queryBuilder;
        if ($this->selection !== null) {
            $this->selection = clone $this->selection;
        }
    }
}
