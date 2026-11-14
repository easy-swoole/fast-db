<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Internal\EntityQueryBuilder;
use EasySwoole\FastDb\Internal\FieldSelection;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\QueryBuilder\Pagination;

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
        if ($page === null) {
            $builder->limit($pageSize);
        } else {
            $builder->limit(Pagination::offset($pageSize, $page), $pageSize);
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
        $fields = $this->selection?->fields;
        // 仅开启数组模式时保持公开字段结构，内部仍能区分是否显式选列。
        return $fields === null || array_key_exists('fields', $fields) ? $fields : ['fields' => null] + $fields;
    }

    /** @internal 显式选列作用于输出和写入，继承的限制只在序列化时传入。 */
    public function filterProperties(array $properties, bool $forOutput = false, ?QueryBuilder $builder = null,
        array $inheritedOutput = []): array
    {
        $selection = $this->selection?->fields;
        $fields = $selection['fields'] ?? null;
        if ($forOutput) {
            // fields(null) 显式取消选列限制，仅切换数组模式时继续沿用继承的选列。
            if (!array_key_exists('fields', $selection ?? [])) {
                $fields = $inheritedOutput['fields'] ?? null;
            }
            $hideFields = $this->getHideFields() ?? $inheritedOutput['hideFields'] ?? [];
            return FieldSelection::filterProperties($properties, $fields, $hideFields);
        }
        $properties = FieldSelection::filterProperties($properties, $fields);
        return self::filterWriteFields($properties, $builder ?? $this->__getQueryBuilder());
    }

    /** @internal 在转换前按底层构建器的写入白名单筛选字段。 */
    public static function filterWriteFields(array $values, QueryBuilder $builder): array
    {
        $fields = $builder->getField();
        if ($fields === null) {
            return $values;
        }
        return array_filter($values, static fn ($field) => in_array($field, $fields, true), ARRAY_FILTER_USE_KEY);
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

    function orWhere(string $col, mixed $whereValue, $operator = '='):Query
    {
        $this->builder()->orWhere($col, $whereValue, $operator);
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
