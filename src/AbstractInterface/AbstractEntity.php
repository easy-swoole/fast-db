<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Beans\EntityReflection;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\Exception\Exception;
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
        $pk = $this->primaryKeyCheck();
        $this->queryLimit()->where($pk,$this->{$pk});
        $query = $this->queryLimit()->__getQueryBuilder();
        $query->delete($this->tableName());
        $ret = FastDb::getInstance()->query($query);
        return $ret->getConnection()->getLastAffectRows() >= 1;
    }

    public static function fastDelete(array|callable $deleteLimit,string $tableName = null):int|null|string
    {
        if(empty($tableName)){
            $tableName = (new static())->tableName();
        }
        $query = new QueryBuilder();
        if(is_array($deleteLimit)){
            foreach ($deleteLimit as $key => $item){
                $query->where($key,$item);
            }
        }else{
            call_user_func($deleteLimit,$query);
        }
        $query->delete($tableName);
        $ret = FastDb::getInstance()->query($query);
        return $ret->getConnection()->getLastAffectRows();
    }

    function update()
    {
        $data = [];
        foreach ($this->compareData as $key => $compareDatum){
            $pVal = null;
            if(isset($this->{$key})){
                $pVal = $this->{$key};
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
        $pk = $this->primaryKeyCheck();
        $this->queryLimit()->where($pk,$this->{$pk});
        $query = $this->queryLimit()->__getQueryBuilder();
        $query->update($this->tableName(),$data);
        $ret = FastDb::getInstance()->query($query);
        return $ret->getConnection()->getLastAffectRows() > 0;
    }

    public static function fastUpdate(array|callable $updateLimit,array $data,string $tableName = null):bool|int|string
    {
        if(empty($tableName)){
            $tableName = (new static())->tableName();
        }
        $query = new QueryBuilder();
        if(is_array($updateLimit)){
            foreach ($updateLimit as $key => $item){
                $query->where($key,$item);
            }
        }else{
            call_user_func($updateLimit,$query);
        }
        $query->update($tableName,$data);
        $ret = FastDb::getInstance()->query($query);
        return $ret->getConnection()->getLastAffectRows();
    }

    function insert(array $updateDuplicateCols = null)
    {
        //插入的时候，null值一般无意义，default值在数据库层做。
        $data = $this->toArray(true);
        $query = $this->queryLimit()->__getQueryBuilder();
        if($query){
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


    private function primaryKeyCheck():string
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        $pk = $entityRef->getPrimaryKey();
        if(empty($pk)){
            $msg = "can not delete entity without primary key set";
            throw new RuntimeError($msg);
        }
        if(empty($this->{$pk})){
            $msg = "can not delete entity without primary key value";
            throw new RuntimeError($msg);
        }
        return $pk;
    }

    public static function findRecord(callable|array $queryLimit, string $tableName = null):?static
    {
        if(empty($tableName)){
            $tableName = (new static())->tableName();
        }
        $query = new QueryBuilder();
        if(is_array($queryLimit)){
            foreach ($queryLimit as $key => $item){
                $query->where($key,$item);
            }
        }else{
            call_user_func($queryLimit,$query);
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

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}