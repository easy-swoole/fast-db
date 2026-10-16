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
use EasySwoole\FastDb\Internal\FieldCodec;
use EasySwoole\FastDb\Internal\RelationLoader;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Utility\ReflectionCache;
use EasySwoole\Mysqli\QueryBuilder;

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

    private function init()
    {
        $this->entityState = new EntityState();
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        // 通过缓存的属性元数据初始化字段，并建立默认值的比较基准。
        /** @var Property $property */
        foreach ($entityRef->allProperties() as $property){
            if($property->convertObject){
                // 非空字段即使默认值为 null，也交给转换器生成有效对象；可空字段保留 null。
                if((!$property->allowNull) || ($property->defaultValue !== null)){
                    // 已经是目标类型的默认值直接保留，避免枚举实例被重复转换成其他枚举值。
                    /** @var ConvertObjectInterface $object */
                    $object = $property->defaultValue instanceof $property->convertObject
                        ? $property->defaultValue
                        : call_user_func([$property->convertObject,'toObject'],$property->defaultValue);
                    $this->{$property->name} = $object;
                    $this->entityState->baseline[$property->name] = $object->toValue();
                }else{
                    $this->{$property->name} = null;
                    $this->entityState->baseline[$property->name] = $property->defaultValue;
                }
            }else{
                if(($property->defaultValue !== null) || $property->allowNull){
                    $this->{$property->name} = $property->defaultValue;
                }
                $this->entityState->baseline[$property->name] = $property->defaultValue;
            }
        }
        if($entityRef->getOnInitialize()){
            $this->callHook($entityRef->getOnInitialize());
        }
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

    function all( bool $selectForUpdate = false ):ListResult
    {
        try {
            $query = $this->queryLimit()->__getQueryBuilder();

            $fields = null;
            $returnAsArray = false;
            if(!empty($this->queryLimit()->getFields())){
                $fields = $this->queryLimit()->getFields()['fields'];
                $returnAsArray = $this->queryLimit()->getFields()['returnAsArray'];
            }

            if($selectForUpdate){
                $query->selectForUpdate();
            }

            $query->get($this->tableName(),null,$fields);
            $ret = static::callQuery($query,$this->onQuery);
            $total = null;
            if(in_array('SQL_CALC_FOUND_ROWS',$query->getLastQueryOptions())){
                // 仅在查询启用总数计算时补查总数，普通列表查询的 total 保持 null。
                $total = $this->readTotal($ret);
            }
            $list = [];

            $hideFields = $this->queryLimit()->getHideFields() ?:[];

            if($returnAsArray){
                foreach ($ret->getResult() as $item){
                    foreach ($hideFields as $field){
                        unset($item[$field]);
                    }
                    $list[] = $item;
                }
            }else{
                foreach ($ret->getResult() as $item){
                    foreach ($hideFields as $field){
                        unset($item[$field]);
                    }
                    // 构造实体时完成字段转换，并将查询结果作为后续更新的比较基准。
                    $t = new static($item);
                    if($this->queryLimit()->isPersistFieldLimit()){
                        $t->queryLimit()->hideFields($hideFields);
                        if(!empty($this->queryLimit()->getFields())){
                            $t->queryLimit()->fields(...$this->queryLimit()->getFields());
                        }
                    }
                    $list[] = $t;
                }
            }
            $this->reset();

            return new ListResult($list,$total);
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->reset();
        }
    }

    /** 使用预取的总数快照，同时保留实体级查询回调对总数查询的通知。 */
    private function readTotal(QueryResult $result): ?int
    {
        $count = FastDb::getInstance()->getTotalResult($result);
        if ($count === null) {
            $count = static::callQuery('SELECT FOUND_ROWS() as count', $this->onQuery);
        } elseif (is_callable($this->onQuery)) {
            ($this->onQuery)($count);
        }
        $value = $count->getResultOne()['count'] ?? null;
        return $value === null ? null : (int) $value;
    }

    function chunk(callable $func,int $chunkSize = 10)
    {
        try {
            $page = 1;
            while (true){
                $this->queryLimit()->page($page,true,$chunkSize);
                // all() 会清理查询状态，因此先保存条件供下一页继续使用。
                $builder = clone $this->queryBuilder;
                $list = $this->all()->list();
                foreach ($list as $item){
                    call_user_func($func,$item);
                }
                if(count($list) < $chunkSize){
                    break;
                }else{
                    $page++;
                    $this->queryBuilder = $builder;
                }
            }
            $this->reset();
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->reset();
        }
    }

    /** 将实体字段转为输出值，同时应用隐藏字段和字段白名单。 */
    function toArray(bool $filterNull = false):array
    {
        $hideFields = $this->queryLimit()->getHideFields() ?:[];
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        $temp = FieldCodec::values($this, $entityRef->allProperties(), $filterNull);
        $temp = array_diff_key($temp, array_flip($hideFields));

        $fields = $this->queryLimit()->getFields();
        if(!empty($fields['fields'])){
            $fields = $fields['fields'];
            foreach ($temp as $key => $val){
                if(!in_array($key,$fields)){
                    unset($temp[$key]);
                }
            }
        }

        $this->reset(true);
        return $temp;
    }

    public function count(string|null $field = '*', string|null $group = null): int|array
    {
        try {
            $fields = null;
            if (!empty($this->queryLimit()->getFields())) {
                $fields = $this->queryLimit()->getFields()['fields'];
            }
            $query = $this->queryLimit()->__getQueryBuilder();
            $hasFiled = false;

            if ($group) {
                $query->groupBy($group);
            }

            if (!empty($fields)) {
                $hasFiled = true;
                $temp = [];
                foreach ($fields as $fieldName){
                    $temp[] = "COUNT(`{$fieldName}`) as $fieldName";
                }
                $fields = $temp;
                $query->get($this->tableName(),1, $fields);
            } else {
                $query->get($this->tableName(),1, "count({$field}) as count");
            }
            $ret = static::callQuery($query,$this->onQuery)->getResult();
            $this->reset();
            if (empty($ret)) {
                if ($hasFiled) {
                    return [];
                }
                return 0;
            }
            $ret = $ret[0];
            if ($hasFiled) {
                return $ret;
            }

            return $ret['count'];
        } finally {
            // 成功、失败或提前返回均消费本次查询配置，实体数据及比较基准不受影响。
            $this->reset();
        }
    }

    private function aggregate(string $aggregate, string|array $cols, string|null $group = null, bool $force = false): int|array|float
    {
        try {
            $multiFields = false;
            if (is_string($cols)) {
                $cols = [$cols];
            }

            if (count($cols) > 1) {
                $multiFields = true;
            }

            $str = "";
            while ($item = array_shift($cols)) {
                $str .= "{$aggregate}(`{$item}`) as {$item}";
                if (!empty($cols)) {
                    $str .= " , ";
                }
            }
            $query = $this->queryLimit()->__getQueryBuilder();
            if ($group) {
                $query->groupBy($group);
            }
            $query->get($this->tableName(), 1, $str);
            $ret = static::callQuery($query,$this->onQuery)->getResult();
            $this->reset();
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
            $this->queryLimit()->where($pk,$this->entityState->primaryKey($this, $pk));
            $query = $this->queryLimit()->__getQueryBuilder();
            $query->delete($this->tableName());
            $ret = static::callQuery($query,$this->onQuery);
            $this->reset();
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
        if (empty($deleteLimit) && 0 !== $deleteLimit) {
            return 0;
        }

        $entity = new static();
        if (empty($tableName)) {
            $tableName = $entity->tableName();
        }
        $query = new QueryBuilder();
        if (is_array($deleteLimit)) {
            foreach ($deleteLimit as $key => $item) {
                if (is_array($item)) {
                    $query->where($key, ...$item);
                } else {
                    $query->where($key, $item);
                }
            }
        } else if (is_callable($deleteLimit)) {
            call_user_func($deleteLimit, $query);
        } else if (is_string($deleteLimit) || is_int($deleteLimit)) {
            $pk = ReflectionCache::getInstance()->parseEntity(static::class)->getPrimaryKey();
            if (empty($pk)) {
                $msg = "entity can not delete record without primary key define";
                throw new RuntimeError($msg);
            }

            if (is_string($deleteLimit)) {
                if (strpos($deleteLimit, ',') !== false) {
                    $pkIds = explode(',', $deleteLimit);
                    foreach ($pkIds as &$pkId) {
                        $pkId = intval($pkId);
                    }
                    unset($pkId);
                    $query->where($pk, $pkIds, 'IN');
                }else{
                    $query->where($pk, $deleteLimit);
                }
            } else {
                $query->where($pk, $deleteLimit);
            }
        }

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
            [$data, $compareValues] = $this->entityState->changes($this, $entityRef->allProperties());

            if(!empty($this->queryLimit()->getFields())){
                $fields = $this->queryLimit()->getFields()['fields'];
                if(!empty($fields)){
                    $data = array_intersect_key($data, array_flip($fields));
                }
            }
            if(empty($data)){
                // 本次保存已消费查询配置，即使没有变化也不把字段限制带入下次操作。
                $this->reset();
                return true;
            }
            $pk = $this->primaryKeyCheck('update');
            $this->queryLimit()->where($pk,$this->entityState->primaryKey($this, $pk));
            $query = $this->queryLimit()->__getQueryBuilder();
            $query->update($this->tableName(),$data);
            // 登记必须早于外部回调，并校验事务代次，避免回调回滚后仍接受旧写入。
            $transactionContext = FastDb::getInstance()->getTransactionContext();
            $active = $transactionContext?->isActive() ?? false;
            $token = $transactionContext?->token();
            if ($active) {
                $transactionContext->rememberBaseline($this, $this->entityState->baseline,
                    static function (self $entity, array $baseline): void { $entity->entityState->restore($baseline); });
            }
            $ret = static::callQuery($query,$this->onQuery);
            $this->reset();
            $success = $ret->getAffectedRows() > 0
                && (!$active || !$token->wasRolledBack());
            if ($success) {
                $this->entityState->accept(array_intersect_key($compareValues, $data));
            }
            return $success;
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
        if(is_array($updateLimit)){
            foreach ($updateLimit as $key => $item){
                $query->where($key,$item);
            }
        }else if(is_callable($updateLimit)){
            call_user_func($updateLimit,$query);
        }else{
            $pk = ReflectionCache::getInstance()->parseEntity(static::class)->getPrimaryKey();
            if(empty($pk)){
                $msg = "entity can not update record without primary key define";
                throw new RuntimeError($msg);
            }

            if (is_string($updateLimit)) {
                if (strpos($updateLimit, ',') !== false) {
                    $pkIds = explode(',', $updateLimit);
                    foreach ($pkIds as &$pkId) {
                        $pkId = intval($pkId);
                    }
                    unset($pkId);
                    $query->where($pk, $pkIds, 'IN');
                }else{
                    $query->where($pk, $updateLimit);
                }
            } else {
                $query->where($pk,$updateLimit);
            }
        }
        $query->update($tableName,$data);
        $ret = static::callQuery($query,$onQuery);
        return $ret->getAffectedRows();
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
            // 先完成输出转换；普通 null 字段省略，让数据库使用默认值。
            $data = FieldCodec::values($this, $entityRef->allProperties(), true);
            $fields = $this->queryLimit()->getFields()['fields'] ?? null;
            if (!empty($fields)) { $data = array_intersect_key($data, array_flip($fields)); }
            $query = $this->queryLimit()->__getQueryBuilder();
            if($updateDuplicateCols){
                $query->onDuplicate($updateDuplicateCols);
            }
            $query->insert($this->tableName(),$data);
            $ret = static::callQuery($query,$this->onQuery);
            $isSuccess = false;
            //swoole客户端问题 https://github.com/swoole/swoole-src/issues/5202
            if($ret->getResult()){
                $isSuccess = true;
            }else if($ret->getAffectedRows() >= 1){
                $isSuccess = true;
            }
            if($ret->getInsertId() >= 1){
                // 将数据库生成的自增主键回填到实体和本次写入数据。
                $ref = ReflectionCache::getInstance()->parseEntity(static::class);
                if($ref->getPrimaryKey()){
                    $this->{$ref->getPrimaryKey()} = $ret->getInsertId();
                    $data[$ref->getPrimaryKey()] =  $ret->getInsertId();
                }
                $isSuccess = true;
            }else if(!empty($updateDuplicateCols)){
                $isSuccess = true;
            }
            if($isSuccess){
                // 写入成功后重新转换字段并同步基准，后续 update() 只处理新的修改。
                $this->setData($data,true);
            }
            return $isSuccess;
        } finally {
            // 插入后兼容保留隐藏字段等输出配置，仅清除 SQL 条件。
            $this->reset(true);
        }
    }


    private function reset(bool $preserveSelection = false):void
    {
        // 只清理查询状态，不清空实体属性、比较基准或查询回调。
        $this->queryBuilder = $preserveSelection ? $this->queryBuilder?->withoutSqlConditions() : null;
    }


    private function primaryKeyCheck(string $op,bool $emptyCheck = true):string
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        $pk = $entityRef->getPrimaryKey();
        if(empty($pk)){
            $msg = "can not {$op} entity without primary key set";
            throw new RuntimeError($msg);
        }
        // 关联元数据解析只需要主键名称，可以关闭主键值检查。
        if(empty($this->{$pk}) && $emptyCheck){
            $msg = "can not {$op} entity without primary key value";
            throw new RuntimeError($msg);
        }
        return $pk;
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
        if (is_array($queryLimit)) {
            foreach ($queryLimit as $key => $item) {
                if (is_array($item)) {
                    $query->where($key, ...$item);
                } else {
                    $query->where($key, $item);
                }
            }
        } else if (is_callable($queryLimit)) {
            call_user_func($queryLimit, $query);
        } else {
            $pk = ReflectionCache::getInstance()->parseEntity(static::class)->getPrimaryKey();
            if (empty($pk)) {
                $msg = "entity can not find record without primary key define";
                throw new RuntimeError($msg);
            }
            $query->where($pk, $queryLimit);
        }
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
        if (is_array($queryLimit)) {
            foreach ($queryLimit as $key => $item) {
                if (is_array($item)) {
                    $query->where($key, ...$item);
                } else {
                    $query->where($key, $item);
                }
            }
        } else if (is_callable($queryLimit)) {
            call_user_func($queryLimit, $query);
        } else if (is_string($queryLimit)) {
            $pk = ReflectionCache::getInstance()->parseEntity(static::class)->getPrimaryKey();
            if (empty($pk)) {
                $msg = "entity can not find all record without primary key define";
                throw new RuntimeError($msg);
            }

            if (strpos($queryLimit, ',') !== false) {
                $pkIds = explode(',', $queryLimit);
                foreach ($pkIds as &$pkId) {
                    $pkId = intval($pkId);
                }
                unset($pkId);
                $query->where($pk, $pkIds, 'IN');
            } else {
                $query->where($pk, $queryLimit);
            }
        }
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
        if(is_callable($callback->callback)){
            $p = $callback->buildEntityHookRuntimeParams($this);
            return call_user_func_array($callback->callback,$p);
        }else if(is_string($callback->callback)){
            if(method_exists($this,$callback->callback)){
                $callback = $callback->callback;
                return $this->$callback();
            }else{
                throw new RuntimeError("{$callback->callback} no a method of class ".static::class);
            }
        }
        return null;
    }

    protected function relateOne(Relate|null $relate = null,string|null $tableName = null):null|array|AbstractEntity
    {
        return $this->loadRelation('one', $this->parseRelate($relate), $tableName);
    }

    protected function relateMany(Relate|null $relate = null,string|null $tableName = null)
    {
        return $this->loadRelation('many', $this->parseRelate($relate), $tableName);
    }

    private function loadRelation(string $kind, Relate $relate, ?string $tableName): mixed
    {
        $this->relationLoader ??= new RelationLoader();
        try {
            return $this->relationLoader->$kind($this, $relate, $tableName,
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
            $relate->selfProperty = $this->primaryKeyCheck('relate',false);
        }else{
            if(!key_exists($relate->selfProperty,$this->entityState->baseline)){
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

    function setOnQuery(?callable $call):static
    {
        $this->onQuery = $call;
        return $this;
    }

    /** 统一执行模型查询，并在成功或异常时通知本次查询回调。 */
    private static function callQuery(QueryBuilder|string $query,?callable $onQuery = null):QueryResult
    {
        $startTick = hrtime(true);
        $startTime = microtime(true);
        // 失败时的实体回调也使用发起查询时的连接名，而不是异常回调修改后的选中连接。
        $db = FastDb::getInstance();
        $connectionName = $db->currentConnection()?->connectionName ?? $db->selectConnection();
        $queryException = null;
        try {
            if($query instanceof QueryBuilder){
                $ret = FastDb::getInstance()->query($query);
            }else{
                $ret = FastDb::getInstance()->rawQuery($query);
            }
        }catch (\Throwable $exception){
            $queryException = $exception;
            throw $exception;
        }finally{
            if(is_callable($onQuery)){
                if(empty($ret)){
                    // 执行失败时也构造查询结果，保留原始异常及 SQL/构造器，便于回调定位错误。
                    $ret = new QueryResult($startTime, $startTick);
                    $ret->setConnectionName($connectionName);
                    $ret->finishTiming();
                    $ret->setException($queryException);
                    if($query instanceof QueryBuilder){
                        $ret->setQueryBuilder($query);
                    }else{
                        $ret->setRawSql($query);
                    }
                }
                try {
                    call_user_func($onQuery,$ret);
                }catch (\Throwable $callbackException){
                    // 查询已经失败时保留原始异常；查询成功时正常抛出回调自身的异常。
                    if($queryException === null){
                        throw $callbackException;
                    }
                }
            }
        }
        return $ret;
    }
}
