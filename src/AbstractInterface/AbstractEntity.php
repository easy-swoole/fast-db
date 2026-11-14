<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\FastDb\RelationType;
use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\EntityReflection;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Internal\EntityState;
use EasySwoole\FastDb\Internal\EntityQueryBuilder;
use EasySwoole\FastDb\Internal\BatchWriter;
use EasySwoole\FastDb\Internal\FieldCodec;
use EasySwoole\FastDb\Internal\RelationLoader;
use EasySwoole\FastDb\Internal\RelationPlan;
use EasySwoole\FastDb\Internal\RelationPreloader;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Utility\ReflectionCache;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\QueryBuilder\SelectPlanner;
use EasySwoole\Mysqli\QueryBuilder\TableReference;

abstract class AbstractEntity implements \JsonSerializable
{
    // 字段的比较基准；转换对象保存 toValue() 的结果，普通字段保存赋值后的值。
    // update() 据此识别变化，也能识别对象内部属性被修改的情况。
    private EntityState $entityState;
    private bool $missingLoadedPrimaryKey = false;

    // 当前实体积累的查询条件和字段限制，执行后通常由 reset() 清理。
    private ?Query $queryBuilder = null;

    // 实体级查询回调，与 FastDb 的全局 onQuery 回调独立。
    private mixed $onQuery = null;

    // 缓存仅属于当前实体实例；键包含连接、关联类型、执行 SQL、参数和返回形式。
    private ?RelationLoader $relationLoader = null;

    /** 外部数据变化后，可主动清空当前实体的全部关联缓存。 */
    public function clearRelationCache(): static
    {
        $this->relationLoader?->clear();
        return $this;
    }

    /** @internal 将按查询形状匹配的预加载结果注入当前实体，不修改字段比较基准。 */
    public function __setRelationData(RelationPlan $plan, QueryBuilder $lookup, string $connectionName, mixed $data): static
    {
        $this->relationLoader ??= new RelationLoader();
        $this->relationLoader->prime($plan->relate, $lookup,
            $plan->returnAsArray, $connectionName, $data);
        return $this;
    }

    abstract function tableName():string;

    function __construct(array|null $data = null)
    {
        $this->init();
        if(!empty($data)){
            // 构造数据同时作为比较基准，避免从数据库加载的字段被误判为待更新。
            $this->setData($data,true);
        }
    }

    /** 克隆实体时独立保存变更状态和查询选项，不共享可变的内部管理对象。 */
    public function __clone()
    {
        $this->entityState = clone $this->entityState;
        $this->relationLoader = $this->relationLoader === null ? null : clone $this->relationLoader;
        $this->queryBuilder = $this->queryBuilder?->forEntity($this);
    }

    /**
     * 按 Property 定义批量赋值，忽略未声明为数据库字段的键。
     * $mergeCompare 为 true 时同步比较基准；正常修改数据时保持 false，供 update() 检测变化。
     */
    function setData(array $data,bool $mergeCompare = false):static
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        $allProperties = $entityRef->allProperties();
        foreach ($data as $key => $val){
            if(!isset($allProperties[$key])){
                continue;
            }
            /** @var Property $property */
            $property = $allProperties[$key];
            $this->{$key} = FieldCodec::assign($property, $val);
            if ($mergeCompare) {
                $this->entityState->baseline[$key] = FieldCodec::compare($this->{$key});
            }
        }
        if ($mergeCompare && $this->missingLoadedPrimaryKey) {
            $key = $entityRef->getPrimaryKey();
            if ($key !== null && isset($data[$key])) { $this->missingLoadedPrimaryKey = false; }
        }
        return $this;
    }

    /** 部分投影仍可读取，但默认值不能成为数据库行的更新或删除依据。 */
    private static function hydrateEntity(string $class, array $row, ?AbstractEntity $entity = null): AbstractEntity
    {
        if ($entity === null) {
            $entity = new $class($row);
        } else {
            $entity->setData($row, true);
        }
        $key = ReflectionCache::getInstance()->parseEntity($class)->getPrimaryKey();
        $entity->missingLoadedPrimaryKey = $key !== null && (!array_key_exists($key, $row) || $row[$key] === null);
        return $entity;
    }

    /** 判断全部或指定映射字段是否相对比较基准发生变化。 */
    public function hasChange(?string $field = null): bool
    {
        return $this->entityState->hasChange($this, $field);
    }

    /** 返回全部变化字段的当前属性值，Bean 等转换对象保持原实例，不消费查询配置或接受新基准。 */
    public function getChanges(): array
    {
        return $this->entityState->getChanges($this);
    }

    function all( bool $selectForUpdate = false ):ListResult
    {
        try { return $this->selectRows($this->queryLimit(), $selectForUpdate); }
        finally { $this->resetQueryOptions(); }
    }

    function chunk(callable $func,int $chunkSize = 10)
    {
        try {
            if ($chunkSize < 1) {
                throw new \InvalidArgumentException('Chunk size must be greater than zero');
            }
            $selection = $this->queryLimit();
            // 固定入口处的查询条件，避免每页重复应用子类配置或使用回调改过的实体属性。
            $queryTemplate = clone $selection;
            $page = [];
            for ($pageNumber = 1; ; $pageNumber++) {
                $selection->page($pageNumber, false, $chunkSize);
                $this->queryBuilder = $selection;
                $list = $this->selectRows($selection, page: $page)->list();
                $this->resetQueryOptions();
                foreach ($list as $item) {
                    if ($func($item) === false) { return; }
                }
                if ($page['rowCount'] < $chunkSize) { return; }
                // 下一页查询前释放本页结果，避免两页实体同时留在内存。
                unset($list, $item);
                $selection = clone $queryTemplate;
            }
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->resetQueryOptions();
        }
    }

    /**
     * 按稳定唯一字段 ASC 分块，默认使用自增字段；每条回调返回 false 时停止。
     * 返回已交付回调的记录数，包含返回 false 的那条记录。
     */
    public function chunkById(callable $func, int $chunkSize = 10, ?string $column = null): int
    {
        try {
            if ($chunkSize < 1) {
                throw new \InvalidArgumentException('Chunk size must be greater than zero');
            }
            $reflection = ReflectionCache::getInstance()->parseEntity(static::class);
            $column ??= $reflection->getAutoIncrementProperty()?->name;
            if ($column === null) {
                throw new RuntimeError('chunkById requires a cursor column, an AutoIncrement field or a primary key');
            }
            $cursorColumn = TableReference::column($column);
            // SQL 使用完整列引用，结果行使用其中最后一段的原始字段名。
            preg_match('/(?:^|\.)`((?:[^`]|``)*)`\z/', $cursorColumn, $cursorParts);
            $cursorField = str_replace('``', '`', $cursorParts[1]);
            if (!isset($reflection->allProperties()[$cursorField])) {
                throw new RuntimeError('chunkById cursor column must refer to a mapped field: ' . $column);
            }
            $template = clone $this->queryLimit();
            $lastId = null;
            $delivered = 0;
            $page = [];
            while (true) {
                $selection = clone $template;
                // 原条件先成组，避免 OR 绕过本页的游标边界。
                $builder = $selection->__getQueryBuilder()->groupExistingWhere();
                if ($lastId !== null) { $builder->where($cursorColumn, $lastId, '>'); }
                $builder->reorder()->orderByRaw($cursorColumn . ' ASC')->limit($chunkSize);
                $this->queryBuilder = $selection;
                $list = $this->selectRows($selection, page: $page, cursorField: $cursorField)->list();
                if ($page['rowCount'] === 0) { return $delivered; }
                $this->resetQueryOptions();
                foreach ($list as $item) {
                    $delivered++;
                    if ($func($item) === false) { return $delivered; }
                }
                if ($page['rowCount'] < $chunkSize) { return $delivered; }
                $lastId = $page['nextId'];
                unset($list, $item);
            }
        } finally {
            $this->resetQueryOptions();
        }
    }

    /** 将实体字段转为输出值，同时应用隐藏字段和字段白名单。 */
    function toArray(bool $filterNull = false):array
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        $properties = $entityRef->allProperties();
        // 标准实体没有输出配置时直接转换；子类覆写 queryLimit 的行为仍然生效。
        if ($this->queryBuilder !== null || $entityRef->hasCustomQueryLimit) {
            $properties = $this->queryLimit()->filterProperties($properties, true);
        }
        return FieldCodec::values($this, $properties, $filterNull);
    }

    public function count(string|null $field = '*', string|null $group = null): int|array
    {
        try {
            $selection = $this->queryLimit();
            $fields = $selection->getFields()['fields'] ?? null;
            $query = $selection->__getQueryBuilder();
            $hasFields = $fields !== null;

            if ($hasFields) {
                $expressions = [];
                foreach ($fields as $fieldName) {
                    $expressions[] = SelectPlanner::aggregateExpression('COUNT', $fieldName, $fieldName, false);
                }
            } else {
                $expressions = [SelectPlanner::aggregateExpression('COUNT', $field ?? '*', 'count', false)];
            }

            $row = $this->selectAggregateRow($query, $expressions, $group);
            return $hasFields ? $row : ($row === [] ? 0 : $row['count']);
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->resetQueryOptions();
        }
    }

    public function sum(string|array $cols, string|null $group = null, bool $force = true): int|array|float|string
    {
        return $this->aggregate('SUM', $cols, $group, $force);
    }

    public function avg(string|array $cols, string|null $group = null, bool $force = true): int|array|float|string
    {
        return $this->aggregate('AVG', $cols, $group, $force);
    }

    public function max(string|array $cols, string|null $group = null, bool $force = true): int|array|float|string
    {
        return $this->aggregate('MAX', $cols, $group, $force);
    }

    public function min(string|array $cols, string|null $group = null, bool $force = true): int|array|float|string
    {
        return $this->aggregate('MIN', $cols, $group, $force);
    }

    function queryLimit():Query
    {
        if(!$this->queryBuilder){
            $this->queryBuilder = new Query($this);
        }
        return $this->queryBuilder;
    }

    function delete()
    {
        try {
            $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
            if($entityRef->getOnDelete()){
                $ret = $this->callHook($entityRef->getOnDelete());
                if($ret === false){
                    return false;
                }
            }
            $query = $this->queryLimit()->__getQueryBuilder();
            $tableName = $this->tableName();
            $this->requirePrimaryKey($query, $entityRef, 'delete');
            $query->delete($tableName);
            return self::callQuery($query, $this->onQuery)->getAffectedRows() >= 1;
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->resetQueryOptions();
        }
    }

    /** 直接按条件删除，不执行实体的 OnDelete 钩子。 */
    public static function fastDelete(
        array|callable|string|int $deleteLimit,
        string|null $tableName = null,
        callable|null $onQuery = null
    ):int|null|string
    {

        if ($tableName === null) {
            $tableName = (new static())->tableName();
        }
        $query = self::fastWriteQuery($deleteLimit, 'delete record');
        $query->delete($tableName);
        return self::callQuery($query, $onQuery)->getAffectedRows();
    }

    function update()
    {
        try {
            $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
            if($entityRef->getOnUpdate()){
                $ret = $this->callHook($entityRef->getOnUpdate());
                if($ret === false){
                    return  false;
                }
            }
            $selection = $this->queryLimit();
            $query = $selection->__getQueryBuilder();
            $tableName = $this->tableName();
            $properties = $selection->filterProperties($entityRef->allProperties(), builder: $query);
            $data = $this->entityState->changes($this, $properties);
            $this->requirePrimaryKey($query, $entityRef, 'update');
            if ($data === []) { return true; }
            $query->update($tableName, $data);
            // 按 SQL 成功顺序接受基准，再通知观察回调；重入保存可据此写回原值。
            return self::callQuery($query, $this->onQuery, function (QueryResult $result) use ($data): void {
                if ($result->getAffectedRows() > 0) { $this->entityState->accept($data); }
            })->getAffectedRows() > 0;
        } finally {
            // 写入配置只用一次，查询结果继承的序列化限制继续生效。
            $this->queryBuilder = $this->queryBuilder?->keepInheritedOutput();
        }
    }

    /** 直接写入给定数据，不执行实体的字段转换、变更检测或 OnUpdate 钩子。 */
    public static function fastUpdate(
        array|callable|string|int $updateLimit,
        array $data,
        string|null $tableName = null,
        callable|null $onQuery = null
    ):bool|int|string
    {
        if ($tableName === null) {
            $tableName = (new static())->tableName();
        }
        $query = self::fastWriteQuery($updateLimit, 'update record');
        $query->update($tableName,$data);
        return self::callQuery($query, $onQuery)->getAffectedRows();
    }

    /** 直接分批新增，返回数据库报告的影响行数汇总。 */
    public static function fastInsertAll(iterable $rows, ?string $tableName = null, ?callable $onQuery = null,
        int $batchSize = 500): int|string
    {
        return self::writeBatch($rows, null, $tableName, $onQuery, $batchSize);
    }

    /** 按数据库主键或唯一索引冲突分批插入或更新，不执行实体转换及钩子。 */
    public static function fastUpsert(iterable $rows, array $updateColumns, ?string $tableName = null,
        ?callable $onQuery = null, int $batchSize = 500): int|string
    {
        return self::writeBatch($rows, $updateColumns, $tableName, $onQuery, $batchSize);
    }

    function insert(array|null $updateDuplicateCols = null)
    {
        try {
            if ($this->missingLoadedPrimaryKey) {
                throw new RuntimeError('can not insert entity without primary key value');
            }
            $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
            if($entityRef->getOnInsert()){
                $ret = $this->callHook($entityRef->getOnInsert());
                if($ret === false){
                    return false;
                }
            }
            // 省略值为 null 的属性，让数据库使用默认值。
            $selection = $this->queryLimit();
            $query = $selection->__getQueryBuilder();
            $tableName = $this->tableName();
            if ($updateDuplicateCols !== null) {
                if ($query instanceof EntityQueryBuilder && $query->getDuplicateColumns() !== null) {
                    throw new \InvalidArgumentException('Configure duplicate updates through insert() or onDuplicate()');
                }
                $query->onDuplicate($updateDuplicateCols);
            }
            $properties = $selection->filterProperties($entityRef->allProperties(), builder: $query);
            $data = FieldCodec::values($this, $properties, true);
            $compareValues = $data;
            // 构建 SQL 会清理选项，提前读取包括 func() 设置在内的冲突更新列。
            $duplicateColumns = $query instanceof EntityQueryBuilder ? $query->getDuplicateColumns() : $updateDuplicateCols;
            $query->insert($tableName, $data);
            if ($duplicateColumns) {
                // 构建器验证更新列后，只保留两个分支都写入实体值的字段。
                // 显式更新值可能覆盖实体值，因此对应字段保留原基准。
                $confirmed = [];
                foreach ($duplicateColumns as $key => $column) {
                    if (is_int($key) && array_key_exists($column, $compareValues)) {
                        $confirmed[$column] = $compareValues[$column];
                    }
                }
                $compareValues = $confirmed;
            }
            $autoIncrement = $entityRef->getAutoIncrementProperty();
            if ($autoIncrement !== null && array_key_exists($autoIncrement->name, $data)) {
                // 显式写入自增字段时保留调用方给出的值。
                $autoIncrement = null;
            }
            // 执行前消费本次配置，公开回调可以用新配置发起查询。
            $this->resetInsertQueryOptions();
            $result = self::callQuery($query, $this->onQuery,
                function (QueryResult $result) use ($compareValues, $autoIncrement): void {
                    if ($result->getResult() !== true) { return; }
                    $insertId = $result->getInsertId();
                    if ($autoIncrement !== null && $insertId >= 1) {
                        // 先按字段定义转换并建立基准，公开回调可直接使用生成的值。
                        $field = $autoIncrement->name;
                        $this->{$field} = FieldCodec::assign($autoIncrement, $insertId);
                        $compareValues[$field] = FieldCodec::compare($this->{$field});
                    }
                    // 只接受本次成功写入字段，不重新赋值，也不覆盖稍后公开回调的修改。
                    $this->entityState->accept($compareValues);
                });
            return $result->getResult() === true;
        } finally {
            // 回调内临时设置的白名单也只在本次调用内生效。
            $this->resetInsertQueryOptions();
        }
    }

    public static function findRecord(
        callable|array|string|int $queryLimit,
        string|null $tableName = null,
        bool $selectForUpdate = false,
        callable|null $onQuery = null
    ): ?static
    {
        if ($tableName === null) {
            $tableName = (new static())->tableName();
        }
        $query = new EntityQueryBuilder();
        self::applyQueryLimit($query, $queryLimit, 'find record');
        // 仅在未指定 LIMIT 时默认取一条，显式 LIMIT 中的偏移量由构造器原样保留。
        $rowCount = $query->getLimitRowCount();
        if ($rowCount === null) {
            $query->limit(1);
        } elseif ($rowCount > 1) {
            throw new \InvalidArgumentException('findRecord() supports a LIMIT of at most one row');
        }
        if ($selectForUpdate) {
            $query->selectForUpdate();
        }
        $query->get($tableName);
        $ret = static::callQuery($query,$onQuery)->getResult();
        if (!empty($ret[0])) {
            return self::hydrateEntity(static::class, $ret[0]);
        }

        return null;
    }

    public static function findAll(
        array|callable|string|null $queryLimit = null,
        string|null $tableName = null,
        bool $returnAsArray = false,
        bool $selectForUpdate = false,
        callable|null $onQuery = null
    ):mixed
    {
        if ($tableName === null) {
            $tableName = (new static())->tableName();
        }
        $query = new QueryBuilder();
        self::applyQueryLimit($query, $queryLimit, 'find all record');
        if($selectForUpdate){
            $query->selectForUpdate();
        }

        $query->get($tableName);
        $result = self::callQuery($query,$onQuery)->getResult();
        if (!$returnAsArray) {
            $list = [];
            foreach ($result as $item) {
                $list[] = self::hydrateEntity(static::class, $item);
            }
            return $list;
        } else {
            return $result;
        }
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    protected function callHook(Call $callback):mixed
    {
        if (is_string($callback->callback)) {
            return $this->{$callback->callback}();
        }
        return ($callback->callback)($this);
    }

    /** @internal 直接读取关联声明并调用配置方法，不执行业务 getter。 */
    public function __relationPlan(string $method): RelationPlan
    {
        $relate = ReflectionCache::getInstance()->parseRelation(static::class, $method);
        $originalQuery = $this->queryBuilder;
        // 子类可能在 queryLimit() 中追加条件，预加载只允许修改临时配置。
        $this->queryBuilder = $originalQuery === null ? null : clone $originalQuery;
        try {
            $query = $this->queryLimit();
        } finally {
            $this->queryBuilder = $originalQuery;
        }
        $this->configureRelation($relate, $query);
        $target = new $relate->targetEntity();
        $tableName = $target->tableName();
        $connectionName = FastDb::getInstance()->selectConnection();
        $selfValue = FieldCodec::compare($this->{$relate->selfProperty});
        $builder = $query->__getQueryBuilder();
        $fields = $query->getFields();
        $returnAsArray = $fields['returnAsArray'] ?? false;
        // 一对一回填复用用于取得表名的实例，与按需加载的构造顺序一致。
        return new RelationPlan($relate, $tableName, $builder, $fields['fields'] ?? null,
            $returnAsArray, $connectionName, $selfValue,
            $relate->type === RelationType::One && !$returnAsArray ? $target : null);
    }

    protected function relateOne(Relate|null $relate = null,string|null $tableName = null):null|array|AbstractEntity
    {
        return $this->loadRelation(RelationType::One, $this->parseRelate($relate), $tableName);
    }

    protected function relateMany(Relate|null $relate = null,string|null $tableName = null)
    {
        return $this->loadRelation(RelationType::Many, $this->parseRelate($relate), $tableName);
    }

    function setOnQuery(?callable $call):static
    {
        $this->onQuery = $call;
        return $this;
    }

    // 内部实现按初始化、结果组装、写入与关联处理的顺序集中放置。

    private function init()
    {
        $this->entityState = new EntityState();
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        // 通过缓存的属性元数据初始化字段，并建立默认值的比较基准。
        foreach ($entityRef->allProperties() as $property) {
            $name = $property->name;
            $value = FieldCodec::assign($property, $property->getDefaultValue());
            if ($value !== null || $property->allowNull) {
                $this->{$name} = $value;
            }
            // 类型属性可能在赋值时转换默认值，按实际属性值建立比较基准。
            $this->entityState->baseline[$name] = FieldCodec::compare($this->{$name} ?? null);
        }
        if($entityRef->getOnInitialize()){
            $this->callHook($entityRef->getOnInitialize());
        }
    }

    /** 列表与两种分块共用查询和结果组装；查询状态由调用入口清理。 */
    private function selectRows(Query $selection, bool $selectForUpdate = false, ?array &$page = null,
        ?string $cursorField = null): ListResult
    {
        $query = $selection->__getQueryBuilder();
        $fields = $selection->getFields();
        $returnAsArray = $fields['returnAsArray'] ?? false;
        if ($selectForUpdate) { $query->selectForUpdate(); }
        $table = $page['table'] ?? $this->tableName();
        $query->get($table, null, $fields['fields'] ?? null);
        $relations = $selection->getRelations();
        $result = static::callQuery($query, $this->onQuery, connection: $page['connection'] ?? null,
            onTotalQuery: $this->notifyTotalQuery(...));
        $total = $result->getTotalCount();
        $hideFields = $selection->getHideFields() ?? [];
        $rows = $result->getResult();
        $parentQueryConnection = $result->getConnectionName();
        if ($page !== null) {
            // 使用实际查询的连接和原始结果翻页，字段隐藏和实体转换只影响交付的数据。
            $page['table'] = $table;
            $page['connection'] = $parentQueryConnection;
            $page['rowCount'] = count($rows);
            if ($cursorField !== null && $rows !== []) {
                $page['nextId'] = $rows[array_key_last($rows)][$cursorField]
                    ?? throw new RuntimeError('chunkById requires a non-null cursor value for column: ' . $cursorField);
            }
        }
        $list = $this->hydrateRows($rows, $selection, $returnAsArray, $hideFields, $relations === [] ? null : $parentQueryConnection);
        if ($relations !== []) {
            $list = (new RelationPreloader())->load($this, $list, $rows, $relations, $returnAsArray,
                $parentQueryConnection, $hideFields, fn (QueryBuilder $builder) => static::callQuery($builder, $this->onQuery),
                self::hydrateEntity(...));
        }
        return new ListResult($list, $total);
    }

    /** 实体查询及游标分块共用字段隐藏、转换和输出配置传播。 */
    private function hydrateRows(mixed $rows, Query $selection, bool $returnAsArray, array $hideFields,
        ?string $parentQueryConnection): array
    {
        $list = [];
        if ($returnAsArray && $hideFields === []) {
            // 按值重建列表，保留重编号、解引用和初始迭代位置的既有语义。
            foreach ($rows as $item) { $list[] = $item; }
            return $list;
        }

        $persistFieldLimit = $selection->isPersistFieldLimit();
        $outputFields = $persistFieldLimit ? ($selection->getFields()['fields'] ?? null) : null;
        $db = null;
        $connectionToRestore = null;
        if ($parentQueryConnection !== null && !$returnAsArray) {
            $db = FastDb::getInstance();
            // 父查询的公开回调可能选了其他库，初始化父模型后恢复该选择。
            $connectionToRestore = $db->selectConnection();
        }
        try {
            foreach ($rows as $item) {
                foreach ($hideFields as $field) {
                    unset($item[$field]);
                }
                if ($returnAsArray) {
                    $list[] = $item;
                    continue;
                }

                if ($db !== null) {
                    // 上一条记录的初始化钩子可能切库，每条记录都要重新选回父查询的连接。
                    $db->selectConnection($parentQueryConnection);
                }
                $entity = self::hydrateEntity(static::class, $item);
                if ($persistFieldLimit) {
                    $entity->queryLimit()->inheritOutputFields($outputFields, $hideFields);
                }
                $list[] = $entity;
            }
        } finally {
            if ($db !== null) {
                $db->selectConnection($connectionToRestore);
            }
        }
        return $list;
    }

    /** 主查询回调可能替换实体观察者，总数通知使用当时的回调。 */
    private function notifyTotalQuery(QueryResult $result): void
    {
        if ($this->onQuery !== null) {
            ($this->onQuery)($result);
        }
    }

    private function aggregate(string $aggregate, string|array $cols, string|null $group = null, bool $force = false): int|array|float|string
    {
        try {
            $cols = is_array($cols) ? $cols : [$cols];
            $multiFields = count($cols) > 1;

            // 复用驱动的字段引用和别名规则，支持限定列名及保留字字段。
            $fields = [];
            foreach ($cols as $column) {
                $fields[] = SelectPlanner::aggregateExpression($aggregate, $column, $column, false);
            }
            $query = $this->queryLimit()->__getQueryBuilder();
            $ret = $this->selectAggregateRow($query, $fields, $group);
            if ($ret === []) {
                return $multiFields ? [] : 0;
            }
            if ($multiFields) {
                if ($force) {
                    foreach ($ret as &$row) {
                        $row = (float) $row;
                    }
                    unset($row);
                }
                return $ret;
            } else {
                $ret = $ret[array_key_first($ret)] ?? 0;
                if ($force) {
                    return (float) $ret;
                }
                return $ret;
            }
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->resetQueryOptions();
        }
    }

    private function selectAggregateRow(QueryBuilder $query, array $fields, ?string $group): array
    {
        if ($group !== null) {
            $query->groupBy($group);
        }
        $query->get($this->tableName(), 1, $fields);
        return static::callQuery($query, $this->onQuery)->getResult()[0] ?? [];
    }

    private static function fastWriteQuery(array|callable|string|int $limit, string $operation): QueryBuilder
    {
        if ($limit === '') {
            throw new \InvalidArgumentException('Fast writes require a non-empty primary key');
        }
        $query = new QueryBuilder();
        self::applyQueryLimit($query, $limit, $operation);
        if (!$query->hasWhere()) {
            throw new \InvalidArgumentException('Fast writes require a WHERE condition');
        }
        return $query;
    }

    /** 标量是主键，数组是条件；数组或字符串形式的回调由调用方显式转换。 */
    private static function applyQueryLimit(QueryBuilder $query, array|callable|string|int|null $limit,
        string $operation): void
    {
        if (is_string($limit) || is_int($limit)) {
            $key = ReflectionCache::getInstance()->parseEntity(static::class)->getPrimaryKey();
            if ($key === null) { throw new RuntimeError('entity can not ' . $operation . ' without primary key define'); }
            $query->where($key, $limit);
        } elseif (is_array($limit)) {
            foreach ($limit as $column => $value) {
                if (!is_string($column)) {
                    throw new \InvalidArgumentException('Query conditions require column names as array keys');
                }
                if (is_array($value)) {
                    if (!array_is_list($value) || count($value) < 2 || count($value) > 3) {
                        throw new \InvalidArgumentException('Array conditions require [value, operator, optional conjunction]');
                    }
                    $query->where($column, ...$value);
                } else {
                    $query->where($column, $value);
                }
            }
        } elseif ($limit !== null) {
            $limit($query);
        }
    }

    private static function writeBatch(iterable $rows, ?array $updates, ?string $tableName,
        ?callable $onQuery, int $batchSize): int|string
    {
        $db = FastDb::getInstance();
        $entryConnection = $db->selectConnection();
        return BatchWriter::write($rows, $updates, $batchSize,
            static function () use ($db, $entryConnection, $tableName): array {
                if ($tableName !== null) { return [$tableName, $entryConnection]; }
                $visible = $db->selectConnection();
                $db->selectConnection($entryConnection);
                try {
                    $table = (new static())->tableName();
                    return [$table, $db->selectConnection()];
                } finally { $db->selectConnection($visible); }
            },
            static fn (QueryBuilder $query, string $connection) => self::callQuery($query, $onQuery, connection: $connection)->getAffectedRows());
    }

    private function resetQueryOptions():void
    {
        // 只清理查询状态，不清空实体属性、比较基准或查询回调。
        $this->queryBuilder = null;
    }

    private function resetInsertQueryOptions(): void
    {
        $hideFields = $this->queryBuilder?->getHideFields();
        $this->queryBuilder = $hideFields === null ? null : (new Query($this))->hideFields($hideFields);
    }

    /** 实例写入统一使用原主键约束查询范围。 */
    private function requirePrimaryKey(QueryBuilder $query, EntityReflection $entityRef, string $operation): void
    {
        $key = $entityRef->getPrimaryKey()
            ?? throw new RuntimeError("can not {$operation} entity without primary key set");
        // 未赋值、null 和空字符串视为缺失，整数 0 和字符串 '0' 都是有效主键。
        if ($this->missingLoadedPrimaryKey || !isset($this->{$key}) || $this->{$key} === '') {
            throw new RuntimeError("can not {$operation} entity without primary key value");
        }
        $primaryKey = $this->entityState->primaryKey($this, $key);
        // 在真正执行的构建器上最后追加主键，防止后续追加的 OR 落到主键约束之外。
        $query->groupExistingWhere()->where($key, $primaryKey);
    }

    /** 按需加载和预加载共用显式配置；不临时替换实体持有的查询对象。 */
    private function configureRelation(Relate $relate, Query $query): void
    {
        if ($relate->returnAsArray) { $query->returnAsArray(); }
        if ($relate->configure !== null) {
            $this->{$relate->configure}($query->__getQueryBuilder());
        }
    }

    private function loadRelation(RelationType $expectedType, Relate $relate, ?string $tableName): mixed
    {
        try {
            // 声明与调用入口必须一致，在配置、缓存读取和 SQL 执行前拦截。
            if ($relate->type !== $expectedType) {
                throw new RuntimeError('Declared relation type does not match its getter');
            }
            $this->relationLoader ??= new RelationLoader();
            $selection = $this->queryLimit();
            $this->configureRelation($relate, $selection);
            return $this->relationLoader->load($this, $selection, $relate, $tableName,
                fn ($query) => static::callQuery($query, $this->onQuery),
                fn () => $this->resetQueryOptions(), self::hydrateEntity(...));
        } finally {
            $this->resetQueryOptions();
        }
    }

    private function parseRelate(?Relate $relate = null): Relate
    {
        $metadata = ReflectionCache::getInstance();
        if ($relate !== null) {
            return $metadata->resolveRelation(static::class, $relate);
        }
        // 未显式传入定义时，从当前实体的关联方法读取注解。
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        return $metadata->parseRelation(static::class, $trace[2]['function']);
    }

    /** 统一执行模型查询，并在成功或异常时通知本次查询回调。 */
    private static function callQuery(QueryBuilder $query,?callable $onQuery = null,?callable $onComplete = null,
        ?string $connection = null, ?callable $onTotalQuery = null):QueryResult
    {
        // 实体只在 SQL 成功后接受基准、读取游标等，通知与异常由执行层处理。
        $complete = $onComplete === null ? null : static function (QueryResult $result, Connection $connection) use ($onComplete): void {
            if ($result->getException() === null) { $onComplete($result, $connection); }
        };
        return FastDb::getInstance()->query($query, onComplete: $complete, connection: $connection,
            onQuery: $onQuery, onTotalQuery: $onTotalQuery);
    }
}
