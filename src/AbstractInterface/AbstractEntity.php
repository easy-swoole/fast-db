<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Utility\ReflectionCache;
use EasySwoole\Mysqli\QueryBuilder;

abstract class AbstractEntity implements \JsonSerializable
{
    // 字段的比较基准；转换对象保存 toValue() 的结果，普通字段保存赋值后的值。
    // update() 据此识别变化，也能识别对象内部属性被修改的情况。
    private array $compareData = [];

    // 当前实体积累的查询条件和字段限制，执行后通常由 reset() 清理。
    private ?Query $queryBuilder = null;

    // 实体级查询回调，与 FastDb 的全局 onQuery 回调独立。
    private mixed $onQuery = null;

    abstract function tableName():string;

    function __construct(array|null $data = null)
    {
        $this->init();
        if(!empty($data)){
            // 构造数据同时作为比较基准，避免从数据库加载的字段被误判为待更新。
            $this->setData($data,true);
        }
    }

    private function init()
    {
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
                    $this->compareData[$property->name] = $object->toValue();
                }else{
                    $this->{$property->name} = null;
                    $this->compareData[$property->name] = $property->defaultValue;
                }
            }else{
                if(($property->defaultValue !== null) || $property->allowNull){
                    $this->{$property->name} = $property->defaultValue;
                }
                $this->compareData[$property->name] = $property->defaultValue;
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
            if($property->convertObject){
                if($val !== null){
                    // 对象直接使用；数组、字符串等输入由转换类的 toObject() 统一处理。
                    if($val instanceof $property->convertObject){
                        $object = $val;
                    }else{
                        $object = call_user_func([$property->convertObject,'toObject'],$val);
                    }
                    $this->{$key} = $object;
                    if($mergeCompare){
                        $this->compareData[$key] = $this->{$key}->toValue();
                    }
                }else{
                    // 可空字段允许清空；非空字段的 null 输入仍需经过转换器。
                    if($property->allowNull){
                        $this->{$key} = null;
                        if($mergeCompare){
                            $this->compareData[$key] = null;
                        }
                    }else{
                        $object = call_user_func([$property->convertObject,'toObject'],$val);
                        $this->{$key} = $object;
                        if($mergeCompare){
                            $this->compareData[$key] = $this->{$key}->toValue();
                        }
                    }
                }
            }else{
                if($property->assignCall){
                    $p = $property->assignCall->buildPropertyRuntimeParams($val,$this);
                    $val = call_user_func_array($property->assignCall->callback,$p);
                }
                $this->{$key} = $val;
                if($mergeCompare){
                    $this->compareData[$key] = $val;
                }
            }
        }
        return $this;
    }

    function all( bool $selectForUpdate = false ):ListResult
    {
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
            $info = static::callQuery('SELECT FOUND_ROWS() as count',$this->onQuery)->getResult();
            if(isset($info[0]['count'])){
                $total = $info[0]['count'];
            }
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
    }

    function chunk(callable $func,int $chunkSize = 10)
    {
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
    }

    /** 将实体字段转为输出值，同时应用隐藏字段和字段白名单。 */
    function toArray(bool $filterNull = false):array
    {
        $hideFields = $this->queryLimit()->getHideFields() ?:[];
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        $temp = [];
        /** @var Property $property */
        foreach ($entityRef->allProperties() as $property){
            $val = null;
            if(isset($this->{$property->name})){
                $val = $this->{$property->name};
            }
            if($val instanceof ConvertObjectInterface){
                // 转换对象和自定义输出回调各自负责生成可输出、可写入数据库的值。
                $val = $val->toValue();
            }else if($property->toValue){
                $p = $property->toValue->buildPropertyRuntimeParams($val,$this);
                $val = call_user_func_array($property->toValue->callback,$p);
            }else if($filterNull && $val === null){
                continue;
            }
            if (!in_array($property->name, $hideFields)){
                $temp[$property->name] = $val;
            }
        }

        $fields = $this->queryLimit()->getFields();
        if(!empty($fields['fields'])){
            $fields = $fields['fields'];
            foreach ($temp as $key => $val){
                if(!in_array($key,$fields)){
                    unset($temp[$key]);
                }
            }
        }

        // 清理 SQL 条件，但保留序列化字段限制，使连续调用 toArray()/jsonSerialize() 结果一致。
        $fieldLimits = $this->queryLimit()->getFields();
        $persistFieldLimit = $this->queryLimit()->isPersistFieldLimit();
        $this->reset();
        $this->queryLimit()->hideFields($hideFields)->persistFieldLimit($persistFieldLimit);
        if($fieldLimits !== null){
            $this->queryLimit()->fields(...$fieldLimits);
        }
        return $temp;
    }

    public function count(string|null $field = '*', string|null $group = null): int|array
    {
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
    }

    private function aggregate(string $aggregate, string|array $cols, string|null $group = null, bool $force = false): int|array|float
    {
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
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        if($entityRef->getOnDelete()){
            $ret = $this->callHook($entityRef->getOnDelete());
            if($ret === false){
                return false;
            }
        }
        // 实体删除必须带有有效主键，附加查询条件与主键条件共同生效。
        $pk = $this->primaryKeyCheck('delete');
        $this->queryLimit()->where($pk,$this->{$pk});
        $query = $this->queryLimit()->__getQueryBuilder();
        $query->delete($this->tableName());
        $ret = static::callQuery($query,$this->onQuery);
        $this->reset();
        return $ret->getConnection()->getLastAffectRows() >= 1;
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
        return $ret->getConnection()->getLastAffectRows();
    }

    function update()
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        if($entityRef->getOnUpdate()){
            $ret = $this->callHook($entityRef->getOnUpdate());
            if($ret === false){
                return  false;
            }
        }
        $data = [];
        $compareValues = [];
        $properties = $entityRef->allProperties();
        // 先比较当前值与基准，再生成数据库值；只把实际变化的字段加入更新语句。
        foreach ($this->compareData as $key => $compareDatum){
            $pVal = null;
            if(isset($this->{$key})){
                $pVal = $this->{$key};
            }
            if($pVal instanceof ConvertObjectInterface){
                $pVal = $pVal->toValue();
            }
            if($pVal !== $compareDatum){
                // 普通字段以赋值后的值比较，写入时再应用输出回调，避免重复转换导致误判。
                $compareValues[$key] = $pVal;
                $property = $properties[$key];
                if(!(($this->{$key} ?? null) instanceof ConvertObjectInterface) && $property->toValue){
                    $params = $property->toValue->buildPropertyRuntimeParams($pVal,$this);
                    $pVal = call_user_func_array($property->toValue->callback,$params);
                }
                $data[$key] = $pVal;
            }
        }

        if(!empty($this->queryLimit()->getFields())){
            $fields = $this->queryLimit()->getFields()['fields'];
            if(!empty($fields)){
                $data = array_intersect_key($data, array_flip($fields));
            }
        }
        if(empty($data)){
            // 没有待写入字段时视为成功，不发送 SQL。
            return true;
        }
        $pk = $this->primaryKeyCheck('update');
        $this->queryLimit()->where($pk,$this->{$pk});
        $query = $this->queryLimit()->__getQueryBuilder();
        $query->update($this->tableName(),$data);
        $ret = static::callQuery($query,$this->onQuery);
        $this->reset();
        $success = $ret->getConnection()->getLastAffectRows() > 0;
        if($success){
            // 事务回滚时恢复更新前的比较基准，让实体仍能识别尚未真正提交的修改。
            // 这里恢复的是基准，不是实体当前属性值。
            $ret->getConnection()->rememberTransactionBaseline(
                $this,
                $this->compareData,
                static function (self $entity, array $baseline): void {
                    $entity->compareData = $baseline;
                }
            );
            // 只同步实际写入字段的基准，被字段白名单排除的修改仍保留为待更新状态。
            foreach ($data as $key => $value){
                $this->compareData[$key] = $compareValues[$key];
            }
        }
        return $success;
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
        return $ret->getConnection()->getLastAffectRows();
    }

    function insert(array|null $updateDuplicateCols = null)
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        if($entityRef->getOnInsert()){
            $ret = $this->callHook($entityRef->getOnInsert());
            if($ret === false){
                return false;
            }
        }
        // 先完成输出转换；普通 null 字段省略，让数据库使用默认值。
        $data = $this->toArray(true);
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
        }else if($ret->getConnection()->getLastAffectRows() >= 1){
            $isSuccess = true;
        }
        if($ret->getConnection()->getLastInsertId() >= 1){
            // 将数据库生成的自增主键回填到实体和本次写入数据。
            $ref = ReflectionCache::getInstance()->parseEntity(static::class);
            if($ref->getPrimaryKey()){
                $this->{$ref->getPrimaryKey()} = $ret->getConnection()->getLastInsertId();
                $data[$ref->getPrimaryKey()] =  $ret->getConnection()->getLastInsertId();
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
    }


    private function reset():void
    {
        // 只清理查询状态，不清空实体属性、比较基准或查询回调。
        $this->queryBuilder = null;
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
        $relate = $this->parseRelate($relate);
        /** @var AbstractEntity $temp */
        $temp = new $relate->targetEntity();

        $query = $this->queryLimit()->__getQueryBuilder();
        $fields = null;
        $returnAsArray = false;
        if(!empty($this->queryLimit()->getFields())){
            $fields = $this->queryLimit()->getFields()['fields'];
            $returnAsArray = $this->queryLimit()->getFields()['returnAsArray'];
        }
        if(isset($this->{$relate->selfProperty})){
            $selfValue = $this->{$relate->selfProperty};
        }else{
            $selfValue = null;
        }

        if(empty($tableName)){
            $tableName = $temp->tableName();
        }

        // 查询两条即可判断一对一关系是否违反唯一性约束，无需拉取全部匹配记录。
        $query->where($relate->targetProperty,$selfValue)
            ->get($tableName,2,$fields);
        $ret = static::callQuery($query,$this->onQuery)->getResult();
        $this->reset();
        if(empty($ret)){
            return null;
        }
        if(count($ret) > 1){
            $msg = "more than one record hit is no allow in relateOne method";
            throw new RuntimeError($msg);
        }
        if($returnAsArray){
            return $ret[0];
        }
        $temp->setData($ret[0]);
        return $temp;

    }

    protected function relateMany(Relate|null $relate = null,string|null $tableName = null)
    {
        $relate = $this->parseRelate($relate);
        /** @var AbstractEntity $temp */
        $temp = new $relate->targetEntity();

        $query = $this->queryLimit()->__getQueryBuilder();
        $fields = null;
        $returnAsArray = false;
        if(!empty($this->queryLimit()->getFields())){
            $fields = $this->queryLimit()->getFields()['fields'];
            $returnAsArray = $this->queryLimit()->getFields()['returnAsArray'];
        }
        if(isset($this->{$relate->selfProperty})){
            $selfValue = $this->{$relate->selfProperty};
        }else{
            $selfValue = null;
        }

        if(empty($tableName)){
            $tableName = $temp->tableName();
        }

        $query->where($relate->targetProperty,$selfValue)
            ->get($tableName,null,$fields);
        $ret = static::callQuery($query,$this->onQuery)->getResult();

        $final = [];
        foreach ($ret as $item){
            if($returnAsArray){
                $final[] = $item;
            }else{
                $final[] = new $relate->targetEntity($item);
            }
        }
        $total = null;
        if(in_array('SQL_CALC_FOUND_ROWS',$query->getLastQueryOptions())){
            $info = static::callQuery('SELECT FOUND_ROWS() as count',$this->onQuery);
            $info = $info->getResult();
            if(isset($info[0]['count'])){
                $total = $info[0]['count'];
            }
        }
        $this->reset();
        return new ListResult($final,$total);
    }

    private function parseRelate(?Relate $relate = null)
    {
        if($relate == null){
            // 未显式传入关系定义时，从调用该方法的实体方法上读取 Relate 属性。
            $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT,3);
            $method = $trace[2]['function'];
            $ref = new \ReflectionClass(static::class);
            $ret = $ref->getMethod($method)->getAttributes(Relate::class);
            if(empty($ret)){
                $msg = "{$method} did not define Relate attribute in ".static::class;
                throw new RuntimeError($msg);
            }
            $relate = new Relate(...$ret[0]->getArguments());
        }
        // 校验关联两端的字段，避免使用未声明的实体属性。
        $check = ReflectionCache::getInstance()->parseEntity($relate->targetEntity);
        // 未指定当前实体关联字段时，默认使用当前实体的主键。
        if(empty($relate->selfProperty)){
            $relate->selfProperty = $this->primaryKeyCheck('relate',false);
        }else{
            if(!key_exists($relate->selfProperty,$this->compareData)){
                $msg = "{$relate->selfProperty} is not a define property in ".static::class;
                throw new RuntimeError($msg);
            }
        }
        if(!key_exists($relate->targetProperty,$check->allProperties())){
            $msg = "{$relate->selfProperty} is not a define property in {$relate->targetEntity}";
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
        $startTime = microtime(true);
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
                    $ret = new QueryResult($startTime);
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
