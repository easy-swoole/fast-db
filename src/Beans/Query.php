<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Internal\FieldSelection;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\Mysqli\QueryBuilder;

class Query
{
    private ?QueryBuilder $queryBuilder = null;

    // SQL 条件由 QueryBuilder 管理，输出筛选规则单独保存。
    private FieldSelection $selection;
    private array $relations = [];

    public function __construct(
        private readonly AbstractEntity $entity
    ){
        $this->selection = new FieldSelection();
    }

    /** 字段输出配置不需要 SQL 构造器，首次添加 SQL 条件或显式访问时再创建。 */
    private function builder(): QueryBuilder
    {
        return $this->queryBuilder ??= new QueryBuilder();
    }

    function limit(int $num,bool $withTotalCount = false):Query
    {
        $this->page(null,$withTotalCount,$num);
        return $this;
    }

    function persistFieldLimit(bool $persistFieldLimit = true):Query
    {
        $this->selection->persistFieldLimit = $persistFieldLimit;
        return $this;
    }

    function isPersistFieldLimit():bool
    {
        return $this->selection->persistFieldLimit;
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
        $this->selection->fields = [
            'fields'=>$fields,
            'returnAsArray'=>$returnAsArray
        ];
        return $this;
    }

    function returnAsArray():Query
    {
        if(!is_array($this->selection->fields)){
            $this->selection->fields = [
                'returnAsArray'=>true,
                'fields'=>null
            ];
        }else{
            $this->selection->fields['returnAsArray'] = true;
        }
        return $this;
    }

    function hideFields(array|string $hideFields):Query
    {
        if(is_string($hideFields)){
            $hideFields = [$hideFields];
        }
        $this->selection->hideFields = $hideFields;
        return $this;
    }

    function getHideFields():?array
    {
        return $this->selection->hideFields;
    }

    function getFields():?array
    {
        return $this->selection->fields;
    }

    /** @internal 输出与写入共用字段白名单，隐藏字段仅作用于输出。 */
    public function filterProperties(array $properties, bool $forOutput = false): array
    {
        return $this->selection->filterProperties($properties, $forOutput);
    }

    /** 声明本次查询需要批量加载的关系方法；空数组清除声明。 */
    public function withRelations(string|array $relations): Query
    {
        $names = is_string($relations) ? [$relations] : $relations;
        foreach ($names as $name) {
            if (!is_string($name) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $name) !== 1) {
                throw new \InvalidArgumentException('Relations must be a list of method names');
            }
        }
        foreach ($names as &$name) {
            try {
                $method = new \ReflectionMethod($this->entity, $name);
                if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() !== 0) {
                    throw new RuntimeError('Preloaded relation getter must be a public instance method without required arguments');
                }
                $name = $method->getName();
            } catch (\ReflectionException $error) {
                throw new RuntimeError('Unknown relation method: ' . $name, 0, $error);
            }
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
        $copy->selection = clone $this->selection;
        return $copy;
    }

    /** @internal 创建独立的游标分页 Query，保留输出配置和原模板。 */
    public function forPageAfterId(string $column, mixed $lastId, int $size): Query
    {
        $copy = new Query($this->entity);
        $copy->queryBuilder = $this->builder()->forPageAfterId($column, $lastId, $size);
        $copy->selection = clone $this->selection;
        $copy->relations = $this->relations;
        return $copy;
    }

    /** 克隆实体时重新绑定所有者，同时复制 SQL 条件和输出配置。 */
    public function forEntity(AbstractEntity $entity): Query
    {
        $copy = new Query($entity);
        $copy->queryBuilder = $this->queryBuilder === null ? null : clone $this->queryBuilder;
        $copy->selection = clone $this->selection;
        $copy->relations = $this->relations;
        return $copy;
    }

    function __clone()
    {
        // 分块读取时复用底层查询构造器。
        $this->queryBuilder = $this->queryBuilder === null ? null : clone $this->queryBuilder;
        $this->selection = clone $this->selection;
    }
}
