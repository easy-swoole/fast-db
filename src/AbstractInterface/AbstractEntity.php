<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Utility\ReflectionCache;
use EasySwoole\Mysqli\QueryBuilder;

abstract class AbstractEntity implements \JsonSerializable
{

    private array $compareData = [];

    private ?Query $queryBuilder = null;

    abstract function tableName():string;


    function __construct(array $data = null)
    {
        $this->init();
        if(!empty($data)){
            $this->setData($data,true);
        }
    }

    private function init()
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        //初始化所有变量和转化
        /** @var Property $property */
        foreach ($entityRef->allProperties() as $property){
            //判断是否需要转化
            if($property->convertObject){
                //如果不允许为null或者是存在默认值
                if((!$property->allowNull) || ($property->defaultValue !== null)){
                    /** @var ConvertObjectInterface $object */
                    $object = call_user_func([$property->convertObject,'toObject'],$property->defaultValue);
                    $this->{$property->name()} = $object;
                    $this->compareData[$property->name()] = $object->toValue();
                }else{
                    $this->{$property->name()} = null;
                    $this->compareData[$property->name()] = $property->defaultValue;
                }
            }else{
                if(($property->defaultValue !== null) || $property->allowNull){
                    $this->{$property->name()} = $property->defaultValue;
                }
                $this->compareData[$property->name()] = $property->defaultValue;
            }
        }
        if($entityRef->getOnInitialize()){
            $this->callHook($entityRef->getOnInitialize()->callback);
        }
    }

    function setData(array $data,bool $mergeCompare = false)
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        $allProperties = $entityRef->allProperties();
        foreach ($data as $key => $val){
            if(!isset($allProperties[$key])){
                continue;
            }
            /** @var Property $property */
            $property = $allProperties[$key];
            if($property->convertObject && ($val !== null)){
                $object = call_user_func([$property->convertObject,'toObject'],$val);
                $this->{$key} = $object;
                if($mergeCompare){
                    $this->compareData[$key] = $this->{$key}->toValue();
                }
            }else{
                $this->{$key} = $val;
                if($mergeCompare){
                    $this->compareData[$key] = $val;
                }
            }
        }
    }

    function all():ListResult
    {
        $query = $this->queryLimit()->__getQueryBuilder();

        $fields = null;
        $returnAsArray = false;
        if(!empty($this->queryLimit()->getFields())){
            $fields = $this->queryLimit()->getFields()['fields'];
            $returnAsArray = $this->queryLimit()->getFields()['returnAsArray'];
        }

        $query->get($this->tableName(),null,$fields);
        $ret = FastDb::getInstance()->query($query);
        $total = null;
        if(in_array('SQL_CALC_FOUND_ROWS',$query->getLastQueryOptions())){
            $info = FastDb::getInstance()->rawQuery('SELECT FOUND_ROWS() as count')->getResult();
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
                $list[] = new static($item);
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

    function toArray(bool $filterNull = false):array
    {
        $hideFields = $this->queryLimit()->getHideFields() ?:[];
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        $temp = [];
        /** @var Property $property */
        foreach ($entityRef->allProperties() as $property){
            $val = null;
            if(isset($this->{$property->name()})){
                $val = $this->{$property->name()};
            }
            if($val instanceof ConvertObjectInterface){
                $val = $val->toValue();
            }else if($filterNull && $val === null){
                continue;
            }
            if(!isset($hideFields[$property->name()])){
                $temp[$property->name()] = $val;
            }
        }
        $this->reset();
        return $temp;
    }


    function count():int|array
    {
        $fields = null;
        if(!empty($this->queryLimit()->getFields())){
            $fields = $this->queryLimit()->getFields()['fields'];
        }
        $query = $this->queryLimit()->__getQueryBuilder();
        $hasFiled = false;
        if(!empty($fields)){
            $hasFiled = true;
            $temp = [];
            foreach ($fields as $field){
                $temp[] = "count(`{$field}`) as $field";
            }
            $fields = $temp;
            $query->get($this->tableName(),null,$fields);
        }else{
            $query->get($this->tableName(),null,'count(*) as count');
        }
        $ret = FastDb::getInstance()->query($query)->getResult();
        $this->reset();
        if(empty($ret)){
            if($hasFiled){
                return [];
            }
            return 0;
        }
        $ret = $ret[0];
        if($hasFiled){
            return $ret;
        }
        return $ret['count'];
    }

    function sum(string|array $cols):int|array
    {
        $multiFields = false;
        if(is_string($cols)){
            $cols = [$cols];
        }
        if(count($cols) > 1){
            $multiFields = true;
        }
        $str = "";
        while ($item = array_shift($cols)){
            $str .= "sum(`{$item}`) as {$item}";
            if(!empty($cols)){
                $str .= " , ";
            }
        }
        $query = $this->queryLimit()->__getQueryBuilder();
        $query->get($this->tableName(),null,$str);
        $ret = FastDb::getInstance()->query($query)->getResult();
        $this->reset();
        if(empty($ret)){
            if($multiFields){
                return [];
            }
            return 0;
        }
        $ret = $ret[0];
        if($multiFields){
            return $ret;
        }else{
            return array_values($ret)[0] ?: 0;
        }
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
            $ret = $this->callHook($entityRef->getOnDelete()->callback);
            if($ret === false){
                return  false;
            }
        }
        $pk = $this->primaryKeyCheck('delete');
        $this->queryLimit()->where($pk,$this->{$pk});
        $query = $this->queryLimit()->__getQueryBuilder();
        $query->delete($this->tableName());
        $this->reset();
        $ret = FastDb::getInstance()->query($query);
        return $ret->getConnection()->getLastAffectRows() >= 1;
    }

    public static function fastDelete(array|callable|string $deleteLimit,string $tableName = null):int|null|string
    {
        if(empty($tableName)){
            $tableName = (new static())->tableName();
        }
        $query = new QueryBuilder();
        if(is_array($deleteLimit)){
            foreach ($deleteLimit as $key => $item){
                $query->where($key,$item);
            }
        }else if(is_callable($deleteLimit)){
            call_user_func($deleteLimit,$query);
        }else{
            $pk = ReflectionCache::getInstance()->parseEntity(static::class)->getPrimaryKey();
            if(empty($pk)){
                $msg = "entity can not delete record without primary key define";
                throw new RuntimeError($msg);
            }
            $query->where($pk,$deleteLimit);
        }
        $query->delete($tableName);
        $ret = FastDb::getInstance()->query($query);
        return $ret->getConnection()->getLastAffectRows();
    }

    function update()
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        if($entityRef->getOnUpdate()){
            $ret = $this->callHook($entityRef->getOnUpdate()->callback);
            if($ret === false){
                return  false;
            }
        }
        $data = [];
        foreach ($this->compareData as $key => $compareDatum){
            $pVal = null;
            if(isset($this->{$key})){
                $pVal = $this->{$key};
            }
            if($pVal instanceof ConvertObjectInterface){
                $pVal = $pVal->toValue();
            }
            if($pVal !== $compareDatum){
                $data[$key] = $pVal;
            }
        }

        if(!empty($this->queryLimit()->getFields())){
            $fields = $this->queryLimit()->getFields()['fields'];
            if(!empty($fields)){
                foreach ($fields as $field){
                    unset($data[$field]);
                }
            }
        }
        if(empty($data)){
            return true;
        }
        $pk = $this->primaryKeyCheck('update');
        $this->queryLimit()->where($pk,$this->{$pk});
        $query = $this->queryLimit()->__getQueryBuilder();
        $query->update($this->tableName(),$data);
        $ret = FastDb::getInstance()->query($query);
        $this->reset();
        return $ret->getConnection()->getLastAffectRows() > 0;
    }

    public static function fastUpdate(array|callable|string $updateLimit,array $data,string $tableName = null):bool|int|string
    {
        if(empty($tableName)){
            $tableName = (new static())->tableName();
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
            $query->where($pk,$updateLimit);
        }
        $query->update($tableName,$data);
        $ret = FastDb::getInstance()->query($query);
        return $ret->getConnection()->getLastAffectRows();
    }

    function insert(array $updateDuplicateCols = null)
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        if($entityRef->getOnInsert()){
            $ret = $this->callHook($entityRef->getOnInsert()->callback);
            if($ret === false){
                return false;
            }
        }
        //插入的时候，null值一般无意义，default值在数据库层做。
        $data = $this->toArray(true);
        $query = $this->queryLimit()->__getQueryBuilder();
        if($updateDuplicateCols){
            $query->onDuplicate($updateDuplicateCols);
        }
        $query->insert($this->tableName(),$data);
        $ret = FastDb::getInstance()->query($query);
        $isSuccess = false;
        //swoole客户端问题 https://github.com/swoole/swoole-src/issues/5202
        if($ret->getResult()){
            $isSuccess = true;
        }else if($ret->getConnection()->getLastAffectRows() >= 1){
            $isSuccess = true;
        }else if($ret->getConnection()->getLastInsertId() >= 1){
            $ref = ReflectionCache::getInstance()->parseEntity(static::class);
            if($ref->getPrimaryKey()){
                $this->{$ref->getPrimaryKey()} = $ret->getConnection()->getLastInsertId();
                $data[$ref->getPrimaryKey()] =  $ret->getConnection()->getLastInsertId();
            }
            $isSuccess = true;
        }else if(!empty($updateDuplicateCols)){
            return true;
        }
        if($isSuccess){
            $this->setData($data,true);
        }
        return $isSuccess;
    }


    private function reset():void
    {
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
        if(empty($this->{$pk}) && $emptyCheck){
            $msg = "can not {$op} entity without primary key value";
            throw new RuntimeError($msg);
        }
        return $pk;
    }

    public static function findRecord(callable|array|string $queryLimit, string $tableName = null):?static
    {
        if(empty($tableName)){
            $tableName = (new static())->tableName();
        }
        $query = new QueryBuilder();
        if(is_array($queryLimit)){
            foreach ($queryLimit as $key => $item){
                $query->where($key,$item);
            }
        }else if(is_callable($queryLimit)){
            call_user_func($queryLimit,$query);
        }else{
            $pk = ReflectionCache::getInstance()->parseEntity(static::class)->getPrimaryKey();
            if(empty($pk)){
                $msg = "entity can not find record without primary key define";
                throw new RuntimeError($msg);
            }
            $query->where($pk,$queryLimit);
        }
        $query->get($tableName,2);
        $ret = FastDb::getInstance()->query($query)->getResult();
        if(!empty($ret)){
            if(count($ret) > 1){
                $msg = "multi record match with your query limit";
                throw new RuntimeError($msg);
            }
            return new static($ret[0]);
        }
        return null;
    }

    public static function findAll(array|callable $queryLimit, string $tableName = null):mixed
    {
        if(empty($tableName)){
            $tableName = (new static())->tableName();
        }
        $query = new QueryBuilder();
        if(is_array($queryLimit)){
            foreach ($queryLimit as $key => $item){
                $query->where($key,$item);
            }
        }else if(is_callable($queryLimit)){
            call_user_func($queryLimit,$query);
        }
        $query->get($tableName);
        return FastDb::getInstance()->query($query)->getResult();
    }


    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    protected function callHook(callable|string $callback):mixed
    {
        if(is_callable($callback)){
            return call_user_func($callback,$this);
        }else{
            if(method_exists($this,$callback)){
                return $this->$callback();
            }else{
                throw new RuntimeError("{$callback} no a method of class ".static::class);
            }
        }
    }

    protected function relateOne(?Relate $relate = null,string $tableName = null):null|array|AbstractEntity
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
            ->get($tableName,2,$fields);

        $ret = FastDb::getInstance()->query($query)->getResult();
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

    protected function relateMany(?Relate $relate = null,string $tableName = null)
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

        $ret = FastDb::getInstance()->query($query)->getResult();

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
            $info = FastDb::getInstance()->rawQuery('SELECT FOUND_ROWS() as count')->getResult();
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
            //解析是否有注释Relate
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
        //检查目标对象
        $check = ReflectionCache::getInstance()->parseEntity($relate->targetEntity);
        //在没有指定目标和当前属性的情况下，都以自身主键为准。
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
}
