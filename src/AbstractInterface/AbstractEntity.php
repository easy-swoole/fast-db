<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Internal\EntityState;
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
        $this->relationLoader->prime($plan->relate, $lookup, $plan->kind,
            (bool) ($plan->query->getFields()['returnAsArray'] ?? false), $connectionName, $data);
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
            $this->{$key} = FieldCodec::assign($this, $property, $val);
            if ($mergeCompare) {
                $this->entityState->baseline[$key] = FieldCodec::compare($this->{$key});
            }
        }
        return $this;
    }

    /** 判断全部或指定映射字段是否相对比较基准发生变化，不调用 Property::toValue 或执行 SQL。 */
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
        finally { $this->reset(); }
    }

    function chunk(callable $func,int $chunkSize = 10)
    {
        try {
            $page = 1;
            while (true){
                // 仅按本页数量判断是否结束，不为每一页额外查询总数。
                $this->queryLimit()->page($page,false,$chunkSize);
                // 列表查询会清理状态，因此先保存条件供下一页继续使用。
                $builder = clone $this->queryBuilder;
                $rowCount = 0;
                $list = $this->selectRows($this->queryLimit(), false,
                    static function (QueryResult $result) use (&$rowCount): void {
                        $rowCount = count($result->getResult());
                    })->list();
                $this->reset();
                foreach ($list as $item){
                    if (call_user_func($func,$item) === false) { return; }
                }
                if($rowCount < $chunkSize){
                    break;
                }else{
                    $page++;
                    $this->queryBuilder = $builder;
                }
            }
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->reset();
        }
    }

    /**
     * 按稳定唯一字段 ASC 分块，默认使用主键；每条回调返回 false 时停止。
     * 返回已交付回调的记录数，包含返回 false 的那条记录。
     */
    public function chunkById(callable $func, int $chunkSize = 10, ?string $column = null): int
    {
        try {
            if ($chunkSize < 1) {
                throw new \InvalidArgumentException('Chunk size must be greater than zero');
            }
            $reflection = ReflectionCache::getInstance()->parseEntity(static::class);
            $column ??= $reflection->getPrimaryKey();
            if ($column === null || !isset($reflection->allProperties()[$column])) {
                throw new RuntimeError('chunkById requires a declared stable unique field or a primary key');
            }
            $template = clone $this->queryLimit();
            $cursorColumn = TableReference::column($column);
            $lastId = null;
            $delivered = 0;
            while (true) {
                $selection = clone $template;
                // 原条件先成组，避免 OR 绕过本页的游标边界。
                $builder = $selection->__getQueryBuilder()->groupExistingWhere();
                if ($lastId !== null) { $builder->where($cursorColumn, $lastId, '>'); }
                $builder->reorder()->orderByRaw($cursorColumn . ' ASC')->limit($chunkSize);
                $this->queryBuilder = $selection;
                $rowCount = 0;
                $nextId = null;
                // 翻页依据原始页，公开回调只能改变交付的数据。
                $list = $this->selectRows($selection, false,
                    static function (QueryResult $result) use ($column, &$rowCount, &$nextId): void {
                        $rows = $result->getResult();
                        $rowCount = count($rows);
                        if ($rowCount > 0) { $nextId = $rows[array_key_last($rows)][$column]; }
                    })->list();
                if ($rowCount === 0) { return $delivered; }
                $this->reset();
                foreach ($list as $item) {
                    $delivered++;
                    if ($func($item) === false) { return $delivered; }
                }
                if ($rowCount < $chunkSize) { return $delivered; }
                $lastId = $nextId;
                unset($list, $item);
            }
        } finally {
            $this->reset();
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
            $fields = $this->queryLimit()->getFields()['fields'] ?? null;
            $query = $this->queryLimit()->__getQueryBuilder();
            $hasFields = !empty($fields);

            if ($group) {
                $query->groupBy($group);
            }

            if ($hasFields) {
                $temp = [];
                foreach ($fields as $fieldName){
                    $temp[] = SelectPlanner::aggregateExpression('COUNT', $fieldName, $fieldName, false);
                }
                $fields = $temp;
                $query->get($this->tableName(),1, $fields);
            } else {
                $column = $field ?? '*';
                // 普通列交给驱动引用；保留单列 count 原有的 DISTINCT、CASE 等可信 SQL 参数。
                $identifier = '(?:`(?:``|[^`])+`|[\p{L}_$][\p{L}\p{N}_$]*)';
                if ($column !== '*' && (in_array(strtoupper($column), ['NULL', 'TRUE', 'FALSE'], true)
                    || preg_match('/\A' . $identifier . '(?:\.' . $identifier . ')*\z/uD', $column) !== 1)) {
                    $column = QueryBuilder::rawExpression($column);
                }
                $query->get($this->tableName(), 1, [SelectPlanner::aggregateExpression('COUNT', $column, 'count', false)]);
            }
            $ret = static::callQuery($query,$this->onQuery)->getResult();
            if (empty($ret)) {
                if ($hasFields) {
                    return [];
                }
                return 0;
            }
            $ret = $ret[0];
            if ($hasFields) {
                return $ret;
            }

            return $ret['count'];
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->reset();
        }
    }

    public function sum(string|array $cols, string|null $group = null, bool $force = true): int|array|float
    {
        return $this->aggregate('SUM', $cols, $group, $force);
    }

    public function avg(string|array $cols, string|null $group = null, bool $force = true): int|array|float
    {
        return $this->aggregate('AVG', $cols, $group, $force);
    }

    public function max(string|array $cols, string|null $group = null, bool $force = true): int|array|float
    {
        return $this->aggregate('MAX', $cols, $group, $force);
    }

    public function min(string|array $cols, string|null $group = null, bool $force = true): int|array|float
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
            // 实体删除必须带有有效主键，附加查询条件与主键条件共同生效。
            $pk = $this->primaryKeyCheck('delete');
            $this->queryLimit()->whereRequired($pk,$this->entityState->primaryKey($this, $pk));
            $query = $this->queryLimit()->__getQueryBuilder();
            $query->delete($this->tableName());
            $ret = static::callQuery($query,$this->onQuery);
            return $ret->getAffectedRows() >= 1;
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->reset();
        }
    }

    /** 直接按条件删除，不执行实体的 OnDelete 钩子。 */
    public static function fastDelete(
        array|callable|string|int $deleteLimit,
        string|null $tableName = null,
        callable|null $onQuery = null
    ):int|null|string
    {
        if ($deleteLimit === [] || $deleteLimit === '') {
            return 0;
        }

        $entity = new static();
        if (empty($tableName)) {
            $tableName = $entity->tableName();
        }
        $query = new QueryBuilder();
        self::applyQueryLimit($query, $deleteLimit, true, 'delete record');
        $query->delete($tableName);
        $ret = static::callQuery($query,$onQuery);
        return $ret->getAffectedRows();
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
            $properties = $this->queryLimit()->filterProperties($entityRef->allProperties());
            $compareValues = [];
            $data = $this->entityState->changes($this, $properties, $compareValues);

            if(empty($data)){
                return true;
            }
            $pk = $this->primaryKeyCheck('update');
            $this->queryLimit()->whereRequired($pk,$this->entityState->primaryKey($this, $pk));
            $query = $this->queryLimit()->__getQueryBuilder();
            $query->update($this->tableName(),$data);
            // 按 SQL 成功顺序接受基准，再通知观察回调；重入保存可据此写回原值。
            $ret = static::callQuery($query,$this->onQuery,
                function (QueryResult $result) use ($compareValues): void {
                    if ($result->getAffectedRows() > 0) { $this->entityState->accept($compareValues); }
                });
            return $ret->getAffectedRows() > 0;
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->reset();
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
        $entity = new static();
        if(empty($tableName)){
            $tableName = $entity->tableName();
        }
        $query = new QueryBuilder();
        self::applyQueryLimit($query, $updateLimit, true, 'update record');
        $query->update($tableName,$data);
        $ret = static::callQuery($query,$onQuery);
        return $ret->getAffectedRows();
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
            $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
            if($entityRef->getOnInsert()){
                $ret = $this->callHook($entityRef->getOnInsert());
                if($ret === false){
                    return false;
                }
            }
            // 生成写入值和比较基准；普通 null 字段省略，让数据库使用默认值。
            $compareValues = [];
            $properties = $this->queryLimit()->filterProperties($entityRef->allProperties());
            $data = FieldCodec::values($this, $properties, true, $compareValues);
            $query = $this->queryLimit()->__getQueryBuilder();
            if($updateDuplicateCols){
                $query->onDuplicate($updateDuplicateCols);
            }
            $query->insert($this->tableName(),$data);
            $isSuccess = false;
            $primaryKey = $entityRef->getPrimaryKey();
            static::callQuery($query, $this->onQuery,
                function (QueryResult $result) use ($compareValues, $primaryKey, $entityRef, &$isSuccess): void {
                    $isSuccess = $result->getResult() === true;
                    if (!$isSuccess) { return; }
                    if ($primaryKey !== null && $result->getInsertId() >= 1) {
                        // 自增主键按字段定义转换后回填，再建立基准，供公开回调继续更新。
                        $this->{$primaryKey} = FieldCodec::assign($this, $entityRef->allProperties()[$primaryKey], $result->getInsertId());
                        $compareValues[$primaryKey] = FieldCodec::compare($this->{$primaryKey});
                    }
                    // 只接受本次成功写入字段，不重新赋值，也不覆盖稍后公开回调的修改。
                    $this->entityState->accept($compareValues);
                });
            return $isSuccess;
        } finally {
            // 插入后兼容保留隐藏字段等输出配置，仅清除 SQL 条件。
            $this->reset(true);
        }
    }

    public static function findRecord(
        callable|array|string|int $queryLimit,
        string|null $tableName = null,
        bool $selectForUpdate = false,
        callable|null $onQuery = null
    ): ?static
    {
        $entity = new static();
        if (empty($tableName)) {
            $tableName = $entity->tableName();
        }
        $query = new QueryBuilder();
        self::applyQueryLimit($query, $queryLimit, false, 'find record');
        if($selectForUpdate){
            $query->selectForUpdate();
        }
        $query->get($tableName, 1);
        $ret = static::callQuery($query,$onQuery)->getResult();
        if (!empty($ret[0])) {
            return new static($ret[0]);
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
        $entity = new static();
        if (empty($tableName)) {
            $tableName = $entity->tableName();
        }
        $query = new QueryBuilder();
        self::applyQueryLimit($query, $queryLimit, true, 'find all record');
        if($selectForUpdate){
            $query->selectForUpdate();
        }

        $query->get($tableName);
        $result = self::callQuery($query,$onQuery)->getResult();
        if (!$returnAsArray) {
            $list = [];
            foreach ($result as $item) {
                $list[] = new static($item);
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
        if (is_callable($callback->callback)) {
            return ($callback->callback)(...$callback->buildEntityHookRuntimeParams($this));
        }
        // 方法名钩子由 PHP 按当前实体作用域调用，调用错误直接传播。
        return $this->{$callback->callback}();
    }

    /** @internal 直接读取关联声明并调用配置方法，不执行业务 getter。 */
    public function __relationPlan(string $method): RelationPlan
    {
        $relate = $this->parseRelate(ReflectionCache::getInstance()->parseRelation(static::class, $method));
        if ($relate->type === null) {
            throw new RuntimeError('Preloading requires an explicit relation type: ' . $method);
        }
        $query = clone $this->queryLimit();
        $this->configureRelation($relate, $query);
        $target = new $relate->targetEntity();
        return new RelationPlan($relate->type, $relate, $target->tableName(), $query,
            FastDb::getInstance()->selectConnection(), FieldCodec::compare($this->{$relate->selfProperty} ?? null));
    }

    protected function relateOne(Relate|null $relate = null,string|null $tableName = null):null|array|AbstractEntity
    {
        return $this->loadRelation('one', $this->parseRelate($relate), $tableName);
    }

    protected function relateMany(Relate|null $relate = null,string|null $tableName = null)
    {
        return $this->loadRelation('many', $this->parseRelate($relate), $tableName);
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
        /** @var Property $property */
        foreach ($entityRef->allProperties() as $property){
            $defaultValue = $property->defaultValue;
            // 默认对象按自身 __clone 语义复制，避免实体修改反射缓存中的共享实例；枚举保持原值。
            if (is_object($defaultValue) && !($defaultValue instanceof \UnitEnum)) {
                $defaultValue = clone $defaultValue;
            }
            if($property->convertObject){
                // 非空字段即使默认值为 null，也交给转换器生成有效对象；可空字段保留 null。
                if((!$property->allowNull) || ($defaultValue !== null)){
                    // 已经是目标类型的默认值不再转换，避免枚举实例被转换成其他枚举值。
                    /** @var ConvertObjectInterface $object */
                    $object = $defaultValue instanceof $property->convertObject
                        ? $defaultValue
                        : call_user_func([$property->convertObject,'toObject'],$defaultValue);
                    $this->{$property->name} = $object;
                    $this->entityState->baseline[$property->name] = $object->toValue();
                }else{
                    $this->{$property->name} = null;
                    $this->entityState->baseline[$property->name] = $defaultValue;
                }
            }else{
                if(($defaultValue !== null) || $property->allowNull){
                    $this->{$property->name} = $defaultValue;
                }
                $this->entityState->baseline[$property->name] = $defaultValue;
            }
        }
        if($entityRef->getOnInitialize()){
            $this->callHook($entityRef->getOnInitialize());
        }
    }

    /** 列表与两种分块共用查询、总数通知和结果组装；查询状态由调用入口清理。 */
    private function selectRows(Query $selection, bool $selectForUpdate = false, ?callable $onComplete = null): ListResult
    {
        $query = $selection->__getQueryBuilder();
        $fields = $selection->getFields();
        $returnAsArray = $fields['returnAsArray'] ?? false;
        if ($selectForUpdate) { $query->selectForUpdate(); }
        $query->get($this->tableName(), null, $fields['fields'] ?? null);
        $relations = $selection->getRelations();
        $connectionName = null;
        $completed = $onComplete;
        if ($relations !== []) {
            // 在公开回调前记录实际查询连接，预加载沿用该连接。
            $completed = static function (QueryResult $result, Connection $connection) use (&$connectionName, $onComplete): void {
                $connectionName = $result->getConnectionName();
                if ($onComplete !== null) { $onComplete($result, $connection); }
            };
        }
        $result = static::callQuery($query, $this->onQuery, $completed);
        $total = $this->readTotal($result);
        $hideFields = $selection->getHideFields() ?: [];
        $rows = $result->getResult();
        $list = $this->hydrateRows($rows, $selection, $returnAsArray, $hideFields);
        if ($relations !== []) {
            $list = (new RelationPreloader())->load($this, $list, $rows, $relations, $returnAsArray,
                $connectionName, $hideFields, fn (QueryBuilder $builder) => static::callQuery($builder, $this->onQuery));
        }
        return new ListResult($list, $total);
    }

    /** 实体查询及游标分块共用字段隐藏、转换和输出配置传播。 */
    private function hydrateRows(mixed $rows, Query $selection, bool $returnAsArray, array $hideFields): array
    {
        $list = [];

        if($returnAsArray){
            if ($hideFields === []) {
                // 按值重建列表，保留重编号、解引用和初始迭代位置的既有语义。
                foreach ($rows as $item) { $list[] = $item; }
            } else {
                foreach ($rows as $item){
                    foreach ($hideFields as $field){
                        unset($item[$field]);
                    }
                    $list[] = $item;
                }
            }
        }else{
            foreach ($rows as $item){
                foreach ($hideFields as $field){
                    unset($item[$field]);
                }
                // 构造实体时完成字段转换，并将查询结果作为后续更新的比较基准。
                $t = new static($item);
                if($selection->isPersistFieldLimit()){
                    $t->queryLimit()->hideFields($hideFields);
                    if(!empty($selection->getFields())){
                        $t->queryLimit()->fields(...$selection->getFields());
                    }
                }
                $list[] = $t;
            }
        }
        return $list;
    }

    /** 使用预取的总数快照，同时保留实体级查询回调对总数查询的通知。 */
    private function readTotal(QueryResult $result): ?int
    {
        $count = $result->getTotalResult();
        if ($count !== null && $this->onQuery !== null) {
            ($this->onQuery)($count);
        }
        $value = $count?->getResultOne()['count'] ?? null;
        return $value === null ? null : (int) $value;
    }

    private function aggregate(string $aggregate, string|array $cols, string|null $group = null, bool $force = false): int|array|float
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
            if ($group) {
                $query->groupBy($group);
            }
            $query->get($this->tableName(), 1, $fields);
            $ret = static::callQuery($query,$this->onQuery)->getResult();
            if (empty($ret)) {
                if ($multiFields) {
                    return [];
                }
                return 0;
            }
            $ret = $ret[0];
            if ($multiFields) {
                if ($force) {
                    foreach ($ret as &$row) {
                        $row = (float) $row;
                    }
                    unset($row);
                }
                return $ret;
            } else {
                $ret = array_values($ret)[0] ?: 0;
                if ($force) {
                    return (float) $ret;
                }
                return $ret;
            }
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->reset();
        }
    }

    /** 字符串始终作为主键；其余 callable（包括数组回调）优先于条件数组解析。 */
    private static function applyQueryLimit(QueryBuilder $query, array|callable|string|int|null $limit,
        bool $multiple, string $operation): void
    {
        if (is_string($limit) || is_int($limit)) {
            $key = ReflectionCache::getInstance()->parseEntity(static::class)->getPrimaryKey();
            if ($key === null) { throw new RuntimeError('entity can not ' . $operation . ' without primary key define'); }
            if ($multiple && is_string($limit) && str_contains($limit, ',')) {
                $query->where($key, explode(',', $limit), 'IN');
            } else { $query->where($key, $limit); }
        } elseif (is_callable($limit)) {
            $limit($query);
        } elseif (is_array($limit)) {
            foreach ($limit as $column => $value) {
                if (is_array($value)) { $query->where($column, ...$value); }
                else { $query->where($column, $value); }
            }
        }
    }

    private static function writeBatch(iterable $rows, ?array $updates, ?string $tableName,
        ?callable $onQuery, int $batchSize): int|string
    {
        $db = FastDb::getInstance();
        $entryConnection = $db->selectConnection();
        return BatchWriter::write($rows, $updates, $batchSize,
            static function () use ($db, $entryConnection, $tableName): array {
                if ($tableName !== null && $tableName !== '') { return [$tableName, $entryConnection]; }
                $visible = $db->selectConnection();
                $db->selectConnection($entryConnection);
                try {
                    $table = (new static())->tableName();
                    return [$table, $db->selectConnection()];
                } finally { $db->selectConnection($visible); }
            },
            static fn (QueryBuilder $query, string $connection) => static::callQuery($query, $onQuery, null, $connection));
    }

    private function reset(bool $preserveSelection = false):void
    {
        // 只清理查询状态，不清空实体属性、比较基准或查询回调。
        $this->queryBuilder = $preserveSelection ? $this->queryBuilder?->withoutSqlConditions() : null;
    }

    private function primaryKeyCheck(string $op):string
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        $pk = $entityRef->getPrimaryKey();
        if($pk === null){
            $msg = "can not {$op} entity without primary key set";
            throw new RuntimeError($msg);
        }
        // 未赋值、null 和空字符串视为缺失；整数 0 和字符串 '0' 均为有效主键。
        if(!isset($this->{$pk}) || $this->{$pk} === ''){
            $msg = "can not {$op} entity without primary key value";
            throw new RuntimeError($msg);
        }
        return $pk;
    }

    /** 按需加载和预加载共用显式配置；不临时替换实体持有的查询对象。 */
    private function configureRelation(Relate $relate, Query $query): void
    {
        if ($relate->returnAsArray) { $query->returnAsArray(); }
        if ($relate->configure !== null) {
            $this->{$relate->configure}($query->__getQueryBuilder());
        }
    }

    private function loadRelation(string $kind, Relate $relate, ?string $tableName): mixed
    {
        $this->relationLoader ??= new RelationLoader();
        try {
            if ($relate->type !== null && $relate->type !== $kind) {
                throw new RuntimeError('Declared relation type does not match its getter');
            }
            $this->configureRelation($relate, $this->queryLimit());
            return $this->relationLoader->load($this, $relate, $tableName, $kind,
                fn ($query) => static::callQuery($query, $this->onQuery), fn () => $this->reset(), fn ($result) => $this->readTotal($result));
        } finally {
            $this->reset();
        }
    }

    private function parseRelate(?Relate $relate = null)
    {
        if($relate == null){
            // 未显式传入关系定义时，从调用该方法的实体方法上读取 Relate 属性。
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,3);
            $method = $trace[2]['function'];
            $relate = ReflectionCache::getInstance()->parseRelation(static::class, $method);
        }
        // 校验关联两端的字段，避免使用未声明的实体属性。
        $check = ReflectionCache::getInstance()->parseEntity($relate->targetEntity);
        // 未指定当前实体关联字段时，默认使用当前实体的主键。
        if(empty($relate->selfProperty)){
            $relate->selfProperty = ReflectionCache::getInstance()->parseEntity(static::class)->getPrimaryKey()
                ?? throw new RuntimeError('can not relate entity without primary key set');
        }else{
            if(!isset(ReflectionCache::getInstance()->parseEntity(static::class)->allProperties()[$relate->selfProperty])){
                $msg = "{$relate->selfProperty} is not a define property in ".static::class;
                throw new RuntimeError($msg);
            }
        }
        if(!key_exists($relate->targetProperty,$check->allProperties())){
            $msg = "{$relate->targetProperty} is not a define property in {$relate->targetEntity}";
            throw new RuntimeError($msg);
        }
        return $relate;
    }

    /** 统一执行模型查询，并在成功或异常时通知本次查询回调。 */
    private static function callQuery(QueryBuilder $query,?callable $onQuery = null,?callable $onComplete = null,
        ?string $connection = null):QueryResult
    {
        $db = FastDb::getInstance();
        $ret = null;
        $complete = $onQuery === null && $onComplete === null ? null : static function (QueryResult $result, Connection $connection) use ($onComplete, &$ret): void {
            $ret = $result;
            if ($result->getException() === null && $onComplete !== null) { $onComplete($result, $connection); }
        };
        // 没有实体观察回调时，无需再次捕获执行器已经处理过的异常。
        if ($onQuery === null) {
            return $db->query($query, null, $complete, $connection);
        }

        $startTick = hrtime(true);
        $startTime = microtime(true);
        $connectionName = $connection ?? $db->selectConnection();
        $error = null;
        try {
            $ret = $db->query($query, null, $complete, $connection);
        } catch (\Throwable $failure) {
            $error = $failure;
        }
        if ($ret === null) {
            // 借用失败尚未进入执行器，也向实体回调提供错误结果。
            $ret = new QueryResult($startTime, $startTick);
            $ret->setConnectionName($connectionName);
            $ret->finishTiming();
            $ret->setException($error);
            $ret->setQueryBuilder($query);
        }
        try { $onQuery($ret); }
        catch (\Throwable $callbackError) {
            // 执行错误优先于观察回调错误；同一个错误在本层只统一抛出一次。
            $error ??= $callbackError;
        }
        if ($error !== null) { throw $error; }
        return $ret;
    }
}
